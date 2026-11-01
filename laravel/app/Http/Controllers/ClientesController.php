<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Clientes: lista e ficha. Cadastro e edição ainda são feitos no sistema antigo.
 * Nenhuma consulta filtra por tenant_id: quem filtra é o RLS do banco, com a locadora definida no middleware.
 * LGPD: o termo da busca (pode ser CPF ou telefone) vai por POST e fica na sessão, nunca no endereço.
 */
class ClientesController extends Controller
{
    public const SITUACOES = ['ativos' => 'Ativos', 'todos' => 'Todos', 'bloqueados' => 'Bloqueados', 'inativos' => 'Inativos'];

    public function index(Request $request): View
    {
        return view('clientes.index', $this->dadosLista($request));
    }

    /** POST do filtro. Pela busca enquanto digita (fetch), devolve só a tabela; pelo formulário, volta à lista. */
    public function filtrar(Request $request): RedirectResponse|Response
    {
        $situacao = (string) $request->input('situacao', 'ativos');
        $filtro = $request->boolean('limpar') ? null : [
            'q'        => mb_substr(trim((string) $request->input('q', '')), 0, 100),
            'situacao' => array_key_exists($situacao, self::SITUACOES) ? $situacao : 'ativos',
        ];
        $filtro === null ? $request->session()->forget('filtro_clientes') : $request->session()->put('filtro_clientes', $filtro);

        if ($request->ajax()) {
            return response()->view('clientes._tabela', $this->dadosLista($request));
        }
        return redirect()->route('clientes.index');
    }

