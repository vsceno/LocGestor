<?php

namespace App\Http\Controllers;

use App\Support\Estoque;
use App\Support\Locacoes;
use App\Support\Recusa;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Locações: lista e ficha, com recebimento, prorrogação, cancelamento, entrega e patrimônio.
 * Nova locação, devolução, documentos e vistorias ainda abrem no sistema antigo.
 * Endereços usam o número da locação (próprio de cada locadora); o RLS esconde as das outras.
 */
class LocacoesController extends Controller
{
    public const ABAS = [
        'abertas'    => 'Em aberto',
        'atrasadas'  => 'Atrasadas',
        'hoje'       => 'Vencem hoje',
        'devolvidas' => 'Devolvidas',
        'canceladas' => 'Canceladas',
        'todas'      => 'Todas',
    ];

    private const FILTROS = [
        'abertas'    => "l.status = 'aberta'",
        'atrasadas'  => "l.status = 'aberta' AND l.data_prev_devolucao < CURRENT_DATE",
        'hoje'       => "l.status = 'aberta' AND l.data_prev_devolucao = CURRENT_DATE",
        'devolvidas' => "l.status = 'devolvida'",
        'canceladas' => "l.status = 'cancelada'",
        'todas'      => 'TRUE',
    ];

    public function index(Request $request): View
    {
        return view('locacoes.index', $this->dadosLista($request));
    }

    /** POST do filtro (o nome do cliente não vai para o endereço). Pela busca enquanto digita, devolve só a tabela. */
    public function filtrar(Request $request): RedirectResponse|Response
    {
        $aba = (string) $request->input('aba', 'abertas');
        $request->session()->put('filtro_locacoes', [
            'q'   => mb_substr(trim((string) $request->input('q', '')), 0, 100),
            'aba' => array_key_exists($aba, self::ABAS) ? $aba : 'abertas',
        ]);
        if ($request->ajax()) {
            return response()->view('locacoes._tabela', $this->dadosLista($request));
        }
        return redirect()->route('locacoes.index');
    }

