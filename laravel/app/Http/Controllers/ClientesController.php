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
