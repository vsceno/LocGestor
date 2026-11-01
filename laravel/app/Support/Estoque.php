<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/** Disponibilidade de estoque num período: mesma consulta de disponivel()/validar_estoque() do sistema antigo. */
class Estoque
{
    public const FORMAS_PGTO = ['Dinheiro', 'PIX', 'Cartão de débito', 'Cartão de crédito', 'Boleto', 'Transferência', 'Cheque'];

    /**
     * Quantidade disponível do produto em [$inicio, $fim]: estoque - itens na rua que ocupam o período (item atrasado
     * continua fora) - reservas ativas que se cruzam - peças em manutenção aberta.
     */
    public static function disponivel(string $produto, string $inicio, string $fim, ?string $ignorarReserva = null, ?string $ignorarLocacao = null): int
    {
        $r = DB::selectOne("
            SELECT p.qtd_total
                 - COALESCE((SELECT SUM(li.quantidade - li.qtd_devolvida)
                               FROM locacao_itens li JOIN locacoes l ON l.id = li.locacao_id
                              WHERE l.status = 'aberta' AND li.produto_id = p.id
                                AND (CAST(? AS uuid) IS NULL OR l.id <> CAST(? AS uuid))
                                AND (l.data_prev_devolucao >= CAST(? AS date) OR CAST(? AS date) <= CURRENT_DATE)), 0)
                 - COALESCE((SELECT SUM(ri.quantidade)
                               FROM reserva_itens ri JOIN reservas r ON r.id = ri.reserva_id
                              WHERE r.status = 'ativa' AND ri.produto_id = p.id
                                AND r.data_inicio <= CAST(? AS date) AND r.data_fim >= CAST(? AS date)
                                AND (CAST(? AS uuid) IS NULL OR r.id <> CAST(? AS uuid))), 0)
                 - COALESCE((SELECT SUM(m.quantidade) FROM manutencoes m WHERE m.produto_id = p.id AND m.data_fim IS NULL), 0) AS disponivel
              FROM produtos p WHERE p.id = ?",
            [$ignorarLocacao, $ignorarLocacao, $inicio, $inicio, $fim, $inicio, $ignorarReserva, $ignorarReserva, $produto]);
        return $r === null ? 0 : (int) $r->disponivel;
    }

    /** Trava os produtos (FOR UPDATE, sempre na mesma ordem) para duas operações não usarem o mesmo estoque. */
    public static function travar(array $produtos): void
    {
        $ids = array_values(array_unique($produtos));
        if ($ids) {
            DB::select('SELECT id FROM produtos WHERE id = ANY (CAST(? AS uuid[])) ORDER BY id FOR UPDATE', ['{' . implode(',', $ids) . '}']);
        }
    }

    /**
     * Confere vários itens [produto_id => qtd]. Devolve null se há estoque, ou a mensagem com TODOS os itens em falta,
     * no mesmo texto do sistema antigo. Use dentro da transação, depois de travar().
     */
    public static function falta(array $itens, string $inicio, string $fim, ?string $ignorarReserva = null, ?string $ignorarLocacao = null): ?string
    {
        $faltas = [];
        foreach ($itens as $produto => $qtd) {
            $disp = self::disponivel((string) $produto, $inicio, $fim, $ignorarReserva, $ignorarLocacao);
            if ($qtd > $disp) {
                $nome = DB::selectOne('SELECT descricao FROM produtos WHERE id = ?', [$produto])->descricao;
                $faltas[] = sprintf('%s (pedido %d, disponível %d)', $nome, $qtd, max(0, $disp));
            }
        }
        return $faltas ? 'Estoque insuficiente: ' . implode('; ', $faltas) . '.' : null;
    }
}