    public function show(string $id): View
    {
        // Id malformado ou de outra locadora: 404, como se não existisse (o RLS esconde a linha)
        abort_unless(Str::isUuid($id), 404);
        $c = DB::selectOne('SELECT * FROM clientes WHERE id = ?', [$id]);
        abort_if($c === null, 404, 'Cliente não encontrado.');

        $r = DB::selectOne("SELECT count(*) AS locacoes,
                                   count(*) FILTER (WHERE l.status = 'aberta') AS abertas,
                                   COALESCE(SUM(v.valor_atual) FILTER (WHERE l.status <> 'cancelada'), 0) AS movimentado,
                                   COALESCE(SUM(v.valor_pago), 0) AS pago,
                                   COALESCE(SUM(GREATEST(v.valor_atual - v.valor_pago, 0)) FILTER (WHERE l.status <> 'cancelada'), 0) AS saldo
                              FROM locacoes l JOIN vw_locacao_valores v ON v.locacao_id = l.id
                             WHERE l.cliente_id = ?", [$id]);
        $locacoes = DB::select('SELECT l.numero, l.data_saida, l.data_prev_devolucao, l.status, v.valor_atual, v.valor_pago
                                  FROM locacoes l JOIN vw_locacao_valores v ON v.locacao_id = l.id
                                 WHERE l.cliente_id = ?
                                 ORDER BY l.data_saida DESC, l.numero DESC LIMIT 100', [$id]);

        return view('clientes.show', compact('c', 'r', 'locacoes'));
    }

    public function create(): View
    {
        return view('clientes.form', ['c' => null]);
    }

    public function edit(string $id): View
    {
        return view('clientes.form', ['c' => $this->cliente($id)]);
    }

    public function store(Request $request): RedirectResponse
    {
        [$dados, $erros] = $this->validar($request, null);
        if ($erros) {
            return back()->withErrors($erros)->withInput();
        }
        $id = DB::selectOne('INSERT INTO clientes (' . implode(', ', array_keys($dados)) . ') VALUES ('
            . implode(', ', array_fill(0, count($dados), '?')) . ') RETURNING id', array_values($dados))->id;
        return redirect()->route('clientes.show', $id)->with('flash', [['tipo' => 'ok', 'msg' => 'Cliente cadastrado.']]);
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        $this->cliente($id);
        [$dados, $erros] = $this->validar($request, $id);
        if ($erros) {
            return back()->withErrors($erros)->withInput();
        }
        $sets = implode(', ', array_map(fn ($k) => "$k = ?", array_keys($dados)));
        DB::update("UPDATE clientes SET $sets WHERE id = ?", [...array_values($dados), $id]);   // o RLS limita à locadora
        return redirect()->route('clientes.show', $id)->with('flash', [['tipo' => 'ok', 'msg' => 'Cliente atualizado.']]);
    }

    public function bloquear(string $id): RedirectResponse
    {
        $this->cliente($id);
        $bloq = DB::selectOne('UPDATE clientes SET bloqueado = NOT bloqueado WHERE id = ? RETURNING bloqueado', [$id])->bloqueado;
        return redirect()->route('clientes.show', $id)->with('flash', [['tipo' => 'ok',
            'msg' => $bloq ? 'Cliente bloqueado: não poderá fazer novas locações.' : 'Cliente desbloqueado.']]);
    }

    public function destroy(string $id): RedirectResponse
    {
        $this->cliente($id);
        // Com locações, não exclui (o histórico e o caixa dependem dele); o banco também impede
        if ((int) DB::selectOne('SELECT count(*) AS n FROM locacoes WHERE cliente_id = ?', [$id])->n > 0) {
            return back()->with('flash', [['tipo' => 'erro', 'msg' => 'Este cliente tem locações e não pode ser excluído. Desative ou bloqueie o cadastro.']]);
        }
        DB::delete('DELETE FROM clientes WHERE id = ?', [$id]);
        return redirect()->route('clientes.index')->with('flash', [['tipo' => 'ok', 'msg' => 'Cliente excluído.']]);
    }

    /** Cliente da locadora logada ou 404 (id malformado ou de outra locadora: o RLS esconde a linha). */
    private function cliente(string $id): object
    {
        abort_unless(Str::isUuid($id), 404);
        $c = DB::selectOne('SELECT * FROM clientes WHERE id = ?', [$id]);
        abort_if($c === null, 404, 'Cliente não encontrado.');
        return $c;
    }

    /**
     * Mesmas regras de cliente_salvar() do sistema antigo. Devolve [dados para gravar, erros por campo].
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function validar(Request $request, ?string $id): array
    {
        $txt = fn (string $k) => trim((string) $request->input($k, ''));
        $tipo = $request->input('tipo') === 'PJ' ? 'PJ' : 'PF';
        $doc = so_digitos($request->input('cpf_cnpj', ''));
        $cep = so_digitos($request->input('cep', ''));
        $c = [
            'tipo'      => $tipo,
            'nome'      => $txt('nome'),
            'cpf_cnpj'  => $doc,
            'rg_ie'     => $txt('rg_ie'),
            'telefone'  => telefone_formatar($request->input('telefone', '')),
            'celular'   => telefone_formatar($request->input('celular', '')),
            'email'     => $txt('email'),
            'cep'       => $cep === '' ? '' : substr($cep, 0, 5) . '-' . substr($cep, 5),
            'endereco'  => $txt('endereco'),
            'numero'    => $txt('numero'),
            'bairro'    => $txt('bairro'),
            'cidade'    => $txt('cidade'),
            'uf'        => strtoupper($txt('uf')),
            'obs'       => $txt('obs'),
            'bloqueado' => $request->boolean('bloqueado') ? 'true' : 'false',
            'ativo'     => $request->boolean('ativo') ? 'true' : 'false',
        ];

        $e = [];
        if (mb_strlen($c['nome']) < 3 || mb_strlen($c['nome']) > 150) {
            $e['nome'] = 'Informe o nome (3 a 150 caracteres).';
        }
        if ($doc !== '' && (strlen($doc) !== ($tipo === 'PF' ? 11 : 14) || !doc_valido($doc))) {
            $e['cpf_cnpj'] = $tipo === 'PF' ? 'CPF inválido.' : 'CNPJ inválido.';
        }
        if ($c['email'] !== '' && filter_var($c['email'], FILTER_VALIDATE_EMAIL) === false) {
            $e['email'] = 'E-mail inválido.';
        }
        if ($cep !== '' && strlen($cep) !== 8) {
            $e['cep'] = 'CEP inválido (8 dígitos).';
        }
        if ($c['uf'] !== '' && !in_array($c['uf'], ufs(), true)) {
            $e['uf'] = 'UF inválida.';
        }
        foreach (['rg_ie' => 20, 'telefone' => 20, 'celular' => 20, 'email' => 120, 'endereco' => 150, 'numero' => 10,
                  'bairro' => 80, 'cidade' => 80, 'obs' => 2000] as $campo => $max) {
            if (!isset($e[$campo]) && mb_strlen($c[$campo]) > $max) {
                $e[$campo] = "Máximo de $max caracteres.";
            }
        }
        // Aviso amigável antes da restrição única do banco (que continua valendo), só dentro da locadora (RLS)
        if ($doc !== '' && !isset($e['cpf_cnpj'])) {
            $outro = DB::selectOne('SELECT nome FROM clientes WHERE cpf_cnpj = ? AND id IS DISTINCT FROM CAST(? AS uuid)', [$doc, $id]);
            if ($outro) {
                $e['cpf_cnpj'] = sprintf('Já existe um cliente com este %s: %s.', $tipo === 'PF' ? 'CPF' : 'CNPJ', $outro->nome);
            }
        }
        return [$c, $e];
    }

    private function dadosLista(Request $request): array
    {
        $f = (array) $request->session()->get('filtro_clientes', []) + ['q' => '', 'situacao' => 'ativos'];
        $q = (string) $f['q'];
        $situacao = array_key_exists($f['situacao'], self::SITUACOES) ? $f['situacao'] : 'ativos';

        $where = [match ($situacao) {
            'todos'      => 'TRUE',
            'bloqueados' => 'c.bloqueado',
            'inativos'   => 'NOT c.ativo',
            default      => 'c.ativo',
        }];
        $params = [];
        if ($q !== '') {
            $cond = 'lower(c.nome) LIKE lower(?)';
            $params[] = '%' . addcslashes($q, '%_\\') . '%';
            $dig = so_digitos($q);
            if (strlen($dig) >= 3) {
                $cond .= " OR c.cpf_cnpj LIKE ? OR regexp_replace(c.telefone, '\\D', '', 'g') LIKE ? OR regexp_replace(c.celular, '\\D', '', 'g') LIKE ?";
                array_push($params, "%$dig%", "%$dig%", "%$dig%");
            }
            $where[] = "($cond)";
        }
        $sqlWhere = implode(' AND ', $where);

        $porPagina = config('locgestor.por_pagina');
        $pagina = $request->ajax() ? 1 : max(1, (int) $request->query('page', 1));
        $total = (int) DB::selectOne("SELECT count(*) AS n FROM clientes c WHERE $sqlWhere", $params)->n;
        $linhas = DB::select("SELECT c.id, c.tipo, c.nome, c.cpf_cnpj, c.telefone, c.celular, c.cidade, c.uf, c.bloqueado, c.ativo,
                                     (SELECT count(*) FROM locacoes l WHERE l.cliente_id = c.id AND l.status = 'aberta') AS abertas
                                FROM clientes c
                               WHERE $sqlWhere
                               ORDER BY lower(c.nome)
                               LIMIT ? OFFSET ?", [...$params, $porPagina, ($pagina - 1) * $porPagina]);

        // Contagem por situação, para os botões de filtro
        $contagem = DB::selectOne('SELECT count(*) FILTER (WHERE ativo) AS ativos, count(*) AS todos,
                                          count(*) FILTER (WHERE bloqueado) AS bloqueados, count(*) FILTER (WHERE NOT ativo) AS inativos
                                     FROM clientes');

        return [
            'clientes'  => new LengthAwarePaginator($linhas, $total, $porPagina, $pagina, ['path' => route('clientes.index')]),
            'q'         => $q,
            'situacao'  => $situacao,
            'situacoes' => self::SITUACOES,
            'contagem'  => $contagem,
        ];
    }
}
