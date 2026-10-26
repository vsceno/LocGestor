<?php
/*
 * Orçamentos: cotação com validade. Não bloqueia estoque e não é cobrança: ao virar locação ou reserva,
 * o orçamento fica "convertido" e não pode mais ser excluído. Os preços negociados ficam gravados nos itens.
 */

declare(strict_types=1);

const ORCAMENTO_VALIDADE_MAX_DIAS = 90;

/** Valor previsto de um orçamento: itens pelo período + acréscimos e fretes − desconto. */
function orcamento_previsto(array $orc, array $itens): float
{
    $dias = max(1, days_between($orc['data_saida'], $orc['data_prev_devolucao']));
    $total = 0.0;
    foreach ($itens as $it) {
        $total += (int) $it['quantidade'] * preco_item($it, $dias);
    }
    return round($total + (float) $orc['acrescimo'] + (float) $orc['frete_entrega'] + (float) $orc['frete_recolhimento']
                 - (float) $orc['desconto'], 2);
}

/**
 * Grava (criar ou alterar) um orçamento aberto. Itens trazem o preço do cadastro, ajustável na tela.
 * Devolve ['id', 'numero'].
 */
function orcamento_salvar(array $d, ?string $id = null): array
{
    $cliente = cliente_resolver((string) ($d['cliente'] ?? ''));
    if ($cliente['bloqueado']) {
        throw new UserError("O cliente {$cliente['nome']} está bloqueado e não pode receber orçamento.");
    }
    $saida = (string) ($d['data_saida'] ?? '');
    $prev = (string) ($d['data_prev_devolucao'] ?? '');
    $validade = (string) ($d['validade'] ?? '');
    if (!valid_date($saida) || !valid_date($prev) || !valid_date($validade)) {
        throw new UserError('Informe as datas de saída, de devolução e a validade do orçamento.');
    }
    if ($prev < $saida) {
        throw new UserError('A devolução prevista não pode ser antes da saída.');
    }
    if ($validade < today() || $validade > date('Y-m-d', strtotime('+' . ORCAMENTO_VALIDADE_MAX_DIAS . ' days'))) {
        throw new UserError('A validade deve ser de hoje até ' . ORCAMENTO_VALIDADE_MAX_DIAS . ' dias.');
    }
    $valores = [];
    foreach (['acrescimo', 'desconto', 'frete_entrega', 'frete_recolhimento'] as $campo) {
        $valores[$campo] = parse_money($d[$campo] ?? '');
        if ($valores[$campo] < 0) {
            throw new UserError('Acréscimos, descontos e fretes não podem ser negativos.');
        }
    }
    $local = trim((string) ($d['local_uso'] ?? ''));
    $obs = trim((string) ($d['obs'] ?? ''));
    if (mb_strlen($local) > 200 || mb_strlen($obs) > 2000) {
        throw new UserError('Local de uso (até 200) ou observações (até 2.000) longos demais.');
    }
    $itens = itens_do_form($d, 'orçamento');
    $cadastro = [];
    foreach (all('SELECT id, valor_diaria, valor_semanal, valor_mensal FROM produtos WHERE id = ANY (CAST(:ids AS uuid[]))',
                 ['ids' => '{' . implode(',', array_keys($itens)) . '}']) as $p) {
        $cadastro[$p['id']] = $p;
    }
    foreach ($itens as $pid => $it) {
        if (!isset($cadastro[$pid])) {
            throw new UserError('Produto não encontrado.');
        }
        $itens[$pid]['diaria'] ??= (float) $cadastro[$pid]['valor_diaria'];
        if ($itens[$pid]['diaria'] < 0) {
            throw new UserError('A diária não pode ser negativa.');
        }
    }

    return tx(function () use ($cliente, $saida, $prev, $validade, $valores, $local, $obs, $itens, $cadastro, $id) {
        if ($id === null) {
            $numero = (int) val("SELECT proximo_numero('orcamento')");
            $id = (string) val('INSERT INTO orcamentos (numero, cliente_id, validade, data_saida, data_prev_devolucao, local_uso,
                                                        acrescimo, desconto, frete_entrega, frete_recolhimento, obs, usuario_id)
                                VALUES (:n, :c, :v, :s, :p, :l, :acr, :desc, :fe, :fr, :o, :u) RETURNING id',
                ['n' => $numero, 'c' => $cliente['id'], 'v' => $validade, 's' => $saida, 'p' => $prev, 'l' => $local,
                 'acr' => $valores['acrescimo'], 'desc' => $valores['desconto'], 'fe' => $valores['frete_entrega'],
                 'fr' => $valores['frete_recolhimento'], 'o' => $obs, 'u' => uid()]);
        } else {
            $st = val("SELECT status FROM orcamentos WHERE id = :id FOR UPDATE", ['id' => $id]);
            if ($st === null) {
                throw new UserError('Orçamento não encontrado.');
            }
            if ($st !== 'aberto') {
                throw new UserError('Este orçamento já foi convertido e não pode mais ser alterado.');
            }
            $numero = (int) val('UPDATE orcamentos SET cliente_id = :c, validade = :v, data_saida = :s, data_prev_devolucao = :p,
                                       local_uso = :l, acrescimo = :acr, desconto = :desc, frete_entrega = :fe,
                                       frete_recolhimento = :fr, obs = :o
                                 WHERE id = :id RETURNING numero',
                ['c' => $cliente['id'], 'v' => $validade, 's' => $saida, 'p' => $prev, 'l' => $local,
                 'acr' => $valores['acrescimo'], 'desc' => $valores['desconto'], 'fe' => $valores['frete_entrega'],
                 'fr' => $valores['frete_recolhimento'], 'o' => $obs, 'id' => $id]);
            q('DELETE FROM orcamento_itens WHERE orcamento_id = :id', ['id' => $id]);
        }
        foreach ($itens as $pid => $it) {
            q('INSERT INTO orcamento_itens (orcamento_id, produto_id, quantidade, valor_diaria, valor_semanal, valor_mensal)
               VALUES (:o, :p, :q, :d, :s, :m)',
              ['o' => $id, 'p' => $pid, 'q' => $it['qtd'], 'd' => $it['diaria'],
               's' => $cadastro[$pid]['valor_semanal'], 'm' => $cadastro[$pid]['valor_mensal']]);
        }
        return ['id' => $id, 'numero' => $numero];
    });
}

