<?php
/*
 * LocGestor - regras de negócio.
 *
 * Todas as consultas rodam com o contexto da locadora já definido (tenant.php).
 * Não há WHERE tenant_id aqui de propósito: o RLS do banco faz esse filtro.
 * Escreva as consultas como se existisse uma única locadora.
 */

declare(strict_types=1);

// =====================================================================
// DISPONIBILIDADE DE ESTOQUE
// =====================================================================

/**
 * Quantidade disponível do produto no período [$inicio, $fim]:
 *   qtd_total
 *   - itens na rua que ocupam o período: devolução prevista >= início, ou o período
 *     começa hoje (ou antes), porque item atrasado continua fora mesmo com a previsão vencida
 *   - reservas ativas que se cruzam com o período (menos a reserva ignorada, ao convertê-la)
 */
function disponivel(string $produtoId, string $inicio, string $fim, ?string $ignorarReserva = null): int
{
    $r = one("
        SELECT p.qtd_total
             - COALESCE((SELECT SUM(li.quantidade - li.qtd_devolvida)
                           FROM locacao_itens li
                           JOIN locacoes l ON l.id = li.locacao_id
                          WHERE l.status = 'aberta'
                            AND li.produto_id = p.id
                            AND (l.data_prev_devolucao >= CAST(:ini1 AS date) OR CAST(:ini2 AS date) <= CURRENT_DATE)), 0)
             - COALESCE((SELECT SUM(ri.quantidade)
                           FROM reserva_itens ri
                           JOIN reservas r ON r.id = ri.reserva_id
                          WHERE r.status = 'ativa'
                            AND ri.produto_id = p.id
                            AND r.data_inicio <= CAST(:fim AS date)
                            AND r.data_fim >= CAST(:ini3 AS date)
                            AND (CAST(:ign1 AS uuid) IS NULL OR r.id <> CAST(:ign2 AS uuid))), 0) AS disponivel
          FROM produtos p
         WHERE p.id = :id",
        ['id' => $produtoId, 'ini1' => $inicio, 'ini2' => $inicio, 'ini3' => $inicio, 'fim' => $fim,
         'ign1' => $ignorarReserva, 'ign2' => $ignorarReserva]);
    return $r === null ? 0 : (int) $r['disponivel'];
}

/**
 * Confere o estoque de vários itens de uma vez. $itens = [produto_id => quantidade].
 * Lança UserError listando TODOS os itens em falta, por exemplo:
 * "Estoque insuficiente: Betoneira 400 L (pedido 8, disponível 7)."
 * Use dentro de tx(), depois de travar os produtos com produtos_travar().
 */
function validar_estoque(array $itens, string $inicio, string $fim, ?string $ignorarReserva = null): void
{
    $faltas = [];
    foreach ($itens as $produtoId => $qtd) {
        $disp = disponivel((string) $produtoId, $inicio, $fim, $ignorarReserva);
        if ($qtd > $disp) {
            $nome = val('SELECT descricao FROM produtos WHERE id = :id', ['id' => $produtoId]);
            $faltas[] = sprintf('%s (pedido %d, disponível %d)', $nome, $qtd, max(0, $disp));
        }
    }
    if ($faltas) {
        throw new UserError('Estoque insuficiente: ' . implode('; ', $faltas) . '.');
    }
}

/** SELECT ... FOR UPDATE nos produtos: impede que duas operações simultâneas usem o mesmo estoque. */
function produtos_travar(array $produtoIds): void
{
    $ids = array_values(array_unique($produtoIds));
    if ($ids) {
        q('SELECT id FROM produtos WHERE id = ANY (CAST(:ids AS uuid[])) ORDER BY id FOR UPDATE',
          ['ids' => '{' . implode(',', $ids) . '}']);
    }
}

/** Quantidade do produto na rua agora (locações em aberto). */
function qtd_alugada(string $produtoId): int
{
    return (int) (val('SELECT qtd_alugada FROM vw_produto_alugado WHERE produto_id = :id', ['id' => $produtoId]) ?? 0);
}

// =====================================================================
// CLIENTES
// =====================================================================

/**
 * Valida e grava um cliente. $id = null cria um novo. Devolve o id.
 * CPF/CNPJ é opcional, mas quando informado precisa ser válido e único NA LOCADORA
 * (outra locadora pode ter o mesmo cliente: a unicidade no banco é por tenant).
 */
