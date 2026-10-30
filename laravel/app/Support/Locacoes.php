<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Regras da locação usadas pelas telas do Laravel. Os valores (previsto, até hoje, pago) vêm da view
 * vw_locacao_valores do banco, a mesma que o sistema antigo usa: nada de recalcular preço em PHP.
 */
class Locacoes
{
    /** Locação pelo número (próprio de cada locadora; o RLS esconde as das outras). */
    public static function porNumero(int $numero): ?object
    {
        return DB::selectOne("SELECT l.*, c.nome AS cliente_nome, c.cpf_cnpj AS cliente_doc, c.tipo AS cliente_tipo,
                                     c.celular AS cliente_celular, c.telefone AS cliente_telefone,
                                     v.valor_previsto, v.valor_atual, v.valor_pago,
                                     (SELECT count(*) FROM devolucoes d WHERE d.locacao_id = l.id) AS devolucoes
                                FROM locacoes l
                                JOIN clientes c ON c.id = l.cliente_id
                                JOIN vw_locacao_valores v ON v.locacao_id = l.id
                               WHERE l.numero = ?", [$numero]);
    }

    public static function saldo(object $loc): float
    {
        return round($loc->status === 'cancelada' ? 0 : $loc->valor_atual - $loc->valor_pago, 2);
    }

    /** Dias cobrados entre a saída e a data: conta o dia da saída (27/10 a 05/11 = 10 dias), mínimo 1. */
    public static function diasCobranca(string $de, string $ate): int
    {
        return max(1, (int) (new \DateTime($de))->diff(new \DateTime($ate))->format('%r%a') + 1);
    }

    /** Dias de atraso (positivo) ou que faltam (negativo) até a devolução prevista. */
    public static function atraso(object $loc): int
    {
        return (int) (new \DateTime($loc->data_prev_devolucao))->diff(new \DateTime(now()->toDateString()))->format('%r%a');
    }

    /** [rótulo, classe do selo]: atrasada, vence hoje, aberta, devolvida ou cancelada. */
    public static function situacao(object $l): array
    {
        $hoje = now()->toDateString();
        if ($l->status === 'aberta') {
            return $l->data_prev_devolucao < $hoje ? ['atrasada', 'selo-vermelho']
                : ($l->data_prev_devolucao === $hoje ? ['vence hoje', 'selo-amarelo'] : ['aberta', 'selo-azul']);
        }
        return $l->status === 'devolvida' ? ['devolvida', 'selo-verde'] : ['cancelada', 'selo-cinza'];
    }

    /** PIX do saldo (null sem chave PIX na locadora ou sem saldo). Igual a locacao_pix(). */
    public static function pix(object $loc, object $empresa): ?array
    {
        $saldo = self::saldo($loc);
        if (($empresa->pix_chave ?? '') === '' || $saldo <= 0) {
            return null;
        }
        return ['valor' => $saldo, 'payload' => Pix::payload($empresa->pix_chave, $empresa->pix_nome, $empresa->pix_cidade, $saldo, 'LOC' . $loc->numero)];
    }

    /** "2 Betoneira 400 L, 5 Carrinho de mão 60 L" (só os pendentes, se $pendentes). */
    public static function itensTexto(string $id, bool $pendentes = false): string
    {
        $cond = $pendentes ? 'AND li.quantidade > li.qtd_devolvida' : '';
        $qtd = $pendentes ? 'li.quantidade - li.qtd_devolvida' : 'li.quantidade';
        return (string) DB::selectOne("SELECT string_agg(($qtd) || ' ' || p.descricao, ', ' ORDER BY p.descricao) AS t
                                         FROM locacao_itens li JOIN produtos p ON p.id = li.produto_id
                                        WHERE li.locacao_id = ? $cond", [$id])->t;
    }

    /** Mensagens prontas para o WhatsApp: resumo, lembrete de devolução e cobrança do saldo. Mesmos textos de whatsapp_locacao(). */
    public static function whatsapp(object $loc, object $empresa): array
    {
        $cli = (object) ['celular' => $loc->cliente_celular, 'telefone' => $loc->cliente_telefone];
        $num = whatsapp_numero($cli);
        $url = fn (string $msg) => $num === null ? null : 'https://wa.me/' . $num . '?text=' . rawurlencode($msg);
        $nome = trim($loc->cliente_nome);
        $chamar = $loc->cliente_tipo === 'PJ' ? $nome : (explode(' ', $nome)[0] ?? $nome);
        $oi = "Olá, $chamar! Aqui é da *" . ($empresa->nome_fantasia ?: $empresa->razao_social) . '*.';
        $n = $loc->numero;
        $saldo = self::saldo($loc);
        $links = [];

        $links['resumo'] = $url("$oi\n\nResumo da sua *locação nº $n*:\n"
            . '• Itens: ' . self::itensTexto($loc->id) . "\n"
            . '• Saída: ' . data_br($loc->data_saida) . "\n"
            . '• Devolução prevista: ' . data_br($loc->data_prev_devolucao) . "\n"
            . '• Valor Total: ' . moeda($loc->valor_previsto)
            . ($loc->valor_pago > 0 ? "\n• Pago: " . moeda($loc->valor_pago) : '')
            . "\n\nQualquer dúvida, estamos à disposição.");

        if ($loc->status === 'aberta') {
            $prev = $loc->data_prev_devolucao;
            $atraso = self::atraso($loc);
            $quando = match (true) {
                $atraso > 0    => 'está *atrasada* desde ' . data_br($prev) . " ($atraso dia(s)). As diárias continuam sendo cobradas até a devolução.",
                $atraso === 0  => 'está prevista para *hoje*, ' . data_br($prev) . '.',
                $atraso === -1 => 'está prevista para *amanhã*, ' . data_br($prev) . '.',
                default        => 'está prevista para ' . data_br($prev) . '.',
            };
            $links['lembrete'] = $url("$oi\n\nLembrete: a devolução da *locação nº $n* "
                . '(' . self::itensTexto($loc->id, true) . ") $quando\n\n"
                . 'Se precisar de mais dias, fale com a gente para prorrogar.');
        }
        if ($saldo > 0.004) {
            $pix = self::pix($loc, $empresa);
            $links['saldo'] = $url("$oi\n\nConsta um saldo de *" . moeda($saldo) . "* da *locação nº $n* "
                . '(total ' . moeda($loc->valor_atual) . ', pago ' . moeda($loc->valor_pago) . ").\n\n"
                . ($pix !== null
                    ? "Para pagar por PIX, copie o código abaixo e cole na opção *PIX Copia e Cola* do app do seu banco:\n\n{$pix['payload']}\n\nObrigado!"
                    : 'Formas de pagamento: ' . implode(', ', Estoque::FORMAS_PGTO) . '. Obrigado!'));
        }
        return ['numero' => $num, 'links' => $links];
    }

    /** Acrescenta uma linha datada ao histórico (campo obs) da locação. */
    public static function historico(string $id, string $texto): void
    {
        $linha = '[' . now()->format('d/m/Y H:i') . ' ' . session('usuario.nome', '') . '] ' . $texto;
        DB::update("UPDATE locacoes SET obs = CASE WHEN obs = '' THEN ? ELSE obs || E'\\n' || ? END WHERE id = ?", [$linha, $linha, $id]);
    }

    /** Entrada (E) ou saída (S) no caixa ligada à locação. */
    public static function lancarCaixa(object $loc, string $tipo, float $valor, string $forma, string $data, string $prefixo): void
    {
        DB::insert("INSERT INTO caixa (data, tipo, categoria, descricao, valor, forma, locacao_id, usuario_id)
                    VALUES (?, ?, 'Locação', ?, ?, ?, ?, ?)",
                   [$data, $tipo, "$prefixo locação nº {$loc->numero} - {$loc->cliente_nome}", $valor, $forma, $loc->id, session('usuario.id')]);
    }
}
