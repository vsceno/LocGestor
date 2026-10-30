<?php

namespace App\Http\Controllers;

use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Patrimônio: unidades de um produto (número de patrimônio e série), para saber qual peça está com qual cliente.
 * Mesmas regras de unidade_salvar(), unidade_baixar() e unidade_situacao() do sistema antigo.
 */
class UnidadesController extends Controller
{
    public const SITUACOES = [
        'disponivel' => ['disponível', 'selo-verde'],
        'na_rua'     => ['na rua', 'selo-azul'],
        'manutencao' => ['em manutenção', 'selo-amarelo'],
        'baixada'    => ['baixada', 'selo-cinza'],
    ];

    /** Unidades com a situação calculada numa consulta só (usada também na edição do produto). */
    public static function comSituacao(string $where, array $params): array
    {
        $lista = DB::select("SELECT u.*, m.id AS manutencao_id, m.numero AS manutencao, l.numero AS locacao
                               FROM unidades u
                               LEFT JOIN manutencoes m ON m.unidade_id = u.id AND m.data_fim IS NULL
                               LEFT JOIN locacao_unidades lu ON lu.unidade_id = u.id AND lu.devolucao_id IS NULL
                               LEFT JOIN locacao_itens li ON li.id = lu.locacao_item_id
                               LEFT JOIN locacoes l ON l.id = li.locacao_id
                              WHERE $where ORDER BY u.ativa DESC, u.patrimonio", $params);
        foreach ($lista as $u) {
            $u->situacao = !$u->ativa ? 'baixada' : ($u->manutencao !== null ? 'manutencao' : ($u->locacao !== null ? 'na_rua' : 'disponivel'));
        }
        return $lista;
    }

    public function create(string $produto): View
    {
        $p = $this->produto($produto);
        return view('produtos.unidade', ['u' => null, 'p' => $p, 'historico' => [], 'cheio' => $this->ativas($produto) >= $p->qtd_total]);
    }

    public function show(string $id): View
    {
        $u = $this->unidade($id);
        $p = $this->produto($u->produto_id);
        $historico = DB::select("SELECT 'locacao' AS tipo, l.numero, l.data_saida AS inicio, d.data AS fim, c.nome AS detalhe, NULL::uuid AS ref
                                   FROM locacao_unidades lu JOIN locacao_itens li ON li.id = lu.locacao_item_id
                                   JOIN locacoes l ON l.id = li.locacao_id JOIN clientes c ON c.id = l.cliente_id
                                   LEFT JOIN devolucoes d ON d.id = lu.devolucao_id
                                  WHERE lu.unidade_id = CAST(? AS uuid)
                                 UNION ALL
                                 SELECT 'manutencao', m.numero, m.data_inicio, m.data_fim, m.descricao, m.id
                                   FROM manutencoes m WHERE m.unidade_id = CAST(? AS uuid)
                                  ORDER BY inicio DESC", [$id, $id]);
        return view('produtos.unidade', ['u' => $u, 'p' => $p, 'historico' => $historico, 'cheio' => false]);
    }

    public function store(Request $request, string $produto): RedirectResponse
    {
        $this->produto($produto);
        [$dados, $e] = $this->validar($request);
        if ($e) {
            return back()->withErrors($e)->withInput();
        }
        try {
            $resultado = DB::transaction(function () use ($dados, $produto) {
                // Trava o produto: duas pessoas cadastrando ao mesmo tempo não passam do estoque
                $p = DB::selectOne('SELECT qtd_total FROM produtos WHERE id = ? FOR UPDATE', [$produto]);
                $ativas = $this->ativas($produto);
                if ($ativas >= $p->qtd_total) {
                    return ['erro' => "Já há $ativas unidade(s) cadastrada(s) para um estoque de {$p->qtd_total}. Aumente o estoque do produto antes."];
                }
                return ['id' => DB::selectOne('INSERT INTO unidades (produto_id, patrimonio, serie, aquisicao_data, aquisicao_valor, obs)
                                                VALUES (?, ?, ?, ?, ?, ?) RETURNING id', [$produto, ...array_values($dados)])->id];
            });
        } catch (QueryException $ex) {
            return $this->erroDoBanco($ex);
        }
        if (isset($resultado['erro'])) {
            return back()->withErrors(['patrimonio' => $resultado['erro']])->withInput();
        }
        return redirect()->route('unidades.show', $resultado['id'])->with('flash', [['tipo' => 'ok', 'msg' => 'Unidade cadastrada.']]);
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        $this->unidade($id);
        [$dados, $e] = $this->validar($request);
        if ($e) {
            return back()->withErrors($e)->withInput();
        }
        try {
            DB::transaction(fn () => DB::update('UPDATE unidades SET patrimonio = ?, serie = ?, aquisicao_data = ?, aquisicao_valor = ?, obs = ? WHERE id = ?',
                                                [...array_values($dados), $id]));
        } catch (QueryException $ex) {
            return $this->erroDoBanco($ex);
        }
        return redirect()->route('unidades.show', $id)->with('flash', [['tipo' => 'ok', 'msg' => 'Unidade atualizada.']]);
    }

    /** Baixa (venda, perda, sucata): a unidade sai do patrimônio e o estoque do produto diminui 1. */
    public function baixar(Request $request, string $id): RedirectResponse
    {
        $this->unidade($id);
        $data = (string) $request->input('baixa_data', '');
        $motivo = trim((string) $request->input('baixa_motivo', ''));
        if (!$this->dataValida($data) || $data > now()->toDateString() || mb_strlen($motivo) < 3 || mb_strlen($motivo) > 200) {
            return back()->withErrors(['baixa' => 'Informe a data (até hoje) e o motivo da baixa (3 a 200 caracteres).'])->withInput();
        }
        $erro = DB::transaction(function () use ($id, $data, $motivo) {
            $u = DB::selectOne('SELECT produto_id FROM unidades WHERE id = ? FOR UPDATE', [$id]);
            DB::select('SELECT id FROM produtos WHERE id = ? FOR UPDATE', [$u->produto_id]);
            $s = self::comSituacao('u.id = ?', [$id])[0];
            if ($s->situacao !== 'disponivel') {
                return match ($s->situacao) {
                    'baixada'    => 'Esta unidade já foi baixada.',
                    'manutencao' => "A unidade está na manutenção nº {$s->manutencao}: conclua a manutenção antes da baixa.",
                    default      => "A unidade está na rua (locação nº {$s->locacao}): registre a devolução antes da baixa.",
                };
            }
            $r = DB::selectOne('SELECT p.qtd_total,
                                       COALESCE((SELECT qtd_alugada FROM vw_produto_alugado WHERE produto_id = p.id), 0)
                                     + COALESCE((SELECT qtd_manutencao FROM vw_produto_manutencao WHERE produto_id = p.id), 0) AS fora
                                  FROM produtos p WHERE p.id = ?', [$u->produto_id]);
            if ($r->qtd_total - 1 < $r->fora) {
                return "Sem estoque livre para a baixa: {$r->fora} peça(s) estão na rua ou em manutenção.";
            }
            DB::update('UPDATE unidades SET ativa = false, baixa_data = ?, baixa_motivo = ? WHERE id = ?', [$data, $motivo, $id]);
            DB::update('UPDATE produtos SET qtd_total = qtd_total - 1 WHERE id = ?', [$u->produto_id]);
            return null;
        });
        if ($erro !== null) {
            return back()->withErrors(['baixa' => $erro])->withInput();
        }
        return redirect()->route('unidades.show', $id)->with('flash', [['tipo' => 'ok', 'msg' => 'Baixa registrada. O estoque do produto diminuiu 1.']]);
    }

    /** Só para unidade cadastrada por engano: com histórico (locação ou manutenção), não exclui. */
    public function destroy(string $id): RedirectResponse
    {
        $u = $this->unidade($id);
        $usada = DB::selectOne('SELECT EXISTS (SELECT 1 FROM locacao_unidades WHERE unidade_id = CAST(? AS uuid))
                                    OR EXISTS (SELECT 1 FROM manutencoes WHERE unidade_id = CAST(? AS uuid)) AS usada', [$id, $id])->usada;
        if ($usada) {
            return back()->with('flash', [['tipo' => 'erro', 'msg' => 'Esta unidade tem histórico e não pode ser excluída. Use a baixa.']]);
        }
        DB::delete('DELETE FROM unidades WHERE id = ?', [$id]);
        return redirect()->route('produtos.edit', $u->produto_id)->with('flash', [['tipo' => 'ok', 'msg' => 'Unidade excluída.']]);
    }

    private function ativas(string $produto): int
    {
        return (int) DB::selectOne('SELECT count(*) AS n FROM unidades WHERE produto_id = ? AND ativa', [$produto])->n;
    }

    private function dataValida(string $d): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4));
    }

    /** @return array{0: array<string, mixed>, 1: array<string, string>} */
    private function validar(Request $request): array
    {
        $data = trim((string) $request->input('aquisicao_data', ''));
        $u = [
            'patrimonio'      => strtoupper(trim((string) $request->input('patrimonio', ''))),
            'serie'           => trim((string) $request->input('serie', '')),
            'aquisicao_data'  => $data === '' ? null : $data,
            'aquisicao_valor' => valor_br($request->input('aquisicao_valor', '')),
            'obs'             => trim((string) $request->input('obs', '')),
        ];
        $e = [];
        if (!preg_match('/^[A-Z0-9][A-Z0-9._\/-]{0,29}$/', $u['patrimonio'])) {
            $e['patrimonio'] = 'Número de patrimônio inválido (até 30 caracteres: letras, números, ponto, hífen, barra).';
        }
        if (mb_strlen($u['serie']) > 60) {
            $e['serie'] = 'Máximo de 60 caracteres.';
        }
        if (mb_strlen($u['obs']) > 2000) {
            $e['obs'] = 'Máximo de 2.000 caracteres.';
        }
        if ($u['aquisicao_data'] !== null && (!$this->dataValida($u['aquisicao_data']) || $u['aquisicao_data'] > now()->toDateString())) {
            $e['aquisicao_data'] = 'Data de aquisição inválida.';
        }
        if ($u['aquisicao_valor'] === null || $u['aquisicao_valor'] < 0 || $u['aquisicao_valor'] > 9999999999) {
            $e['aquisicao_valor'] = 'Valor de aquisição inválido.';
        }
        return [$u, $e];
    }

    private function erroDoBanco(QueryException $e): RedirectResponse
    {
        if ($e->getCode() === '23505' && str_contains($e->getMessage(), 'unidades_patrimonio_uk')) {
            return back()->withErrors(['patrimonio' => 'Já existe uma unidade com este número de patrimônio.'])->withInput();
        }
        throw $e;
    }

    private function produto(string $id): object
    {
        abort_unless(Str::isUuid($id), 404);
        $p = DB::selectOne('SELECT id, codigo, descricao, qtd_total FROM produtos WHERE id = ?', [$id]);
        abort_if($p === null, 404, 'Produto não encontrado.');
        return $p;
    }

    /** Unidade da locadora logada (com a situação) ou 404. */
    private function unidade(string $id): object
    {
        abort_unless(Str::isUuid($id), 404);
        $u = self::comSituacao('u.id = ?', [$id])[0] ?? null;
        abort_if($u === null, 404, 'Unidade não encontrada.');
        return $u;
    }
}
