<?php

namespace App\Http\Controllers;

use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Produtos: lista com disponibilidade, cadastro, edição e exclusão.
 * Categorias, kits e patrimônio (unidades) ainda abrem no sistema antigo.
 * Disponível hoje = estoque - alugado (na rua) - reservado para hoje - em manutenção (igual ao sistema antigo).
 */
class ProdutosController extends Controller
{
    public const SITUACOES = ['ativos' => 'Ativos', 'todos' => 'Todos', 'inativos' => 'Inativos'];
    public const UNIDADES = ['UN', 'PC', 'JG', 'CJ', 'M', 'M2'];

    /** Restrições únicas do banco que viram mensagem no campo certo (o banco é quem garante). */
    private const UNICOS = [
        'produtos_codigo_uk'       => ['codigo', 'Já existe um produto com este código.'],
        'produto_barras_codigo_uk' => ['codigos_barras', 'Este código de barras já está em outro produto.'],
    ];

    public function index(Request $request): View
    {
        return view('produtos.index', $this->dadosLista($request));
    }

    /** POST do filtro: fica na sessão, como em clientes. Pela busca enquanto digita (fetch), devolve só a tabela. */
    public function filtrar(Request $request): RedirectResponse|Response
    {
        $situacao = (string) $request->input('situacao', 'ativos');
        $categoria = (string) $request->input('categoria', '');
        $filtro = $request->boolean('limpar') ? null : [
            'q'         => mb_substr(trim((string) $request->input('q', '')), 0, 100),
            'situacao'  => array_key_exists($situacao, self::SITUACOES) ? $situacao : 'ativos',
            'categoria' => Str::isUuid($categoria) ? $categoria : '',
        ];
        $filtro === null ? $request->session()->forget('filtro_produtos') : $request->session()->put('filtro_produtos', $filtro);

        if ($request->ajax()) {
            return response()->view('produtos._tabela', $this->dadosLista($request));
        }
        return redirect()->route('produtos.index');
    }

    public function create(): View
    {
        return view('produtos.form', ['p' => null, 'categorias' => $this->categorias(), 'codigos' => '', 'uso' => null, 'unidades' => []]);
    }

    public function edit(string $id): View
    {
        $p = $this->produto($id);
        $codigos = implode("\n", array_column(DB::select('SELECT codigo FROM produto_barras WHERE produto_id = ? ORDER BY principal DESC, codigo', [$id]), 'codigo'));
        return view('produtos.form', ['p' => $p, 'categorias' => $this->categorias(), 'codigos' => $codigos, 'uso' => $this->uso($id),
                                      'unidades' => UnidadesController::comSituacao('u.produto_id = ?', [$id])]);
    }

    public function store(Request $request): RedirectResponse
    {
        [$dados, $barras, $erros] = $this->validar($request);
        if ($erros) {
            return back()->withErrors($erros)->withInput();
        }
        try {
            DB::transaction(function () use ($dados, $barras) {
                $id = DB::selectOne('INSERT INTO produtos (' . implode(', ', array_keys($dados)) . ') VALUES ('
                    . implode(', ', array_fill(0, count($dados), '?')) . ') RETURNING id', array_values($dados))->id;
                $this->gravarCodigos($id, $barras);
            });
        } catch (QueryException $e) {
            return $this->erroDoBanco($e);
        }
        return redirect()->route('produtos.index')->with('flash', [['tipo' => 'ok', 'msg' => 'Produto cadastrado.']]);
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        $this->produto($id);
        [$dados, $barras, $erros] = $this->validar($request);
        if ($erros) {
            return back()->withErrors($erros)->withInput();
        }
        try {
            $erro = DB::transaction(function () use ($dados, $barras, $id) {
                // Trava o produto para ninguém alugar no meio da conferência
                if (DB::selectOne('SELECT id FROM produtos WHERE id = ? FOR UPDATE', [$id]) === null) {
                    abort(404);
                }
                $u = $this->uso($id);
                if ($dados['qtd_total'] < $u->alugado) {
                    return sprintf('O estoque total não pode ser menor que a quantidade alugada no momento (%d na rua).', $u->alugado);
                }
                if ($dados['qtd_total'] < $u->alugado + $u->manutencao) {
                    return sprintf('O estoque total não pode ser menor que o que está fora (%d na rua e %d em manutenção).', $u->alugado, $u->manutencao);
                }
                if ($dados['qtd_total'] < $u->unidades) {
                    return "O estoque total não pode ser menor que as {$u->unidades} unidade(s) de patrimônio cadastradas. Dê baixa nas que saíram.";
                }
                $sets = implode(', ', array_map(fn ($k) => "$k = ?", array_keys($dados)));
                DB::update("UPDATE produtos SET $sets WHERE id = ?", [...array_values($dados), $id]);
                $this->gravarCodigos($id, $barras);
                return null;
            });
        } catch (QueryException $e) {
            return $this->erroDoBanco($e);
        }
        if ($erro !== null) {
            return back()->withErrors(['qtd_total' => $erro])->withInput();
        }
        return redirect()->route('produtos.index')->with('flash', [['tipo' => 'ok', 'msg' => 'Produto atualizado.']]);
    }

