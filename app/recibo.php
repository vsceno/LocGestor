<?php
/*
 * Recibos numerados: pagamento avulso ou ligado a uma locação. Não entra no caixa (o pagamento
 * continua sendo lançado no caixa, como antes); o recibo é só o comprovante, com número fixo.
 */

declare(strict_types=1);

/** Emite um recibo. Devolve ['id', 'numero']. */
function recibo_salvar(array $d): array
{
    $recebido = trim($d['recebido_de'] ?? '');
    $doc = so_digitos($d['documento'] ?? '');
    $valor = parse_money($d['valor'] ?? '');
    $referente = trim($d['referente'] ?? '');
    $forma = $d['forma'] ?? 'Dinheiro';
    $locacaoId = (string) ($d['locacao_id'] ?? '');

    $erros = [];
    if (mb_strlen($recebido) < 2 || mb_strlen($recebido) > 150) {
        $erros[] = 'Informe quem pagou (2 a 150 caracteres).';
    }
    if ($doc !== '' && !doc_valido($doc)) {
        $erros[] = 'CPF ou CNPJ inválido (deixe em branco se não quiser informar).';
    }
    if ($valor <= 0 || $valor > 9999999999) {
        $erros[] = 'Informe um valor maior que zero.';
    }
    if (mb_strlen($referente) < 2 || mb_strlen($referente) > 200) {
        $erros[] = 'Informe a referência do pagamento (2 a 200 caracteres).';
    }
    if (!in_array($forma, FORMAS_PGTO, true)) {
        $erros[] = 'Forma de pagamento inválida.';
    }
    $clienteId = null;
    if ($locacaoId !== '') {
        $clienteId = is_uuid($locacaoId) ? val('SELECT cliente_id FROM locacoes WHERE id = :id', ['id' => $locacaoId]) : null;
        if ($clienteId === null) {
            $erros[] = 'Locação inválida.';
        }
    }
    if ($erros) {
        throw new UserError(implode(' ', $erros));
    }

    return tx(function () use ($recebido, $doc, $valor, $referente, $forma, $locacaoId, $clienteId) {
        $numero = (int) val("SELECT proximo_numero('recibo')");
        $id = (string) val('INSERT INTO recibos (numero, recebido_de, documento, valor, referente, forma, cliente_id, locacao_id, usuario_id)
                            VALUES (:n, :r, :d, :v, :ref, :f, :c, :l, :u) RETURNING id',
                           ['n' => $numero, 'r' => $recebido, 'd' => $doc, 'v' => $valor, 'ref' => $referente, 'f' => $forma,
                            'c' => $clienteId, 'l' => $locacaoId === '' ? null : $locacaoId, 'u' => uid()]);
        return ['id' => $id, 'numero' => $numero];
    });
}
