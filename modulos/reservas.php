<?php
/*
 * Reservas: garantem equipamentos para uma data futura. Bloqueiam o estoque no período
 * e viram locação pelo botão "Retirar → locação" (saída = hoje).
 */

declare(strict_types=1);

const ABAS_RESERVA = ['ativa' => 'Ativas', 'convertida' => 'Convertidas', 'cancelada' => 'Canceladas'];

function reserva_da_url(): array
{
    $n = get('n');
    $r = ($n !== '' && ctype_digit($n) && strlen($n) < 10)
        ? one('SELECT r.*, c.nome AS cliente_nome, c.cpf_cnpj AS cliente_doc FROM reservas r
                 JOIN clientes c ON c.id = r.cliente_id WHERE r.numero = :n', ['n' => (int) $n])
        : null;
    if ($r === null) {
        pagina_erro(404, 'Reserva não encontrada.');
    }
    return $r;
}

/** Selos "vencida" (passou do fim sem retirar) e "retirar hoje" (dentro do período). */
function selo_reserva(array $r): string
{
    if ($r['status'] === 'ativa') {
        if ($r['data_fim'] < today()) {
            return '<span class="selo vermelho">vencida</span>';
        }
        if ($r['data_inicio'] <= today()) {
            return '<span class="selo laranja">retirar hoje</span>';
        }
        return '<span class="selo azul">ativa</span>';
    }
    return $r['status'] === 'convertida' ? '<span class="selo verde">convertida</span>' : '<span class="selo">cancelada</span>';
}

function reservas_index(): void
{
    $aba = get('aba', 'ativa');
    $aba = isset(ABAS_RESERVA[$aba]) ? $aba : 'ativa';
    $cont = one("SELECT count(*) FILTER (WHERE status = 'ativa') AS ativa, count(*) FILTER (WHERE status = 'convertida') AS convertida,
                        count(*) FILTER (WHERE status = 'cancelada') AS cancelada FROM reservas");
    $pg = paginate('SELECT count(*) FROM reservas WHERE status = :s', ['s' => $aba]);
    $reservas = all("SELECT r.id, r.numero, r.data_inicio, r.data_fim, r.status, c.nome AS cliente,
                            (SELECT string_agg(ri.quantidade || ' ' || p.descricao, ', ' ORDER BY p.descricao)
                               FROM reserva_itens ri JOIN produtos p ON p.id = ri.produto_id WHERE ri.reserva_id = r.id) AS itens,
                            (SELECT l.numero FROM locacoes l WHERE l.reserva_id = r.id LIMIT 1) AS locacao
                       FROM reservas r JOIN clientes c ON c.id = r.cliente_id
                      WHERE r.status = :s
                      ORDER BY " . ($aba === 'ativa' ? 'r.data_inicio, r.numero' : 'r.data_inicio DESC, r.numero DESC') . "
                      LIMIT {$pg['por']} OFFSET {$pg['offset']}", ['s' => $aba]);

    layout_start('Reservas', 'reservas');
    ?>
    <div class="card">
        <div class="card-titulo">
            <h2>Reservas</h2>
            <a class="btn" href="<?= e(url('reservas', ['a' => 'nova'])) ?>">+ Nova reserva</a>
        </div>
        <nav class="abas">
            <?php foreach (ABAS_RESERVA as $k => $rot): ?>
                <a href="<?= e(url('reservas', ['aba' => $k])) ?>"<?= $k === $aba ? ' class="ativa"' : '' ?>><?= e($rot) ?> <span class="cont">(<?= num($cont[$k]) ?>)</span></a>
            <?php endforeach; ?>
        </nav>
        <div class="tabela-wrap">
            <table class="tabela">
                <thead><tr><th>Nº</th><th>Cliente</th><th>Retirada</th><th>Devolução</th><th>Itens</th><th>Situação</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($reservas as $r): ?>
                    <tr>
                        <td><a href="<?= e(url('reservas', ['a' => 'ver', 'n' => $r['numero']])) ?>"><strong><?= e($r['numero']) ?></strong></a></td>
                        <td><?= e($r['cliente']) ?></td>
                        <td><?= e(dt($r['data_inicio'])) ?></td>
                        <td><?= e(dt($r['data_fim'])) ?></td>
                        <td><?= e($r['itens']) ?></td>
                        <td><?= selo_reserva($r) ?>
                            <?php if ($r['locacao']): ?><a href="<?= e(url('locacoes', ['a' => 'ver', 'n' => $r['locacao']])) ?>">locação nº <?= e($r['locacao']) ?></a><?php endif; ?></td>
                        <td class="nowrap">
                            <?php if ($r['status'] === 'ativa'): ?>
                                <a class="btn btn-peq" href="<?= e(url('locacoes', ['a' => 'nova', 'reserva' => $r['id']])) ?>">Retirar → locação</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$reservas): ?><tr><td colspan="7" class="vazio">Nenhuma reserva nesta aba.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <?= pager($pg, 'reservas', ['aba' => $aba]) ?>
    </div>
    <?php
    layout_end();
}

function reservas_nova(): void
{
    reserva_form(null);
}

function reservas_editar(): void
{
    $r = reserva_da_url();
    if ($r['status'] !== 'ativa') {
        pagina_erro(200, 'Só é possível alterar reserva ativa.');
    }
    reserva_form($r);
}

function reserva_form(?array $r): void
{
    $clienteTxt = $r ? cliente_rotulo(['nome' => $r['cliente_nome'], 'cpf_cnpj' => $r['cliente_doc']]) : '';
    $ini = $r['data_inicio'] ?? date('Y-m-d', strtotime('+1 day'));
    $fim = $r['data_fim'] ?? date('Y-m-d', strtotime('+2 days'));
    $linhas = $r ? all("SELECT produto_id, quantidade, '' AS diaria FROM reserva_itens WHERE reserva_id = :id", ['id' => $r['id']]) : [];
    if (has_old()) {
        $clienteTxt = old('cliente');
        $ini = old('data_inicio', $ini);
        $fim = old('data_fim', $fim);
        $linhas = [];
        foreach ((array) old('produto_id', []) as $i => $pid) {
            $linhas[] = ['produto_id' => $pid, 'quantidade' => old('quantidade', [])[$i] ?? '', 'diaria' => '',
                         'periodo' => old('periodo', [])[$i] ?? 'diaria'];
        }
    }
    if (!$linhas) {
        $linhas[] = ['produto_id' => '', 'quantidade' => '1', 'diaria' => ''];
    }
    $clientes = all('SELECT nome, cpf_cnpj FROM clientes WHERE ativo AND NOT bloqueado ORDER BY lower(nome)');
    $produtos = all('SELECT id, codigo, descricao, valor_diaria FROM produtos WHERE ativo ORDER BY descricao');

    layout_start($r ? 'Editar reserva nº ' . $r['numero'] : 'Nova reserva', 'reservas');
    ?>
    <form method="post" action="<?= e(url('reservas', ['a' => 'salvar'])) ?>" class="form" data-itens
          data-api="<?= e(url('api', ['a' => 'disponivel'])) ?>">
        <?= csrf_field() ?>
        <!-- reserva_id: na edição, a própria reserva não conta contra o estoque -->
        <input type="hidden" name="id" id="reserva_id" value="<?= e($r['id'] ?? '') ?>">
        <div class="card">
            <h2>Cliente</h2>
            <label class="obrig">Cliente
                <input type="text" name="cliente" value="<?= e($clienteTxt) ?>" list="lista-clientes" required autocomplete="off"
                       placeholder="Digite o nome e escolha na lista">
                <datalist id="lista-clientes">
                    <?php foreach ($clientes as $c): ?><option value="<?= e(cliente_rotulo($c)) ?>"></option><?php endforeach; ?>
                </datalist>
            </label>
            <div class="linha">
                <label class="obrig">Retirada
                    <input type="date" name="data_inicio" id="data_saida" value="<?= e($ini) ?>" min="<?= e(today()) ?>" required>
                </label>
                <label class="obrig">Devolução
                    <input type="date" name="data_fim" id="data_prev" value="<?= e($fim) ?>" min="<?= e(today()) ?>" required>
                    <span class="ajuda" id="info-dias"></span>
                </label>
            </div>
        </div>
        <div class="card">
            <div class="card-titulo">
                <h2>Itens reservados</h2>
                <div class="acoes"><?= kit_seletor_html(kits_para_form()) ?>
                    <button type="button" class="btn btn-sec btn-peq" id="add-item">+ Adicionar item</button></div>
            </div>
            <div class="tabela-wrap">
                <table class="tabela" id="tabela-itens">
                    <thead><tr><th style="min-width:240px">Produto</th><th>Período</th><th class="num">Qtd.</th><th class="num">Disponível no período</th><th></th></tr></thead>
                    <tbody><?php foreach ($linhas as $ln) { linha_item_html($produtos, $ln, false, false, true); } ?></tbody>
                </table>
            </div>
            <template id="modelo-linha"><?php linha_item_html($produtos, ['produto_id' => '', 'quantidade' => '1', 'diaria' => '', 'periodo' => 'diaria'], false, false, true); ?></template>
            <label>Observações<textarea name="obs" maxlength="2000"><?= e(old('obs', $r['obs'] ?? '')) ?></textarea></label>
        </div>
        <div class="acoes">
            <button type="submit" class="btn"><?= $r ? 'Salvar alterações' : 'Reservar' ?></button>
            <a class="btn btn-sec" href="<?= e($r ? url('reservas', ['a' => 'ver', 'n' => $r['numero']]) : url('reservas')) ?>">Cancelar</a>
        </div>
    </form>
    <?php
    layout_end();
}

function reservas_salvar(): void
{
    $id = post('id');
    if ($id !== '' && !is_uuid($id)) {
        throw new UserError('Reserva inválida.');
    }
    $r = reserva_salvar($_POST, $id === '' ? null : $id);
    flash($id === '' ? "Reserva nº {$r['numero']} registrada." : "Reserva nº {$r['numero']} alterada.");
    redirect(url('reservas', ['a' => 'ver', 'n' => $r['numero']]));
}

function reservas_ver(): void
{
    $r = reserva_da_url();
    $itens = all('SELECT ri.quantidade, ri.periodo, p.codigo, p.descricao, p.unidade, p.valor_diaria, p.valor_semanal, p.valor_quinzenal, p.valor_mensal
                    FROM reserva_itens ri JOIN produtos p ON p.id = ri.produto_id
                   WHERE ri.reserva_id = :id ORDER BY p.descricao', ['id' => $r['id']]);
    $locacao = val('SELECT numero FROM locacoes WHERE reserva_id = :id LIMIT 1', ['id' => $r['id']]);
    $dias = dias_cobranca($r['data_inicio'], $r['data_fim']);
    $estimado = array_sum(array_map(fn($i) => $i['quantidade'] * preco_item($i, $dias), $itens));

    layout_start('Reserva nº ' . $r['numero'], 'reservas');
    ?>
    <div class="grid-2">
        <div class="card">
            <div class="card-titulo"><h2>Dados da reserva</h2><div><?= selo_reserva($r) ?></div></div>
            <dl class="dados">
                <dt>Cliente</dt><dd><a href="<?= e(url('clientes', ['a' => 'ver', 'id' => $r['cliente_id']])) ?>"><?= e($r['cliente_nome']) ?></a></dd>
                <dt>Retirada</dt><dd><?= e(dt($r['data_inicio'])) ?></dd>
                <dt>Devolução</dt><dd><?= e(dt($r['data_fim'])) ?> (<?= num($dias) ?> dia(s))</dd>
                <dt>Valor estimado</dt><dd><?= money($estimado) ?> <span class="muted">(pelas diárias atuais)</span></dd>
                <?php if ($locacao): ?><dt>Locação</dt><dd><a href="<?= e(url('locacoes', ['a' => 'ver', 'n' => $locacao])) ?>">nº <?= e($locacao) ?></a></dd><?php endif; ?>
                <?php if ($r['obs'] !== ''): ?><dt>Observações</dt><dd><?= nl2br(e($r['obs'])) ?></dd><?php endif; ?>
                <dt>Registrada em</dt><dd><?= e(dt($r['criado_em'], true)) ?></dd>
            </dl>
        </div>
        <div class="card">
            <h2>Ações</h2>
            <?php if ($r['status'] === 'ativa'): ?>
                <div class="acoes">
                    <a class="btn" href="<?= e(url('locacoes', ['a' => 'nova', 'reserva' => $r['id']])) ?>">Retirar → locação</a>
                    <?= botao_whatsapp(whatsapp_reserva($r), 'Confirmar no WhatsApp') ?>
                    <a class="btn btn-sec" href="<?= e(url('reservas', ['a' => 'editar', 'n' => $r['numero']])) ?>">Editar</a>
                    <form method="post" action="<?= e(url('reservas', ['a' => 'cancelar'])) ?>" data-confirmar="Cancelar a reserva nº <?= e($r['numero']) ?>?">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= e($r['id']) ?>">
                        <button class="btn btn-perigo">Cancelar reserva</button>
                    </form>
                </div>
                <p class="muted">"Retirar" abre uma nova locação com saída hoje, já preenchida com estes itens.</p>
            <?php else: ?>
                <p class="muted">Reserva <?= e($r['status']) ?>: nenhuma ação disponível.</p>
            <?php endif; ?>
        </div>
    </div>
    <div class="card">
        <h2>Itens reservados</h2>
        <div class="tabela-wrap">
            <table class="tabela">
                <thead><tr><th>Código</th><th>Produto</th><th class="num">Qtd.</th><th>Período</th><th class="num">Preço atual</th></tr></thead>
                <tbody>
                <?php foreach ($itens as $i): ?>
                    <tr><td><code><?= e($i['codigo']) ?></code></td><td><?= e($i['descricao']) ?></td>
                        <td class="num"><?= num($i['quantidade']) ?> <?= e($i['unidade']) ?></td><td><?= periodo_html($i) ?></td><td class="num"><?= precos_html($i) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
    layout_end();
}

function reservas_cancelar(): void
{
    $id = post('id');
    if (!is_uuid($id)) {
        throw new UserError('Reserva inválida.');
    }
    reserva_cancelar($id);
    $n = val('SELECT numero FROM reservas WHERE id = :id', ['id' => $id]);
    flash("Reserva nº $n cancelada.");
    redirect(url('reservas', ['a' => 'ver', 'n' => $n]));
}
