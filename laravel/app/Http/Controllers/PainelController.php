<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PainelController extends Controller
{
    public function index(): View
    {
        // Tudo filtrado pelo RLS: só os números da locadora logada
        $k = DB::selectOne("SELECT
                (SELECT count(*) FROM locacoes WHERE status = 'aberta') AS abertas,
                (SELECT count(*) FROM locacoes WHERE status = 'aberta' AND data_prev_devolucao < CURRENT_DATE) AS atrasadas,
                (SELECT count(*) FROM locacoes WHERE status = 'aberta' AND data_prev_devolucao = CURRENT_DATE) AS vencem_hoje,
                (SELECT COALESCE(SUM(GREATEST(v.valor_atual - v.valor_pago, 0)), 0)
                   FROM vw_locacao_valores v JOIN locacoes l ON l.id = v.locacao_id WHERE l.status <> 'cancelada') AS a_receber,
                (SELECT count(*) FROM clientes WHERE ativo) AS clientes");

        $atrasadas = DB::select("SELECT l.numero, c.nome, l.data_prev_devolucao, CURRENT_DATE - l.data_prev_devolucao AS dias
                                   FROM locacoes l JOIN clientes c ON c.id = l.cliente_id
                                  WHERE l.status = 'aberta' AND l.data_prev_devolucao <= CURRENT_DATE + 3
                                  ORDER BY l.data_prev_devolucao, l.numero LIMIT 8");

        return view('painel', compact('k', 'atrasadas'));
    }
}
