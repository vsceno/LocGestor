<?php

namespace App\Http\Controllers;

use App\Support\Estoque;
use App\Support\Formulario;
use App\Support\Recusa;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Manutenção: ordens de conserto ou revisão. Enquanto aberta, as peças ficam fora do estoque disponível.
 * Pode ser de uma unidade (patrimônio) ou de N peças de um produto. Mesmas regras de manutencao_abrir() e
 * manutencao_concluir() do sistema antigo.
 */
class ManutencoesController extends Controller
{
    public const SITUACOES = ['abertas' => 'Em manutenção', 'concluidas' => 'Concluídas', 'todas' => 'Todas'];
    public const TIPOS = ['corretiva' => 'Corretiva (conserto)', 'preventiva' => 'Preventiva (revisão)'];

    public function index(Request $request): View
    {
        $situacao = (string) $request->query('situacao', 'abertas');
        $situacao = array_key_exists($situacao, self::SITUACOES) ? $situacao : 'abertas';
        $where = match ($situacao) {
            'concluidas' => 'm.data_fim IS NOT NULL',
            'todas'      => 'TRUE',
            default      => 'm.data_fim IS NULL',
        };
        $porPagina = config('locgestor.por_pagina');
        $pagina = max(1, (int) $request->query('page', 1));
        $total = (int) DB::selectOne("SELECT count(*) AS n FROM manutencoes m WHERE $where")->n;
        $linhas = DB::select("SELECT m.id, m.numero, m.quantidade, m.tipo, m.descricao, m.data_inicio, m.data_fim, m.custo,
                                     p.descricao AS produto, u.patrimonio
                                FROM manutencoes m JOIN produtos p ON p.id = m.produto_id LEFT JOIN unidades u ON u.id = m.unidade_id
                               WHERE $where ORDER BY m.data_fim IS NULL DESC, m.numero DESC LIMIT ? OFFSET ?",
                              [$porPagina, ($pagina - 1) * $porPagina]);

        return view('manutencoes.index', [
            'manutencoes' => new LengthAwarePaginator($linhas, $total, $porPagina, $pagina, ['path' => route('manutencoes.index'), 'query' => ['situacao' => $situacao]]),
            'situacao'    => $situacao,
            'situacoes'   => self::SITUACOES,
            'abertas'     => (int) DB::selectOne('SELECT COALESCE(SUM(quantidade), 0) AS n FROM manutencoes WHERE data_fim IS NULL')->n,
            'custoAno'    => (float) DB::selectOne("SELECT COALESCE(SUM(custo), 0) AS v FROM manutencoes WHERE data_fim >= date_trunc('year', CURRENT_DATE)")->v,
        ]);
    }

