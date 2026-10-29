<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Clientes: lista e ficha. Cadastro e edição ainda são feitos no sistema antigo.
 * Nenhuma consulta filtra por tenant_id: quem filtra é o RLS do banco, com a locadora definida no middleware.
 */
class ClientesController extends Controller
{
    public const SITUACOES = ['ativos' => 'Ativos', 'todos' => 'Todos', 'bloqueados' => 'Bloqueados', 'inativos' => 'Inativos'];

    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $situacao = array_key_exists($request->query('situacao'), self::SITUACOES) ? $request->query('situacao') : 'ativos';

        $where = [match ($situacao) {
            'todos'      => 'TRUE',
            'bloqueados' => 'c.bloqueado',
            'inativos'   => 'NOT c.ativo',
            default      => 'c.ativo',
        }];
        $params = [];
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $cond = 'lower(c.nome) LIKE lower(?)';
            $params[] = $like;
            $dig = so_digitos($q);
            if (strlen($dig) >= 3) {
                $cond .= " OR c.cpf_cnpj LIKE ? OR regexp_replace(c.telefone, '\\D', '', 'g') LIKE ? OR regexp_replace(c.celular, '\\D', '', 'g') LIKE ?";
                array_push($params, "%$dig%", "%$dig%", "%$dig%");
            }
            $where[] = "($cond)";
        }
        $sqlWhere = implode(' AND ', $where);

        $porPagina = config('locgestor.por_pagina');
        $pagina = max(1, (int) $request->query('page', 1));
        $total = (int) DB::selectOne("SELECT count(*) AS n FROM clientes c WHERE $sqlWhere", $params)->n;
        $linhas = DB::select("SELECT c.id, c.tipo, c.nome, c.cpf_cnpj, c.telefone, c.celular, c.cidade, c.uf, c.bloqueado, c.ativo,
                                     (SELECT count(*) FROM locacoes l WHERE l.cliente_id = c.id AND l.status = 'aberta') AS abertas
                                FROM clientes c
                               WHERE $sqlWhere
                               ORDER BY lower(c.nome)
                               LIMIT ? OFFSET ?", [...$params, $porPagina, ($pagina - 1) * $porPagina]);

        $clientes = new LengthAwarePaginator($linhas, $total, $porPagina, $pagina,
            ['path' => $request->url(), 'query' => array_filter(['q' => $q, 'situacao' => $situacao !== 'ativos' ? $situacao : null])]);

        return view('clientes.index', ['clientes' => $clientes, 'q' => $q, 'situacao' => $situacao, 'situacoes' => self::SITUACOES]);
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
}
