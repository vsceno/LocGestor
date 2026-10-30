<?php

namespace App\Http\Controllers;

use App\Support\Arquivos;
use App\Support\Locacoes;
use App\Support\Recusa;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

/**
 * Vistoria da locação: checklist de cada equipamento, fotos e assinatura do cliente, na retirada e na devolução.
 * Depois de gravada, não se altera nem se apaga. Mesmas regras de vistoria_registrar() do sistema antigo.
 */
class VistoriasController extends Controller
{
    public const ESTADOS = ['ok' => 'OK', 'avariado' => 'Avariado', 'sujo' => 'Sujo', 'incompleto' => 'Incompleto'];

    public function create(Request $request, int $numero): View|RedirectResponse
    {
        $loc = $this->locacao($numero);
        $tipo = $request->query('tipo') === 'retirada' ? 'retirada' : 'devolucao';
        if ($loc->status === 'cancelada') {
            return $this->voltar($numero, 'erro', 'Locação cancelada não recebe vistoria.');
        }
        if ($tipo === 'retirada' && $this->temRetirada($loc->id)) {
            return $this->voltar($numero, 'aviso', 'Esta locação já tem vistoria de retirada.');
        }

        return view('vistorias.form', [
            'loc'   => $loc,
            'tipo'  => $tipo,
            'itens' => DB::select('SELECT li.id, li.quantidade, li.qtd_devolvida, p.codigo, p.descricao, p.unidade
                                     FROM locacao_itens li JOIN produtos p ON p.id = li.produto_id
                                    WHERE li.locacao_id = ? ORDER BY p.descricao', [$loc->id]),
        ]);
    }

    public function store(Request $request, int $numero): RedirectResponse
    {
        $loc = $this->locacao($numero);
        try {
            $this->registrar($request, $loc);
        } catch (Recusa $e) {
            return back()->withErrors(['vistoria' => $e->getMessage()])->withInput($request->except(['fotos', 'assinatura']));
        }
        $rotulo = $request->input('tipo') === 'retirada' ? 'Vistoria de retirada' : 'Vistoria de devolução';
        return $this->voltar($numero, 'ok', "$rotulo registrada.");
    }

    /** Vistoria para ver e imprimir (A4): equipamentos, observações, fotos e assinatura. */
    public function show(string $id): View
    {
        abort_unless(Str::isUuid($id), 404);
        $v = DB::selectOne('SELECT v.*, u.nome AS usuario, l.numero, l.id AS locacao_id, c.nome AS cliente
                              FROM vistorias v JOIN locacoes l ON l.id = v.locacao_id JOIN clientes c ON c.id = l.cliente_id
                              LEFT JOIN usuarios u ON u.id = v.usuario_id WHERE v.id = ?', [$id]);
        abort_if($v === null, 404, 'Vistoria não encontrada.');

        return view('impressao.vistoria', [
            'v'      => $v,
            'itens'  => DB::select('SELECT vi.estado, vi.obs, li.quantidade, p.codigo, p.descricao, p.unidade
                                      FROM vistoria_itens vi JOIN locacao_itens li ON li.id = vi.locacao_item_id
                                      JOIN produtos p ON p.id = li.produto_id
                                     WHERE vi.vistoria_id = ? ORDER BY p.descricao', [$id]),
            'fotos'  => DB::select('SELECT id FROM vistoria_fotos WHERE vistoria_id = ? ORDER BY criado_em, id', [$id]),
            'estados' => self::ESTADOS,
        ]);
    }

    /**
     * Confere e grava a vistoria. Os arquivos são gravados primeiro; se o banco falhar, eles são apagados
     * (nada fica órfão no disco). Qualquer recusa acontece antes de gravar qualquer coisa.
     */
    private function registrar(Request $request, object $loc): void
    {
        $tipo = (string) $request->input('tipo', '');
        if (!in_array($tipo, ['retirada', 'devolucao'], true)) {
            throw new Recusa('Tipo de vistoria inválido.');
        }
        if ($loc->status === 'cancelada') {
            throw new Recusa('Locação cancelada não recebe vistoria.');
        }
        if ($tipo === 'retirada' && $this->temRetirada($loc->id)) {
            throw new Recusa('Esta locação já tem vistoria de retirada.');
        }

        // Estado de cada item: todos os itens da locação precisam ser avaliados
        $avaliados = [];
        $estados = (array) $request->input('estado', []);
        $obsItens = (array) $request->input('obs_item', []);
        foreach (DB::select('SELECT li.id, p.descricao FROM locacao_itens li JOIN produtos p ON p.id = li.produto_id WHERE li.locacao_id = ?', [$loc->id]) as $it) {
            $estado = (string) ($estados[$it->id] ?? '');
            if (!isset(self::ESTADOS[$estado])) {
                throw new Recusa("Informe o estado de {$it->descricao}.");
            }
            $avaliados[] = ['id' => $it->id, 'estado' => $estado, 'obs' => mb_substr(trim((string) ($obsItens[$it->id] ?? '')), 0, 200)];
        }
        $obs = mb_substr(trim((string) $request->input('obs', '')), 0, 2000);

        // Assinatura (opcional): se houver, exige o nome de quem assinou
        $assinante = mb_substr(trim((string) $request->input('assinante_nome', '')), 0, 100);
        $assinaturaBytes = null;
        $dataUrl = (string) $request->input('assinatura', '');
        if ($dataUrl !== '') {
            $decodificado = preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $m) && strlen($m[1]) <= 700000
                ? base64_decode($m[1], true) : false;
            if ($decodificado === false) {
                throw new Recusa('Assinatura inválida. Limpe e assine de novo.');
            }
            $img = Arquivos::regravar($decodificado, 900, true);
            if (Arquivos::assinaturaVazia($img['gd'])) {
                throw new Recusa('A assinatura está em branco. Peça para o cliente assinar na área indicada.');
            }
            if (mb_strlen($assinante) < 3) {
                throw new Recusa('Informe o nome de quem assinou.');
            }
            $assinaturaBytes = $img['bytes'];
        }

        $fotos = array_values(array_filter((array) $request->file('fotos', []), fn ($f) => $f instanceof UploadedFile));
        if (count($fotos) > Arquivos::FOTOS_POR_VISTORIA) {
            throw new Recusa('Envie no máximo ' . Arquivos::FOTOS_POR_VISTORIA . ' fotos por vistoria.');
        }

        $gravados = [];
        try {
            $registrosFotos = [];
            foreach ($fotos as $f) {
                if ($f->getError() === UPLOAD_ERR_INI_SIZE || $f->getError() === UPLOAD_ERR_FORM_SIZE || $f->getSize() > Arquivos::FOTO_BYTES_MAX) {
                    throw new Recusa("A foto {$f->getClientOriginalName()} é grande demais (máximo de 15 MB).");
                }
                if (!$f->isValid()) {
                    throw new Recusa("Não foi possível receber a foto {$f->getClientOriginalName()}. Tente de novo.");
                }
                $img = Arquivos::regravar((string) file_get_contents($f->getRealPath()), Arquivos::FOTO_LADO_MAX);
                $rel = Arquivos::gravar($img['bytes'], 'jpg');
                $gravados[] = $rel;
                $registrosFotos[] = ['arquivo' => $rel, 'tamanho' => strlen($img['bytes']), 'largura' => $img['largura'], 'altura' => $img['altura']];
            }

            $relAssinatura = '';
            if ($assinaturaBytes !== null) {
                $relAssinatura = Arquivos::gravar($assinaturaBytes, 'png');
                $gravados[] = $relAssinatura;
            }

            DB::transaction(function () use ($loc, $tipo, $obs, $assinante, $relAssinatura, $avaliados, $registrosFotos) {
                $id = DB::selectOne("INSERT INTO vistorias (locacao_id, tipo, obs, assinante_nome, assinatura_arquivo, assinado_em, usuario_id)
                                     VALUES (?, ?, ?, ?, ?, CASE WHEN CAST(? AS text) = '' THEN NULL ELSE now() END, ?) RETURNING id",
                                    [$loc->id, $tipo, $obs, $relAssinatura !== '' ? $assinante : '', $relAssinatura, $relAssinatura,
                                     session('usuario.id')])->id;
                foreach ($avaliados as $it) {
                    DB::insert('INSERT INTO vistoria_itens (vistoria_id, locacao_item_id, estado, obs) VALUES (?, ?, ?, ?)',
                               [$id, $it['id'], $it['estado'], $it['obs']]);
                }
                foreach ($registrosFotos as $f) {
                    DB::insert('INSERT INTO vistoria_fotos (vistoria_id, arquivo, tamanho, largura, altura) VALUES (?, ?, ?, ?, ?)',
                               [$id, $f['arquivo'], $f['tamanho'], $f['largura'], $f['altura']]);
                }
            });
        } catch (Throwable $e) {
            foreach ($gravados as $rel) {
                Arquivos::apagar($rel);
            }
            throw $e;
        }
    }

    private function temRetirada(string $locacaoId): bool
    {
        return DB::selectOne("SELECT 1 AS ok FROM vistorias WHERE locacao_id = ? AND tipo = 'retirada'", [$locacaoId]) !== null;
    }

    private function voltar(int $numero, string $tipo, string $msg): RedirectResponse
    {
        return redirect()->route('locacoes.show', $numero)->with('flash', [['tipo' => $tipo, 'msg' => $msg]]);
    }

    /** Locação da locadora logada ou 404 (o RLS esconde as das outras). */
    private function locacao(int $numero): object
    {
        abort_if($numero < 1 || $numero > 999999999, 404);
        $loc = Locacoes::porNumero($numero);
        abort_if($loc === null, 404, 'Locação não encontrada.');
        return $loc;
    }
}