    public function destroy(string $id): RedirectResponse
    {
        $this->produto($id);
        // Com histórico (locação, reserva, orçamento, kit, patrimônio ou manutenção), não exclui; o banco também impede
        if ($this->uso($id)->usado) {
            return back()->with('flash', [['tipo' => 'erro', 'msg' => 'Este produto já foi usado e não pode ser excluído. Desmarque “Ativo” para tirá-lo das novas locações.']]);
        }
        try {
            DB::delete('DELETE FROM produtos WHERE id = ?', [$id]);   // os códigos de barras vão junto (ON DELETE CASCADE)
        } catch (QueryException $e) {
            if ($e->getCode() === '23503') {
                return back()->with('flash', [['tipo' => 'erro', 'msg' => 'Este produto já foi usado e não pode ser excluído.']]);
            }
            throw $e;
        }
        return redirect()->route('produtos.index')->with('flash', [['tipo' => 'ok', 'msg' => 'Produto excluído.']]);
    }

    /** Produto da locadora logada ou 404 (id malformado ou de outra locadora: o RLS esconde a linha). */
    private function produto(string $id): object
    {
        abort_unless(Str::isUuid($id), 404);
        $p = DB::selectOne('SELECT * FROM produtos WHERE id = ?', [$id]);
        abort_if($p === null, 404, 'Produto não encontrado.');
        return $p;
    }

    /** @return array<string, string> id => nome */
    private function categorias(): array
    {
        return array_column(DB::select('SELECT id, nome FROM categorias ORDER BY nome'), 'nome', 'id');
    }

    /** O que está fora agora e se o produto tem histórico (aí não pode ser excluído). */
    private function uso(string $id): object
    {
        return DB::selectOne("SELECT COALESCE((SELECT qtd_alugada FROM vw_produto_alugado WHERE produto_id = CAST(? AS uuid)), 0) AS alugado,
                                     COALESCE((SELECT qtd_manutencao FROM vw_produto_manutencao WHERE produto_id = CAST(? AS uuid)), 0) AS manutencao,
                                     (SELECT count(*) FROM unidades WHERE produto_id = CAST(? AS uuid) AND ativa) AS unidades,
                                     EXISTS (SELECT 1 FROM locacao_itens WHERE produto_id = CAST(? AS uuid)
                                             UNION ALL SELECT 1 FROM reserva_itens WHERE produto_id = CAST(? AS uuid)
                                             UNION ALL SELECT 1 FROM orcamento_itens WHERE produto_id = CAST(? AS uuid)
                                             UNION ALL SELECT 1 FROM kit_itens WHERE produto_id = CAST(? AS uuid)
                                             UNION ALL SELECT 1 FROM unidades WHERE produto_id = CAST(? AS uuid)
                                             UNION ALL SELECT 1 FROM manutencoes WHERE produto_id = CAST(? AS uuid)) AS usado",
                             array_fill(0, 9, $id));
    }

