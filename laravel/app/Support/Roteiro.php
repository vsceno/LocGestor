<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Roteiro do motorista: as entregas e coletas de um dia.
 *   entrega = locação com saída no dia;
 *   coleta  = locação aberta com devolução prevista no dia (no dia de hoje, também as atrasadas).
 * Mesmas regras de roteiro_paradas() do sistema antigo.
 */
class Roteiro
{
    public const TIPOS = ['entrega' => 'Entrega', 'coleta' => 'Coleta'];

    /** Paradas do dia, as pendentes primeiro. */
    public static function paradas(string $data): array
    {
        $hoje = $data === now()->toDateString() ? 'true' : 'false';
        $paradas = array_map(fn ($r) => (array) $r, DB::select("
            SELECT 'entrega' AS tipo, l.id, l.numero, l.local_uso, l.data_saida, l.data_prev_devolucao, false AS atrasada,
                   c.nome, c.tipo AS cliente_tipo, c.celular, c.telefone, c.endereco, c.numero AS end_numero, c.bairro, c.cidade, c.uf
              FROM locacoes l JOIN clientes c ON c.id = l.cliente_id
             WHERE l.status <> 'cancelada' AND l.data_saida = CAST(? AS date)
            UNION ALL
            SELECT 'coleta', l.id, l.numero, l.local_uso, l.data_saida, l.data_prev_devolucao, l.data_prev_devolucao < CAST(? AS date),
                   c.nome, c.tipo, c.celular, c.telefone, c.endereco, c.numero, c.bairro, c.cidade, c.uf
              FROM locacoes l JOIN clientes c ON c.id = l.cliente_id
             WHERE l.status = 'aberta'
               AND (l.data_prev_devolucao = CAST(? AS date) OR (CAST(? AS boolean) AND l.data_prev_devolucao < CAST(? AS date)))
             ORDER BY 1 DESC, 3", [$data, $data, $data, $hoje, $data]));

        $feitas = [];
        foreach (DB::select('SELECT e.locacao_id, e.tipo, e.feita_em, e.obs, u.nome AS usuario FROM entregas e
                               LEFT JOIN usuarios u ON u.id = e.usuario_id WHERE e.data = ?', [$data]) as $f) {
            $feitas[$f->locacao_id . $f->tipo] = (array) $f;
        }
        foreach ($paradas as &$p) {
            $p['feita'] = $feitas[$p['id'] . $p['tipo']] ?? null;
            $p['itens'] = Locacoes::itensTexto($p['id'], $p['tipo'] === 'coleta');
            $doCliente = trim(implode(', ', array_filter([$p['endereco'], $p['end_numero'], $p['bairro']])) . ' - '
                              . $p['cidade'] . ($p['uf'] !== '' ? '/' . $p['uf'] : ''), ' -/');
            $p['endereco_parada'] = $p['local_uso'] !== '' ? $p['local_uso'] : $doCliente;
        }
        unset($p);
        usort($paradas, fn ($a, $b) => [$a['feita'] !== null, $a['tipo'] !== 'entrega', $a['numero']]
                                    <=> [$b['feita'] !== null, $b['tipo'] !== 'entrega', $b['numero']]);
        return $paradas;
    }

    /** Como chamar o cliente: primeiro nome (PF) ou a razão social (PJ). */
    public static function saudacao(string $nome, string $tipoCliente, object $empresa): string
    {
        $nome = trim($nome);
        $chamar = $tipoCliente === 'PJ' ? $nome : (explode(' ', $nome)[0] ?? $nome);
        $loja = $empresa->nome_fantasia ?: $empresa->razao_social;
        return "Olá, $chamar! Aqui é da *$loja*.";
    }
}
