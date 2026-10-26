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
// PREÇO POR PERÍODO
// =====================================================================

/**
 * Preço de 1 unidade por $dias (mínimo 1): meses de 30 dias, depois semanas de 7, depois diárias.
 * As diárias que sobram nunca passam do preço da semana, e o que sobra do mês nunca passa do mês;
 * o total nunca passa de só diárias. Semanal/mensal 0 = não oferecido.
 * A mesma regra está na função preco_periodo() do banco e em LG.precoPeriodo (app.js): mude as três juntas.
 */
function preco_periodo(int $dias, float $diaria, float $semanal = 0, float $mensal = 0): float
{
    $d = max(1, $dias);
    $m = $mensal > 0 ? intdiv($d, 30) : 0;
    $r = $mensal > 0 ? $d % 30 : $d;
    $s = $semanal > 0 ? intdiv($r, 7) * $semanal + min(($r % 7) * $diaria, $semanal) : $r * $diaria;
    return round(min($d * $diaria, $m * $mensal + ($mensal > 0 ? min($s, $mensal) : $s)), 2);
}

/** Preço de um item (linha com valor_diaria/semanal/mensal) por $dias. */
function preco_item(array $it, int $dias): float
{
    return preco_periodo($dias, (float) $it['valor_diaria'], (float) ($it['valor_semanal'] ?? 0), (float) ($it['valor_mensal'] ?? 0));
}

/** Tabela de preços para célula de tabela: "R$ 45,00/dia<br>R$ 225,00/semana<br>R$ 700,00/mês". */
function precos_html(array $it): string
{
    $partes = [money($it['valor_diaria']) . '/dia'];
    if ((float) ($it['valor_semanal'] ?? 0) > 0) {
        $partes[] = money($it['valor_semanal']) . '/semana';
    }
    if ((float) ($it['valor_mensal'] ?? 0) > 0) {
        $partes[] = money($it['valor_mensal']) . '/mês';
    }
    return implode('<br>', array_map('e', $partes));
}

// =====================================================================
// DISPONIBILIDADE DE ESTOQUE
// =====================================================================

/**
 * Quantidade disponível do produto no período [$inicio, $fim]:
 *   qtd_total
 *   - itens na rua que ocupam o período: devolução prevista >= início, ou o período
 *     começa hoje (ou antes), porque item atrasado continua fora mesmo com a previsão vencida
 *   - reservas ativas que se cruzam com o período (menos a reserva ignorada, ao convertê-la)
 *   - peças em manutenção aberta (sem data de volta: indisponíveis em qualquer período)
 */
function disponivel(string $produtoId, string $inicio, string $fim, ?string $ignorarReserva = null,
                    ?string $ignorarLocacao = null): int
{
    $r = one("
        SELECT p.qtd_total
             - COALESCE((SELECT SUM(li.quantidade - li.qtd_devolvida)
                           FROM locacao_itens li
                           JOIN locacoes l ON l.id = li.locacao_id
                          WHERE l.status = 'aberta'
                            AND li.produto_id = p.id
                            AND (CAST(:igl1 AS uuid) IS NULL OR l.id <> CAST(:igl2 AS uuid))
                            AND (l.data_prev_devolucao >= CAST(:ini1 AS date) OR CAST(:ini2 AS date) <= CURRENT_DATE)), 0)
             - COALESCE((SELECT SUM(ri.quantidade)
                           FROM reserva_itens ri
                           JOIN reservas r ON r.id = ri.reserva_id
                          WHERE r.status = 'ativa'
                            AND ri.produto_id = p.id
                            AND r.data_inicio <= CAST(:fim AS date)
                            AND r.data_fim >= CAST(:ini3 AS date)
                            AND (CAST(:ign1 AS uuid) IS NULL OR r.id <> CAST(:ign2 AS uuid))), 0)
             - COALESCE((SELECT SUM(m.quantidade) FROM manutencoes m
                          WHERE m.produto_id = p.id AND m.data_fim IS NULL), 0) AS disponivel
          FROM produtos p
         WHERE p.id = :id",
        ['id' => $produtoId, 'ini1' => $inicio, 'ini2' => $inicio, 'ini3' => $inicio, 'fim' => $fim,
         'ign1' => $ignorarReserva, 'ign2' => $ignorarReserva, 'igl1' => $ignorarLocacao, 'igl2' => $ignorarLocacao]);
    return $r === null ? 0 : (int) $r['disponivel'];
}

/**
 * Confere o estoque de vários itens de uma vez. $itens = [produto_id => quantidade].
 * Lança UserError listando TODOS os itens em falta, por exemplo:
 * "Estoque insuficiente: Betoneira 400 L (pedido 8, disponível 7)."
 * Use dentro de tx(), depois de travar os produtos com produtos_travar().
 */
