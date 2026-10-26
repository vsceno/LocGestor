<?php
/*
 * Locações: lista, nova, detalhe, devolução, receber, prorrogar, cancelar, contrato e fatura.
 * As URLs usam o número da locação (?n=12), próprio de cada locadora; internamente, o id.
 */

declare(strict_types=1);

const ABAS_LOCACAO = [
    'abertas'    => 'Em aberto',
    'atrasadas'  => 'Atrasadas',
    'hoje'       => 'Vencem hoje',
    'devolvidas' => 'Devolvidas',
    'canceladas' => 'Canceladas',
    'todas'      => 'Todas',
];

const MESES = ['', 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto',
               'setembro', 'outubro', 'novembro', 'dezembro'];

/** Locação pela URL: ?n=<número> ou ?id=<uuid>. Outra locadora = "não encontrada" (RLS). */
function locacao_da_url(): array
{
    $n = get('n');
    $id = get('id');
    $loc = null;
    if ($n !== '' && ctype_digit($n) && strlen($n) < 10) {
        $id = val('SELECT id FROM locacoes WHERE numero = :n', ['n' => (int) $n]);
        $loc = $id ? locacao_buscar($id) : null;
    } elseif (is_uuid($id)) {
        $loc = locacao_buscar($id);
    }
    if ($loc === null) {
        pagina_erro(404, 'Locação não encontrada.');
    }
    return $loc;
}

function url_locacao(array $loc, string $acao = 'ver'): string
{
    return url('locacoes', ['a' => $acao, 'n' => $loc['numero']]);
}

/** Locação pelo id vindo de formulário (validado). */
function locacao_do_post(): array
{
    $id = post('id');
    $loc = is_uuid($id) ? locacao_buscar($id) : null;
    if ($loc === null) {
        throw new UserError('Locação não encontrada.');
    }
    return $loc;
}

// =====================================================================
// LISTA
// =====================================================================
function locacoes_index(): void
{
    $aba = get('aba', 'abertas');
    $aba = isset(ABAS_LOCACAO[$aba]) ? $aba : 'abertas';
    $busca = mb_substr(get('q'), 0, 100);

    $filtros = [
        'abertas'    => "l.status = 'aberta'",
        'atrasadas'  => "l.status = 'aberta' AND l.data_prev_devolucao < CURRENT_DATE",
        'hoje'       => "l.status = 'aberta' AND l.data_prev_devolucao = CURRENT_DATE",
        'devolvidas' => "l.status = 'devolvida'",
        'canceladas' => "l.status = 'cancelada'",
        'todas'      => 'TRUE',
    ];
    $contagem = one('SELECT ' . implode(', ', array_map(fn($k, $f) => "count(*) FILTER (WHERE $f) AS $k",
                                                           array_keys($filtros), $filtros)) . ' FROM locacoes l');

    $where = [$filtros[$aba]];
    $params = [];
    if ($busca !== '') {
        if (ctype_digit($busca) && strlen($busca) < 10) {
            $where[] = 'l.numero = :num';
            $params['num'] = (int) $busca;
        } else {
            $where[] = 'lower(c.nome) LIKE lower(:nome)';
            $params['nome'] = like($busca);
        }
    }
    $sqlWhere = implode(' AND ', $where);
    $ordem = in_array($aba, ['abertas', 'atrasadas', 'hoje'], true)
        ? 'l.data_prev_devolucao, l.numero' : 'l.data_saida DESC, l.numero DESC';

    $pg = paginate("SELECT count(*) FROM locacoes l JOIN clientes c ON c.id = l.cliente_id WHERE $sqlWhere", $params);
    $locs = all("SELECT l.id, l.numero, l.data_saida, l.data_prev_devolucao, l.status, c.nome AS cliente,
                        v.valor_atual, v.valor_pago,
                        (SELECT COALESCE(SUM(li.quantidade - li.qtd_devolvida), 0) FROM locacao_itens li WHERE li.locacao_id = l.id) AS pendentes
                   FROM locacoes l
                   JOIN clientes c ON c.id = l.cliente_id
                   JOIN vw_locacao_valores v ON v.locacao_id = l.id
                  WHERE $sqlWhere
                  ORDER BY $ordem
                  LIMIT {$pg['por']} OFFSET {$pg['offset']}", $params);

    layout_start('Locações', 'locacoes');
    ?>
    <div class="card">
        <div class="card-titulo">
            <h2>Locações</h2>
            <a class="btn" href="<?= e(url('locacoes', ['a' => 'nova'])) ?>">+ Nova locação</a>
        </div>
        <nav class="abas">
            <?php foreach (ABAS_LOCACAO as $k => $rot): ?>
                <a href="<?= e(url('locacoes', ['aba' => $k] + ($busca !== '' ? ['q' => $busca] : []))) ?>"<?= $k === $aba ? ' class="ativa"' : '' ?>>
                    <?= e($rot) ?> <span class="cont">(<?= num($contagem[$k]) ?>)</span></a>
            <?php endforeach; ?>
        </nav>
        <form method="get" class="filtros">
            <input type="hidden" name="p" value="locacoes">
            <input type="hidden" name="aba" value="<?= e($aba) ?>">
            <input type="search" name="q" value="<?= e($busca) ?>" placeholder="Nº da locação ou nome do cliente" aria-label="Buscar">
            <button class="btn btn-sec">Buscar</button>
        </form>
        <div class="tabela-wrap">
            <table class="tabela">
                <?php $comLembrete = in_array($aba, ['abertas', 'atrasadas', 'hoje'], true); ?>
                <thead><tr><th>Nº</th><th>Cliente</th><th>Saída</th><th>Devolução prevista</th><th>Situação</th>
                    <th class="num">Itens na rua</th><th class="num">Valor</th><th class="num">Pago</th><th class="num">Saldo</th>
                    <?php if ($comLembrete): ?><th></th><?php endif; ?></tr></thead>
                <tbody>
                <?php foreach ($locs as $l): $saldo = $l['status'] === 'cancelada' ? 0 : $l['valor_atual'] - $l['valor_pago']; ?>
                    <tr>
                        <td><a href="<?= e(url_locacao($l)) ?>"><strong><?= e($l['numero']) ?></strong></a></td>
                        <td><a href="<?= e(url_locacao($l)) ?>"><?= e($l['cliente']) ?></a></td>
                        <td><?= e(dt($l['data_saida'])) ?></td>
                        <td><?= e(dt($l['data_prev_devolucao'])) ?></td>
                        <td><?= selo_status_locacao($l) ?></td>
                        <td class="num"><?= num($l['pendentes']) ?></td>
                        <td class="num"><?= money($l['valor_atual']) ?></td>
                        <td class="num"><?= money($l['valor_pago']) ?></td>
                        <td class="num<?= $saldo > 0.004 ? ' alerta' : '' ?>"><?= money($saldo) ?></td>
                        <?php if ($comLembrete): ?>
                            <td><?= botao_whatsapp(whatsapp_locacao(locacao_buscar($l['id']))['links']['lembrete'] ?? null, 'Lembrete', true) ?></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$locs): ?>
                    <tr><td colspan="<?= $comLembrete ? 10 : 9 ?>" class="vazio">Nenhuma locação nesta aba.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?= pager($pg, 'locacoes', ['aba' => $aba] + ($busca !== '' ? ['q' => $busca] : [])) ?>
    </div>
    <?php
    layout_end();
}