    /** Substitui os códigos de barras do produto (dentro da transação do produto). O primeiro é o principal. */
    private function gravarCodigos(string $id, array $barras): void
    {
        DB::delete('DELETE FROM produto_barras WHERE produto_id = ?', [$id]);
        foreach ($barras as $i => $codigo) {
            DB::insert('INSERT INTO produto_barras (produto_id, codigo, principal) VALUES (?, ?, CAST(? AS boolean))',
                       [$id, $codigo, $i === 0 ? 'true' : 'false']);
        }
    }

    private function erroDoBanco(QueryException $e): RedirectResponse
    {
        if ($e->getCode() === '23505') {
            foreach (self::UNICOS as $restricao => [$campo, $msg]) {
                if (str_contains($e->getMessage(), $restricao)) {
                    return back()->withErrors([$campo => $msg])->withInput();
                }
            }
        }
        throw $e;
    }

    /**
     * Mesmas regras de produto_salvar() do sistema antigo. Devolve [dados para gravar, códigos de barras, erros por campo].
     * @return array{0: array<string, mixed>, 1: list<string>, 2: array<string, string>}
     */
    private function validar(Request $request): array
    {
        $txt = fn (string $k) => trim((string) $request->input($k, ''));
        $categoria = $txt('categoria_id');
        $qtd = filter_var($txt('qtd_total'), FILTER_VALIDATE_INT);
        $p = [
            'codigo'       => strtoupper($txt('codigo')),
            'descricao'    => $txt('descricao'),
            'categoria_id' => $categoria === '' ? null : $categoria,
            'unidade'      => strtoupper($txt('unidade') ?: 'UN'),
            'qtd_total'    => $qtd,
            'ncm'          => so_digitos($request->input('ncm', '')),
            'obs'          => $txt('obs'),
            'ativo'        => $request->boolean('ativo') ? 'true' : 'false',
        ];

        $e = [];
        foreach (['valor_diaria', 'valor_semanal', 'valor_quinzenal', 'valor_mensal', 'valor_reposicao'] as $k) {
            $p[$k] = valor_br($request->input($k, ''));
            if ($p[$k] === null) {
                $e[$k] = 'Valor inválido.';
                $p[$k] = 0.0;
            } elseif ($p[$k] < 0 || $p[$k] > 9999999999) {
                $e[$k] = 'O valor não pode ser negativo.';
            }
        }

        if (!preg_match('/^[A-Z0-9][A-Z0-9._\/-]{0,29}$/', $p['codigo'])) {
            $e['codigo'] = 'Código inválido (até 30 caracteres: letras, números, ponto, hífen, barra).';
        }
        if (mb_strlen($p['descricao']) < 2 || mb_strlen($p['descricao']) > 150) {
            $e['descricao'] = 'Informe a descrição (2 a 150 caracteres).';
        }
        if ($p['categoria_id'] !== null && (!Str::isUuid($p['categoria_id'])
                || DB::selectOne('SELECT 1 AS ok FROM categorias WHERE id = ?', [$p['categoria_id']]) === null)) {
            $e['categoria_id'] = 'Categoria inválida.';
        }
        if (mb_strlen($p['unidade']) > 10) {
            $e['unidade'] = 'Informe a unidade (até 10 caracteres).';
        }
        if ($qtd === false || $qtd < 0 || $qtd > 1000000) {
            $e['qtd_total'] = 'Quantidade em estoque inválida.';
        }
        if (($p['valor_semanal'] > 0 || $p['valor_mensal'] > 0) && $p['valor_diaria'] <= 0 && !isset($e['valor_diaria'])) {
            $e['valor_diaria'] = 'Para usar preço semanal ou mensal, informe também a diária (usada nos dias que sobram).';
        }
        if ($p['valor_semanal'] > 0 && $p['valor_mensal'] > 0 && $p['valor_mensal'] < $p['valor_semanal'] && !isset($e['valor_mensal'])) {
            $e['valor_mensal'] = 'O preço mensal não pode ser menor que o semanal.';
        }
        if ($p['ncm'] !== '' && strlen($p['ncm']) !== 8) {
            $e['ncm'] = 'NCM deve ter 8 dígitos.';
        }
        if (mb_strlen($p['obs']) > 2000) {
            $e['obs'] = 'Máximo de 2000 caracteres.';
        }

        // Códigos de barras: um por linha (ou vírgula/espaço), só dígitos, de 5 a 20, sem repetir
        $barras = [];
        foreach (preg_split('/[\s,;]+/', $txt('codigos_barras'), -1, PREG_SPLIT_NO_EMPTY) as $c) {
            if (!preg_match('/^[0-9]{5,20}$/', $c)) {
                $e['codigos_barras'] ??= "Código de barras inválido \"$c\" (use só números, de 5 a 20 dígitos).";
            } elseif (in_array($c, $barras, true)) {
                $e['codigos_barras'] ??= "O código de barras $c está repetido no produto.";
            } else {
                $barras[] = $c;
            }
        }
        return [$p, $barras, $e];
    }