function cliente_salvar(array $d, ?string $id = null): string
{
    $tipo = ($d['tipo'] ?? 'PF') === 'PJ' ? 'PJ' : 'PF';
    $doc = so_digitos($d['cpf_cnpj'] ?? '');
    $cep = so_digitos($d['cep'] ?? '');
    $c = [
        'tipo'      => $tipo,
        'nome'      => trim($d['nome'] ?? ''),
        'cpf_cnpj'  => $doc,
        'rg_ie'     => trim($d['rg_ie'] ?? ''),
        'telefone'  => telefone_formatar($d['telefone'] ?? ''),
        'celular'   => telefone_formatar($d['celular'] ?? ''),
        'email'     => trim($d['email'] ?? ''),
        'cep'       => $cep === '' ? '' : substr($cep, 0, 5) . '-' . substr($cep, 5),
        'endereco'  => trim($d['endereco'] ?? ''),
        'numero'    => trim($d['numero'] ?? ''),
        'bairro'    => trim($d['bairro'] ?? ''),
        'cidade'    => trim($d['cidade'] ?? ''),
        'uf'        => strtoupper(trim($d['uf'] ?? '')),
        'obs'       => trim($d['obs'] ?? ''),
        'bloqueado' => !empty($d['bloqueado']),
        'ativo'     => !empty($d['ativo']),
    ];

    $erros = [];
    if (mb_strlen($c['nome']) < 3 || mb_strlen($c['nome']) > 150) {
        $erros[] = 'Informe o nome (3 a 150 caracteres).';
    }
    if ($doc !== '') {
        $tam = $tipo === 'PF' ? 11 : 14;
        if (strlen($doc) !== $tam || !doc_valido($doc)) {
            $erros[] = $tipo === 'PF' ? 'CPF inválido.' : 'CNPJ inválido.';
        }
    }
    if ($c['email'] !== '' && filter_var($c['email'], FILTER_VALIDATE_EMAIL) === false) {
        $erros[] = 'E-mail inválido.';
    }
    if ($cep !== '' && strlen($cep) !== 8) {
        $erros[] = 'CEP inválido (8 dígitos).';
    }
    if ($c['uf'] !== '' && !in_array($c['uf'], UFS, true)) {
        $erros[] = 'UF inválida.';
    }
    $limites = ['rg_ie' => 20, 'telefone' => 20, 'celular' => 20, 'email' => 120, 'endereco' => 150,
                'numero' => 10, 'bairro' => 80, 'cidade' => 80];
    foreach ($limites as $campo => $max) {
        if (mb_strlen($c[$campo]) > $max) {
            $erros[] = "O campo $campo aceita no máximo $max caracteres.";
        }
    }
    if ($erros) {
        throw new UserError(implode(' ', $erros));
    }

    // Mensagem amigável antes de bater na restrição única do banco (que continua valendo)
    if ($doc !== '') {
        $outro = one('SELECT id, nome FROM clientes WHERE cpf_cnpj = :d AND id IS DISTINCT FROM CAST(:id AS uuid)',
                     ['d' => $doc, 'id' => $id]);
        if ($outro) {
            throw new UserError(sprintf('Já existe um cliente com este %s: %s.', $tipo === 'PF' ? 'CPF' : 'CNPJ', $outro['nome']));
        }
    }

    $params = $c;
    $params['bloqueado'] = $c['bloqueado'] ? 'true' : 'false';
    $params['ativo'] = $c['ativo'] ? 'true' : 'false';
    $colunas = array_keys($c);

    if ($id === null) {
        return (string) val('INSERT INTO clientes (' . implode(', ', $colunas) . ')
                             VALUES (:' . implode(', :', $colunas) . ') RETURNING id', $params);
    }
    $sets = implode(', ', array_map(fn($k) => "$k = :$k", $colunas));
    $params['id'] = $id;
    if (val("UPDATE clientes SET $sets WHERE id = :id RETURNING id", $params) === null) {
        throw new UserError('Cliente não encontrado.');
    }
    return $id;
}

/** Resumo financeiro do cliente: locações, total movimentado e saldo devedor. */
function cliente_resumo(string $clienteId): array
{
    return one("SELECT count(*)                                                           AS locacoes,
                       count(*) FILTER (WHERE l.status = 'aberta')                        AS abertas,
                       COALESCE(SUM(v.valor_atual) FILTER (WHERE l.status <> 'cancelada'), 0) AS movimentado,
                       COALESCE(SUM(v.valor_pago), 0)                                     AS pago,
                       COALESCE(SUM(GREATEST(v.valor_atual - v.valor_pago, 0))
                                FILTER (WHERE l.status <> 'cancelada'), 0)                AS saldo
                  FROM locacoes l
                  JOIN vw_locacao_valores v ON v.locacao_id = l.id
                 WHERE l.cliente_id = :id", ['id' => $clienteId]);
}

// =====================================================================
// PRODUTOS E CATEGORIAS
// =====================================================================

/**
 * Valida e grava um produto. $id = null cria um novo. Devolve o id.
 * Regra: o estoque total não pode ficar menor que a quantidade alugada (na rua).
 */
function produto_salvar(array $d, ?string $id = null): string
{
    $p = [
        'codigo'          => strtoupper(trim($d['codigo'] ?? '')),
        'descricao'       => trim($d['descricao'] ?? ''),
        'categoria_id'    => ($d['categoria_id'] ?? '') === '' ? null : $d['categoria_id'],
        'unidade'         => strtoupper(trim($d['unidade'] ?? 'UN')),
        'qtd_total'       => filter_var($d['qtd_total'] ?? '', FILTER_VALIDATE_INT),
        'valor_diaria'    => parse_money($d['valor_diaria'] ?? ''),
        'valor_reposicao' => parse_money($d['valor_reposicao'] ?? ''),
        'ncm'             => so_digitos($d['ncm'] ?? ''),
        'obs'             => trim($d['obs'] ?? ''),
        'ativo'           => !empty($d['ativo']) ? 'true' : 'false',
    ];

    $erros = [];
    if (!preg_match('/^[A-Z0-9][A-Z0-9._\/-]{0,29}$/', $p['codigo'])) {
        $erros[] = 'Código inválido (até 30 caracteres: letras, números, ponto, hífen, barra).';
    }
    if (mb_strlen($p['descricao']) < 2 || mb_strlen($p['descricao']) > 150) {
        $erros[] = 'Informe a descrição (2 a 150 caracteres).';
    }
    if ($p['categoria_id'] !== null && (!is_uuid($p['categoria_id'])
            || val('SELECT 1 FROM categorias WHERE id = :id', ['id' => $p['categoria_id']]) === null)) {
        $erros[] = 'Categoria inválida.';
    }
    if ($p['unidade'] === '' || mb_strlen($p['unidade']) > 10) {
        $erros[] = 'Informe a unidade (até 10 caracteres).';
    }
    if ($p['qtd_total'] === false || $p['qtd_total'] < 0 || $p['qtd_total'] > 1000000) {
        $erros[] = 'Quantidade em estoque inválida.';
    }
    if ($p['valor_diaria'] < 0 || $p['valor_reposicao'] < 0 || $p['valor_diaria'] > 9999999999 || $p['valor_reposicao'] > 9999999999) {
        $erros[] = 'Valores não podem ser negativos.';
    }
    if ($p['ncm'] !== '' && strlen($p['ncm']) !== 8) {
        $erros[] = 'NCM deve ter 8 dígitos.';
    }
    if ($erros) {
        throw new UserError(implode(' ', $erros));
    }

    $colunas = array_keys($p);
    return tx(function () use ($p, $id, $colunas) {
        if ($id === null) {
            return (string) val('INSERT INTO produtos (' . implode(', ', $colunas) . ')
                                 VALUES (:' . implode(', :', $colunas) . ') RETURNING id', $p);
        }
        // Trava o produto para ninguém alugar no meio da conferência
        if (val('SELECT id FROM produtos WHERE id = :id FOR UPDATE', ['id' => $id]) === null) {
            throw new UserError('Produto não encontrado.');
        }
        $alugado = qtd_alugada($id);
        if ($p['qtd_total'] < $alugado) {
            throw new UserError(sprintf('O estoque total não pode ser menor que a quantidade alugada no momento (%d na rua).', $alugado));
        }
        $sets = implode(', ', array_map(fn($k) => "$k = :$k", $colunas));
        q("UPDATE produtos SET $sets WHERE id = :id", $p + ['id' => $id]);
        return $id;
    });
}

function categoria_salvar(string $nome, ?string $id = null): void
{
    $nome = trim($nome);
    if (mb_strlen($nome) < 2 || mb_strlen($nome) > 60) {
        throw new UserError('O nome da categoria deve ter de 2 a 60 caracteres.');
    }
    if ($id === null) {
        q('INSERT INTO categorias (nome) VALUES (:n)', ['n' => $nome]);
    } elseif (val('UPDATE categorias SET nome = :n WHERE id = :id RETURNING id', ['n' => $nome, 'id' => $id]) === null) {
        throw new UserError('Categoria não encontrada.');
    }
}