// =====================================================================
// NOVA LOCAÇÃO
// =====================================================================
function locacoes_nova(): void
{
    // Pré-preenchimento: vindo da ficha do cliente (?cliente=) ou de uma reserva (?reserva=)
    $clienteTxt = '';
    $saida = today();
    $prev = date('Y-m-d', strtotime('+1 day'));
    $linhas = [];
    $reserva = null;

    $cid = get('cliente');
    if (is_uuid($cid) && ($c = one('SELECT nome, cpf_cnpj FROM clientes WHERE id = :id AND ativo', ['id' => $cid]))) {
        $clienteTxt = cliente_rotulo($c);
    }
    $rid = get('reserva');
    if (is_uuid($rid)) {
        $reserva = one("SELECT r.id, r.numero, r.data_fim, r.obs, c.nome, c.cpf_cnpj FROM reservas r
                          JOIN clientes c ON c.id = r.cliente_id WHERE r.id = :id AND r.status = 'ativa'", ['id' => $rid]);
        if ($reserva === null) {
            pagina_erro(404, 'Reserva não encontrada ou não está mais ativa.');
        }
        $clienteTxt = cliente_rotulo($reserva);
        $prev = max($reserva['data_fim'], today());
        foreach (all('SELECT ri.produto_id, ri.quantidade, p.valor_diaria FROM reserva_itens ri
                        JOIN produtos p ON p.id = ri.produto_id WHERE ri.reserva_id = :id', ['id' => $rid]) as $ri) {
            $linhas[] = ['produto_id' => $ri['produto_id'], 'quantidade' => $ri['quantidade'], 'diaria' => num($ri['valor_diaria'], 2)];
        }
    }

    // Repetir locação: cliente, itens, local, fretes e endereço da locação anterior; datas de hoje, mesmo prazo.
    // Acréscimo e desconto não são copiados (são negociados de novo).
    $copia = ['local_uso' => '', 'frete_entrega' => '', 'frete_recolhimento' => '', 'endereco_entrega' => ''];
    $copiar = get('copiar');
    if (is_uuid($copiar) && !has_old()) {
        $orig = one('SELECT l.*, c.nome, c.cpf_cnpj FROM locacoes l JOIN clientes c ON c.id = l.cliente_id WHERE l.id = :id', ['id' => $copiar]);
        if ($orig === null) {
            pagina_erro(404, 'Locação para repetir não encontrada.');
        }
        $clienteTxt = cliente_rotulo($orig);
        $prazo = max(0, days_between($orig['data_saida'], $orig['data_prev_devolucao']));
        $saida = today();
        $prev = date('Y-m-d', strtotime("+$prazo days"));
        $copia = ['local_uso' => $orig['local_uso'], 'frete_entrega' => num($orig['frete_entrega'], 2),
                  'frete_recolhimento' => num($orig['frete_recolhimento'], 2), 'endereco_entrega' => $orig['endereco_entrega']];
        $linhas = [];
        foreach (all('SELECT li.produto_id, li.quantidade, li.valor_diaria FROM locacao_itens li WHERE li.locacao_id = :id ORDER BY li.id',
                     ['id' => $copiar]) as $li) {
            $linhas[] = ['produto_id' => $li['produto_id'], 'quantidade' => $li['quantidade'], 'diaria' => num($li['valor_diaria'], 2)];
        }
    }

    // Depois de um erro, volta exatamente o que foi digitado
    if (has_old()) {
        $clienteTxt = old('cliente');
        $saida = old('data_saida', $saida);
        $prev = old('data_prev_devolucao', $prev);
        $linhas = [];
        foreach ((array) old('produto_id', []) as $i => $pid) {
            $linhas[] = ['produto_id' => $pid, 'quantidade' => old('quantidade', [])[$i] ?? '', 'diaria' => old('diaria', [])[$i] ?? ''];
        }
        if (old('reserva_id') !== '' && is_uuid(old('reserva_id'))) {
            $reserva = one("SELECT id, numero FROM reservas WHERE id = :id", ['id' => old('reserva_id')]);
        }
    }
    if (!$linhas) {
        $linhas[] = ['produto_id' => '', 'quantidade' => '1', 'diaria' => ''];
    }

    $clientes = all("SELECT nome, cpf_cnpj FROM clientes WHERE ativo AND NOT bloqueado ORDER BY lower(nome)");
    $produtos = all('SELECT id, codigo, descricao, unidade, valor_diaria, valor_semanal, valor_mensal FROM produtos WHERE ativo ORDER BY descricao');

    layout_start('Nova locação', 'locacoes');
    ?>
    <form method="post" action="<?= e(url('locacoes', ['a' => 'criar'])) ?>" class="form" id="form-locacao" data-itens
          data-api="<?= e(url('api', ['a' => 'disponivel'])) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="reserva_id" id="reserva_id" value="<?= e($reserva['id'] ?? '') ?>">
        <?php if ($reserva): ?>
            <div class="flash flash-aviso">Retirada da reserva nº <?= e($reserva['numero']) ?>: ao salvar, a reserva será marcada como convertida.</div>
        <?php endif; ?>
        <div class="card">
            <h2>Cliente e período</h2>
            <label class="obrig">Cliente
                <input type="text" name="cliente" value="<?= e($clienteTxt) ?>" list="lista-clientes" required autocomplete="off"
                       placeholder="Digite o nome e escolha na lista">
                <datalist id="lista-clientes">
                    <?php foreach ($clientes as $c): ?><option value="<?= e(cliente_rotulo($c)) ?>"></option><?php endforeach; ?>
                </datalist>
                <span class="ajuda">Clientes bloqueados não aparecem. <a href="<?= e(url('clientes', ['a' => 'novo'])) ?>">Cadastrar cliente</a></span>
            </label>
            <div class="linha">
                <label class="obrig">Data de saída
                    <input type="date" name="data_saida" id="data_saida" value="<?= e($saida) ?>" max="<?= e(today()) ?>" required>
                </label>
                <label class="obrig">Devolução prevista
                    <input type="date" name="data_prev_devolucao" id="data_prev" value="<?= e($prev) ?>" required>
                    <span class="ajuda" id="info-dias"></span>
                </label>
                <label>Local de uso (obra/evento)
                    <input type="text" name="local_uso" value="<?= e(has_old() ? old('local_uso') : $copia['local_uso']) ?>" maxlength="200">
                </label>
            </div>
        </div>

        <div class="card">
            <div class="card-titulo">
                <h2>Itens</h2>
                <div class="acoes"><?= kit_seletor_html(kits_para_form()) ?>
                    <button type="button" class="btn btn-sec btn-peq" id="add-item">+ Adicionar item</button></div>
            </div>
            <p class="ajuda">Preço semanal e mensal do cadastro do produto entram sozinhos no período; a diária pode ser ajustada aqui.</p>
            <div class="tabela-wrap">
                <table class="tabela" id="tabela-itens">
                    <thead><tr><th style="min-width:240px">Produto</th><th class="num">Qtd.</th><th class="num">Diária (R$)</th>
                        <th class="num">Disponível</th><th class="num">Subtotal</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($linhas as $ln) { linha_item_html($produtos, $ln); } ?>
                    </tbody>
                </table>
            </div>
            <template id="modelo-linha"><?php linha_item_html($produtos, ['produto_id' => '', 'quantidade' => '1', 'diaria' => '']); ?></template>
        </div>

        <div class="card">
            <h2>Valores</h2>
            <div class="linha">
                <label>Acréscimo (montagem etc.) R$
                    <input type="text" name="acrescimo" id="acrescimo" value="<?= e(old('acrescimo')) ?>" inputmode="decimal" placeholder="0,00">
                </label>
                <label>Frete de entrega R$
                    <input type="text" name="frete_entrega" id="frete-entrega" value="<?= e(has_old() ? old('frete_entrega') : $copia['frete_entrega']) ?>" inputmode="decimal" placeholder="0,00">
                </label>
                <label>Frete de recolhimento R$
                    <input type="text" name="frete_recolhimento" id="frete-recolhimento" value="<?= e(old('frete_recolhimento')) ?>" inputmode="decimal" placeholder="0,00">
                </label>
                <label>Endereço de entrega
                    <input type="text" name="endereco_entrega" value="<?= e(has_old() ? old('endereco_entrega') : $copia['endereco_entrega']) ?>" maxlength="200" placeholder="Se vazio, o local de uso">
                </label>
                <label>Desconto R$
                    <input type="text" name="desconto" id="desconto" value="<?= e(old('desconto')) ?>" inputmode="decimal" placeholder="0,00">
                </label>
                <label>Adiantamento recebido R$
                    <input type="text" name="adiantamento" value="<?= e(old('adiantamento')) ?>" inputmode="decimal" placeholder="0,00">
                </label>
                <label>Forma de pagamento
                    <select name="forma"><?= options(FORMAS_PGTO, old('forma', 'PIX'), '—') ?></select>
                </label>
            </div>
            <p class="total-previsto">Total previsto: <strong id="total-previsto">R$ 0,00</strong>
                <span class="muted" id="total-detalhe"></span></p>
            <label>Observações
                <textarea name="obs" maxlength="2000"><?= e(old('obs', $reserva['obs'] ?? '')) ?></textarea>
            </label>
        </div>
        <div class="acoes">
            <button type="submit" class="btn">Registrar locação</button>
            <a class="btn btn-sec" href="<?= e(url('locacoes')) ?>">Cancelar</a>
        </div>
    </form>
    <?php
    layout_end();
}

function locacoes_criar(): void
{
    $r = locacao_criar($_POST);
    flash("Locação nº {$r['numero']} registrada.");
    redirect(url('locacoes', ['a' => 'ver', 'n' => $r['numero']]));
}

// =====================================================================
// DETALHE
// =====================================================================
function locacoes_ver(): void
{
    $loc = locacao_da_url();
    $aberta = $loc['status'] === 'aberta';
    $diasHoje = max(1, days_between($loc['data_saida'], today()));
    $atraso = $aberta ? days_between($loc['data_prev_devolucao'], today()) : 0;
    $saldo = $loc['valor_atual'] - $loc['valor_pago'];

    $itens = all("SELECT li.id, li.produto_id, li.quantidade, li.qtd_devolvida, li.valor_diaria, li.valor_semanal, li.valor_mensal,
                         p.codigo, p.descricao, p.unidade,
                         COALESCE((SELECT SUM(di.valor) FROM devolucao_itens di WHERE di.locacao_item_id = li.id), 0) AS valor_devolvido
                    FROM locacao_itens li JOIN produtos p ON p.id = li.produto_id
                   WHERE li.locacao_id = :id ORDER BY p.descricao", ['id' => $loc['id']]);
    $devolucoes = all("SELECT d.id, d.data, d.multa, d.avaria, d.obs,
                              (SELECT string_agg(di.quantidade || ' ' || p.descricao, ', ' ORDER BY p.descricao)
                                 FROM devolucao_itens di JOIN locacao_itens li ON li.id = di.locacao_item_id
                                 JOIN produtos p ON p.id = li.produto_id WHERE di.devolucao_id = d.id) AS itens,
                              (SELECT max(di.dias) FROM devolucao_itens di WHERE di.devolucao_id = d.id) AS dias,
                              (SELECT SUM(di.valor) FROM devolucao_itens di WHERE di.devolucao_id = d.id) AS valor
                         FROM devolucoes d WHERE d.locacao_id = :id ORDER BY d.data, d.criado_em", ['id' => $loc['id']]);
    $pagamentos = all('SELECT data, tipo, descricao, valor, forma FROM caixa WHERE locacao_id = :id ORDER BY data, criado_em',
                      ['id' => $loc['id']]);
    $cliente = one('SELECT id, telefone, celular FROM clientes WHERE id = :id', ['id' => $loc['cliente_id']]);
    $whats = whatsapp_locacao($loc);
    $pix = locacao_pix($loc);
    $vistorias = all("SELECT v.id, v.tipo, v.criado_em, v.assinante_nome, v.assinatura_arquivo <> '' AS assinada, u.nome AS usuario,
                             (SELECT count(*) FROM vistoria_fotos f WHERE f.vistoria_id = v.id) AS fotos,
                             (SELECT count(*) FROM vistoria_itens i WHERE i.vistoria_id = v.id AND i.estado <> 'ok') AS problemas
                        FROM vistorias v LEFT JOIN usuarios u ON u.id = v.usuario_id
                       WHERE v.locacao_id = :id ORDER BY v.criado_em", ['id' => $loc['id']]);
    $temRetirada = (bool) array_filter($vistorias, fn($v) => $v['tipo'] === 'retirada');

    layout_start('Locação nº ' . $loc['numero'], 'locacoes');
    ?>
    <div class="kpis">
        <div class="kpi azul"><span>Valor previsto</span><strong><?= money($loc['valor_previsto']) ?></strong>
            <small><?= num(max(1, days_between($loc['data_saida'], $loc['data_prev_devolucao']))) ?> dia(s) combinados</small></div>
        <div class="kpi"><span><?= $aberta ? 'Até hoje' : ($loc['status'] === 'devolvida' ? 'Valor final' : 'Valor (cancelada)') ?></span>
            <strong><?= money($loc['valor_atual']) ?></strong>
            <?php if ($aberta): ?><small><?= num($diasHoje) ?> dia(s) até hoje</small><?php endif; ?></div>
        <div class="kpi verde"><span>Pago</span><strong><?= money($loc['valor_pago']) ?></strong></div>
        <div class="kpi <?= $saldo > 0.004 ? 'vermelho' : 'verde' ?>"><span>Saldo</span><strong><?= money($saldo) ?></strong></div>
    </div>

    <div class="grid-2">
        <div class="card">
            <div class="card-titulo"><h2>Dados da locação</h2><div><?= selo_status_locacao($loc) ?></div></div>
            <dl class="dados">
                <dt>Cliente</dt><dd><a href="<?= e(url('clientes', ['a' => 'ver', 'id' => $loc['cliente_id']])) ?>"><?= e($loc['cliente_nome']) ?></a>
                    <?php if ($cliente['celular'] || $cliente['telefone']): ?><br><span class="muted"><?= e($cliente['celular'] ?: $cliente['telefone']) ?></span><?php endif; ?></dd>
                <dt>Saída</dt><dd><?= e(dt($loc['data_saida'])) ?></dd>
                <dt>Devolução prevista</dt><dd><?= e(dt($loc['data_prev_devolucao'])) ?>
                    <?php if ($atraso > 0): ?><span class="selo vermelho"><?= num($atraso) ?> dia(s) de atraso</span><?php endif; ?></dd>
                <?php if ($loc['local_uso'] !== ''): ?><dt>Local de uso</dt><dd><?= e($loc['local_uso']) ?></dd><?php endif; ?>
                <?php if ((float) $loc['acrescimo'] > 0): ?><dt>Acréscimo</dt><dd><?= money($loc['acrescimo']) ?></dd><?php endif; ?>
                <?php if ((float) $loc['frete_entrega'] > 0): ?><dt>Frete de entrega</dt><dd><?= money($loc['frete_entrega']) ?></dd><?php endif; ?>
                <?php if ((float) $loc['frete_recolhimento'] > 0): ?><dt>Frete de recolhimento</dt><dd><?= money($loc['frete_recolhimento']) ?></dd><?php endif; ?>
                <?php if ((float) $loc['desconto'] > 0): ?><dt>Desconto</dt><dd><?= money($loc['desconto']) ?></dd><?php endif; ?>
                <?php if ($loc['endereco_entrega'] !== ''): ?><dt>Endereço de entrega</dt><dd><?= e($loc['endereco_entrega']) ?></dd><?php endif; ?>
                <?php if ($loc['reserva_id']): ?><dt>Origem</dt><dd>Reserva nº <?= e(val('SELECT numero FROM reservas WHERE id = :id', ['id' => $loc['reserva_id']])) ?></dd><?php endif; ?>
                <dt>Registrada em</dt><dd><?= e(dt($loc['criado_em'], true)) ?></dd>
                <?php if ($loc['obs'] !== ''): ?><dt>Observações</dt><dd><?= nl2br(e($loc['obs'])) ?></dd><?php endif; ?>
            </dl>
            <div class="acoes" style="margin-top:14px">
                <?php if ($aberta): ?><a class="btn" href="<?= e(url_locacao($loc, 'devolucao')) ?>">Registrar devolução</a><?php endif; ?>
                <a class="btn btn-sec" href="<?= e(url_locacao($loc, 'contrato')) ?>" target="_blank">Contrato</a>
                <a class="btn btn-sec" href="<?= e(url_locacao($loc, 'fatura')) ?>" target="_blank">Fatura</a>
                <a class="btn btn-sec" href="<?= e(url('locacoes', ['a' => 'nova', 'copiar' => $loc['id']])) ?>">Repetir locação</a>
            </div>
        </div>

        <div>
            <?php if ($loc['status'] !== 'cancelada'): ?>
            <div class="card">
                <h2>WhatsApp do cliente</h2>
                <div class="acoes">
                    <?= botao_whatsapp($whats['links']['resumo'] ?? null, 'Enviar resumo') ?>
                    <?php if (array_key_exists('lembrete', $whats['links'])): ?><?= botao_whatsapp($whats['links']['lembrete'], 'Lembrete de devolução') ?><?php endif; ?>
                    <?php if (array_key_exists('saldo', $whats['links'])): ?><?= botao_whatsapp($whats['links']['saldo'], 'Cobrar saldo') ?><?php endif; ?>
                </div>
                <p class="muted" style="margin:8px 0 0"><?= $whats['numero'] !== null
                    ? 'Abre o WhatsApp com a mensagem pronta; confira e toque em enviar.'
                    : 'Cadastre o celular do cliente para usar o WhatsApp.' ?></p>
            </div>
            <?php if ($pix !== null): ?>
            <div class="card">
                <h2>Cobrar por PIX</h2>
                <?= pix_bloco($pix) ?>
                <p class="muted" style="margin:10px 0 0">Quando o pagamento cair na conta, registre-o abaixo em "Receber pagamento" (forma PIX).</p>
            </div>
            <?php elseif ($saldo > 0.004 && empty(empresa()['pix_chave']) && is_admin()): ?>
            <div class="card">
                <h2>Cobrar por PIX</h2>
                <p class="muted" style="margin:0">Cadastre a chave PIX da locadora em
                    <a href="<?= e(url('config')) ?>">Configurações</a> para mostrar aqui o QR Code com o valor do saldo.</p>
            </div>
            <?php endif; ?>
            <div class="card">
                <h2>Receber pagamento</h2>
                <form method="post" action="<?= e(url('locacoes', ['a' => 'receber'])) ?>" class="form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= e($loc['id']) ?>">
                    <div class="linha">
                        <label class="obrig">Valor R$<input type="text" name="valor" value="<?= $saldo > 0 ? e(num($saldo, 2)) : '' ?>" inputmode="decimal" required></label>
                        <label class="obrig">Forma<select name="forma" required><?= options(FORMAS_PGTO, 'PIX') ?></select></label>
                        <label class="obrig">Data<input type="date" name="data" value="<?= e(today()) ?>" max="<?= e(today()) ?>" required></label>
                    </div>
                    <button class="btn">Registrar recebimento</button>
                </form>
            </div>
            <?php endif; ?>
            <?php if ($aberta): ?>
            <div class="card">
                <h2>Prorrogar</h2>
                <form method="post" action="<?= e(url('locacoes', ['a' => 'prorrogar'])) ?>" class="form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= e($loc['id']) ?>">
                    <div class="linha">
                        <label class="obrig">Nova devolução prevista<input type="date" name="data" min="<?= e($loc['data_saida']) ?>" required
                               value="<?= e(date('Y-m-d', strtotime(max($loc['data_prev_devolucao'], today()) . ' +1 day'))) ?>"></label>
                        <label>Motivo<input type="text" name="motivo" maxlength="200"></label>
                    </div>
                    <button class="btn btn-sec">Prorrogar</button>
                </form>
            </div>
            <?php if ((int) $loc['devolucoes'] === 0): ?>
            <div class="card">
                <h2>Cancelar locação</h2>
                <form method="post" action="<?= e(url('locacoes', ['a' => 'cancelar'])) ?>" class="form"
                      data-confirmar="Cancelar a locação nº <?= e($loc['numero']) ?>? Os itens voltam ao estoque<?= $loc['valor_pago'] > 0 ? ' e o valor pago será estornado' : '' ?>.">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= e($loc['id']) ?>">
                    <div class="linha">
                        <label>Motivo<input type="text" name="motivo" maxlength="200"></label>
                        <?php if ($loc['valor_pago'] > 0): ?>
                            <label class="obrig">Estornar <?= money($loc['valor_pago']) ?> via<select name="forma" required><?= options(FORMAS_PGTO, 'PIX') ?></select></label>
                        <?php endif; ?>
                    </div>
                    <button class="btn btn-perigo">Cancelar locação</button>
                </form>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <h2>Itens</h2>
        <div class="tabela-wrap">
            <table class="tabela">
                <thead><tr><th>Código</th><th>Produto</th><th class="num">Qtd.</th><th class="num">Devolvida</th><th class="num">Pendente</th>
                    <th class="num">Preço</th><th class="num"><?= $aberta ? 'Valor até hoje' : 'Valor' ?></th></tr></thead>
                <tbody>
                <?php foreach ($itens as $it):
                    $pend = $it['quantidade'] - $it['qtd_devolvida'];
                    $valor = $it['valor_devolvido'] + ($aberta ? $pend * preco_item($it, $diasHoje) : 0); ?>
                    <tr>
                        <td><code><?= e($it['codigo']) ?></code></td>
                        <td><?= e($it['descricao']) ?></td>
                        <td class="num"><?= num($it['quantidade']) ?> <span class="muted"><?= e($it['unidade']) ?></span></td>
                        <td class="num"><?= num($it['qtd_devolvida']) ?></td>
                        <td class="num"><?= $pend > 0 ? '<span class="selo ' . ($atraso > 0 ? 'vermelho' : 'amarelo') . '">' . num($pend) . '</span>' : '0' ?></td>
                        <td class="num"><?= precos_html($it) ?></td>
                        <td class="num"><?= $loc['status'] === 'cancelada' ? '—' : money($valor) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($aberta): ?>
    <div class="card">
        <h2>Entrega e recolhimento</h2>
        <form method="post" action="<?= e(url('locacoes', ['a' => 'entrega_salvar'])) ?>" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= e($loc['id']) ?>">
            <div class="linha">
                <label>Frete de entrega R$<input type="text" name="frete_entrega" value="<?= e(num($loc['frete_entrega'], 2)) ?>" inputmode="decimal"></label>
                <label>Frete de recolhimento R$<input type="text" name="frete_recolhimento" value="<?= e(num($loc['frete_recolhimento'], 2)) ?>" inputmode="decimal"></label>
            </div>
            <label>Endereço de entrega e recolhimento<input type="text" name="endereco_entrega" value="<?= e($loc['endereco_entrega']) ?>" maxlength="200"></label>
            <button class="btn btn-sec btn-peq">Salvar entrega</button>
        </form>
    </div>
    <?php endif; ?>
    <?php locacao_patrimonio_card($loc, $itens); ?>

    <div class="card">
        <div class="card-titulo">
            <h2>Vistorias (checklist, fotos e assinatura)</h2>
            <?php if ($loc['status'] !== 'cancelada'): ?>
            <div class="acoes">
                <?php if (!$temRetirada): ?><a class="btn" href="<?= e(url('locacoes', ['a' => 'vistoria', 'n' => $loc['numero'], 'tipo' => 'retirada'])) ?>">Vistoria de retirada</a><?php endif; ?>
                <a class="btn btn-sec" href="<?= e(url('locacoes', ['a' => 'vistoria', 'n' => $loc['numero'], 'tipo' => 'devolucao'])) ?>">Vistoria de devolução</a>
            </div>
            <?php endif; ?>
        </div>
        <?php if (!$temRetirada && $loc['status'] === 'aberta'): ?>
            <p class="flash flash-aviso" style="margin:0 0 12px">Ainda não há vistoria de retirada. Registre o estado dos equipamentos com fotos e a
                assinatura do cliente: é a sua prova em caso de avaria.</p>
        <?php endif; ?>
        <div class="tabela-wrap">
            <table class="tabela">
                <thead><tr><th>Tipo</th><th>Data</th><th>Feita por</th><th class="num">Fotos</th><th>Itens com problema</th><th>Assinatura</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($vistorias as $v): ?>
                    <tr><td><?= $v['tipo'] === 'retirada' ? 'Retirada' : 'Devolução' ?></td>
                        <td class="nowrap"><?= e(dt($v['criado_em'], true)) ?></td>
                        <td><?= e($v['usuario'] ?? '') ?></td>
                        <td class="num"><?= num($v['fotos']) ?></td>
                        <td><?= (int) $v['problemas'] > 0 ? '<span class="selo vermelho">' . num($v['problemas']) . '</span>' : '<span class="selo verde">nenhum</span>' ?></td>
                        <td><?= $v['assinada'] ? '<span class="selo verde">' . e($v['assinante_nome']) . '</span>' : '<span class="selo">sem assinatura</span>' ?></td>
                        <td><a class="btn btn-sec btn-peq" href="<?= e(url('locacoes', ['a' => 'vistoria_ver', 'id' => $v['id']])) ?>" target="_blank">Ver / imprimir</a></td></tr>
                <?php endforeach; ?>
                <?php if (!$vistorias): ?><tr><td colspan="7" class="vazio">Nenhuma vistoria registrada.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="grid-2">
        <div class="card">
            <h2>Devoluções</h2>
            <div class="tabela-wrap">
                <table class="tabela">
                    <thead><tr><th>Data</th><th>Itens</th><th class="num">Dias</th><th class="num">Valor</th><th class="num">Multa + avaria</th></tr></thead>
                    <tbody>
                    <?php foreach ($devolucoes as $d): ?>
                        <tr>
                            <td><?= e(dt($d['data'])) ?></td>
                            <td><?= e($d['itens']) ?><?= $d['obs'] !== '' ? '<br><span class="muted">' . e($d['obs']) . '</span>' : '' ?></td>
                            <td class="num"><?= num($d['dias']) ?></td>
                            <td class="num"><?= money($d['valor']) ?></td>
                            <td class="num"><?= money($d['multa'] + $d['avaria']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$devolucoes): ?><tr><td colspan="5" class="vazio">Nenhuma devolução.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card">
            <h2>Pagamentos</h2>
            <div class="tabela-wrap">
                <table class="tabela">
                    <thead><tr><th>Data</th><th>Descrição</th><th>Forma</th><th class="num">Valor</th></tr></thead>
                    <tbody>
                    <?php foreach ($pagamentos as $pgto): ?>
                        <tr>
                            <td><?= e(dt($pgto['data'])) ?></td>
                            <td><?= e($pgto['descricao']) ?></td>
                            <td><?= e($pgto['forma']) ?></td>
                            <td class="num<?= $pgto['tipo'] === 'S' ? ' alerta' : '' ?>"><?= ($pgto['tipo'] === 'S' ? '− ' : '') . money($pgto['valor']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$pagamentos): ?><tr><td colspan="4" class="vazio">Nenhum pagamento.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php
    layout_end($pix !== null ? ['assets/vendor/qrcode.js', 'assets/js/pix.js'] : []);
}

function locacoes_receber(): void
{
    $loc = locacao_do_post();
    recebimento_registrar($loc['id'], $_POST);
    flash('Recebimento de ' . money(parse_money(post('valor'))) . ' registrado.');
    redirect(url_locacao($loc));
}

function locacoes_prorrogar(): void
{
    $loc = locacao_do_post();
    locacao_prorrogar($loc['id'], post('data'), mb_substr(post('motivo'), 0, 200));
    flash('Devolução prevista alterada para ' . dt(post('data')) . '.');
    redirect(url_locacao($loc));
}

function locacoes_cancelar(): void
{
    $loc = locacao_do_post();
    locacao_cancelar($loc['id'], mb_substr(post('motivo'), 0, 200), post('forma'));
    flash("Locação nº {$loc['numero']} cancelada.");
    redirect(url_locacao($loc));
}

// =====================================================================
// DEVOLUÇÃO
// =====================================================================
function locacoes_devolucao(): void
{
    $loc = locacao_da_url();
    if ($loc['status'] !== 'aberta') {
        flash('Esta locação não está em aberto.', 'aviso');
        redirect(url_locacao($loc));
    }
    $itens = all('SELECT li.id, li.quantidade, li.qtd_devolvida, li.valor_diaria, li.valor_semanal, li.valor_mensal, p.descricao, p.unidade
                    FROM locacao_itens li JOIN produtos p ON p.id = li.produto_id
                   WHERE li.locacao_id = :id AND li.quantidade > li.qtd_devolvida ORDER BY p.descricao', ['id' => $loc['id']]);
    // O que já foi apurado em devoluções anteriores (itens + multas + avarias)
    $apurado = (float) val('SELECT COALESCE((SELECT SUM(di.valor) FROM devolucao_itens di JOIN devolucoes d ON d.id = di.devolucao_id
                                              WHERE d.locacao_id = :a), 0)
                                 + COALESCE((SELECT SUM(multa + avaria) FROM devolucoes WHERE locacao_id = :b), 0)',
                           ['a' => $loc['id'], 'b' => $loc['id']]);
    $data = old('data', today());
    $dias = max(1, days_between($loc['data_saida'], $data));
    // Sugestão inicial (sem JavaScript): tudo devolvido hoje
    $desta = 0.0;
    foreach ($itens as $it) {
        $desta += ($it['quantidade'] - $it['qtd_devolvida']) * preco_item($it, $dias);
    }
    $ajuste = locacao_ajuste($loc);
    $saldoSugerido = max(0, $apurado + $desta + $ajuste - $loc['valor_pago']);
    $qtdOld = (array) old('qtd', []);
    // Unidades (patrimônio) na rua: marcadas = voltaram nesta devolução
    $naRua = [];
    foreach (all('SELECT lu.locacao_item_id, lu.unidade_id, u.patrimonio FROM locacao_unidades lu
                    JOIN unidades u ON u.id = lu.unidade_id JOIN locacao_itens li ON li.id = lu.locacao_item_id
                   WHERE li.locacao_id = :id AND lu.devolucao_id IS NULL ORDER BY u.patrimonio', ['id' => $loc['id']]) as $r) {
        $naRua[$r['locacao_item_id']][] = $r;
    }
    $unidOld = has_old() ? array_map('strval', (array) old('unidades', [])) : null;

    layout_start('Devolução · locação nº ' . $loc['numero'], 'locacoes');
    ?>
    <form method="post" action="<?= e(url('locacoes', ['a' => 'devolver'])) ?>" class="form" id="form-devolucao"
          data-saida="<?= e($loc['data_saida']) ?>" data-prev="<?= e($loc['data_prev_devolucao']) ?>"
          data-apurado="<?= e($apurado) ?>" data-acrescimo="<?= e(sprintf('%.2f', (float) $loc['acrescimo'] + (float) $loc['frete_entrega'] + (float) $loc['frete_recolhimento'])) ?>"
          data-desconto="<?= e($loc['desconto']) ?>" data-pago="<?= e($loc['valor_pago']) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= e($loc['id']) ?>">
        <div class="card">
            <h2><?= e($loc['cliente_nome']) ?></h2>
            <div class="linha">
                <label class="obrig">Data da devolução
                    <input type="date" name="data" id="data_dev" value="<?= e($data) ?>" min="<?= e($loc['data_saida']) ?>" max="<?= e(today()) ?>" required>
                </label>
                <div><span class="muted">Saída</span><br><strong><?= e(dt($loc['data_saida'])) ?></strong></div>
                <div><span class="muted">Prevista</span><br><strong><?= e(dt($loc['data_prev_devolucao'])) ?></strong></div>
                <div><span class="muted">Dias</span><br><strong id="dev-dias"><?= num($dias) ?></strong> <span id="dev-atraso" class="alerta"></span></div>
            </div>
        </div>
        <div class="card">
            <h2>Itens a devolver</h2>
            <div class="tabela-wrap">
                <table class="tabela">
                    <thead><tr><th>Produto</th><th class="num">Pendente</th><th class="num">Devolver agora</th><th class="num">Preço</th><th class="num">Valor</th></tr></thead>
                    <tbody>
                    <?php foreach ($itens as $it): $pend = $it['quantidade'] - $it['qtd_devolvida']; ?>
                        <tr class="linha-dev" data-diaria="<?= e($it['valor_diaria']) ?>" data-semanal="<?= e($it['valor_semanal']) ?>"
                            data-mensal="<?= e($it['valor_mensal']) ?>">
                            <td><?= e($it['descricao']) ?>
                                <?php if (!empty($naRua[$it['id']])): ?>
                                    <div class="unidades-dev"><span class="muted">Voltaram:</span>
                                        <?php foreach ($naRua[$it['id']] as $u): ?>
                                            <label class="check"><input type="checkbox" name="unidades[]" value="<?= e($u['unidade_id']) ?>"
                                                <?= $unidOld === null || in_array($u['unidade_id'], $unidOld, true) ? 'checked' : '' ?>> <?= e($u['patrimonio']) ?></label>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="num"><?= num($pend) ?> <span class="muted"><?= e($it['unidade']) ?></span></td>
                            <td class="num"><input type="number" name="qtd[<?= e($it['id']) ?>]" class="qtd-dev" min="0" max="<?= $pend ?>" step="1"
                                                   value="<?= e($qtdOld[$it['id']] ?? $pend) ?>" style="width:80px" aria-label="Quantidade a devolver"></td>
                            <td class="num"><?= precos_html($it) ?></td>
                            <td class="num valor-dev">—</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="grid-2">
            <div class="card">
                <h2>Multa, avaria e pagamento</h2>
                <div class="linha">
                    <label>Multa R$<input type="text" name="multa" id="multa" value="<?= e(old('multa')) ?>" inputmode="decimal" placeholder="0,00"></label>
                    <label>Avaria R$<input type="text" name="avaria" id="avaria" value="<?= e(old('avaria')) ?>" inputmode="decimal" placeholder="0,00"></label>
                </div>
                <label>Observação (estado dos equipamentos)<textarea name="obs" maxlength="2000"><?= e(old('obs')) ?></textarea></label>
                <div class="linha">
                    <label>Receber agora R$<input type="text" name="receber" id="receber" value="<?= e(old('receber', $saldoSugerido > 0 ? num($saldoSugerido, 2) : '')) ?>" inputmode="decimal"></label>
                    <label>Forma<select name="forma"><?= options(FORMAS_PGTO, old('forma', 'PIX'), '—') ?></select></label>
                </div>
            </div>
            <div class="card">
                <h2>Resumo</h2>
                <dl class="dados resumo">
                    <dt>Desta devolução</dt><dd class="num" id="r-desta">—</dd>
                    <dt>Já apurado antes</dt><dd class="num"><?= money($apurado) ?></dd>
                    <dt>Acréscimos e fretes − desconto</dt><dd class="num"><?= money($ajuste) ?></dd>
                    <dt>Total apurado</dt><dd class="num" id="r-total">—</dd>
                    <dt>Já pago</dt><dd class="num"><?= money($loc['valor_pago']) ?></dd>
                    <dt><strong>Saldo</strong></dt><dd class="num"><strong id="r-saldo">—</strong></dd>
                </dl>
                <p class="muted" id="r-pendente-aviso"></p>
            </div>
        </div>
        <div class="acoes">
            <button type="submit" class="btn">Confirmar devolução</button>
            <a class="btn btn-sec" href="<?= e(url_locacao($loc)) ?>">Voltar</a>
        </div>
    </form>
    <?php
    layout_end();
}

function locacoes_devolver(): void
{
    $loc = locacao_do_post();
    $r = devolucao_registrar($loc['id'], $_POST);
    flash($r['total'] ? "Devolução registrada. Locação nº {$loc['numero']} encerrada."
                      : 'Devolução parcial registrada. Ainda há itens pendentes.');
    redirect(url_locacao($loc));
}

// =====================================================================
// CONTRATO E FATURA (impressão A4)
// =====================================================================
function data_extenso(string $d): string
{
    $ts = strtotime($d);
    return date('j', $ts) . ' de ' . MESES[(int) date('n', $ts)] . ' de ' . date('Y', $ts);
}

function locatario_impressao(array $loc): void
{
    $c = one('SELECT * FROM clientes WHERE id = :id', ['id' => $loc['cliente_id']]);
    $end = trim(implode(', ', array_filter([$c['endereco'], $c['numero'], $c['bairro']])));
    ?>
    <h2>Locatário</h2>
    <table>
        <tr><th style="width:22%">Nome / razão social</th><td colspan="3"><?= e($c['nome']) ?></td></tr>
        <tr><th><?= $c['tipo'] === 'PJ' ? 'CNPJ' : 'CPF' ?></th><td><?= e(doc_formatar($c['cpf_cnpj'])) ?></td>
            <th style="width:18%"><?= $c['tipo'] === 'PJ' ? 'Insc. estadual' : 'RG' ?></th><td><?= e($c['rg_ie']) ?></td></tr>
        <tr><th>Endereço</th><td colspan="3"><?= e($end) ?><?= $c['cidade'] !== '' ? ' · ' . e($c['cidade'] . '/' . $c['uf']) : '' ?><?= $c['cep'] !== '' ? ' · CEP ' . e($c['cep']) : '' ?></td></tr>
        <tr><th>Telefone</th><td><?= implode(' · ', array_map(fn($t) => '<span class="nowrap">' . e($t) . '</span>', array_filter([$c['telefone'], $c['celular']]))) ?></td><th>E-mail</th><td><?= e($c['email']) ?></td></tr>
    </table>
    <?php
}

function locacoes_contrato(): void
{
    $loc = locacao_da_url();
    $emp = empresa();
    $dias = max(1, days_between($loc['data_saida'], $loc['data_prev_devolucao']));
    $itens = all("SELECT li.quantidade, li.valor_diaria, li.valor_semanal, li.valor_mensal, p.codigo, p.descricao, p.unidade, p.valor_reposicao,
                         (SELECT string_agg(u.patrimonio, ', ' ORDER BY u.patrimonio) FROM locacao_unidades lu
                            JOIN unidades u ON u.id = lu.unidade_id WHERE lu.locacao_item_id = li.id) AS patrimonios
                    FROM locacao_itens li JOIN produtos p ON p.id = li.produto_id
                   WHERE li.locacao_id = :id ORDER BY p.descricao", ['id' => $loc['id']]);
    $subtotal = 0.0;
    // Assinatura do cliente feita na tela, na vistoria de retirada
    $assinatura = one("SELECT id, assinante_nome, assinado_em FROM vistorias
                        WHERE locacao_id = :id AND tipo = 'retirada' AND assinatura_arquivo <> ''", ['id' => $loc['id']]);

    print_start('Contrato de locação nº ' . $loc['numero'], url_locacao($loc));
    cabecalho_impressao('Contrato de locação', 'Nº ' . $loc['numero']);
    locatario_impressao($loc);
    ?>
    <h2>Período e local</h2>
    <table>
        <tr><th style="width:22%">Saída</th><td><?= e(dt($loc['data_saida'])) ?></td>
            <th style="width:22%">Devolução prevista</th><td><?= e(dt($loc['data_prev_devolucao'])) ?> (<?= num($dias) ?> dia(s))</td></tr>
        <?php if ($loc['local_uso'] !== ''): ?><tr><th>Local de uso</th><td colspan="3"><?= e($loc['local_uso']) ?></td></tr><?php endif; ?>
    </table>
    <h2>Bens locados</h2>
    <table>
        <thead><tr><th class="num">Qtd.</th><th>Un.</th><th>Código</th><th>Descrição</th><th class="num">Preço (un.)</th>
            <th class="num">Valor de reposição (un.)</th><th class="num">Subtotal previsto</th></tr></thead>
        <tbody>
        <?php foreach ($itens as $it): $sub = $it['quantidade'] * preco_item($it, $dias); $subtotal += $sub; ?>
            <tr><td class="num"><?= num($it['quantidade']) ?></td><td><?= e($it['unidade']) ?></td><td class="nowrap"><?= e($it['codigo']) ?></td>
                <td><?= e($it['descricao']) ?><?= $it['patrimonios'] ? '<br><small>Patrimônio: ' . e($it['patrimonios']) . '</small>' : '' ?></td>
                <td class="num"><?= precos_html($it) ?></td>
                <td class="num"><?= money($it['valor_reposicao']) ?></td><td class="num"><?= money($sub) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr><td colspan="6" class="num">Subtotal</td><td class="num"><?= money($subtotal) ?></td></tr>
            <?php if ((float) $loc['acrescimo'] > 0): ?><tr><td colspan="6" class="num">Acréscimo</td><td class="num"><?= money($loc['acrescimo']) ?></td></tr><?php endif; ?>
            <?php if ((float) $loc['frete_entrega'] > 0): ?><tr><td colspan="6" class="num">Frete de entrega</td><td class="num"><?= money($loc['frete_entrega']) ?></td></tr><?php endif; ?>
            <?php if ((float) $loc['frete_recolhimento'] > 0): ?><tr><td colspan="6" class="num">Frete de recolhimento</td><td class="num"><?= money($loc['frete_recolhimento']) ?></td></tr><?php endif; ?>
            <?php if ((float) $loc['desconto'] > 0): ?><tr><td colspan="6" class="num">Desconto</td><td class="num">− <?= money($loc['desconto']) ?></td></tr><?php endif; ?>
            <tr><td colspan="6" class="num">Valor previsto</td><td class="num"><?= money($loc['valor_previsto']) ?></td></tr>
        </tfoot>
    </table>
    <h2>Cláusulas</h2>
    <div class="clausulas"><?= e($emp['termos_contrato']) ?></div>
    <p style="margin-top:6mm"><?= e(($emp['cidade'] ?: '') . ($emp['cidade'] ? ', ' : '') . data_extenso($loc['data_saida'])) ?>.</p>
    <div class="assinaturas">
        <div>LOCADORA<br><?= e($emp['razao_social']) ?></div>
        <div class="<?= $assinatura ? 'assinado' : '' ?>">
            <?php if ($assinatura): ?>
                <img src="<?= e(url('arquivos', ['a' => 'assinatura', 'id' => $assinatura['id']])) ?>" alt="Assinatura do locatário">
            <?php endif; ?>
            LOCATÁRIO<br><?= e($loc['cliente_nome']) ?>
            <?php if ($assinatura): ?>
                <small>Assinado eletronicamente por <?= e($assinatura['assinante_nome']) ?> em <?= e(dt($assinatura['assinado_em'], true)) ?>,
                    na vistoria de retirada.</small>
            <?php endif; ?>
        </div>
    </div>
    <?php
    print_end();
}

function locacoes_fatura(): void
{
    $loc = locacao_da_url();
    $emp = empresa();
    $aberta = $loc['status'] === 'aberta';
    $diasHoje = max(1, days_between($loc['data_saida'], today()));
    // Itens já devolvidos (com os dias efetivos) + pendentes cobrados até hoje
    $linhas = all('SELECT d.data, di.quantidade, di.dias, di.valor, li.valor_diaria, li.valor_semanal, li.valor_mensal, p.descricao, p.unidade
                     FROM devolucao_itens di
                     JOIN devolucoes d ON d.id = di.devolucao_id
                     JOIN locacao_itens li ON li.id = di.locacao_item_id
                     JOIN produtos p ON p.id = li.produto_id
                    WHERE d.locacao_id = :id ORDER BY d.data, p.descricao', ['id' => $loc['id']]);
    $pendentes = $aberta ? all('SELECT li.quantidade - li.qtd_devolvida AS quantidade, li.valor_diaria, li.valor_semanal, li.valor_mensal,
                                       p.descricao, p.unidade
                                  FROM locacao_itens li JOIN produtos p ON p.id = li.produto_id
                                 WHERE li.locacao_id = :id AND li.quantidade > li.qtd_devolvida ORDER BY p.descricao',
                               ['id' => $loc['id']]) : [];
    $extras = one('SELECT COALESCE(SUM(multa), 0) AS multa, COALESCE(SUM(avaria), 0) AS avaria FROM devolucoes WHERE locacao_id = :id',
                  ['id' => $loc['id']]);
    $saldo = $loc['valor_atual'] - $loc['valor_pago'];

    $whats = whatsapp_locacao($loc)['links'];
    $pix = locacao_pix($loc);
    print_start('Fatura de locação nº ' . $loc['numero'], url_locacao($loc),
                botao_whatsapp($whats['saldo'] ?? $whats['resumo'] ?? null, isset($whats['saldo']) ? 'Cobrar saldo no WhatsApp' : 'Enviar resumo no WhatsApp'));
    cabecalho_impressao('Fatura de locação', 'Nº ' . $loc['numero']);
    locatario_impressao($loc);
    ?>
    <h2>Período</h2>
    <p>Saída em <?= e(dt($loc['data_saida'])) ?> · devolução prevista <?= e(dt($loc['data_prev_devolucao'])) ?>
       · situação: <strong><?= e($aberta ? 'em aberto (valores até ' . dt(today()) . ')' : $loc['status']) ?></strong></p>
    <h2>Discriminação</h2>
    <table>
        <thead><tr><th>Devolução</th><th>Descrição</th><th class="num">Qtd.</th><th class="num">Dias</th><th class="num">Preço (un.)</th><th class="num">Valor</th></tr></thead>
        <tbody>
        <?php foreach ($linhas as $l): ?>
            <tr><td><?= e(dt($l['data'])) ?></td><td><?= e($l['descricao']) ?></td><td class="num"><?= num($l['quantidade']) ?> <?= e($l['unidade']) ?></td>
                <td class="num"><?= num($l['dias']) ?></td><td class="num"><?= precos_html($l) ?></td><td class="num"><?= money($l['valor']) ?></td></tr>
        <?php endforeach; ?>
        <?php foreach ($pendentes as $l): ?>
            <tr><td><em>pendente</em></td><td><?= e($l['descricao']) ?></td><td class="num"><?= num($l['quantidade']) ?> <?= e($l['unidade']) ?></td>
                <td class="num"><?= num($diasHoje) ?></td><td class="num"><?= precos_html($l) ?></td>
                <td class="num"><?= money($l['quantidade'] * preco_item($l, $diasHoje)) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <?php if ((float) $extras['multa'] > 0): ?><tr><td colspan="5" class="num">Multa</td><td class="num"><?= money($extras['multa']) ?></td></tr><?php endif; ?>
            <?php if ((float) $extras['avaria'] > 0): ?><tr><td colspan="5" class="num">Avaria</td><td class="num"><?= money($extras['avaria']) ?></td></tr><?php endif; ?>
            <?php if ((float) $loc['acrescimo'] > 0): ?><tr><td colspan="5" class="num">Acréscimo</td><td class="num"><?= money($loc['acrescimo']) ?></td></tr><?php endif; ?>
            <?php if ((float) $loc['frete_entrega'] > 0): ?><tr><td colspan="5" class="num">Frete de entrega</td><td class="num"><?= money($loc['frete_entrega']) ?></td></tr><?php endif; ?>
            <?php if ((float) $loc['frete_recolhimento'] > 0): ?><tr><td colspan="5" class="num">Frete de recolhimento</td><td class="num"><?= money($loc['frete_recolhimento']) ?></td></tr><?php endif; ?>
            <?php if ((float) $loc['desconto'] > 0): ?><tr><td colspan="5" class="num">Desconto</td><td class="num">− <?= money($loc['desconto']) ?></td></tr><?php endif; ?>
            <tr><td colspan="5" class="num">Total da locação</td><td class="num"><?= money($loc['valor_atual']) ?></td></tr>
            <tr><td colspan="5" class="num">Valor pago</td><td class="num"><?= money($loc['valor_pago']) ?></td></tr>
            <tr><td colspan="5" class="num">Saldo a pagar</td><td class="num"><?= money(max(0, $saldo)) ?></td></tr>
        </tfoot>
    </table>
    <?php if ($pix !== null): ?><?= pix_bloco($pix) ?><?php endif; ?>
    <div class="obs-fiscal"><?= nl2br(e($emp['obs_fatura'])) ?></div>
    <p class="muted" style="margin-top:4mm">Este documento é uma fatura de locação de bens móveis e não substitui nota fiscal quando exigida.</p>
    <?php
    print_end($pix !== null ? ['assets/vendor/qrcode.js', 'assets/js/pix.js'] : []);
}

// =====================================================================
// VISTORIAS (checklist de retirada/devolução com fotos e assinatura)
// =====================================================================
function locacoes_vistoria(): void
{
    $loc = locacao_da_url();
    $tipo = get('tipo') === 'retirada' ? 'retirada' : 'devolucao';
    if ($loc['status'] === 'cancelada') {
        pagina_erro(200, 'Locação cancelada não recebe vistoria.');
    }
    if ($tipo === 'retirada' && val("SELECT 1 FROM vistorias WHERE locacao_id = :id AND tipo = 'retirada'", ['id' => $loc['id']])) {
        flash('Esta locação já tem vistoria de retirada.', 'aviso');
        redirect(url_locacao($loc));
    }
    $itens = all('SELECT li.id, li.quantidade, li.qtd_devolvida, p.codigo, p.descricao, p.unidade
                    FROM locacao_itens li JOIN produtos p ON p.id = li.produto_id
                   WHERE li.locacao_id = :id ORDER BY p.descricao', ['id' => $loc['id']]);
    $estadoOld = (array) old('estado', []);
    $obsOld = (array) old('obs_item', []);

    layout_start(($tipo === 'retirada' ? 'Vistoria de retirada' : 'Vistoria de devolução') . ' · locação nº ' . $loc['numero'], 'locacoes');
    ?>
    <form method="post" action="<?= e(url('locacoes', ['a' => 'vistoria_salvar'])) ?>" class="form" enctype="multipart/form-data" id="form-vistoria">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= e($loc['id']) ?>">
        <input type="hidden" name="tipo" value="<?= e($tipo) ?>">
        <div class="card">
            <h2><?= e($loc['cliente_nome']) ?></h2>
            <p class="muted" style="margin:0">Confira cada equipamento junto com o cliente, fotografe o que for importante e peça a
                assinatura no fim. Depois de salva, a vistoria não pode ser alterada.</p>
        </div>
        <div class="card">
            <h2>Estado dos equipamentos</h2>
            <div class="tabela-wrap">
                <table class="tabela">
                    <thead><tr><th>Equipamento</th><th class="num"><?= $tipo === 'retirada' ? 'Qtd.' : 'Devolvida / total' ?></th><th>Estado</th><th>Observação</th></tr></thead>
                    <tbody>
                    <?php foreach ($itens as $it): ?>
                        <tr><td><code><?= e($it['codigo']) ?></code> <?= e($it['descricao']) ?></td>
                            <td class="num"><?= $tipo === 'retirada' ? num($it['quantidade']) : num($it['qtd_devolvida']) . ' / ' . num($it['quantidade']) ?> <?= e($it['unidade']) ?></td>
                            <td><select name="estado[<?= e($it['id']) ?>]" aria-label="Estado de <?= e($it['descricao']) ?>">
                                <?= options(ESTADOS_VISTORIA, $estadoOld[$it['id']] ?? 'ok') ?></select></td>
                            <td><input type="text" name="obs_item[<?= e($it['id']) ?>]" value="<?= e($obsOld[$it['id']] ?? '') ?>" maxlength="200"
                                       placeholder="ex.: risco na lateral" aria-label="Observação"></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <label>Observações gerais<textarea name="obs" maxlength="2000"><?= e(old('obs')) ?></textarea></label>
        </div>
        <div class="card">
            <h2>Fotos</h2>
            <label>Tirar ou escolher fotos (até <?= FOTOS_POR_VISTORIA ?>)
                <input type="file" name="fotos[]" id="fotos" accept="image/jpeg,image/png,image/webp" multiple>
                <span class="ajuda">No celular, abre a câmera. As fotos são reduzidas antes do envio e os dados de localização são removidos.</span>
            </label>
            <div class="fotos-previa" id="fotos-previa"></div>
        </div>
        <div class="card">
            <h2>Assinatura do cliente</h2>
            <label>Nome de quem assina<input type="text" name="assinante_nome" maxlength="100"
                   value="<?= e(old('assinante_nome', $tipo === 'retirada' ? $loc['cliente_nome'] : '')) ?>"></label>
            <div class="assinatura-area">
                <canvas id="assinatura-pad" width="900" height="260" aria-label="Área de assinatura: assine com o dedo ou o mouse"></canvas>
                <span class="assinatura-linha">Assine acima da linha</span>
            </div>
            <input type="hidden" name="assinatura" id="assinatura">
            <div class="acoes" style="margin-top:8px">
                <button type="button" class="btn btn-sec btn-peq" id="assinatura-limpar">Limpar assinatura</button>
                <span class="ajuda">Opcional, mas recomendada: é a concordância do cliente com o estado registrado.</span>
            </div>
        </div>
        <div class="acoes">
            <button type="submit" class="btn" id="vistoria-salvar">Salvar vistoria</button>
            <a class="btn btn-sec" href="<?= e(url_locacao($loc)) ?>">Cancelar</a>
        </div>
    </form>
    <?php
    layout_end(['assets/js/vistoria.js']);
}

function locacoes_vistoria_salvar(): void
{
    $loc = locacao_do_post();
    $tipo = post('tipo');
    vistoria_registrar($loc['id'], $tipo, $_POST);
    flash(($tipo === 'retirada' ? 'Vistoria de retirada' : 'Vistoria de devolução') . ' registrada.');
    redirect(url_locacao($loc));
}

/** Vistoria para ver e imprimir (A4): itens, fotos e assinatura. */
function locacoes_vistoria_ver(): void
{
    $id = get('id');
    $v = is_uuid($id) ? one('SELECT v.*, u.nome AS usuario, l.numero, l.id AS locacao_id, c.nome AS cliente
                               FROM vistorias v JOIN locacoes l ON l.id = v.locacao_id JOIN clientes c ON c.id = l.cliente_id
                               LEFT JOIN usuarios u ON u.id = v.usuario_id WHERE v.id = :id', ['id' => $id]) : null;
    if ($v === null) {
        pagina_erro(404, 'Vistoria não encontrada.');
    }
    $itens = all('SELECT vi.estado, vi.obs, li.quantidade, p.codigo, p.descricao, p.unidade
                    FROM vistoria_itens vi JOIN locacao_itens li ON li.id = vi.locacao_item_id JOIN produtos p ON p.id = li.produto_id
                   WHERE vi.vistoria_id = :id ORDER BY p.descricao', ['id' => $v['id']]);
    $fotos = all('SELECT id FROM vistoria_fotos WHERE vistoria_id = :id ORDER BY criado_em, id', ['id' => $v['id']]);
    $titulo = $v['tipo'] === 'retirada' ? 'Vistoria de retirada' : 'Vistoria de devolução';

    print_start("$titulo · locação nº {$v['numero']}", url('locacoes', ['a' => 'ver', 'n' => $v['numero']]));
    cabecalho_impressao($titulo, 'Locação nº ' . $v['numero'], 'Feita em ' . dt($v['criado_em'], true));
    ?>
    <p><strong>Locatário:</strong> <?= e($v['cliente']) ?> · <strong>Responsável pela vistoria:</strong> <?= e($v['usuario'] ?? '') ?></p>
    <h2>Estado dos equipamentos</h2>
    <table>
        <thead><tr><th>Código</th><th>Descrição</th><th class="num">Qtd.</th><th>Estado</th><th>Observação</th></tr></thead>
        <tbody>
        <?php foreach ($itens as $it): ?>
            <tr><td><?= e($it['codigo']) ?></td><td><?= e($it['descricao']) ?></td><td class="num"><?= num($it['quantidade']) ?> <?= e($it['unidade']) ?></td>
                <td><strong><?= e(ESTADOS_VISTORIA[$it['estado']] ?? $it['estado']) ?></strong></td><td><?= e($it['obs']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($v['obs'] !== ''): ?><h2>Observações</h2><p><?= nl2br(e($v['obs'])) ?></p><?php endif; ?>
    <?php if ($fotos): ?>
        <h2>Fotos (<?= num(count($fotos)) ?>)</h2>
        <div class="fotos">
            <?php foreach ($fotos as $i => $f): ?>
                <figure><img src="<?= e(url('arquivos', ['a' => 'foto', 'id' => $f['id']])) ?>" alt="Foto <?= $i + 1 ?> da vistoria" loading="lazy">
                    <figcaption>Foto <?= $i + 1 ?></figcaption></figure>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <h2>Assinatura</h2>
    <?php if ($v['assinatura_arquivo'] !== ''): ?>
        <div class="assinatura-impressa">
            <img src="<?= e(url('arquivos', ['a' => 'assinatura', 'id' => $v['id']])) ?>" alt="Assinatura de <?= e($v['assinante_nome']) ?>">
            <p><?= e($v['assinante_nome']) ?><br><small class="muted">Assinado eletronicamente em <?= e(dt($v['assinado_em'], true)) ?>,
                na tela do sistema, concordando com o estado registrado acima.</small></p>
        </div>
    <?php else: ?>
        <p class="muted">Vistoria sem assinatura do cliente.</p>
    <?php endif; ?>
    <?php
    print_end();
}

// =====================================================================
// PATRIMÔNIO: quais unidades saíram em cada item
// =====================================================================

/** Cartão do detalhe: unidades de cada item (escolha enquanto aberta). Só aparece se algum produto tem patrimônio. */
function locacao_patrimonio_card(array $loc, array $itens): void
{
    $aberta = $loc['status'] === 'aberta';
    $alocadas = [];
    foreach (all('SELECT lu.locacao_item_id, lu.unidade_id, lu.devolucao_id, u.patrimonio, d.data AS voltou
                    FROM locacao_unidades lu JOIN unidades u ON u.id = lu.unidade_id JOIN locacao_itens li ON li.id = lu.locacao_item_id
                    LEFT JOIN devolucoes d ON d.id = lu.devolucao_id
                   WHERE li.locacao_id = :id ORDER BY u.patrimonio', ['id' => $loc['id']]) as $r) {
        $alocadas[$r['locacao_item_id']][] = $r;
    }
    // Unidades livres agora, por produto (mais as que já estão nesta locação)
    $livres = [];
    if ($aberta) {
        foreach (all('SELECT u.id, u.produto_id, u.patrimonio FROM unidades u
                       WHERE u.ativa AND u.produto_id = ANY (CAST(:p AS uuid[]))
                         AND NOT EXISTS (SELECT 1 FROM manutencoes m WHERE m.unidade_id = u.id AND m.data_fim IS NULL)
                         AND NOT EXISTS (SELECT 1 FROM locacao_unidades lu WHERE lu.unidade_id = u.id AND lu.devolucao_id IS NULL)
                       ORDER BY u.patrimonio', ['p' => '{' . implode(',', array_unique(array_column($itens, 'produto_id'))) . '}']) as $u) {
            $livres[$u['produto_id']][] = $u;
        }
    }
    $comPatrimonio = array_filter($itens, fn($it) => !empty($alocadas[$it['id']]) || !empty($livres[$it['produto_id']]));
    if (!$comPatrimonio) {
        return;
    }
    ?>
    <div class="card">
        <h2>Patrimônio (unidades que saíram)</h2>
        <form method="post" action="<?= e(url('locacoes', ['a' => 'unidades_salvar'])) ?>" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= e($loc['id']) ?>">
            <?php foreach ($comPatrimonio as $it):
                $pend = $it['quantidade'] - $it['qtd_devolvida'];
                $naRua = array_filter($alocadas[$it['id']] ?? [], fn($a) => $a['devolucao_id'] === null);
                $voltaram = array_filter($alocadas[$it['id']] ?? [], fn($a) => $a['devolucao_id'] !== null); ?>
                <div class="patrimonio-item">
                    <strong><?= e($it['descricao']) ?></strong>
                    <span class="muted"><?= $aberta ? num($pend) . ' na rua · escolha até ' . num($pend) : '' ?></span>
                    <div class="patrimonio-opcoes">
                        <?php if ($aberta): ?>
                            <input type="hidden" name="unidades[<?= e($it['id']) ?>][]" value="">
                            <?php foreach (array_merge($naRua, $livres[$it['produto_id']] ?? []) as $u): $uid = $u['unidade_id'] ?? $u['id']; ?>
                                <label class="check"><input type="checkbox" name="unidades[<?= e($it['id']) ?>][]" value="<?= e($uid) ?>"
                                    <?= isset($u['unidade_id']) ? 'checked' : '' ?>> <?= e($u['patrimonio']) ?></label>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <?= e(implode(', ', array_column($naRua, 'patrimonio')) ?: '—') ?>
                        <?php endif; ?>
                        <?php foreach ($voltaram as $v): ?>
                            <span class="selo verde"><?= e($v['patrimonio']) ?> voltou em <?= e(dt($v['voltou'])) ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if ($aberta): ?>
                <button class="btn btn-sec btn-peq">Salvar patrimônio</button>
                <span class="ajuda">Aparece no contrato. Na devolução, marque quais unidades voltaram.</span>
            <?php endif; ?>
        </form>
    </div>
    <?php
}

function locacoes_entrega_salvar(): void
{
    $loc = locacao_do_post();
    locacao_entrega_salvar($loc['id'], $_POST);
    flash('Entrega e fretes atualizados.');
    redirect(url_locacao($loc));
}

function locacoes_unidades_salvar(): void
{
    $loc = locacao_do_post();
    $porItem = [];
    foreach ((array) ($_POST['unidades'] ?? []) as $item => $lista) {
        $porItem[(string) $item] = array_values(array_filter((array) $lista, fn($v) => $v !== ''));
    }
    locacao_unidades_salvar($loc['id'], $porItem);
    flash('Patrimônio da locação atualizado.');
    redirect(url_locacao($loc));
}