    private function dadosLista(Request $request): array
    {
        $f = (array) $request->session()->get('filtro_produtos', []) + ['q' => '', 'situacao' => 'ativos', 'categoria' => ''];
        $q = (string) $f['q'];
        $situacao = array_key_exists($f['situacao'], self::SITUACOES) ? $f['situacao'] : 'ativos';
        $categoria = Str::isUuid((string) $f['categoria']) ? $f['categoria'] : '';

        $where = [];
        $params = [];
        if ($q !== '') {
            $where[] = '(lower(p.codigo) LIKE lower(?) OR lower(p.descricao) LIKE lower(?)
                         OR EXISTS (SELECT 1 FROM produto_barras b WHERE b.produto_id = p.id AND b.codigo = ?))';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            array_push($params, $like, $like, $q);
        }
        if ($categoria !== '') {
            $where[] = 'p.categoria_id = ?';
            $params[] = $categoria;
        }
        // A contagem dos botões respeita a busca e a categoria; a lista respeita também a situação
        $base = $where ? implode(' AND ', $where) : 'TRUE';
        $sqlWhere = $base . ' AND ' . match ($situacao) { 'todos' => 'TRUE', 'inativos' => 'NOT p.ativo', default => 'p.ativo' };

        $porPagina = config('locgestor.por_pagina');
        $pagina = $request->ajax() ? 1 : max(1, (int) $request->query('page', 1));
        $total = (int) DB::selectOne("SELECT count(*) AS n FROM produtos p WHERE $sqlWhere", $params)->n;
        $linhas = DB::select("SELECT p.id, p.codigo, p.descricao, p.unidade, p.qtd_total, p.valor_diaria, p.valor_semanal,
                                     p.valor_quinzenal, p.valor_mensal, p.ativo, c.nome AS categoria,
                                     COALESCE(a.qtd_alugada, 0) AS alugado, COALESCE(mn.qtd_manutencao, 0) AS manutencao,
                                     COALESCE((SELECT SUM(ri.quantidade)
                                                 FROM reserva_itens ri JOIN reservas r ON r.id = ri.reserva_id
                                                WHERE r.status = 'ativa' AND ri.produto_id = p.id
                                                  AND CURRENT_DATE BETWEEN r.data_inicio AND r.data_fim), 0) AS reservado
                                FROM produtos p
                                LEFT JOIN categorias c ON c.id = p.categoria_id
                                LEFT JOIN vw_produto_alugado a ON a.produto_id = p.id
                                LEFT JOIN vw_produto_manutencao mn ON mn.produto_id = p.id
                               WHERE $sqlWhere
                               ORDER BY p.descricao
                               LIMIT ? OFFSET ?", [...$params, $porPagina, ($pagina - 1) * $porPagina]);
        foreach ($linhas as $l) {
            $l->disponivel = $l->qtd_total - $l->alugado - $l->reservado - $l->manutencao;
        }

        $contagem = DB::selectOne("SELECT count(*) FILTER (WHERE p.ativo) AS ativos, count(*) AS todos, count(*) FILTER (WHERE NOT p.ativo) AS inativos
                                     FROM produtos p WHERE $base", $params);

        return [
            'produtos'   => new LengthAwarePaginator($linhas, $total, $porPagina, $pagina, ['path' => route('produtos.index')]),
            'q'          => $q,
            'situacao'   => $situacao,
            'situacoes'  => self::SITUACOES,
            'categoria'  => $categoria,
            'categorias' => $this->categorias(),
            'contagem'   => $contagem,
        ];
    }
}