    public function create(Request $request): View
    {
        // Vindo de um produto ou de uma unidade (patrimônio): já preenche o que a tela de origem sabe
        $produto = Str::isUuid((string) $request->query('produto', '')) ? (string) $request->query('produto') : '';
        $unidade = Str::isUuid((string) $request->query('unidade', '')) ? (string) $request->query('unidade') : '';

        return view('manutencoes.form', [
            'produtos' => DB::select("SELECT id, codigo || ' — ' || descricao AS nome FROM produtos WHERE ativo ORDER BY descricao"),
            // Só unidades livres agora: ativas, fora de manutenção e fora da rua
            'unidades' => DB::select("SELECT u.id, u.patrimonio || ' — ' || p.descricao AS nome FROM unidades u JOIN produtos p ON p.id = u.produto_id
                                       WHERE u.ativa
                                         AND NOT EXISTS (SELECT 1 FROM manutencoes m WHERE m.unidade_id = u.id AND m.data_fim IS NULL)
                                         AND NOT EXISTS (SELECT 1 FROM locacao_unidades lu WHERE lu.unidade_id = u.id AND lu.devolucao_id IS NULL)
                                       ORDER BY u.patrimonio"),
            'produto' => $produto,
            'unidade' => $unidade,
            'tipos'   => self::TIPOS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        try {
            $r = $this->abrir($request);
        } catch (Recusa $e) {
            return back()->withErrors(['manutencao' => $e->getMessage()])->withInput();
        }
        return redirect()->route('manutencoes.show', $r['id'])->with('flash', [['tipo' => 'ok',
            'msg' => "Manutenção nº {$r['numero']} aberta. As peças saíram do estoque disponível."]]);
    }

    public function show(string $id): View
    {
        $m = $this->manutencao($id);
        return view('manutencoes.show', ['m' => $m, 'tipos' => self::TIPOS]);
    }

    public function concluir(Request $request, string $id): RedirectResponse
    {
        $this->manutencao($id);
        try {
            $this->fechar($request, $id);
        } catch (Recusa $e) {
            return back()->withErrors(['concluir' => $e->getMessage()])->withInput();
        }
        return redirect()->route('manutencoes.show', $id)->with('flash', [['tipo' => 'ok',
            'msg' => 'Manutenção concluída. As peças voltaram ao estoque disponível.']]);
    }

    /** Só se exclui manutenção em aberto (a aberta por engano). Concluída fica no histórico. */
    public function destroy(string $id): RedirectResponse
    {
        $this->manutencao($id);
        if (DB::delete('DELETE FROM manutencoes WHERE id = ? AND data_fim IS NULL', [$id]) === 0) {
            return back()->with('flash', [['tipo' => 'erro', 'msg' => 'Só é possível excluir manutenção em aberto.']]);
        }
        return redirect()->route('manutencoes.index')->with('flash', [['tipo' => 'ok', 'msg' => 'Manutenção excluída.']]);
    }

    /** @return array{id: string, numero: int} */
    private function abrir(Request $request): array
    {
        $unidadeId = (string) $request->input('unidade_id', '');
        $produtoId = (string) $request->input('produto_id', '');
        $tipo = (string) $request->input('tipo', '');
        $descricao = trim((string) $request->input('descricao', ''));
        $fornecedor = trim((string) $request->input('fornecedor', ''));
        $inicio = (string) $request->input('data_inicio', '');
        $qtd = $unidadeId !== '' ? 1 : filter_var($request->input('quantidade', ''), FILTER_VALIDATE_INT);

        if (!array_key_exists($tipo, self::TIPOS)) {
            throw new Recusa('Escolha o tipo de manutenção.');
        }
        if (mb_strlen($descricao) < 3 || mb_strlen($descricao) > 500 || mb_strlen($fornecedor) > 100) {
            throw new Recusa('Descreva o problema ou o serviço (3 a 500 caracteres); fornecedor até 100.');
        }
        if (!Formulario::dataValida($inicio) || $inicio > now()->toDateString()) {
            throw new Recusa('A data de início deve ser hoje ou antes.');
        }
        if ($qtd === false || $qtd < 1 || $qtd > 100000) {
            throw new Recusa('Quantidade inválida.');
        }
        if (($unidadeId !== '' && !Str::isUuid($unidadeId)) || ($unidadeId === '' && !Str::isUuid($produtoId))) {
            throw new Recusa('Escolha o produto ou a unidade.');
        }

        return DB::transaction(function () use ($unidadeId, $produtoId, $tipo, $descricao, $fornecedor, $inicio, $qtd) {
            if ($unidadeId !== '') {
                $u = DB::selectOne('SELECT produto_id FROM unidades WHERE id = ? FOR UPDATE', [$unidadeId]);
                if ($u === null) {
                    throw new Recusa('Unidade não encontrada.');
                }
                $produtoId = $u->produto_id;
                $s = UnidadesController::comSituacao('u.id = ?', [$unidadeId])[0];
                if ($s->situacao !== 'disponivel') {
                    throw new Recusa(match ($s->situacao) {
                        'baixada'    => 'Esta unidade foi baixada.',
                        'manutencao' => "A unidade já está na manutenção nº {$s->manutencao}.",
                        default      => "A unidade está na rua (locação nº {$s->locacao}): registre a devolução antes.",
                    });
                }
            }
            Estoque::travar([$produtoId]);
            $p = DB::selectOne('SELECT descricao FROM produtos WHERE id = ?', [$produtoId]);
            if ($p === null) {
                throw new Recusa('Produto não encontrado.');
            }
            $hoje = now()->toDateString();
            $livre = Estoque::disponivel($produtoId, $hoje, $hoje);
            if ($qtd > $livre) {
                throw new Recusa("Só há $livre peça(s) de {$p->descricao} livre(s) agora: as demais estão na rua, reservadas ou em manutenção.");
            }
            $numero = (int) DB::selectOne("SELECT proximo_numero('manutencao') AS n")->n;
            $id = DB::selectOne('INSERT INTO manutencoes (numero, produto_id, unidade_id, quantidade, tipo, descricao, fornecedor, data_inicio, usuario_id)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id',
                                [$numero, $produtoId, $unidadeId === '' ? null : $unidadeId, $qtd, $tipo, $descricao, $fornecedor, $inicio,
                                 session('usuario.id')])->id;
            return ['id' => $id, 'numero' => $numero];
        });
    }

    /** As peças voltam ao estoque disponível; o custo pode virar saída no caixa. */
    private function fechar(Request $request, string $id): void
    {
        $fim = (string) $request->input('data_fim', '');
        $custo = valor_br($request->input('custo', ''));
        $solucao = trim((string) $request->input('solucao', ''));
        $lancar = $request->boolean('lancar_caixa') && $custo !== null && $custo > 0;
        $forma = (string) $request->input('forma', '');
        if ($custo === null || $custo < 0 || $custo > 9999999999 || mb_strlen($solucao) > 500) {
            throw new Recusa('Confira o custo e o serviço realizado (até 500 caracteres).');
        }
        if ($lancar && !in_array($forma, Estoque::FORMAS_PGTO, true)) {
            throw new Recusa('Escolha a forma de pagamento.');
        }

        DB::transaction(function () use ($id, $fim, $custo, $solucao, $lancar, $forma) {
            $m = DB::selectOne('SELECT m.*, p.descricao AS produto, u.patrimonio FROM manutencoes m JOIN produtos p ON p.id = m.produto_id
                                 LEFT JOIN unidades u ON u.id = m.unidade_id WHERE m.id = ? FOR UPDATE OF m', [$id]);
            if ($m === null) {
                throw new Recusa('Manutenção não encontrada.');
            }
            if ($m->data_fim !== null) {
                throw new Recusa('Esta manutenção já foi concluída.');
            }
            if (!Formulario::dataValida($fim) || $fim < $m->data_inicio || $fim > now()->toDateString()) {
                throw new Recusa('A data de conclusão deve estar entre o início (' . data_br($m->data_inicio) . ') e hoje.');
            }
            $caixaId = null;
            if ($lancar) {
                $descricao = "Manutenção nº {$m->numero} - {$m->produto}" . ($m->patrimonio ? " ({$m->patrimonio})" : '');
                $caixaId = DB::selectOne("INSERT INTO caixa (data, tipo, categoria, descricao, valor, forma, usuario_id)
                                          VALUES (?, 'S', 'Manutenção', ?, ?, ?, ?) RETURNING id",
                                         [$fim, mb_substr($descricao, 0, 200), $custo, $forma, session('usuario.id')])->id;
            }
            DB::update('UPDATE manutencoes SET data_fim = ?, custo = ?, solucao = ?, caixa_id = ? WHERE id = ?', [$fim, $custo, $solucao, $caixaId, $id]);
        });
    }

    private function manutencao(string $id): object
    {
        abort_unless(Str::isUuid($id), 404);
        $m = DB::selectOne('SELECT m.*, p.descricao AS produto, p.codigo, u.patrimonio, u.serie, us.nome AS usuario
                              FROM manutencoes m JOIN produtos p ON p.id = m.produto_id
                              LEFT JOIN unidades u ON u.id = m.unidade_id LEFT JOIN usuarios us ON us.id = m.usuario_id
                             WHERE m.id = ?', [$id]);
        abort_if($m === null, 404, 'Manutenção não encontrada.');
        return $m;
    }
}