function validar_estoque(array $itens, string $inicio, string $fim, ?string $ignorarReserva = null,
                         ?string $ignorarLocacao = null): void
{
    $faltas = [];
    foreach ($itens as $produtoId => $qtd) {
        $disp = disponivel((string) $produtoId, $inicio, $fim, $ignorarReserva, $ignorarLocacao);
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

function qtd_manutencao(string $produtoId): int
{
    return (int) (val('SELECT qtd_manutencao FROM vw_produto_manutencao WHERE produto_id = :id', ['id' => $produtoId]) ?? 0);
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
 * Códigos de barras digitados (um por linha, vírgula ou espaço; o primeiro é o principal).
 * Devolve ['lista' => [[codigo, principal]], 'erros' => [...]]. Só dígitos, de 5 a 20, sem repetir no texto.
 */
function codigos_barras_parse(string $texto): array
{
    $partes = array_values(array_filter(preg_split('/[\s,;]+/', trim($texto)), fn($x) => $x !== ''));
    $lista = [];
    $erros = [];
    foreach ($partes as $i => $c) {
        if (!preg_match('/^[0-9]{5,20}$/', $c)) {
            $erros[] = "Código de barras inválido \"$c\" (use só números, de 5 a 20 dígitos).";
            continue;
        }
        if (in_array($c, array_column($lista, 0), true)) {
            $erros[] = "O código de barras $c está repetido no produto.";
            continue;
        }
        $lista[] = [$c, $i === 0];
    }
    return ['lista' => $lista, 'erros' => $erros];
}

/** Substitui os códigos de barras do produto (dentro da transação do produto). */
function produto_codigos_gravar(string $produtoId, array $lista): void
{
    q('DELETE FROM produto_barras WHERE produto_id = :id', ['id' => $produtoId]);
    foreach ($lista as [$codigo, $principal]) {
        q('INSERT INTO produto_barras (produto_id, codigo, principal) VALUES (:p, :c, :pr)',
          ['p' => $produtoId, 'c' => $codigo, 'pr' => $principal ? 'true' : 'false']);
    }
}

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
        'valor_semanal'   => parse_money($d['valor_semanal'] ?? ''),
        'valor_mensal'    => parse_money($d['valor_mensal'] ?? ''),
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
    foreach (['valor_diaria', 'valor_semanal', 'valor_mensal', 'valor_reposicao'] as $k) {
        if ($p[$k] < 0 || $p[$k] > 9999999999) {
            $erros[] = 'Valores não podem ser negativos.';
            break;
        }
    }
    if (($p['valor_semanal'] > 0 || $p['valor_mensal'] > 0) && $p['valor_diaria'] <= 0) {
        $erros[] = 'Para usar preço semanal ou mensal, informe também a diária (usada nos dias que sobram).';
    }
    if ($p['valor_semanal'] > 0 && $p['valor_mensal'] > 0 && $p['valor_mensal'] < $p['valor_semanal']) {
        $erros[] = 'O preço mensal não pode ser menor que o semanal.';
    }
    if ($p['ncm'] !== '' && strlen($p['ncm']) !== 8) {
        $erros[] = 'NCM deve ter 8 dígitos.';
    }
    $codigos = codigos_barras_parse((string) ($d['codigos_barras'] ?? ''));
    $erros = array_merge($erros, $codigos['erros']);
    if ($erros) {
        throw new UserError(implode(' ', $erros));
    }

    $colunas = array_keys($p);
    return tx(function () use ($p, $id, $colunas, $codigos) {
        if ($id === null) {
            $novoId = (string) val('INSERT INTO produtos (' . implode(', ', $colunas) . ')
                                    VALUES (:' . implode(', :', $colunas) . ') RETURNING id', $p);
            produto_codigos_gravar($novoId, $codigos['lista']);
            return $novoId;
        }
        // Trava o produto para ninguém alugar no meio da conferência
        if (val('SELECT id FROM produtos WHERE id = :id FOR UPDATE', ['id' => $id]) === null) {
            throw new UserError('Produto não encontrado.');
        }
        $alugado = qtd_alugada($id);
        if ($p['qtd_total'] < $alugado) {
            throw new UserError(sprintf('O estoque total não pode ser menor que a quantidade alugada no momento (%d na rua).', $alugado));
        }
        $manutencao = qtd_manutencao($id);
        if ($p['qtd_total'] < $alugado + $manutencao) {
            throw new UserError(sprintf('O estoque total não pode ser menor que o que está fora (%d na rua e %d em manutenção).', $alugado, $manutencao));
        }
        $unidades = (int) val('SELECT count(*) FROM unidades WHERE produto_id = :id AND ativa', ['id' => $id]);
        if ($p['qtd_total'] < $unidades) {
            throw new UserError("O estoque total não pode ser menor que as $unidades unidade(s) de patrimônio cadastradas. Dê baixa nas que saíram.");
        }
        $sets = implode(', ', array_map(fn($k) => "$k = :$k", $colunas));
        q("UPDATE produtos SET $sets WHERE id = :id", $p + ['id' => $id]);
        produto_codigos_gravar($id, $codigos['lista']);
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

// =====================================================================
// KITS (pacote de produtos: na locação/reserva vira os itens que o compõem)
// =====================================================================

/** Valida e grava um kit com seus itens (produto_id[] e quantidade[]). $id = null cria. Devolve o id. */
function kit_salvar(array $d, ?string $id = null): string
{
    $k = [
        'codigo' => strtoupper(trim($d['codigo'] ?? '')),
        'nome'   => trim($d['nome'] ?? ''),
        'obs'    => trim($d['obs'] ?? ''),
        'ativo'  => !empty($d['ativo']) ? 'true' : 'false',
    ];
    if (!preg_match('/^[A-Z0-9][A-Z0-9._\/-]{0,29}$/', $k['codigo'])) {
        throw new UserError('Código inválido (até 30 caracteres: letras, números, ponto, hífen, barra).');
    }
    if (mb_strlen($k['nome']) < 2 || mb_strlen($k['nome']) > 150) {
        throw new UserError('Informe o nome do kit (2 a 150 caracteres).');
    }
    if (mb_strlen($k['obs']) > 2000) {
        throw new UserError('Observações muito longas (máximo de 2.000 caracteres).');
    }
    $itens = itens_do_form($d, 'composição do kit');

    return tx(function () use ($k, $id, $itens) {
        $achados = (int) val('SELECT count(*) FROM produtos WHERE id = ANY (CAST(:ids AS uuid[]))',
                             ['ids' => '{' . implode(',', array_keys($itens)) . '}']);
        if ($achados !== count($itens)) {
            throw new UserError('Produto não encontrado na composição do kit.');
        }
        if ($id === null) {
            $id = (string) val('INSERT INTO kits (codigo, nome, obs, ativo) VALUES (:codigo, :nome, :obs, :ativo) RETURNING id', $k);
        } else {
            if (val('UPDATE kits SET codigo = :codigo, nome = :nome, obs = :obs, ativo = :ativo WHERE id = :id RETURNING id',
                    $k + ['id' => $id]) === null) {
                throw new UserError('Kit não encontrado.');
            }
            q('DELETE FROM kit_itens WHERE kit_id = :id', ['id' => $id]);
        }
        foreach ($itens as $pid => $it) {
            q('INSERT INTO kit_itens (kit_id, produto_id, quantidade) VALUES (:k, :p, :q)', ['k' => $id, 'p' => $pid, 'q' => $it['qtd']]);
        }
        return $id;
    });
}

/**
 * Kits ativos para o seletor da locação/reserva: [id => [codigo, nome, itens => [[p => produto_id, q => qtd]]]].
 * Itens de produto inativo ficam de fora (não podem ser alugados); kit sem nenhum item ativo não aparece.
 */
function kits_para_form(): array
{
    $kits = [];
    foreach (all('SELECT k.id, k.codigo, k.nome, ki.produto_id, ki.quantidade
                    FROM kits k
                    JOIN kit_itens ki ON ki.kit_id = k.id
                    JOIN produtos p ON p.id = ki.produto_id
                   WHERE k.ativo AND p.ativo
                   ORDER BY k.nome, p.descricao') as $r) {
        $kits[$r['id']] ??= ['codigo' => $r['codigo'], 'nome' => $r['nome'], 'itens' => []];
        $kits[$r['id']]['itens'][] = ['p' => $r['produto_id'], 'q' => (int) $r['quantidade']];
    }
    return $kits;
}

// =====================================================================
// PATRIMÔNIO (unidades) E MANUTENÇÃO
// O estoque continua sendo produtos.qtd_total; unidades dão número de patrimônio/série a
// até qtd_total peças. Manutenção aberta tira as peças do disponível (ver disponivel()).
// =====================================================================

const TIPOS_MANUTENCAO = ['corretiva' => 'Corretiva (conserto)', 'preventiva' => 'Preventiva (revisão)'];

/** Situação atual de uma unidade: baixada, manutencao (nº), na_rua (locação nº) ou disponivel. */
function unidade_situacao(string $unidadeId): array
{
    $u = one("SELECT u.ativa,
                     (SELECT numero FROM manutencoes m WHERE m.unidade_id = u.id AND m.data_fim IS NULL) AS manutencao,
                     (SELECT l.numero FROM locacao_unidades lu JOIN locacao_itens li ON li.id = lu.locacao_item_id
                        JOIN locacoes l ON l.id = li.locacao_id
                       WHERE lu.unidade_id = u.id AND lu.devolucao_id IS NULL) AS locacao
                FROM unidades u WHERE u.id = :id", ['id' => $unidadeId]);
    return match (true) {
        $u === null             => ['nao_encontrada', null],
        !$u['ativa']            => ['baixada', null],
        $u['manutencao'] !== null => ['manutencao', (int) $u['manutencao']],
        $u['locacao'] !== null  => ['na_rua', (int) $u['locacao']],
        default                 => ['disponivel', null],
    };
}

/** Valida e grava uma unidade. Nova: até qtd_total unidades ativas por produto. Devolve o id. */
function unidade_salvar(array $d, ?string $id = null): string
{
    $u = [
        'patrimonio'      => strtoupper(trim((string) ($d['patrimonio'] ?? ''))),
        'serie'           => trim((string) ($d['serie'] ?? '')),
        'aquisicao_data'  => ($d['aquisicao_data'] ?? '') === '' ? null : (string) $d['aquisicao_data'],
        'aquisicao_valor' => parse_money($d['aquisicao_valor'] ?? ''),
        'obs'             => trim((string) ($d['obs'] ?? '')),
    ];
    if (!preg_match('/^[A-Z0-9][A-Z0-9._\/-]{0,29}$/', $u['patrimonio'])) {
        throw new UserError('Número de patrimônio inválido (até 30 caracteres: letras, números, ponto, hífen, barra).');
    }
    if (mb_strlen($u['serie']) > 60 || mb_strlen($u['obs']) > 2000) {
        throw new UserError('Número de série (até 60) ou observações (até 2.000) longos demais.');
    }
    if ($u['aquisicao_data'] !== null && (!valid_date($u['aquisicao_data']) || $u['aquisicao_data'] > today())) {
        throw new UserError('Data de aquisição inválida.');
    }
    if ($u['aquisicao_valor'] < 0 || $u['aquisicao_valor'] > 9999999999) {
        throw new UserError('Valor de aquisição inválido.');
    }
    return tx(function () use ($u, $d, $id) {
        if ($id !== null) {
            if (val('UPDATE unidades SET patrimonio = :patrimonio, serie = :serie, aquisicao_data = :aquisicao_data,
                            aquisicao_valor = :aquisicao_valor, obs = :obs WHERE id = :id RETURNING id', $u + ['id' => $id]) === null) {
                throw new UserError('Unidade não encontrada.');
            }
            return $id;
        }
        $pid = (string) ($d['produto_id'] ?? '');
        $p = is_uuid($pid) ? one('SELECT id, descricao, qtd_total FROM produtos WHERE id = :id FOR UPDATE', ['id' => $pid]) : null;
        if ($p === null) {
            throw new UserError('Produto não encontrado.');
        }
        $ativas = (int) val('SELECT count(*) FROM unidades WHERE produto_id = :id AND ativa', ['id' => $pid]);
        if ($ativas >= (int) $p['qtd_total']) {
            throw new UserError("Já há $ativas unidade(s) cadastrada(s) para um estoque de {$p['qtd_total']}. Aumente o estoque do produto antes.");
        }
        return (string) val('INSERT INTO unidades (produto_id, patrimonio, serie, aquisicao_data, aquisicao_valor, obs)
                             VALUES (:p, :patrimonio, :serie, :aquisicao_data, :aquisicao_valor, :obs) RETURNING id', $u + ['p' => $pid]);
    });
}

/** Baixa (venda, perda, sucata): a unidade sai do patrimônio e o estoque do produto diminui 1. */
function unidade_baixar(string $id, string $data, string $motivo): void
{
    $motivo = trim($motivo);
    if (!valid_date($data) || $data > today() || mb_strlen($motivo) < 3 || mb_strlen($motivo) > 200) {
        throw new UserError('Informe a data (até hoje) e o motivo da baixa (3 a 200 caracteres).');
    }
    tx(function () use ($id, $data, $motivo) {
        $u = one('SELECT u.id, u.produto_id, u.patrimonio FROM unidades u WHERE u.id = :id FOR UPDATE', ['id' => $id]);
        if ($u === null) {
            throw new UserError('Unidade não encontrada.');
        }
        produtos_travar([$u['produto_id']]);
        [$sit, $ref] = unidade_situacao($id);
        if ($sit !== 'disponivel') {
            throw new UserError(match ($sit) {
                'baixada'    => 'Esta unidade já foi baixada.',
                'manutencao' => "A unidade está na manutenção nº $ref: conclua a manutenção antes da baixa.",
                default      => "A unidade está na rua (locação nº $ref): registre a devolução antes da baixa.",
            });
        }
        $p = one('SELECT qtd_total FROM produtos WHERE id = :id', ['id' => $u['produto_id']]);
        $fora = qtd_alugada($u['produto_id']) + qtd_manutencao($u['produto_id']);
        if ((int) $p['qtd_total'] - 1 < $fora) {
            throw new UserError("Sem estoque livre para a baixa: $fora peça(s) estão na rua ou em manutenção.");
        }
        q('UPDATE unidades SET ativa = false, baixa_data = :d, baixa_motivo = :m WHERE id = :id', ['d' => $data, 'm' => $motivo, 'id' => $id]);
        q('UPDATE produtos SET qtd_total = qtd_total - 1 WHERE id = :id', ['id' => $u['produto_id']]);
    });
}

/** Abre uma ordem de manutenção (de uma unidade ou de N peças do produto). Devolve [id, numero]. */
function manutencao_abrir(array $d): array
{
    $unidadeId = (string) ($d['unidade_id'] ?? '');
    $produtoId = (string) ($d['produto_id'] ?? '');
    $tipo = (string) ($d['tipo'] ?? '');
    $descricao = trim((string) ($d['descricao'] ?? ''));
    $fornecedor = trim((string) ($d['fornecedor'] ?? ''));
    $inicio = (string) ($d['data_inicio'] ?? '');
    $qtd = $unidadeId !== '' ? 1 : filter_var($d['quantidade'] ?? '', FILTER_VALIDATE_INT);
    if (!isset(TIPOS_MANUTENCAO[$tipo])) {
        throw new UserError('Escolha o tipo de manutenção.');
    }
    if (mb_strlen($descricao) < 3 || mb_strlen($descricao) > 500 || mb_strlen($fornecedor) > 100) {
        throw new UserError('Descreva o problema ou o serviço (3 a 500 caracteres); fornecedor até 100.');
    }
    if (!valid_date($inicio) || $inicio > today()) {
        throw new UserError('A data de início deve ser hoje ou antes.');
    }
    if ($qtd === false || $qtd < 1 || $qtd > 100000) {
        throw new UserError('Quantidade inválida.');
    }
    if (($unidadeId !== '' && !is_uuid($unidadeId)) || ($unidadeId === '' && !is_uuid($produtoId))) {
        throw new UserError('Escolha o produto ou a unidade.');
    }

    return tx(function () use ($unidadeId, $produtoId, $tipo, $descricao, $fornecedor, $inicio, $qtd) {
        if ($unidadeId !== '') {
            $u = one('SELECT produto_id FROM unidades WHERE id = :id FOR UPDATE', ['id' => $unidadeId]);
            if ($u === null) {
                throw new UserError('Unidade não encontrada.');
            }
            $produtoId = $u['produto_id'];
            [$sit, $ref] = unidade_situacao($unidadeId);
            if ($sit !== 'disponivel') {
                throw new UserError(match ($sit) {
                    'baixada'    => 'Esta unidade foi baixada.',
                    'manutencao' => "A unidade já está na manutenção nº $ref.",
                    default      => "A unidade está na rua (locação nº $ref): registre a devolução antes.",
                });
            }
        }
        produtos_travar([$produtoId]);
        $p = one('SELECT descricao FROM produtos WHERE id = :id', ['id' => $produtoId]);
        if ($p === null) {
            throw new UserError('Produto não encontrado.');
        }
        $livre = disponivel($produtoId, today(), today());
        if ($qtd > $livre) {
            throw new UserError("Só há $livre peça(s) de {$p['descricao']} livre(s) agora: as demais estão na rua, reservadas ou em manutenção.");
        }
        $numero = (int) val("SELECT proximo_numero('manutencao')");
        $id = (string) val('INSERT INTO manutencoes (numero, produto_id, unidade_id, quantidade, tipo, descricao, fornecedor, data_inicio, usuario_id)
                            VALUES (:n, :p, :u, :q, :t, :d, :f, :i, :usr) RETURNING id',
            ['n' => $numero, 'p' => $produtoId, 'u' => $unidadeId === '' ? null : $unidadeId, 'q' => $qtd, 't' => $tipo,
             'd' => $descricao, 'f' => $fornecedor, 'i' => $inicio, 'usr' => uid()]);
        return ['id' => $id, 'numero' => $numero];
    });
}

/** Conclui a manutenção: as peças voltam ao estoque disponível; o custo pode virar saída no caixa. */
function manutencao_concluir(string $id, array $d): void
{
    $fim = (string) ($d['data_fim'] ?? '');
    $custo = parse_money($d['custo'] ?? '');
    $solucao = trim((string) ($d['solucao'] ?? ''));
    $lancar = !empty($d['lancar_caixa']) && $custo > 0;
    $forma = $lancar ? forma_valida((string) ($d['forma'] ?? '')) : '';
    if ($custo < 0 || $custo > 9999999999 || mb_strlen($solucao) > 500) {
        throw new UserError('Confira o custo e o serviço realizado (até 500 caracteres).');
    }
    tx(function () use ($id, $fim, $custo, $solucao, $lancar, $forma) {
        $m = one('SELECT m.*, p.descricao AS produto, u.patrimonio FROM manutencoes m JOIN produtos p ON p.id = m.produto_id
                    LEFT JOIN unidades u ON u.id = m.unidade_id WHERE m.id = :id FOR UPDATE OF m', ['id' => $id]);
        if ($m === null) {
            throw new UserError('Manutenção não encontrada.');
        }
        if ($m['data_fim'] !== null) {
            throw new UserError('Esta manutenção já foi concluída.');
        }
        if (!valid_date($fim) || $fim < $m['data_inicio'] || $fim > today()) {
            throw new UserError('A data de conclusão deve estar entre o início (' . dt($m['data_inicio']) . ') e hoje.');
        }
        $caixaId = null;
        if ($lancar) {
            $caixaId = (string) val("INSERT INTO caixa (data, tipo, categoria, descricao, valor, forma, usuario_id)
                                     VALUES (:d, 'S', 'Manutenção', :descr, :v, :f, :u) RETURNING id",
                ['d' => $fim, 'descr' => mb_substr("Manutenção nº {$m['numero']} - {$m['produto']}" . ($m['patrimonio'] ? " ({$m['patrimonio']})" : ''), 0, 200),
                 'v' => $custo, 'f' => $forma, 'u' => uid()]);
        }
        q('UPDATE manutencoes SET data_fim = :f, custo = :c, solucao = :s, caixa_id = :cx WHERE id = :id',
          ['f' => $fim, 'c' => $custo, 's' => $solucao, 'cx' => $caixaId, 'id' => $id]);
    });
}

/**
 * Unidades que saíram em cada item da locação (aberta): [locacao_item_id => [unidade_id, ...]].
 * Só unidades do produto do item, ativas, fora de manutenção e não alocadas em outra locação;
 * no máximo a quantidade ainda pendente do item. Substitui a seleção anterior das que estão na rua.
 */
function locacao_unidades_salvar(string $locacaoId, array $porItem): void
{
    tx(function () use ($locacaoId, $porItem) {
        $st = val('SELECT status FROM locacoes WHERE id = :id FOR UPDATE', ['id' => $locacaoId]);
        if ($st === null) {
            throw new UserError('Locação não encontrada.');
        }
        if ($st !== 'aberta') {
            throw new UserError('Só é possível indicar o patrimônio de locação em aberto.');
        }
        $itens = all('SELECT li.id, li.produto_id, li.quantidade - li.qtd_devolvida AS pendente, p.descricao
                        FROM locacao_itens li JOIN produtos p ON p.id = li.produto_id
                       WHERE li.locacao_id = :id FOR UPDATE OF li', ['id' => $locacaoId]);
        $validos = array_column($itens, 'id');
        foreach (array_keys($porItem) as $k) {
            if (!in_array((string) $k, $validos, true)) {
                throw new UserError('Item inválido.');
            }
        }
        foreach ($itens as $it) {
            $escolha = array_values(array_unique(array_filter(array_map('strval', (array) ($porItem[$it['id']] ?? [])), 'is_uuid')));
            if (count($escolha) > (int) $it['pendente']) {
                throw new UserError("{$it['descricao']}: escolha no máximo {$it['pendente']} unidade(s) (quantidade na rua).");
            }
            q('DELETE FROM locacao_unidades WHERE locacao_item_id = :i AND devolucao_id IS NULL', ['i' => $it['id']]);
            foreach ($escolha as $uid) {
                $u = one('SELECT produto_id, patrimonio FROM unidades WHERE id = :id FOR UPDATE', ['id' => $uid]);
                if ($u === null || $u['produto_id'] !== $it['produto_id']) {
                    throw new UserError("Unidade inválida para {$it['descricao']}.");
                }
                [$sit, $ref] = unidade_situacao($uid);
                if ($sit !== 'disponivel') {
                    throw new UserError("A unidade {$u['patrimonio']} " . match ($sit) {
                        'baixada'    => 'foi baixada.',
                        'manutencao' => "está na manutenção nº $ref.",
                        default      => "está na rua (locação nº $ref).",
                    });
                }
                q('INSERT INTO locacao_unidades (locacao_item_id, unidade_id) VALUES (:i, :u)', ['i' => $it['id'], 'u' => $uid]);
            }
        }
    });
}

// =====================================================================
// LOCAÇÕES
// =====================================================================

/** Texto que identifica o cliente no <datalist>: "Nome · documento" (o UUID nunca aparece). */
function cliente_rotulo(array $c): string
{
    return $c['cpf_cnpj'] !== '' ? $c['nome'] . ' · ' . doc_formatar($c['cpf_cnpj']) : $c['nome'];
}

/** Resolve o texto escolhido no <datalist> para o cliente (só clientes ativos). */
function cliente_resolver(string $texto): array
{
    $partes = explode(' · ', trim($texto), 2);
    $nome = trim($partes[0]);
    $doc = so_digitos($partes[1] ?? '');
    if ($nome === '') {
        throw new UserError('Escolha o cliente.');
    }
    $achados = all("SELECT id, nome, cpf_cnpj, bloqueado FROM clientes
                     WHERE ativo AND nome = :nome AND (:doc1 = '' OR cpf_cnpj = :doc2)",
                   ['nome' => $nome, 'doc1' => $doc, 'doc2' => $doc]);
    if (!$achados) {
        throw new UserError('Cliente não encontrado. Escolha um cliente da lista ou cadastre-o antes.');
    }
    if (count($achados) > 1) {
        throw new UserError('Há mais de um cliente com esse nome. Escolha na lista a opção com o documento.');
    }
    return $achados[0];
}

/**
 * Itens do formulário (produto_id[], quantidade[], diaria[]) => [produto_id => ['qtd', 'diaria']].
 * Linhas repetidas do mesmo produto são somadas; diária vazia fica null (usa a do cadastro).
 */
function itens_do_form(array $d, string $onde): array
{
    $itens = [];
    foreach ((array) ($d['produto_id'] ?? []) as $i => $pid) {
        $pid = (string) $pid;
        if ($pid === '') {
            continue;
        }
        if (!is_uuid($pid)) {
            throw new UserError('Produto inválido na linha ' . ($i + 1) . '.');
        }
        $qtd = filter_var($d['quantidade'][$i] ?? '', FILTER_VALIDATE_INT);
        if ($qtd === false || $qtd < 1 || $qtd > 100000) {
            throw new UserError('Quantidade inválida na linha ' . ($i + 1) . '.');
        }
        $diariaTxt = trim((string) ($d['diaria'][$i] ?? ''));
        if (!isset($itens[$pid])) {
            $itens[$pid] = ['qtd' => 0, 'diaria' => null];
        }
        $itens[$pid]['qtd'] += $qtd;
        if ($itens[$pid]['diaria'] === null && $diariaTxt !== '') {
            $itens[$pid]['diaria'] = parse_money($diariaTxt);
        }
    }
    if (!$itens) {
        throw new UserError("Inclua pelo menos um item na $onde.");
    }
    return $itens;
}

/**
 * Unidades (patrimônio) que voltaram nesta devolução. Item devolvido por inteiro libera todas as suas; em
 * devolução parcial voltam as marcadas, e as que ficam na rua não podem passar do que continua pendente.
 */
function devolucao_unidades(string $devolucaoId, array $devolver, array $marcadas): void
{
    $marcadas = array_values(array_filter(array_map('strval', $marcadas), 'is_uuid'));
    foreach ($devolver as $dv) {
        $it = $dv['item'];
        $naRua = array_column(all('SELECT lu.unidade_id FROM locacao_unidades lu
                                    WHERE lu.locacao_item_id = :i AND lu.devolucao_id IS NULL FOR UPDATE', ['i' => $it['id']]), 'unidade_id');
        if (!$naRua) {
            continue;
        }
        $pendenteDepois = $it['quantidade'] - $it['qtd_devolvida'] - $dv['qtd'];
        $voltam = $pendenteDepois === 0 ? $naRua : array_values(array_intersect($naRua, $marcadas));
        if (count($voltam) > $dv['qtd']) {
            throw new UserError("Foram marcadas mais unidades de {$it['descricao']} do que a quantidade devolvida ({$dv['qtd']}).");
        }
        if (count($naRua) - count($voltam) > $pendenteDepois) {
            throw new UserError("Marque quais unidades (patrimônio) de {$it['descricao']} voltaram.");
        }
        if ($voltam) {
            q('UPDATE locacao_unidades SET devolucao_id = :d
                WHERE locacao_item_id = :i AND devolucao_id IS NULL AND unidade_id = ANY (CAST(:u AS uuid[]))',
              ['d' => $devolucaoId, 'i' => $it['id'], 'u' => '{' . implode(',', $voltam) . '}']);
        }
    }
}

/** Busca a locação com todos os valores da view. null se não existir (nesta locadora). */
function locacao_buscar(string $id): ?array
{
    return one("SELECT l.*, c.nome AS cliente_nome, c.cpf_cnpj AS cliente_doc, c.tipo AS cliente_tipo,
                       v.valor_previsto, v.valor_atual, v.valor_pago,
                       (SELECT count(*) FROM devolucoes d WHERE d.locacao_id = l.id) AS devolucoes
                  FROM locacoes l
                  JOIN clientes c ON c.id = l.cliente_id
                  JOIN vw_locacao_valores v ON v.locacao_id = l.id
                 WHERE l.id = :id", ['id' => $id]);
}

/** Acrescenta uma linha datada ao histórico (campo obs) da locação. */
function locacao_historico(string $id, string $texto): void
{
    $linha = '[' . date('d/m/Y H:i') . ' ' . (user()['nome'] ?? '') . '] ' . $texto;
    q("UPDATE locacoes SET obs = CASE WHEN obs = '' THEN :l1 ELSE obs || E'\\n' || :l2 END WHERE id = :id",
      ['l1' => $linha, 'l2' => $linha, 'id' => $id]);
}

function forma_valida(string $forma): string
{
    if (!in_array($forma, FORMAS_PGTO, true)) {
        throw new UserError('Escolha a forma de pagamento.');
    }
    return $forma;
}

/** Lança uma entrada no caixa ligada à locação (recebimento ou adiantamento). */
function caixa_lancar_locacao(array $loc, string $tipo, float $valor, string $forma, string $data, string $prefixo): void
{
    q("INSERT INTO caixa (data, tipo, categoria, descricao, valor, forma, locacao_id, usuario_id)
       VALUES (:data, :tipo, 'Locação', :descr, :valor, :forma, :loc, :u)",
      ['data' => $data, 'tipo' => $tipo, 'descr' => "$prefixo locação nº {$loc['numero']} - {$loc['cliente_nome']}",
       'valor' => $valor, 'forma' => $forma, 'loc' => $loc['id'], 'u' => uid()]);
}

/**
 * Cria a locação. $d vem do formulário:
 *   cliente (texto do datalist), data_saida, data_prev_devolucao, local_uso, obs,
 *   acrescimo, desconto, adiantamento, forma, reserva_id (opcional),
 *   produto_id[], quantidade[], diaria[]
 * Devolve ['id' => uuid, 'numero' => int].
 */

/** Acréscimos e fretes de uma locação (o que soma ao valor; o desconto é subtraído à parte). */
function locacao_ajuste(array $loc): float
{
    return (float) $loc['acrescimo'] + (float) $loc['frete_entrega'] + (float) $loc['frete_recolhimento'] - (float) $loc['desconto'];
}

/**
 * Fretes e endereço de entrega de uma locação: [frete_entrega, frete_recolhimento, endereco].
 * O endereço, se não for informado, é o local de uso.
 */
function entrega_campos(array $d, string $local): array
{
    $fe = parse_money($d['frete_entrega'] ?? '');
    $fr = parse_money($d['frete_recolhimento'] ?? '');
    if ($fe < 0 || $fr < 0 || $fe > 9999999999 || $fr > 9999999999) {
        throw new UserError('Os fretes não podem ser negativos.');
    }
    $endereco = trim((string) ($d['endereco_entrega'] ?? ''));
    if (mb_strlen($endereco) > 200) {
        throw new UserError('O endereço de entrega aceita no máximo 200 caracteres.');
    }
    return [$fe, $fr, $endereco !== '' ? $endereco : $local];
}

/**
 * Desconto ou acréscimo só entra com permissão: quem lança é administrador, ou digita a senha de um
 * administrador ativo. Devolve o id de quem autorizou (null se não há desconto nem acréscimo).
 */
function desconto_autorizador(float $acrescimo, float $desconto, string $senhaAdmin): ?string
{
    if ($acrescimo <= 0 && $desconto <= 0) {
        return null;
    }
    if (is_admin()) {
        return uid();
    }
    if ($senhaAdmin !== '') {
        foreach (all("SELECT id, senha_hash FROM usuarios WHERE perfil = 'admin' AND ativo") as $adm) {
            if (password_verify($senhaAdmin, $adm['senha_hash'])) {
                return $adm['id'];
            }
        }
    }
    throw new UserError('Desconto ou acréscimo precisa da senha de um administrador.');
}

/**
 * Edita os fretes e o endereço de entrega de uma locação em aberto (o histórico registra a mudança).
 */
function locacao_entrega_salvar(string $locacaoId, array $d): void
{
    tx(function () use ($locacaoId, $d) {
        $loc = one('SELECT id, numero, status, local_uso, frete_entrega, frete_recolhimento, endereco_entrega
                      FROM locacoes WHERE id = :id FOR UPDATE', ['id' => $locacaoId]);
        if ($loc === null) {
            throw new UserError('Locação não encontrada.');
        }
        if ($loc['status'] !== 'aberta') {
            throw new UserError('Só é possível alterar o frete e o endereço de locação em aberto.');
        }
        [$fe, $fr, $endereco] = entrega_campos($d, $loc['local_uso']);
        q('UPDATE locacoes SET frete_entrega = :fe, frete_recolhimento = :fr, endereco_entrega = :end WHERE id = :id',
          ['fe' => $fe, 'fr' => $fr, 'end' => $endereco, 'id' => $locacaoId]);
        $mudou = [];
        if ((float) $loc['frete_entrega'] !== $fe) {
            $mudou[] = 'frete de entrega ' . money($loc['frete_entrega']) . ' → ' . money($fe);
        }
        if ((float) $loc['frete_recolhimento'] !== $fr) {
            $mudou[] = 'frete de recolhimento ' . money($loc['frete_recolhimento']) . ' → ' . money($fr);
        }
        if ($loc['endereco_entrega'] !== $endereco) {
            $mudou[] = 'endereço de entrega alterado';
        }
        if ($mudou) {
            locacao_historico($locacaoId, 'Entrega: ' . implode('; ', $mudou) . '.');
        }
    });
}

function locacao_criar(array $d, ?string $autorizadoPor = null): array
{
    $cliente = cliente_resolver((string) ($d['cliente'] ?? ''));
    if ($cliente['bloqueado']) {
        throw new UserError("O cliente {$cliente['nome']} está bloqueado e não pode fazer novas locações.");
    }

    $saida = (string) ($d['data_saida'] ?? '');
    $prev = (string) ($d['data_prev_devolucao'] ?? '');
    if (!valid_date($saida) || !valid_date($prev)) {
        throw new UserError('Informe datas válidas de saída e de devolução prevista.');
    }
    // A locação registra o que já saiu. Retirada futura é reserva.
    if ($saida > today()) {
        throw new UserError('A data de saída não pode ser no futuro. Para garantir equipamentos para outra data, faça uma reserva.');
    }
    if ($prev < $saida) {
        throw new UserError('A devolução prevista não pode ser antes da saída.');
    }

    $acrescimo = parse_money($d['acrescimo'] ?? '');
    $desconto = parse_money($d['desconto'] ?? '');
    $adiantamento = parse_money($d['adiantamento'] ?? '');
    if ($acrescimo < 0 || $desconto < 0 || $adiantamento < 0) {
        throw new UserError('Acréscimo, desconto e adiantamento não podem ser negativos.');
    }
    $autorizadoPor ??= desconto_autorizador($acrescimo, $desconto, (string) ($d['senha_admin'] ?? ''));
    $forma = $adiantamento > 0 ? forma_valida((string) ($d['forma'] ?? '')) : '';
    $local = trim((string) ($d['local_uso'] ?? ''));
    $obs = trim((string) ($d['obs'] ?? ''));
    if (mb_strlen($local) > 200) {
        throw new UserError('O local de uso aceita no máximo 200 caracteres.');
    }
    [$freteEntrega, $freteRecolhimento, $endereco] = entrega_campos($d, $local);

    $reservaId = (string) ($d['reserva_id'] ?? '');
    $reservaId = $reservaId === '' ? null : $reservaId;
    if ($reservaId !== null && !is_uuid($reservaId)) {
        throw new UserError('Reserva inválida.');
    }

    $itens = itens_do_form($d, 'locação');

    return tx(function () use ($cliente, $saida, $prev, $acrescimo, $desconto, $adiantamento, $forma, $local, $obs, $reservaId, $itens,
                               $freteEntrega, $freteRecolhimento, $endereco, $autorizadoPor) {
        if ($reservaId !== null) {
            $st = val('SELECT status FROM reservas WHERE id = :id FOR UPDATE', ['id' => $reservaId]);
            if ($st !== 'ativa') {
                throw new UserError('A reserva não está mais ativa.');
            }
        }

        // Trava os produtos e confere o estoque com os dados já travados
        produtos_travar(array_keys($itens));
        $cadastro = [];
        foreach (all('SELECT id, descricao, valor_diaria, valor_semanal, valor_mensal, ativo FROM produtos WHERE id = ANY (CAST(:ids AS uuid[]))',
                     ['ids' => '{' . implode(',', array_keys($itens)) . '}']) as $p) {
            $cadastro[$p['id']] = $p;
        }
        foreach ($itens as $pid => &$it) {
            if (!isset($cadastro[$pid])) {
                throw new UserError('Produto não encontrado.');
            }
            if (!$cadastro[$pid]['ativo']) {
                throw new UserError("O produto {$cadastro[$pid]['descricao']} está inativo.");
            }
            $it['diaria'] ??= (float) $cadastro[$pid]['valor_diaria'];
            if ($it['diaria'] < 0) {
                throw new UserError('A diária não pode ser negativa.');
            }
        }
        unset($it);
        validar_estoque(array_map(fn($it) => $it['qtd'], $itens), $saida, $prev, $reservaId);

        $numero = (int) val("SELECT proximo_numero('locacao')");
        $id = (string) val('INSERT INTO locacoes (numero, cliente_id, data_saida, data_prev_devolucao, local_uso,
                                                  desconto, acrescimo, obs, reserva_id, usuario_id,
                                                  frete_entrega, frete_recolhimento, endereco_entrega, desconto_autorizado_por)
                            VALUES (:n, :c, :s, :p, :local, :desc, :acr, :obs, :res, :u, :fe, :fr, :end, :aut) RETURNING id',
            ['n' => $numero, 'c' => $cliente['id'], 's' => $saida, 'p' => $prev, 'local' => $local,
             'desc' => $desconto, 'acr' => $acrescimo, 'obs' => $obs, 'res' => $reservaId, 'u' => uid(),
             'fe' => $freteEntrega, 'fr' => $freteRecolhimento, 'end' => $endereco, 'aut' => $autorizadoPor]);
        foreach ($itens as $pid => $it) {
            // Semanal e mensal vêm do cadastro (o formulário só permite ajustar a diária)
            q('INSERT INTO locacao_itens (locacao_id, produto_id, quantidade, valor_diaria, valor_semanal, valor_mensal)
               VALUES (:l, :p, :q, :d, :s, :m)',
              ['l' => $id, 'p' => $pid, 'q' => $it['qtd'], 'd' => $it['diaria'],
               's' => $cadastro[$pid]['valor_semanal'], 'm' => $cadastro[$pid]['valor_mensal']]);
        }
        if ($reservaId !== null) {
            q("UPDATE reservas SET status = 'convertida' WHERE id = :id", ['id' => $reservaId]);
        }
        if ($adiantamento > 0) {
            caixa_lancar_locacao(['id' => $id, 'numero' => $numero, 'cliente_nome' => $cliente['nome']],
                                 'E', $adiantamento, $forma, today(), 'Adiantamento');
        }
        return ['id' => $id, 'numero' => $numero];
    });
}

/**
 * Devolução total ou parcial. $d: data, qtd[locacao_item_id] => quantidade,
 * multa, avaria, obs, receber (valor a receber agora), forma.
 * Dias = max(1, data - saída); cada item vale qtd x diária x dias.
 */
function devolucao_registrar(string $locacaoId, array $d): array
{
    $data = (string) ($d['data'] ?? '');
    $multa = parse_money($d['multa'] ?? '');
    $avaria = parse_money($d['avaria'] ?? '');
    $receber = parse_money($d['receber'] ?? '');
    $obs = trim((string) ($d['obs'] ?? ''));
    if (!valid_date($data)) {
        throw new UserError('Informe a data da devolução.');
    }
    if ($multa < 0 || $avaria < 0 || $receber < 0) {
        throw new UserError('Multa, avaria e valor recebido não podem ser negativos.');
    }
    $forma = $receber > 0 ? forma_valida((string) ($d['forma'] ?? '')) : '';
    $qtds = (array) ($d['qtd'] ?? []);
    $unidades = (array) ($d['unidades'] ?? []);

    return tx(function () use ($locacaoId, $data, $multa, $avaria, $receber, $forma, $obs, $qtds, $unidades) {
        $loc = one('SELECT l.*, c.nome AS cliente_nome FROM locacoes l JOIN clientes c ON c.id = l.cliente_id
                     WHERE l.id = :id FOR UPDATE OF l', ['id' => $locacaoId]);
        if ($loc === null) {
            throw new UserError('Locação não encontrada.');
        }
        if ($loc['status'] !== 'aberta') {
            throw new UserError('Só é possível registrar devolução de locação em aberto.');
        }
        if ($data < $loc['data_saida'] || $data > today()) {
            throw new UserError('A data da devolução deve estar entre a saída (' . dt($loc['data_saida']) . ') e hoje.');
        }

        $itens = all('SELECT li.id, li.quantidade, li.qtd_devolvida, li.valor_diaria, li.valor_semanal, li.valor_mensal, p.descricao
                        FROM locacao_itens li JOIN produtos p ON p.id = li.produto_id
                       WHERE li.locacao_id = :id ORDER BY p.descricao FOR UPDATE OF li', ['id' => $locacaoId]);
        $dias = max(1, days_between($loc['data_saida'], $data));
        $devolver = [];
        foreach ($itens as $it) {
            $txt = trim((string) ($qtds[$it['id']] ?? '0'));
            $q = $txt === '' ? 0 : filter_var($txt, FILTER_VALIDATE_INT);
            $pendente = $it['quantidade'] - $it['qtd_devolvida'];
            if ($q === false || $q < 0) {
                throw new UserError("Quantidade inválida para {$it['descricao']}.");
            }
            if ($q > $pendente) {
                throw new UserError("Não é possível devolver $q de {$it['descricao']}: só há $pendente pendente(s).");
            }
            if ($q > 0) {
                $devolver[] = ['item' => $it, 'qtd' => $q, 'valor' => round($q * preco_item($it, $dias), 2)];
            }
        }
        // Itens que não pertencem a esta locação não são aceitos
        $idsValidos = array_column($itens, 'id');
        foreach (array_keys($qtds) as $k) {
            if (!in_array((string) $k, $idsValidos, true) && (int) $qtds[$k] !== 0) {
                throw new UserError('Item inválido na devolução.');
            }
        }
        if (!$devolver) {
            throw new UserError('Informe a quantidade de pelo menos um item a devolver.');
        }

        $devId = (string) val('INSERT INTO devolucoes (locacao_id, data, multa, avaria, obs, usuario_id)
                               VALUES (:l, :d, :m, :a, :o, :u) RETURNING id',
            ['l' => $locacaoId, 'd' => $data, 'm' => $multa, 'a' => $avaria, 'o' => $obs, 'u' => uid()]);
        foreach ($devolver as $dv) {
            q('INSERT INTO devolucao_itens (devolucao_id, locacao_item_id, quantidade, dias, valor) VALUES (:d, :i, :q, :n, :v)',
              ['d' => $devId, 'i' => $dv['item']['id'], 'q' => $dv['qtd'], 'n' => $dias, 'v' => $dv['valor']]);
            q('UPDATE locacao_itens SET qtd_devolvida = qtd_devolvida + :q WHERE id = :i', ['q' => $dv['qtd'], 'i' => $dv['item']['id']]);
        }
        devolucao_unidades($devId, $devolver, $unidades);

        // Nada mais pendente: a locação está encerrada
        $pendentes = (int) val('SELECT COALESCE(SUM(quantidade - qtd_devolvida), 0) FROM locacao_itens WHERE locacao_id = :id',
                               ['id' => $locacaoId]);
        if ($pendentes === 0) {
            q("UPDATE locacoes SET status = 'devolvida' WHERE id = :id", ['id' => $locacaoId]);
        }
        if ($receber > 0) {
            caixa_lancar_locacao($loc, 'E', $receber, $forma, today(), 'Recebimento');
        }
        return ['total' => $pendentes === 0, 'dias' => $dias];
    });
}

/** Recebimento avulso: entrada no caixa, categoria "Locação". */
function recebimento_registrar(string $locacaoId, array $d): void
{
    $valor = parse_money($d['valor'] ?? '');
    $data = (string) ($d['data'] ?? today());
    if ($valor <= 0) {
        throw new UserError('Informe o valor recebido.');
    }
    if (!valid_date($data) || $data > today()) {
        throw new UserError('A data do recebimento não pode ser no futuro.');
    }
    $forma = forma_valida((string) ($d['forma'] ?? ''));
    tx(function () use ($locacaoId, $valor, $data, $forma) {
        $loc = one('SELECT l.id, l.numero, l.status, c.nome AS cliente_nome FROM locacoes l
                      JOIN clientes c ON c.id = l.cliente_id WHERE l.id = :id FOR UPDATE OF l', ['id' => $locacaoId]);
        if ($loc === null) {
            throw new UserError('Locação não encontrada.');
        }
        if ($loc['status'] === 'cancelada') {
            throw new UserError('Locação cancelada não recebe pagamentos.');
        }
        caixa_lancar_locacao($loc, 'E', $valor, $forma, $data, 'Recebimento');
    });
}

/** Prorroga a devolução prevista. Se a nova data avança sobre reservas, confere o estoque. */
function locacao_prorrogar(string $locacaoId, string $novaData, string $motivo = ''): void
{
    if (!valid_date($novaData)) {
        throw new UserError('Informe a nova data de devolução.');
    }
    tx(function () use ($locacaoId, $novaData, $motivo) {
        $loc = one('SELECT * FROM locacoes WHERE id = :id FOR UPDATE', ['id' => $locacaoId]);
        if ($loc === null) {
            throw new UserError('Locação não encontrada.');
        }
        if ($loc['status'] !== 'aberta') {
            throw new UserError('Só é possível prorrogar locação em aberto.');
        }
        if ($novaData < $loc['data_saida']) {
            throw new UserError('A nova data não pode ser antes da saída.');
        }
        if ($novaData === $loc['data_prev_devolucao']) {
            throw new UserError('A nova data é igual à atual.');
        }
        if ($novaData > $loc['data_prev_devolucao']) {
            // Os itens pendentes continuarão fora até a nova data: não podem invadir reservas já feitas
            $pend = [];
            foreach (all('SELECT produto_id, SUM(quantidade - qtd_devolvida) AS q FROM locacao_itens
                           WHERE locacao_id = :id GROUP BY produto_id HAVING SUM(quantidade - qtd_devolvida) > 0',
                         ['id' => $locacaoId]) as $r) {
                $pend[$r['produto_id']] = (int) $r['q'];
            }
            produtos_travar(array_keys($pend));
            try {
                validar_estoque($pend, today(), $novaData, null, $locacaoId);
            } catch (UserError $e) {
                throw new UserError('Não é possível prorrogar até ' . dt($novaData) . ' por causa de reservas no período. '
                                    . $e->getMessage());
            }
        }
        q('UPDATE locacoes SET data_prev_devolucao = :d WHERE id = :id', ['d' => $novaData, 'id' => $locacaoId]);
        locacao_historico($locacaoId, 'Prorrogada de ' . dt($loc['data_prev_devolucao']) . ' para ' . dt($novaData)
                                      . ($motivo !== '' ? ". Motivo: $motivo" : '.'));
    });
}

/** Cancela locação em aberto sem devoluções. Havendo valor pago, lança o estorno (saída) no caixa. */
function locacao_cancelar(string $locacaoId, string $motivo, string $forma): void
{
    tx(function () use ($locacaoId, $motivo, $forma) {
        q('SELECT id FROM locacoes WHERE id = :id FOR UPDATE', ['id' => $locacaoId]);
        $loc = locacao_buscar($locacaoId);
        if ($loc === null) {
            throw new UserError('Locação não encontrada.');
        }
        if ($loc['status'] !== 'aberta') {
            throw new UserError('Só é possível cancelar locação em aberto.');
        }
        if ((int) $loc['devolucoes'] > 0) {
            throw new UserError('Esta locação já tem devoluções registradas e não pode ser cancelada.');
        }
        $pago = (float) $loc['valor_pago'];
        if ($pago > 0) {
            caixa_lancar_locacao($loc, 'S', $pago, forma_valida($forma), today(), 'Estorno');
        }
        q("UPDATE locacoes SET status = 'cancelada' WHERE id = :id", ['id' => $locacaoId]);
        // As unidades nunca saíram de fato: some o vínculo
        q('DELETE FROM locacao_unidades WHERE locacao_item_id IN (SELECT id FROM locacao_itens WHERE locacao_id = :id)', ['id' => $locacaoId]);
        locacao_historico($locacaoId, 'Cancelada' . ($motivo !== '' ? ". Motivo: $motivo" : '.')
                                      . ($pago > 0 ? ' Estorno de ' . money($pago) . " ($forma)." : ''));
    });
}

// =====================================================================
// RESERVAS
// =====================================================================

/**
 * Cria ou altera uma reserva. Começa hoje ou depois e precisa de estoque no período
 * (descontando locações e outras reservas; na edição, ignora a própria reserva).
 * $d: cliente (texto do datalist), data_inicio, data_fim, obs, produto_id[], quantidade[]
 */
function reserva_salvar(array $d, ?string $id = null): array
{
    $cliente = cliente_resolver((string) ($d['cliente'] ?? ''));
    if ($cliente['bloqueado']) {
        throw new UserError("O cliente {$cliente['nome']} está bloqueado e não pode fazer reservas.");
    }
    $ini = (string) ($d['data_inicio'] ?? '');
    $fim = (string) ($d['data_fim'] ?? '');
    if (!valid_date($ini) || !valid_date($fim)) {
        throw new UserError('Informe datas válidas de retirada e de devolução.');
    }
    if ($ini < today()) {
        throw new UserError('A reserva deve começar hoje ou depois. Para equipamento que já saiu, registre uma locação.');
    }
    if ($fim < $ini) {
        throw new UserError('A devolução não pode ser antes da retirada.');
    }
    $obs = trim((string) ($d['obs'] ?? ''));
    $itens = array_map(fn($it) => $it['qtd'], itens_do_form($d, 'reserva'));

    return tx(function () use ($id, $cliente, $ini, $fim, $obs, $itens) {
        if ($id !== null) {
            $st = val('SELECT status FROM reservas WHERE id = :id FOR UPDATE', ['id' => $id]);
            if ($st === null) {
                throw new UserError('Reserva não encontrada.');
            }
            if ($st !== 'ativa') {
                throw new UserError('Só é possível alterar reserva ativa.');
            }
        }
        produtos_travar(array_keys($itens));
        $ativos = (int) val('SELECT count(*) FROM produtos WHERE ativo AND id = ANY (CAST(:ids AS uuid[]))',
                            ['ids' => '{' . implode(',', array_keys($itens)) . '}']);
        if ($ativos !== count($itens)) {
            throw new UserError('Produto não encontrado ou inativo.');
        }
        validar_estoque($itens, $ini, $fim, $id);

        if ($id === null) {
            $numero = (int) val("SELECT proximo_numero('reserva')");
            $id = (string) val('INSERT INTO reservas (numero, cliente_id, data_inicio, data_fim, obs, usuario_id)
                                VALUES (:n, :c, :i, :f, :o, :u) RETURNING id',
                ['n' => $numero, 'c' => $cliente['id'], 'i' => $ini, 'f' => $fim, 'o' => $obs, 'u' => uid()]);
        } else {
            $numero = (int) val('UPDATE reservas SET cliente_id = :c, data_inicio = :i, data_fim = :f, obs = :o
                                  WHERE id = :id RETURNING numero',
                ['c' => $cliente['id'], 'i' => $ini, 'f' => $fim, 'o' => $obs, 'id' => $id]);
            q('DELETE FROM reserva_itens WHERE reserva_id = :id', ['id' => $id]);
        }
        foreach ($itens as $pid => $qtd) {
            q('INSERT INTO reserva_itens (reserva_id, produto_id, quantidade) VALUES (:r, :p, :q)',
              ['r' => $id, 'p' => $pid, 'q' => $qtd]);
        }
        return ['id' => $id, 'numero' => $numero];
    });
}

function reserva_cancelar(string $id): void
{
    $st = val('SELECT status FROM reservas WHERE id = :id', ['id' => $id]);
    if ($st === null) {
        throw new UserError('Reserva não encontrada.');
    }
    if ($st !== 'ativa') {
        throw new UserError('Só é possível cancelar reserva ativa.');
    }
    q("UPDATE reservas SET status = 'cancelada' WHERE id = :id", ['id' => $id]);
}

// =====================================================================
// FLUXO DE CAIXA
// =====================================================================

/** Lançamento manual (despesa, outra receita). Os de locação são feitos pelas telas de locação. */
function lancamento_registrar(array $d): void
{
    $data = (string) ($d['data'] ?? '');
    $tipo = (string) ($d['tipo'] ?? '');
    $categoria = trim((string) ($d['categoria'] ?? ''));
    $descricao = trim((string) ($d['descricao'] ?? ''));
    $valor = parse_money($d['valor'] ?? '');
    $erros = [];
    if (!valid_date($data) || $data > today()) {
        $erros[] = 'Informe a data (até hoje).';
    }
    if (!in_array($tipo, ['E', 'S'], true)) {
        $erros[] = 'Escolha entrada ou saída.';
    }
    if ($categoria === '' || mb_strlen($categoria) > 60) {
        $erros[] = 'Informe a categoria (até 60 caracteres).';
    }
    if ($descricao === '' || mb_strlen($descricao) > 200) {
        $erros[] = 'Informe a descrição (até 200 caracteres).';
    }
    if ($valor <= 0) {
        $erros[] = 'O valor deve ser maior que zero.';
    }
    if ($erros) {
        throw new UserError(implode(' ', $erros));
    }
    q('INSERT INTO caixa (data, tipo, categoria, descricao, valor, forma, usuario_id)
       VALUES (:d, :t, :c, :ds, :v, :f, :u)',
      ['d' => $data, 't' => $tipo, 'c' => $categoria, 'ds' => $descricao, 'v' => $valor,
       'f' => forma_valida((string) ($d['forma'] ?? '')), 'u' => uid()]);
}

/** Só o admin exclui, e nunca lançamentos ligados a uma locação (esses se corrigem pela locação). */
function lancamento_excluir(string $id): void
{
    require_admin();
    $l = one('SELECT locacao_id FROM caixa WHERE id = :id', ['id' => $id]);
    if ($l === null) {
        throw new UserError('Lançamento não encontrado.');
    }
    if ($l['locacao_id'] !== null) {
        throw new UserError('Lançamentos de locação não podem ser excluídos. Use o estorno (cancelamento) na própria locação.');
    }
    if (val('SELECT 1 FROM contas_pagar WHERE caixa_id = :id', ['id' => $id]) !== null) {
        throw new UserError('Este lançamento é o pagamento de uma conta: use "Desfazer pagamento" em Contas a pagar.');
    }
    q('DELETE FROM caixa WHERE id = :id', ['id' => $id]);
}

// =====================================================================
// CONTAS A PAGAR E RECORRENTES
// =====================================================================

const CONTAS_HORIZONTE_DIAS = 90;   // até onde as recorrentes geram parcelas (e a previsão de caixa olha)

/** Campos comuns de conta/recorrente validados. */
function conta_campos(array $d): array
{
    $c = [
        'descricao' => trim((string) ($d['descricao'] ?? '')),
        'categoria' => trim((string) ($d['categoria'] ?? '')),
        'valor'     => parse_money($d['valor'] ?? ''),
    ];
    if (mb_strlen($c['descricao']) < 3 || mb_strlen($c['descricao']) > 200) {
        throw new UserError('Informe a descrição (3 a 200 caracteres).');
    }
    if ($c['categoria'] === '' || mb_strlen($c['categoria']) > 60) {
        throw new UserError('Informe a categoria (até 60 caracteres).');
    }
    if ($c['valor'] <= 0 || $c['valor'] > 9999999999) {
        throw new UserError('O valor deve ser maior que zero.');
    }
    return $c;
}

/**
 * Gera as parcelas das contas recorrentes ativas até CONTAS_HORIZONTE_DIAS à frente.
 * Continua de onde parou (gerado_ate): uma parcela excluída não volta.
 */
function contas_gerar_recorrentes(): void
{
    tx(function () {
        $limite = date('Y-m-d', strtotime('+' . CONTAS_HORIZONTE_DIAS . ' days'));
        foreach (all('SELECT * FROM contas_recorrentes WHERE ativa FOR UPDATE') as $r) {
            // Primeiro mês a gerar: o seguinte ao último gerado, ou o do início
            $mes = $r['gerado_ate'] !== null ? date('Y-m-01', strtotime($r['gerado_ate'] . ' first day of next month'))
                                             : date('Y-m-01', strtotime($r['inicio']));
            $ultimo = $r['gerado_ate'];
            // Primeira geração (ou refeita) não cria parcelas já vencidas; depois segue mês a mês, sem pular
            $piso = $r['gerado_ate'] === null ? max($r['inicio'], today()) : $r['inicio'];
            while (true) {
                $venc = date('Y-m-', strtotime($mes)) . sprintf('%02d', $r['dia']);
                if ($venc > $limite) {
                    break;
                }
                if ($venc >= $piso) {
                    q('INSERT INTO contas_pagar (descricao, categoria, valor, vencimento, recorrente_id)
                       VALUES (:d, :c, :v, :venc, :r) ON CONFLICT (tenant_id, recorrente_id, vencimento) DO NOTHING',
                      ['d' => $r['descricao'], 'c' => $r['categoria'], 'v' => $r['valor'], 'venc' => $venc, 'r' => $r['id']]);
                    $ultimo = $venc;
                }
                $mes = date('Y-m-01', strtotime($mes . ' +1 month'));
            }
            if ($ultimo !== $r['gerado_ate']) {
                q('UPDATE contas_recorrentes SET gerado_ate = :g WHERE id = :id', ['g' => $ultimo, 'id' => $r['id']]);
            }
        }
    });
}

/** Conta avulsa. Devolve o id. */
function conta_salvar(array $d): string
{
    $c = conta_campos($d);
    $venc = (string) ($d['vencimento'] ?? '');
    if (!valid_date($venc)) {
        throw new UserError('Informe o vencimento.');
    }
    return (string) val('INSERT INTO contas_pagar (descricao, categoria, valor, vencimento, usuario_id)
                         VALUES (:descricao, :categoria, :valor, :v, :u) RETURNING id', $c + ['v' => $venc, 'u' => uid()]);
}

/**
 * Conta recorrente (só admin). Alterar o modelo atualiza as parcelas futuras ainda em aberto;
 * desativar remove as parcelas futuras em aberto. Devolve o id.
 */
function recorrente_salvar(array $d, ?string $id = null): string
{
    require_admin();
    $c = conta_campos($d);
    $dia = filter_var($d['dia'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 28]]);
    $inicio = (string) ($d['inicio'] ?? '');
    $ativa = !empty($d['ativa']);
    if ($dia === false) {
        throw new UserError('O dia do vencimento deve ser de 1 a 28 (vale para todos os meses).');
    }
    if (!valid_date($inicio)) {
        throw new UserError('Informe a partir de quando a conta vence.');
    }
    $id = tx(function () use ($c, $dia, $inicio, $ativa, $id) {
        if ($id === null) {
            return (string) val('INSERT INTO contas_recorrentes (descricao, categoria, valor, dia, inicio)
                                 VALUES (:descricao, :categoria, :valor, :dia, :inicio) RETURNING id',
                                $c + ['dia' => $dia, 'inicio' => $inicio]);
        }
        $antes = one('SELECT dia, inicio FROM contas_recorrentes WHERE id = :id FOR UPDATE', ['id' => $id]);
        if ($antes === null) {
            throw new UserError('Conta recorrente não encontrada.');
        }
        // Mudou o dia ou o início, ou foi desativada: as parcelas futuras em aberto são refeitas (ou somem)
        $refazer = !$ativa || (int) $antes['dia'] !== $dia || $antes['inicio'] !== $inicio;
        q('UPDATE contas_recorrentes SET descricao = :descricao, categoria = :categoria, valor = :valor, dia = :dia,
                  inicio = :inicio, ativa = :ativa' . ($refazer ? ', gerado_ate = NULL' : '') . ' WHERE id = :id',
          $c + ['dia' => $dia, 'inicio' => $inicio, 'ativa' => $ativa ? 'true' : 'false', 'id' => $id]);
        if ($refazer) {
            q('DELETE FROM contas_pagar WHERE recorrente_id = :id AND pago_em IS NULL AND vencimento >= CURRENT_DATE', ['id' => $id]);
            // Não regera o que já passou: continua do último vencimento mantido
            q('UPDATE contas_recorrentes SET gerado_ate = (SELECT max(vencimento) FROM contas_pagar WHERE recorrente_id = :id) WHERE id = :id2',
              ['id' => $id, 'id2' => $id]);
        } else {
            q('UPDATE contas_pagar SET descricao = :descricao, categoria = :categoria, valor = :valor
                WHERE recorrente_id = :id AND pago_em IS NULL AND vencimento >= CURRENT_DATE', $c + ['id' => $id]);
        }
        return $id;
    });
    contas_gerar_recorrentes();
    return $id;
}

/** Paga a conta: lança a saída no caixa (com o valor pago) e marca como paga. */
function conta_pagar(string $id, array $d): void
{
    $data = (string) ($d['data'] ?? '');
    $valor = ($d['valor'] ?? '') === '' ? null : parse_money($d['valor']);
    $forma = forma_valida((string) ($d['forma'] ?? ''));
    if (!valid_date($data) || $data > today()) {
        throw new UserError('Informe a data do pagamento (até hoje).');
    }
    if ($valor !== null && ($valor <= 0 || $valor > 9999999999)) {
        throw new UserError('O valor pago deve ser maior que zero.');
    }
    tx(function () use ($id, $data, $valor, $forma) {
        $c = one('SELECT * FROM contas_pagar WHERE id = :id FOR UPDATE', ['id' => $id]);
        if ($c === null) {
            throw new UserError('Conta não encontrada.');
        }
        if ($c['pago_em'] !== null) {
            throw new UserError('Esta conta já foi paga.');
        }
        $valor ??= (float) $c['valor'];
        $caixa = (string) val("INSERT INTO caixa (data, tipo, categoria, descricao, valor, forma, usuario_id)
                               VALUES (:d, 'S', :c, :ds, :v, :f, :u) RETURNING id",
            ['d' => $data, 'c' => $c['categoria'], 'ds' => $c['descricao'], 'v' => $valor, 'f' => $forma, 'u' => uid()]);
        q('UPDATE contas_pagar SET pago_em = :d, valor = :v, caixa_id = :cx WHERE id = :id',
          ['d' => $data, 'v' => $valor, 'cx' => $caixa, 'id' => $id]);
    });
}

/** Desfaz o pagamento (só admin): apaga a saída do caixa e a conta volta a ficar em aberto. */
function conta_desfazer_pagamento(string $id): void
{
    require_admin();
    tx(function () use ($id) {
        $c = one('SELECT caixa_id FROM contas_pagar WHERE id = :id AND pago_em IS NOT NULL FOR UPDATE', ['id' => $id]);
        if ($c === null) {
            throw new UserError('Conta paga não encontrada.');
        }
        q('UPDATE contas_pagar SET pago_em = NULL, caixa_id = NULL WHERE id = :id', ['id' => $id]);
        q('DELETE FROM caixa WHERE id = :id', ['id' => $c['caixa_id']]);
    });
}

/**
 * Previsão de caixa para os próximos $dias: saldo de hoje + o que entra (saldos das locações, reservas
 * estimadas) − o que sai (contas em aberto), semana a semana. Vencidos entram na primeira semana.
 */
function previsao_caixa(int $dias): array
{
    contas_gerar_recorrentes();
    $hoje = today();
    $limite = date('Y-m-d', strtotime("+$dias days"));
    $saldo = (float) val("SELECT COALESCE(SUM(CASE WHEN tipo = 'E' THEN valor ELSE -valor END), 0) FROM caixa WHERE data <= CURRENT_DATE");

    $itens = [];
    // A receber: locações abertas (no fim previsto) e devolvidas com saldo (já vencido)
    foreach (all("SELECT l.numero, c.nome, l.status, l.data_prev_devolucao,
                         GREATEST(v.valor_previsto, v.valor_atual) - v.valor_pago AS aberta_saldo, v.valor_atual - v.valor_pago AS saldo
                    FROM locacoes l JOIN clientes c ON c.id = l.cliente_id JOIN vw_locacao_valores v ON v.locacao_id = l.id
                   WHERE l.status <> 'cancelada'
                     AND (CASE WHEN l.status = 'aberta' THEN GREATEST(v.valor_previsto, v.valor_atual) ELSE v.valor_atual END) - v.valor_pago > 0.004") as $l) {
        $aberta = $l['status'] === 'aberta';
        $data = $aberta ? $l['data_prev_devolucao'] : $hoje;
        if ($data > $limite) {
            continue;
        }
        $itens[] = ['tipo' => 'E', 'data' => max($data, $hoje), 'vencido' => $data < $hoje || !$aberta,
                    'valor' => (float) ($aberta ? $l['aberta_saldo'] : $l['saldo']),
                    'descricao' => "Locação nº {$l['numero']} - {$l['nome']}", 'provavel' => false];
    }
    // Reservas ativas que terminam no horizonte: valor estimado pelos preços atuais
    foreach (all("SELECT r.numero, c.nome, r.data_inicio, r.data_fim, ri.quantidade, p.valor_diaria, p.valor_semanal, p.valor_mensal
                    FROM reservas r JOIN clientes c ON c.id = r.cliente_id
                    JOIN reserva_itens ri ON ri.reserva_id = r.id JOIN produtos p ON p.id = ri.produto_id
                   WHERE r.status = 'ativa' AND r.data_fim BETWEEN CURRENT_DATE AND CAST(:l AS date)", ['l' => $limite]) as $r) {
        $chave = "R{$r['numero']}";
        $valor = $r['quantidade'] * preco_item($r, max(1, days_between($r['data_inicio'], $r['data_fim'])));
        if (isset($itens[$chave])) {
            $itens[$chave]['valor'] += $valor;
        } else {
            $itens[$chave] = ['tipo' => 'E', 'data' => $r['data_fim'], 'vencido' => false, 'valor' => $valor,
                              'descricao' => "Reserva nº {$r['numero']} - {$r['nome']} (estimado)", 'provavel' => true];
        }
    }
    // A pagar: contas em aberto até o fim do horizonte
    foreach (all('SELECT descricao, vencimento, valor FROM contas_pagar WHERE pago_em IS NULL AND vencimento <= CAST(:l AS date)',
                 ['l' => $limite]) as $c) {
        $itens[] = ['tipo' => 'S', 'data' => max($c['vencimento'], $hoje), 'vencido' => $c['vencimento'] < $hoje,
                    'valor' => (float) $c['valor'], 'descricao' => $c['descricao'], 'provavel' => false];
    }
    $itens = array_values($itens);
    usort($itens, fn($a, $b) => [$a['data'], $a['tipo']] <=> [$b['data'], $b['tipo']]);

    // Semanas a partir de hoje
    $semanas = [];
    $acumulado = $saldo;
    for ($i = 0; $i * 7 < $dias; $i++) {
        $ini = date('Y-m-d', strtotime("+" . ($i * 7) . ' days'));
        $fim = min(date('Y-m-d', strtotime('+' . ($i * 7 + 6) . ' days')), $limite);
        $doPeriodo = array_filter($itens, fn($x) => $x['data'] >= $ini && $x['data'] <= $fim);
        $entra = array_sum(array_map(fn($x) => $x['tipo'] === 'E' ? $x['valor'] : 0, $doPeriodo));
        $sai = array_sum(array_map(fn($x) => $x['tipo'] === 'S' ? $x['valor'] : 0, $doPeriodo));
        $acumulado += $entra - $sai;
        $semanas[] = ['inicio' => $ini, 'fim' => $fim, 'entradas' => round($entra, 2), 'saidas' => round($sai, 2), 'saldo' => round($acumulado, 2)];
    }
    $vencido = fn(string $t) => round(array_sum(array_map(fn($x) => $x['tipo'] === $t && $x['vencido'] ? $x['valor'] : 0, $itens)), 2);
    return ['saldo' => round($saldo, 2), 'semanas' => $semanas, 'itens' => $itens,
            'receber_vencido' => $vencido('E'), 'pagar_vencido' => $vencido('S')];
}

// =====================================================================
// CONFIGURAÇÕES: empresa, usuários e senha
// =====================================================================

function empresa_salvar(array $d): void
{
    require_admin();
    $cnpj = so_digitos($d['cnpj'] ?? '');
    $e = [
        'razao_social'    => trim((string) ($d['razao_social'] ?? '')),
        'nome_fantasia'   => trim((string) ($d['nome_fantasia'] ?? '')),
        'cnpj'            => $cnpj,
        'ie'              => trim((string) ($d['ie'] ?? '')),
        'telefone'        => telefone_formatar($d['telefone'] ?? ''),
        'email'           => trim((string) ($d['email'] ?? '')),
        'endereco'        => trim((string) ($d['endereco'] ?? '')),
        'cidade'          => trim((string) ($d['cidade'] ?? '')),
        'uf'              => strtoupper(trim((string) ($d['uf'] ?? ''))),
        'cep'             => trim((string) ($d['cep'] ?? '')),
        // O navegador envia as quebras de linha do <textarea> como \r\n: guarda só \n
        'termos_contrato' => trim(str_replace(["\r\n", "\r"], "\n", (string) ($d['termos_contrato'] ?? ''))),
        'obs_fatura'      => trim(str_replace(["\r\n", "\r"], "\n", (string) ($d['obs_fatura'] ?? ''))),
        'pix_chave'       => trim((string) ($d['pix_chave'] ?? '')),
        'pix_nome'        => pix_texto((string) ($d['pix_nome'] ?? ''), 25),
        'pix_cidade'      => pix_texto((string) ($d['pix_cidade'] ?? ''), 15),
    ];
    $erros = [];
    if (mb_strlen($e['razao_social']) < 3 || mb_strlen($e['razao_social']) > 150) {
        $erros[] = 'Informe a razão social.';
    }
    if ($cnpj !== '' && (strlen($cnpj) !== 14 || !doc_valido($cnpj))) {
        $erros[] = 'CNPJ inválido.';
    }
    if ($e['email'] !== '' && filter_var($e['email'], FILTER_VALIDATE_EMAIL) === false) {
        $erros[] = 'E-mail inválido.';
    }
    if ($e['uf'] !== '' && !in_array($e['uf'], UFS, true)) {
        $erros[] = 'UF inválida.';
    }
    foreach (['nome_fantasia' => 150, 'ie' => 20, 'endereco' => 200, 'cidade' => 80, 'cep' => 9] as $k => $max) {
        if (mb_strlen($e[$k]) > $max) {
            $erros[] = "O campo $k aceita no máximo $max caracteres.";
        }
    }
    if ($e['termos_contrato'] === '') {
        $erros[] = 'O texto do contrato não pode ficar vazio.';
    }
    if ($e['pix_chave'] !== '') {
        $chave = pix_chave_normalizar($e['pix_chave']);
        if ($chave === null) {
            $erros[] = 'Chave PIX inválida (use CPF, CNPJ, e-mail, celular com DDD ou a chave aleatória).';
        } else {
            $e['pix_chave'] = $chave;
            // Sem nome/cidade próprios, usa os da empresa (o banco exige os dois)
            $e['pix_nome'] = $e['pix_nome'] ?: pix_texto($e['nome_fantasia'] ?: $e['razao_social'], 25);
            $e['pix_cidade'] = $e['pix_cidade'] ?: pix_texto($e['cidade'], 15);
            if ($e['pix_cidade'] === '') {
                $erros[] = 'Informe a cidade do recebedor PIX (ou a cidade da empresa).';
            }
        }
    }
    if ($erros) {
        throw new UserError(implode(' ', $erros));
    }
    $sets = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($e)));
    q("UPDATE empresa SET $sets", $e); // sem WHERE de propósito: o RLS restringe à linha da locadora logada
}

/**
 * Valida e padroniza a chave PIX como o banco espera; null se inválida.
 * CPF/CNPJ: só dígitos (com dígitos verificadores válidos); celular: +55DDDNÚMERO;
 * e-mail e chave aleatória: em minúsculas.
 */
function pix_chave_normalizar(string $chave): ?string
{
    $chave = trim($chave);
    if (is_uuid($chave)) {
        return strtolower($chave);
    }
    if (str_contains($chave, '@')) {
        return filter_var($chave, FILTER_VALIDATE_EMAIL) && strlen($chave) <= 77 ? strtolower($chave) : null;
    }
    $d = so_digitos($chave);
    if (str_starts_with($chave, '+')) {
        return preg_match('/^55\d{10,11}$/', $d) ? '+' . $d : null;
    }
    if ((strlen($d) === 11 || strlen($d) === 14) && doc_valido($d)) {
        return $d;
    }
    // 10 ou 11 dígitos que não são CPF: celular/telefone sem o +55
    return (strlen($d) === 10 || strlen($d) === 11) ? '+55' . $d : null;
}

/** PIX do saldo da locação: ['payload' => copia e cola, 'valor' => saldo]; null sem saldo ou sem chave. */
function locacao_pix(array $loc): ?array
{
    $emp = empresa();
    $saldo = round($loc['status'] === 'cancelada' ? 0 : $loc['valor_atual'] - $loc['valor_pago'], 2);
    if (($emp['pix_chave'] ?? '') === '' || $saldo <= 0) {
        return null;
    }
    return [
        'valor'   => $saldo,
        'payload' => pix_payload($emp['pix_chave'], $emp['pix_nome'], $emp['pix_cidade'], $saldo, 'LOC' . $loc['numero']),
    ];
}

/** Respeita tenants.limite_usuarios, contando só os usuários ativos. */
function verificar_limite_usuarios(?string $ignorarId = null): void
{
    $limite = (int) tenant()['limite_usuarios'];
    $ativos = (int) val('SELECT count(*) FROM usuarios WHERE ativo AND id IS DISTINCT FROM CAST(:id AS uuid)', ['id' => $ignorarId]);
    if ($ativos >= $limite) {
        throw new UserError("Seu plano permite até $limite usuário(s) ativo(s). Desative um usuário ou fale com o suporte para ampliar o plano.");
    }
}

/**
 * Cria ou altera um usuário da locadora (só admin).
 * Ninguém pode desativar nem rebaixar a si mesmo. Senha mínima de 8 caracteres.
 */
function usuario_salvar(array $d, ?string $id = null): void
{
    require_admin();
    $nome = trim((string) ($d['nome'] ?? ''));
    $login = strtolower(trim((string) ($d['login'] ?? '')));
    $perfil = ($d['perfil'] ?? '') === 'admin' ? 'admin' : 'operador';
    $ativo = !empty($d['ativo']);
    $senha = (string) ($d['senha'] ?? '');

    $erros = [];
    if ($nome === '' || mb_strlen($nome) > 100) {
        $erros[] = 'Informe o nome.';
    }
    if (!preg_match('/^[a-z0-9._-]{3,40}$/', $login)) {
        $erros[] = 'Login inválido (3 a 40 letras minúsculas, números, ponto, hífen ou sublinhado).';
    }
    if (($id === null || $senha !== '') && mb_strlen($senha) < 8) {
        $erros[] = 'A senha deve ter pelo menos 8 caracteres.';
    }
    if ($id !== null && $id === uid() && (!$ativo || $perfil !== 'admin')) {
        $erros[] = 'Você não pode desativar nem rebaixar o seu próprio usuário.';
    }
    if ($erros) {
        throw new UserError(implode(' ', $erros));
    }

    tx(function () use ($id, $nome, $login, $perfil, $ativo, $senha) {
        // Trava os usuários desta locadora para a contagem do limite não mudar no meio
        q('SELECT id FROM usuarios FOR UPDATE');
        if ($id === null) {
            if ($ativo) {
                verificar_limite_usuarios();
            }
            // A senha foi definida pelo admin: o novo usuário troca no primeiro acesso
            q('INSERT INTO usuarios (nome, login, senha_hash, perfil, ativo, trocar_senha) VALUES (:n, :l, :h, :p, :a, true)',
              ['n' => $nome, 'l' => $login, 'h' => password_hash($senha, PASSWORD_DEFAULT), 'p' => $perfil,
               'a' => $ativo ? 'true' : 'false']);
            return;
        }
        $atual = one('SELECT ativo FROM usuarios WHERE id = :id', ['id' => $id]);
        if ($atual === null) {
            throw new UserError('Usuário não encontrado.');
        }
        if ($ativo && !$atual['ativo']) {
            verificar_limite_usuarios($id);
        }
        q('UPDATE usuarios SET nome = :n, login = :l, perfil = :p, ativo = :a WHERE id = :id',
          ['n' => $nome, 'l' => $login, 'p' => $perfil, 'a' => $ativo ? 'true' : 'false', 'id' => $id]);
        if ($senha !== '') {
            // Senha redefinida pelo admin para outro usuário vale só até o próximo acesso dele
            q('UPDATE usuarios SET senha_hash = :h, trocar_senha = :t WHERE id = :id',
              ['h' => password_hash($senha, PASSWORD_DEFAULT), 't' => $id !== uid() ? 'true' : 'false', 'id' => $id]);
        }
    });
}

function senha_trocar(string $atual, string $nova, string $confirma): void
{
    $hash = val('SELECT senha_hash FROM usuarios WHERE id = :id', ['id' => uid()]);
    if ($hash === null || !password_verify($atual, $hash)) {
        usleep(500000);
        throw new UserError('A senha atual não confere.');
    }
    if (mb_strlen($nova) < 8) {
        throw new UserError('A nova senha deve ter pelo menos 8 caracteres.');
    }
    if ($nova !== $confirma) {
        throw new UserError('A confirmação não é igual à nova senha.');
    }
    if ($nova === $atual) {
        throw new UserError('A nova senha deve ser diferente da atual.');
    }
    q('UPDATE usuarios SET senha_hash = :h, trocar_senha = false WHERE id = :id',
      ['h' => password_hash($nova, PASSWORD_DEFAULT), 'id' => uid()]);
    $_SESSION['usuario']['trocar_senha'] = false;
}

// =====================================================================
// WHATSAPP (links wa.me: abrem o WhatsApp do atendente com a mensagem pronta;
// nada é enviado pelo servidor e o número não passa pelos logs do sistema)
// =====================================================================

/** Número no formato do wa.me (55 + DDD + número), a partir do celular ou do telefone. null se não houver. */
function whatsapp_numero(array $cliente): ?string
{
    foreach ([$cliente['celular'] ?? '', $cliente['telefone'] ?? ''] as $tel) {
        $d = ltrim(so_digitos($tel), '0');
        if (strlen($d) === 10 || strlen($d) === 11) {
            return '55' . $d;
        }
        if ((strlen($d) === 12 || strlen($d) === 13) && str_starts_with($d, '55')) {
            return $d;
        }
    }
    return null;
}

function whatsapp_url(?string $numero, string $mensagem): ?string
{
    return $numero === null ? null : 'https://wa.me/' . $numero . '?text=' . rawurlencode($mensagem);
}

/** Como chamar o cliente: primeiro nome (PF) ou a razão social (PJ). */
function whatsapp_saudacao(array $cliente): string
{
    $nome = trim($cliente['nome'] ?? '');
    $chamar = ($cliente['tipo'] ?? 'PF') === 'PJ' ? $nome : (explode(' ', $nome)[0] ?? $nome);
    $loja = empresa()['nome_fantasia'] ?: empresa()['razao_social'];
    return "Olá, $chamar! Aqui é da *$loja*.";
}

/** "2 Betoneira 400 L, 5 Carrinho de mão 60 L" (só os pendentes, se $pendentes). */
function whatsapp_itens(string $locacaoId, bool $pendentes = false): string
{
    $cond = $pendentes ? 'AND li.quantidade > li.qtd_devolvida' : '';
    $qtd = $pendentes ? 'li.quantidade - li.qtd_devolvida' : 'li.quantidade';
    return (string) val("SELECT string_agg(($qtd) || ' ' || p.descricao, ', ' ORDER BY p.descricao)
                           FROM locacao_itens li JOIN produtos p ON p.id = li.produto_id
                          WHERE li.locacao_id = :id $cond", ['id' => $locacaoId]);
}

/** Mensagens prontas para uma locação: resumo, lembrete de devolução e cobrança do saldo. */
function whatsapp_locacao(array $loc): array
{
    $cli = one('SELECT nome, tipo, celular, telefone FROM clientes WHERE id = :id', ['id' => $loc['cliente_id']]);
    $num = whatsapp_numero($cli);
    $oi = whatsapp_saudacao($cli);
    $n = $loc['numero'];
    $saldo = $loc['status'] === 'cancelada' ? 0 : $loc['valor_atual'] - $loc['valor_pago'];
    $msgs = [];

    $msgs['resumo'] = whatsapp_url($num, "$oi\n\nResumo da sua *locação nº $n*:\n"
        . '• Itens: ' . whatsapp_itens($loc['id']) . "\n"
        . '• Saída: ' . dt($loc['data_saida']) . "\n"
        . '• Devolução prevista: ' . dt($loc['data_prev_devolucao']) . "\n"
        . '• Valor previsto: ' . money($loc['valor_previsto'])
        . ($loc['valor_pago'] > 0 ? "\n• Pago: " . money($loc['valor_pago']) : '')
        . "\n\nQualquer dúvida, estamos à disposição.");

    if ($loc['status'] === 'aberta') {
        $prev = $loc['data_prev_devolucao'];
        $atraso = days_between($prev, today());
        $quando = match (true) {
            $atraso > 0  => 'está *atrasada* desde ' . dt($prev) . " ($atraso dia(s)). As diárias continuam sendo cobradas até a devolução.",
            $atraso === 0 => 'está prevista para *hoje*, ' . dt($prev) . '.',
            $atraso === -1 => 'está prevista para *amanhã*, ' . dt($prev) . '.',
            default      => 'está prevista para ' . dt($prev) . '.',
        };
        $msgs['lembrete'] = whatsapp_url($num, "$oi\n\nLembrete: a devolução da *locação nº $n* "
            . '(' . whatsapp_itens($loc['id'], true) . ") $quando\n\n"
            . 'Se precisar de mais dias, fale com a gente para prorrogar.');
    }
    if ($saldo > 0.004) {
        $pix = locacao_pix($loc);
        $msgs['saldo'] = whatsapp_url($num, "$oi\n\nConsta um saldo de *" . money($saldo) . "* da *locação nº $n* "
            . '(total ' . money($loc['valor_atual']) . ', pago ' . money($loc['valor_pago']) . ").\n\n"
            . ($pix !== null
                ? "Para pagar por PIX, copie o código abaixo e cole na opção *PIX Copia e Cola* do app do seu banco:\n\n{$pix['payload']}\n\nObrigado!"
                : 'Formas de pagamento: ' . implode(', ', FORMAS_PGTO) . '. Obrigado!'));
    }
    return ['numero' => $num, 'links' => $msgs];
}

/** Confirmação de reserva. */
function whatsapp_reserva(array $r): ?string
{
    $cli = one('SELECT nome, tipo, celular, telefone FROM clientes WHERE id = :id', ['id' => $r['cliente_id']]);
    $itens = (string) val("SELECT string_agg(ri.quantidade || ' ' || p.descricao, ', ' ORDER BY p.descricao)
                             FROM reserva_itens ri JOIN produtos p ON p.id = ri.produto_id WHERE ri.reserva_id = :id",
                          ['id' => $r['id']]);
    return whatsapp_url(whatsapp_numero($cli), whatsapp_saudacao($cli)
        . "\n\nSua *reserva nº {$r['numero']}* está confirmada:\n• Itens: $itens\n"
        . '• Retirada: ' . dt($r['data_inicio']) . "\n• Devolução: " . dt($r['data_fim'])
        . "\n\nAté lá!");
}

// =====================================================================
// VISTORIAS (checklist de retirada/devolução com fotos e assinatura)
// =====================================================================

const ESTADOS_VISTORIA = ['ok' => 'OK', 'avariado' => 'Avariado', 'sujo' => 'Sujo', 'incompleto' => 'Incompleto'];

/**
 * Registra a vistoria. $d: estado[locacao_item_id], obs_item[locacao_item_id], obs,
 * assinante_nome, assinatura (data:image/png;base64,...). Fotos vêm de $_FILES['fotos'].
 * Retirada: uma por locação. Devolução: quantas forem precisas (devoluções parciais).
 * Vistoria é prova: depois de gravada não se altera nem se apaga.
 */
function vistoria_registrar(string $locacaoId, string $tipo, array $d): string
{
    if (!in_array($tipo, ['retirada', 'devolucao'], true)) {
        throw new UserError('Tipo de vistoria inválido.');
    }
    $loc = one('SELECT id, numero, status FROM locacoes WHERE id = :id', ['id' => $locacaoId]);
    if ($loc === null) {
        throw new UserError('Locação não encontrada.');
    }
    if ($loc['status'] === 'cancelada') {
        throw new UserError('Locação cancelada não recebe vistoria.');
    }
    if ($tipo === 'retirada' && val("SELECT 1 FROM vistorias WHERE locacao_id = :id AND tipo = 'retirada'", ['id' => $locacaoId])) {
        throw new UserError('Esta locação já tem vistoria de retirada.');
    }

    // Estado de cada item (todos os itens da locação precisam ser avaliados)
    $itens = all('SELECT li.id, p.descricao FROM locacao_itens li JOIN produtos p ON p.id = li.produto_id WHERE li.locacao_id = :id',
                 ['id' => $locacaoId]);
    $estados = (array) ($d['estado'] ?? []);
    $obsItens = (array) ($d['obs_item'] ?? []);
    $avaliados = [];
    foreach ($itens as $it) {
        $estado = (string) ($estados[$it['id']] ?? '');
        if (!isset(ESTADOS_VISTORIA[$estado])) {
            throw new UserError("Informe o estado de {$it['descricao']}.");
        }
        $avaliados[] = ['id' => $it['id'], 'estado' => $estado, 'obs' => mb_substr(trim((string) ($obsItens[$it['id']] ?? '')), 0, 200)];
    }
    $obs = mb_substr(trim((string) ($d['obs'] ?? '')), 0, 2000);

    // Assinatura (opcional): exige o nome de quem assinou
    $assinante = mb_substr(trim((string) ($d['assinante_nome'] ?? '')), 0, 100);
    $assinaturaBytes = null;
    $dataUrl = (string) ($d['assinatura'] ?? '');
    if ($dataUrl !== '') {
        if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $m) || strlen($m[1]) > 700000) {
            throw new UserError('Assinatura inválida. Limpe e assine de novo.');
        }
        $img = imagem_regravar((string) base64_decode($m[1], true), 900, true);
        if (assinatura_vazia($img['gd'])) {
            throw new UserError('A assinatura está em branco. Peça para o cliente assinar na área indicada.');
        }
        if (mb_strlen($assinante) < 3) {
            throw new UserError('Informe o nome de quem assinou.');
        }
        $assinaturaBytes = $img['bytes'];
    }

    $fotos = uploads('fotos');
    if (count($fotos) > FOTOS_POR_VISTORIA) {
        throw new UserError('Envie no máximo ' . FOTOS_POR_VISTORIA . ' fotos por vistoria.');
    }

    // Arquivos primeiro; se o banco falhar, eles são apagados (nada fica órfão no disco)
    $gravados = [];
    try {
        $registrosFotos = [];
        foreach ($fotos as $f) {
            if ($f['erro'] === UPLOAD_ERR_INI_SIZE || $f['erro'] === UPLOAD_ERR_FORM_SIZE || $f['tamanho'] > FOTO_BYTES_MAX) {
                throw new UserError("A foto {$f['nome']} é grande demais (máximo de 15 MB).");
            }
            if ($f['erro'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp'])) {
                throw new UserError("Não foi possível receber a foto {$f['nome']}. Tente de novo.");
            }
            $img = imagem_regravar((string) file_get_contents($f['tmp']), FOTO_LADO_MAX);
            $rel = arquivo_gravar($img['bytes'], 'jpg');
            $gravados[] = $rel;
            $registrosFotos[] = ['arquivo' => $rel, 'tamanho' => strlen($img['bytes']), 'largura' => $img['largura'], 'altura' => $img['altura']];
        }
        $relAssinatura = '';
        if ($assinaturaBytes !== null) {
            $relAssinatura = arquivo_gravar($assinaturaBytes, 'png');
            $gravados[] = $relAssinatura;
        }

        return tx(function () use ($locacaoId, $tipo, $obs, $assinante, $relAssinatura, $avaliados, $registrosFotos) {
            $id = (string) val('INSERT INTO vistorias (locacao_id, tipo, obs, assinante_nome, assinatura_arquivo, assinado_em, usuario_id)
                                VALUES (:l, :t, :o, :n, :a, CASE WHEN :a2 = \'\' THEN NULL ELSE now() END, :u) RETURNING id',
                ['l' => $locacaoId, 't' => $tipo, 'o' => $obs, 'n' => $relAssinatura !== '' ? $assinante : '',
                 'a' => $relAssinatura, 'a2' => $relAssinatura, 'u' => uid()]);
            foreach ($avaliados as $it) {
                q('INSERT INTO vistoria_itens (vistoria_id, locacao_item_id, estado, obs) VALUES (:v, :i, :e, :o)',
                  ['v' => $id, 'i' => $it['id'], 'e' => $it['estado'], 'o' => $it['obs']]);
            }
            foreach ($registrosFotos as $f) {
                q('INSERT INTO vistoria_fotos (vistoria_id, arquivo, tamanho, largura, altura) VALUES (:v, :a, :t, :w, :h)',
                  ['v' => $id, 'a' => $f['arquivo'], 't' => $f['tamanho'], 'w' => $f['largura'], 'h' => $f['altura']]);
            }
            return $id;
        });
    } catch (Throwable $e) {
        foreach ($gravados as $rel) {
            arquivo_apagar($rel);
        }
        throw $e;
    }
}