/**
 * Transforma o orçamento em locação (saída já feita) ou em reserva (saída futura).
 * Só com orçamento aberto e dentro da validade. Devolve ['tipo', 'numero', 'id'].
 */
function orcamento_converter(string $id, string $tipo): array
{
    if (!in_array($tipo, ['locacao', 'reserva'], true)) {
        throw new UserError('Escolha se o orçamento vira locação ou reserva.');
    }
    return tx(function () use ($id, $tipo) {
        $orc = one('SELECT o.*, c.nome, c.cpf_cnpj FROM orcamentos o JOIN clientes c ON c.id = o.cliente_id
                     WHERE o.id = :id FOR UPDATE OF o', ['id' => $id]);
        if ($orc === null) {
            throw new UserError('Orçamento não encontrado.');
        }
        if ($orc['status'] !== 'aberto') {
            throw new UserError('Este orçamento já foi convertido.');
        }
        if ($orc['validade'] < today()) {
            throw new UserError('Orçamento vencido em ' . dt($orc['validade']) . '. Faça um novo orçamento.');
        }
        $itens = all('SELECT oi.produto_id, oi.quantidade, oi.valor_diaria FROM orcamento_itens oi WHERE oi.orcamento_id = :id', ['id' => $id]);
        // Cliente pelo mesmo texto da tela ("Nome · documento")
        $clienteTxt = $orc['nome'] . ($orc['cpf_cnpj'] !== '' ? ' · ' . $orc['cpf_cnpj'] : '');
        $linhasProd = [
            'produto_id' => array_column($itens, 'produto_id'),
            'quantidade' => array_map(fn($i) => (string) $i['quantidade'], $itens),
            'diaria'     => array_map(fn($i) => number_format((float) $i['valor_diaria'], 2, '.', ''), $itens),
        ];
        if ($tipo === 'locacao') {
            if ($orc['data_saida'] > today()) {
                throw new UserError('A saída é no futuro: converta em reserva (a locação só registra o que já saiu).');
            }
            $r = locacao_criar(['cliente' => $clienteTxt, 'data_saida' => $orc['data_saida'],
                                'data_prev_devolucao' => $orc['data_prev_devolucao'], 'local_uso' => $orc['local_uso'],
                                'acrescimo' => number_format((float) $orc['acrescimo'], 2, '.', ''),
                                'desconto' => number_format((float) $orc['desconto'], 2, '.', ''),
                                'frete_entrega' => number_format((float) $orc['frete_entrega'], 2, '.', ''),
                                'frete_recolhimento' => number_format((float) $orc['frete_recolhimento'], 2, '.', ''),
                                'obs' => trim('Do orçamento nº ' . $orc['numero'] . '. ' . $orc['obs']),
                                'produto_id' => $linhasProd['produto_id'], 'quantidade' => $linhasProd['quantidade'],
                                'diaria' => $linhasProd['diaria']]);
            q("UPDATE orcamentos SET status = 'convertido', locacao_id = :l WHERE id = :id", ['l' => $r['id'], 'id' => $id]);
            return ['tipo' => 'locacao', 'numero' => $r['numero'], 'id' => $r['id']];
        }
        // Reserva: não guarda preço (o valor é calculado na retirada)
        $r = reserva_salvar(['cliente' => $clienteTxt, 'data_inicio' => $orc['data_saida'], 'data_fim' => $orc['data_prev_devolucao'],
                             'obs' => trim('Do orçamento nº ' . $orc['numero'] . '. ' . $orc['obs']),
                             'produto_id' => $linhasProd['produto_id'], 'quantidade' => $linhasProd['quantidade']]);
        q("UPDATE orcamentos SET status = 'convertido', reserva_id = :r WHERE id = :id", ['r' => $r['id'], 'id' => $id]);
        return ['tipo' => 'reserva', 'numero' => $r['numero'], 'id' => $r['id']];
    });
}

/** Exclui só orçamento aberto (convertido fica para o histórico). */
function orcamento_excluir(string $id): void
{
    if (q("DELETE FROM orcamentos WHERE id = :id AND status = 'aberto'", ['id' => $id])->rowCount() === 0) {
        throw new UserError('Só é possível excluir orçamento que ainda não foi convertido.');
    }
}