    public function show(int $numero): View
    {
        $loc = $this->locacao($numero);
        $empresa = DB::selectOne('SELECT * FROM empresa');
        $itens = DB::select("SELECT li.id, li.produto_id, li.quantidade, li.qtd_devolvida, li.valor_diaria, li.valor_semanal, li.valor_quinzenal,
                                    li.valor_mensal, li.periodo, li.data_prev_devolucao, p.codigo, p.descricao, p.unidade,
                                    COALESCE((SELECT SUM(di.valor) FROM devolucao_itens di WHERE di.locacao_item_id = li.id), 0) AS valor_devolvido,
                                    -- O que falta devolver, pelo preço de hoje: as mesmas funções de preço da view vw_locacao_valores
                                    (li.quantidade - li.qtd_devolvida) * CASE WHEN li.periodo = 'automatico'
                                        THEN preco_periodo(CURRENT_DATE - l.data_saida + 1, li.valor_diaria, li.valor_semanal, li.valor_mensal)
                                        ELSE preco_plano(CURRENT_DATE - l.data_saida + 1, li.periodo, li.valor_diaria, li.valor_semanal, li.valor_quinzenal, li.valor_mensal)
                                    END AS pendente_hoje
                               FROM locacao_itens li JOIN produtos p ON p.id = li.produto_id JOIN locacoes l ON l.id = li.locacao_id
                              WHERE li.locacao_id = ? ORDER BY p.descricao", [$loc->id]);
        $devolucoes = DB::select("SELECT d.id, d.data, d.multa, d.avaria, d.obs,
                                         (SELECT string_agg(di.quantidade || ' ' || p.descricao, ', ' ORDER BY p.descricao)
                                            FROM devolucao_itens di JOIN locacao_itens li ON li.id = di.locacao_item_id
                                            JOIN produtos p ON p.id = li.produto_id WHERE di.devolucao_id = d.id) AS itens,
                                         (SELECT max(di.dias) FROM devolucao_itens di WHERE di.devolucao_id = d.id) AS dias,
                                         (SELECT SUM(di.valor) FROM devolucao_itens di WHERE di.devolucao_id = d.id) AS valor
                                    FROM devolucoes d WHERE d.locacao_id = ? ORDER BY d.data, d.criado_em", [$loc->id]);
        $pagamentos = DB::select('SELECT data, tipo, descricao, valor, forma FROM caixa WHERE locacao_id = ? ORDER BY data, criado_em', [$loc->id]);
        $vistorias = DB::select("SELECT v.id, v.tipo, v.criado_em, v.assinante_nome, v.assinatura_arquivo <> '' AS assinada, u.nome AS usuario,
                                        (SELECT count(*) FROM vistoria_fotos f WHERE f.vistoria_id = v.id) AS fotos,
                                        (SELECT count(*) FROM vistoria_itens i WHERE i.vistoria_id = v.id AND i.estado <> 'ok') AS problemas
                                   FROM vistorias v LEFT JOIN usuarios u ON u.id = v.usuario_id
                                  WHERE v.locacao_id = ? ORDER BY v.criado_em", [$loc->id]);
        $reserva = $loc->reserva_id ? DB::selectOne('SELECT numero FROM reservas WHERE id = ?', [$loc->reserva_id])?->numero : null;

        return view('locacoes.show', [
            'loc'        => $loc,
            'itens'      => $itens,
            'devolucoes' => $devolucoes,
            'pagamentos' => $pagamentos,
            'vistorias'  => $vistorias,
            'reserva'    => $reserva,
            'whats'      => Locacoes::whatsapp($loc, $empresa),
            'pix'        => Locacoes::pix($loc, $empresa),
            'semChavePix'=> ($empresa->pix_chave ?? '') === '',
            'patrimonio' => $this->patrimonio($loc, $itens),
        ]);
    }

    public function receber(Request $request, int $numero): RedirectResponse
    {
        $this->locacao($numero);
        $valor = valor_br($request->input('valor', ''));
        $data = (string) $request->input('data', now()->toDateString());
        $forma = (string) $request->input('forma', '');
        if ($valor === null || $valor <= 0) {
            return $this->erro('Informe o valor recebido.', 'receber');
        }
        if (!$this->dataValida($data) || $data > now()->toDateString()) {
            return $this->erro('A data do recebimento não pode ser no futuro.', 'receber');
        }
        if (!in_array($forma, Estoque::FORMAS_PGTO, true)) {
            return $this->erro('Escolha a forma de pagamento.', 'receber');
        }
        try {
            DB::transaction(function () use ($numero, $valor, $data, $forma) {
                $loc = DB::selectOne('SELECT l.id, l.numero, l.status, c.nome AS cliente_nome FROM locacoes l
                                        JOIN clientes c ON c.id = l.cliente_id WHERE l.numero = ? FOR UPDATE OF l', [$numero]);
                if ($loc->status === 'cancelada') {
                    throw new Recusa('Locação cancelada não recebe pagamentos.');
                }
                Locacoes::lancarCaixa($loc, 'E', $valor, $forma, $data, 'Recebimento');
            });
        } catch (Recusa $e) {
            // A transação foi desfeita: nada fica gravado pela metade
            return $this->erro($e->getMessage(), 'receber');
        }
        return $this->ok($numero, 'Recebimento de ' . moeda($valor) . ' registrado.');
    }

    /** Prorroga a devolução prevista. Se a nova data avança sobre reservas, confere o estoque. */
    public function prorrogar(Request $request, int $numero): RedirectResponse
    {
        $this->locacao($numero);
        $nova = (string) $request->input('data', '');
        $motivo = mb_substr(trim((string) $request->input('motivo', '')), 0, 200);
        if (!$this->dataValida($nova)) {
            return $this->erro('Informe a nova data de devolução.', 'prorrogar');
        }
        try {
            DB::transaction(function () use ($numero, $nova, $motivo) {
                $loc = DB::selectOne('SELECT * FROM locacoes WHERE numero = ? FOR UPDATE', [$numero]);
                if ($loc->status !== 'aberta') {
                    throw new Recusa('Só é possível prorrogar locação em aberto.');
                }
                if ($nova < $loc->data_saida) {
                    throw new Recusa('A nova data não pode ser antes da saída.');
                }
                if ($nova === $loc->data_prev_devolucao) {
                    throw new Recusa('A nova data é igual à atual.');
                }
                if ($nova > $loc->data_prev_devolucao) {
                    // Os itens pendentes continuarão fora até a nova data: não podem invadir reservas já feitas
                    $pend = [];
                    foreach (DB::select('SELECT produto_id, SUM(quantidade - qtd_devolvida) AS q FROM locacao_itens WHERE locacao_id = ?
                                          GROUP BY produto_id HAVING SUM(quantidade - qtd_devolvida) > 0', [$loc->id]) as $r) {
                        $pend[$r->produto_id] = (int) $r->q;
                    }
                    Estoque::travar(array_keys($pend));
                    $falta = Estoque::falta($pend, now()->toDateString(), $nova, null, $loc->id);
                    if ($falta !== null) {
                        throw new Recusa('Não é possível prorrogar até ' . data_br($nova) . " por causa de reservas no período. $falta");
                    }
                }
                DB::update('UPDATE locacoes SET data_prev_devolucao = ? WHERE id = ?', [$nova, $loc->id]);
                Locacoes::historico($loc->id, 'Prorrogada de ' . data_br($loc->data_prev_devolucao) . ' para ' . data_br($nova)
                                              . ($motivo !== '' ? ". Motivo: $motivo" : '.'));
            });
        } catch (Recusa $e) {
            // A transação foi desfeita: nada fica gravado pela metade
            return $this->erro($e->getMessage(), 'prorrogar');
        }
        return $this->ok($numero, 'Devolução prevista alterada para ' . data_br($nova) . '.');
    }

    /** Cancela locação em aberto sem devoluções. Havendo valor pago, lança o estorno (saída) no caixa. */
    public function cancelar(Request $request, int $numero): RedirectResponse
    {
        $this->locacao($numero);
        $motivo = mb_substr(trim((string) $request->input('motivo', '')), 0, 200);
        $forma = (string) $request->input('forma', '');
        try {
            DB::transaction(function () use ($numero, $motivo, $forma) {
                DB::select('SELECT id FROM locacoes WHERE numero = ? FOR UPDATE', [$numero]);
                $loc = Locacoes::porNumero($numero);
                if ($loc->status !== 'aberta') {
                    throw new Recusa('Só é possível cancelar locação em aberto.');
                }
                if ((int) $loc->devolucoes > 0) {
                    throw new Recusa('Esta locação já tem devoluções registradas e não pode ser cancelada.');
                }
                $pago = (float) $loc->valor_pago;
                if ($pago > 0) {
                    if (!in_array($forma, Estoque::FORMAS_PGTO, true)) {
                        throw new Recusa('Escolha a forma de pagamento.');
                    }
                    Locacoes::lancarCaixa($loc, 'S', $pago, $forma, now()->toDateString(), 'Estorno');
                }
                DB::update("UPDATE locacoes SET status = 'cancelada' WHERE id = ?", [$loc->id]);
                // As unidades nunca saíram de fato: some o vínculo
                DB::delete('DELETE FROM locacao_unidades WHERE locacao_item_id IN (SELECT id FROM locacao_itens WHERE locacao_id = ?)', [$loc->id]);
                Locacoes::historico($loc->id, 'Cancelada' . ($motivo !== '' ? ". Motivo: $motivo" : '.')
                                              . ($pago > 0 ? ' Estorno de ' . moeda($pago) . " ($forma)." : ''));
            });
        } catch (Recusa $e) {
            // A transação foi desfeita: nada fica gravado pela metade
            return $this->erro($e->getMessage(), 'cancelar');
        }
        return $this->ok($numero, "Locação nº $numero cancelada.");
    }

    /** Fretes e endereço de entrega de locação em aberto (o histórico registra a mudança). */
    public function entrega(Request $request, int $numero): RedirectResponse
    {
        $this->locacao($numero);
        $fe = valor_br($request->input('frete_entrega', ''));
        $fr = valor_br($request->input('frete_recolhimento', ''));
        $endereco = trim((string) $request->input('endereco_entrega', ''));
        if ($fe === null || $fr === null || $fe < 0 || $fr < 0 || $fe > 9999999999 || $fr > 9999999999) {
            return $this->erro('Os fretes não podem ser negativos.', 'entrega');
        }
        if (mb_strlen($endereco) > 200) {
            return $this->erro('O endereço de entrega aceita no máximo 200 caracteres.', 'entrega');
        }
        try {
            DB::transaction(function () use ($numero, $fe, $fr, $endereco) {
                $loc = DB::selectOne('SELECT id, status, local_uso, frete_entrega, frete_recolhimento, endereco_entrega FROM locacoes WHERE numero = ? FOR UPDATE', [$numero]);
                if ($loc->status !== 'aberta') {
                    throw new Recusa('Só é possível alterar o frete e o endereço de locação em aberto.');
                }
                $endereco = $endereco !== '' ? $endereco : $loc->local_uso;
                DB::update('UPDATE locacoes SET frete_entrega = ?, frete_recolhimento = ?, endereco_entrega = ? WHERE id = ?', [$fe, $fr, $endereco, $loc->id]);
                $mudou = [];
                if ((float) $loc->frete_entrega !== $fe) {
                    $mudou[] = 'frete de entrega ' . moeda($loc->frete_entrega) . ' → ' . moeda($fe);
                }
                if ((float) $loc->frete_recolhimento !== $fr) {
                    $mudou[] = 'frete de recolhimento ' . moeda($loc->frete_recolhimento) . ' → ' . moeda($fr);
                }
                if ($loc->endereco_entrega !== $endereco) {
                    $mudou[] = 'endereço de entrega alterado';
                }
                if ($mudou) {
                    Locacoes::historico($loc->id, 'Entrega: ' . implode('; ', $mudou) . '.');
                }
            });
        } catch (Recusa $e) {
            // A transação foi desfeita: nada fica gravado pela metade
            return $this->erro($e->getMessage(), 'entrega');
        }
        return $this->ok($numero, 'Entrega e fretes atualizados.');
    }

    /** Quais unidades (patrimônio) de cada item estão na rua. Mesmas regras de locacao_unidades_salvar(). */
    public function unidades(Request $request, int $numero): RedirectResponse
    {
        $this->locacao($numero);
        $porItem = [];
        foreach ((array) $request->input('unidades', []) as $item => $lista) {
            $porItem[(string) $item] = array_values(array_unique(array_filter(array_map('strval', (array) $lista), fn ($v) => Str::isUuid($v))));
        }
        try {
            DB::transaction(function () use ($numero, $porItem) {
                $loc = DB::selectOne('SELECT id, status FROM locacoes WHERE numero = ? FOR UPDATE', [$numero]);
                if ($loc->status !== 'aberta') {
                    throw new Recusa('Só é possível indicar o patrimônio de locação em aberto.');
                }
                $itens = DB::select('SELECT li.id, li.produto_id, li.quantidade - li.qtd_devolvida AS pendente, p.descricao
                                       FROM locacao_itens li JOIN produtos p ON p.id = li.produto_id
                                      WHERE li.locacao_id = ? FOR UPDATE OF li', [$loc->id]);
                $validos = array_column($itens, 'id');
                foreach (array_keys($porItem) as $k) {
                    if (!in_array((string) $k, $validos, true)) {
                        throw new Recusa('Item inválido.');
                    }
                }
                foreach ($itens as $it) {
                    $escolha = $porItem[$it->id] ?? [];
                    if (count($escolha) > (int) $it->pendente) {
                        throw new Recusa("{$it->descricao}: escolha no máximo {$it->pendente} unidade(s) (quantidade na rua).");
                    }
                    DB::delete('DELETE FROM locacao_unidades WHERE locacao_item_id = ? AND devolucao_id IS NULL', [$it->id]);
                    foreach ($escolha as $uid) {
                        $u = DB::selectOne('SELECT produto_id, patrimonio FROM unidades WHERE id = ? FOR UPDATE', [$uid]);
                        if ($u === null || $u->produto_id !== $it->produto_id) {
                            throw new Recusa("Unidade inválida para {$it->descricao}.");
                        }
                        $s = UnidadesController::comSituacao('u.id = ?', [$uid])[0];
                        if ($s->situacao !== 'disponivel') {
                            throw new Recusa("A unidade {$u->patrimonio} " . match ($s->situacao) {
                                'baixada'    => 'foi baixada.',
                                'manutencao' => "está na manutenção nº {$s->manutencao}.",
                                default      => "está na rua (locação nº {$s->locacao}).",
                            });
                        }
                        DB::insert('INSERT INTO locacao_unidades (locacao_item_id, unidade_id) VALUES (?, ?)', [$it->id, $uid]);
                    }
                }
            });
        } catch (Recusa $e) {
            // A transação foi desfeita: nada fica gravado pela metade
            return $this->erro($e->getMessage(), 'patrimonio');
        }
        return $this->ok($numero, 'Patrimônio da locação atualizado.');
    }

    /** Unidades por item: as que estão nesta locação e as livres agora (só em locação aberta). */
    private function patrimonio(object $loc, array $itens): array
    {
        $alocadas = [];
        foreach (DB::select('SELECT lu.locacao_item_id, lu.unidade_id, lu.devolucao_id, u.patrimonio, d.data AS voltou
                               FROM locacao_unidades lu JOIN unidades u ON u.id = lu.unidade_id JOIN locacao_itens li ON li.id = lu.locacao_item_id
                               LEFT JOIN devolucoes d ON d.id = lu.devolucao_id
                              WHERE li.locacao_id = ? ORDER BY u.patrimonio', [$loc->id]) as $r) {
            $alocadas[$r->locacao_item_id][] = $r;
        }
        $livres = [];
        if ($loc->status === 'aberta' && $itens) {
            foreach (DB::select('SELECT u.id, u.produto_id, u.patrimonio FROM unidades u
                                  WHERE u.ativa AND u.produto_id = ANY (CAST(? AS uuid[]))
                                    AND NOT EXISTS (SELECT 1 FROM manutencoes m WHERE m.unidade_id = u.id AND m.data_fim IS NULL)
                                    AND NOT EXISTS (SELECT 1 FROM locacao_unidades lu WHERE lu.unidade_id = u.id AND lu.devolucao_id IS NULL)
                                  ORDER BY u.patrimonio', ['{' . implode(',', array_unique(array_column($itens, 'produto_id'))) . '}']) as $u) {
                $livres[$u->produto_id][] = $u;
            }
        }
        $lista = [];
        foreach ($itens as $it) {
            $naRua = array_values(array_filter($alocadas[$it->id] ?? [], fn ($a) => $a->devolucao_id === null));
            $voltaram = array_values(array_filter($alocadas[$it->id] ?? [], fn ($a) => $a->devolucao_id !== null));
            $opcoes = array_merge(
                array_map(fn ($a) => (object) ['id' => $a->unidade_id, 'patrimonio' => $a->patrimonio, 'marcada' => true], $naRua),
                // Livres só se ainda há peça deste item na rua para indicar
                $it->quantidade > $it->qtd_devolvida
                    ? array_map(fn ($u) => (object) ['id' => $u->id, 'patrimonio' => $u->patrimonio, 'marcada' => false], $livres[$it->produto_id] ?? [])
                    : [],
            );
            if ($naRua || $voltaram || $opcoes) {
                $lista[] = (object) ['item' => $it, 'naRua' => $naRua, 'voltaram' => $voltaram, 'opcoes' => $opcoes];
            }
        }
        return $lista;
    }

    private function locacao(int $numero): object
    {
        abort_if($numero < 1 || $numero > 999999999, 404);
        $loc = Locacoes::porNumero($numero);
        abort_if($loc === null, 404, 'Locação não encontrada.');
        return $loc;
    }

    private function dataValida(string $d): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4));
    }

    /** Volta à ficha com o erro no cartão da ação ($onde) e o que foi digitado. */
    private function erro(string $msg, string $onde): RedirectResponse
    {
        return back()->withErrors([$onde => $msg])->withInput();
    }

    private function ok(int $numero, string $msg): RedirectResponse
    {
        return redirect()->route('locacoes.show', $numero)->with('flash', [['tipo' => 'ok', 'msg' => $msg]]);
    }

    private function dadosLista(Request $request): array
    {
        $f = (array) $request->session()->get('filtro_locacoes', []) + ['q' => '', 'aba' => 'abertas'];
        $q = (string) $f['q'];
        $aba = array_key_exists($f['aba'], self::ABAS) ? $f['aba'] : 'abertas';

        $contagem = DB::selectOne('SELECT ' . implode(', ', array_map(fn ($k, $w) => "count(*) FILTER (WHERE $w) AS $k",
                                                                     array_keys(self::FILTROS), self::FILTROS)) . ' FROM locacoes l');
        $where = [self::FILTROS[$aba]];
        $params = [];
        if ($q !== '') {
            if (ctype_digit($q) && strlen($q) < 10) {
                $where[] = 'l.numero = ?';
                $params[] = (int) $q;
            } else {
                $where[] = 'lower(c.nome) LIKE lower(?)';
                $params[] = '%' . addcslashes($q, '%_\\') . '%';
            }
        }
        $sqlWhere = implode(' AND ', $where);
        $abertas = in_array($aba, ['abertas', 'atrasadas', 'hoje'], true);
        $ordem = $abertas ? 'l.data_prev_devolucao, l.numero' : 'l.data_saida DESC, l.numero DESC';

        $porPagina = config('locgestor.por_pagina');
        $pagina = $request->ajax() ? 1 : max(1, (int) $request->query('page', 1));
        $total = (int) DB::selectOne("SELECT count(*) AS n FROM locacoes l JOIN clientes c ON c.id = l.cliente_id WHERE $sqlWhere", $params)->n;
        $linhas = DB::select("SELECT l.id, l.numero, l.data_saida, l.data_prev_devolucao, l.status, l.cliente_id,
                                     c.nome AS cliente_nome, c.tipo AS cliente_tipo, c.celular AS cliente_celular, c.telefone AS cliente_telefone,
                                     v.valor_previsto, v.valor_atual, v.valor_pago,
                                     (SELECT COALESCE(SUM(li.quantidade - li.qtd_devolvida), 0) FROM locacao_itens li WHERE li.locacao_id = l.id) AS pendentes
                                FROM locacoes l
                                JOIN clientes c ON c.id = l.cliente_id
                                JOIN vw_locacao_valores v ON v.locacao_id = l.id
                               WHERE $sqlWhere
                               ORDER BY $ordem
                               LIMIT ? OFFSET ?", [...$params, $porPagina, ($pagina - 1) * $porPagina]);
        if ($abertas) {
            $empresa = DB::selectOne('SELECT * FROM empresa');
            foreach ($linhas as $l) {
                $l->lembrete = Locacoes::whatsapp($l, $empresa)['links']['lembrete'] ?? null;
            }
        }

        return [
            'locacoes' => new LengthAwarePaginator($linhas, $total, $porPagina, $pagina, ['path' => route('locacoes.index')]),
            'q'        => $q,
            'aba'      => $aba,
            'abas'     => self::ABAS,
            'contagem' => $contagem,
            'lembrete' => $abertas,
        ];
    }
}
