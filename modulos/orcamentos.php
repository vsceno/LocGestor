<?php
/*
 * Orçamentos: lista, cadastro, impressão (A4) e conversão em locação ou reserva.
 */

declare(strict_types=1);

function orcamentos_index(): void
{
    $situacao = get('situacao') === 'convertidos' ? 'convertido' : (get('situacao') === 'todos' ? '' : 'aberto');
    $where = $situacao === '' ? 'TRUE' : 'o.status = :s';
    $params = $situacao === '' ? [] : ['s' => $situacao];
    $pg = paginate("SELECT count(*) FROM orcamentos o WHERE $where", $params);
    $lista = all("SELECT o.id, o.numero, o.emissao, o.validade, o.data_saida, o.data_prev_devolucao, o.status,
                         o.locacao_id, o.reserva_id, c.nome, l.numero AS locacao_numero, r.numero AS reserva_numero
                    FROM orcamentos o JOIN clientes c ON c.id = o.cliente_id
                    LEFT JOIN locacoes l ON l.id = o.locacao_id LEFT JOIN reservas r ON r.id = o.reserva_id
                   WHERE $where ORDER BY o.numero DESC LIMIT {$pg['por']} OFFSET {$pg['offset']}", $params);
    $valores = [];
    foreach ($lista as $o) {
        $valores[$o['id']] = orcamento_previsto(one('SELECT * FROM orcamentos WHERE id = :id', ['id' => $o['id']]),
                                                all('SELECT oi.quantidade, oi.valor_diaria, oi.valor_semanal, oi.valor_mensal
                                                       FROM orcamento_itens oi WHERE oi.orcamento_id = :id', ['id' => $o['id']]));
    }
    layout_start('Orçamentos', 'orcamentos');
    ?>
    <div class="card">
        <div class="card-titulo">
            <h2>Orçamentos</h2>
            <a class="btn" href="<?= e(url('orcamentos', ['a' => 'novo'])) ?>">+ Novo orçamento</a>
        </div>
        <p class="ajuda">Cotação com validade. Não reserva equipamento: para garantir, converta em reserva (ou em locação, se o equipamento já saiu).</p>
        <form method="get" class="filtros">
            <input type="hidden" name="p" value="orcamentos">
            <select name="situacao" aria-label="Situação"><?= options(['aberto' => 'Em aberto', 'convertidos' => 'Convertidos', 'todos' => 'Todos'],
                get('situacao') ?: 'aberto') ?></select>
            <button class="btn btn-sec">Filtrar</button>
        </form>
        <div class="tabela-wrap">
            <table class="tabela">
                <thead><tr><th>Nº</th><th>Cliente</th><th>Período</th><th>Validade</th><th class="num">Valor previsto</th><th>Situação</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($lista as $o):
                    $vencido = $o['status'] === 'aberto' && $o['validade'] < today(); ?>
                    <tr>
                        <td><a href="<?= e(url('orcamentos', ['a' => 'ver', 'id' => $o['id']])) ?>"><?= num($o['numero']) ?></a></td>
                        <td><?= e($o['nome']) ?></td>
                        <td class="nowrap"><?= e(dt($o['data_saida'])) ?> a <?= e(dt($o['data_prev_devolucao'])) ?></td>
                        <td class="nowrap"><?= e(dt($o['validade'])) ?> <?= $vencido ? '<span class="selo vermelho">vencido</span>' : '' ?></td>
                        <td class="num"><?= money($valores[$o['id']] ?? 0) ?></td>
                        <td><?php if ($o['status'] === 'convertido'): ?>
                                <span class="selo verde"><?= $o['locacao_id'] ? 'locação nº ' . e($o['locacao_numero']) : 'reserva nº ' . e($o['reserva_numero']) ?></span>
                            <?php else: ?><span class="selo azul">em aberto</span><?php endif; ?></td>
                        <td><a class="btn btn-sec btn-peq" href="<?= e(url('orcamentos', ['a' => 'ver', 'id' => $o['id']])) ?>">Abrir</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$lista): ?><tr><td colspan="7" class="vazio">Nenhum orçamento.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <?= pager($pg, 'orcamentos', array_filter(['situacao' => get('situacao')])) ?>
    </div>
    <?php
    layout_end();
}

function orcamento_da_url(): array
{
    $id = get('id');
    $o = is_uuid($id) ? one('SELECT o.*, c.nome AS cliente_nome, c.cpf_cnpj AS cliente_doc, c.tipo AS cliente_tipo, c.celular, c.telefone,
                                    l.numero AS locacao_numero, r.numero AS reserva_numero
                               FROM orcamentos o JOIN clientes c ON c.id = o.cliente_id
                               LEFT JOIN locacoes l ON l.id = o.locacao_id LEFT JOIN reservas r ON r.id = o.reserva_id
                              WHERE o.id = :id', ['id' => $id]) : null;
    if ($o === null) {
        pagina_erro(404, 'Orçamento não encontrado.');
    }
    return $o;
}

function orcamento_itens(string $id): array
{
    return all('SELECT oi.quantidade, oi.valor_diaria, oi.valor_semanal, oi.valor_mensal, p.codigo, p.descricao, p.unidade
                  FROM orcamento_itens oi JOIN produtos p ON p.id = oi.produto_id
                 WHERE oi.orcamento_id = :id ORDER BY p.descricao', ['id' => $id]);
}

function orcamentos_novo(): void
{
    $clienteTxt = '';
    $saida = date('Y-m-d', strtotime('+1 day'));
    $prev = date('Y-m-d', strtotime('+2 days'));
    $validade = date('Y-m-d', strtotime('+7 days'));
    $linhas = [];
    if (has_old()) {
        $clienteTxt = old('cliente');
        $saida = old('data_saida', $saida);
        $prev = old('data_prev_devolucao', $prev);
        $validade = old('validade', $validade);
        foreach ((array) old('produto_id', []) as $i => $pid) {
            $linhas[] = ['produto_id' => $pid, 'quantidade' => old('quantidade', [])[$i] ?? '', 'diaria' => old('diaria', [])[$i] ?? ''];
        }
    }
    if (!$linhas) {
        $linhas[] = ['produto_id' => '', 'quantidade' => '1', 'diaria' => ''];
    }
    $clientes = all("SELECT nome, cpf_cnpj FROM clientes WHERE ativo AND NOT bloqueado ORDER BY lower(nome)");
    $produtos = all('SELECT id, codigo, descricao, unidade, valor_diaria, valor_semanal, valor_mensal FROM produtos WHERE ativo ORDER BY descricao');

    layout_start('Novo orçamento', 'orcamentos');
    ?>
    <form method="post" action="<?= e(url('orcamentos', ['a' => 'salvar'])) ?>" class="form" id="form-locacao" data-itens
          data-api="<?= e(url('api', ['a' => 'disponivel'])) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="reserva_id" id="reserva_id" value="">
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
                <label class="obrig">Data de saída prevista
                    <input type="date" name="data_saida" id="data_saida" value="<?= e($saida) ?>" required>
                </label>
                <label class="obrig">Devolução prevista
                    <input type="date" name="data_prev_devolucao" id="data_prev" value="<?= e($prev) ?>" required>
                    <span class="ajuda" id="info-dias"></span>
                </label>
                <label class="obrig">Validade do orçamento
                    <input type="date" name="validade" value="<?= e($validade) ?>" required>
                    <span class="ajuda">Até 90 dias.</span>
                </label>
            </div>
            <label>Local de uso (obra/evento)
                <input type="text" name="local_uso" value="<?= e(old('local_uso')) ?>" maxlength="200">
            </label>
        </div>
        <div class="card">
            <div class="card-titulo">
                <h2>Itens</h2>
                <button type="button" class="btn btn-sec btn-peq" id="add-item">+ Adicionar item</button>
            </div>
            <p class="ajuda">O preço do cadastro (diária, semana e mês) entra sozinho; você pode negociar a diária aqui.</p>
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
                <label>Acréscimo (montagem etc.) R$<input type="text" name="acrescimo" id="acrescimo" value="<?= e(old('acrescimo')) ?>" inputmode="decimal" placeholder="0,00"></label>
                <label>Frete de entrega R$<input type="text" name="frete_entrega" id="frete-entrega" value="<?= e(old('frete_entrega')) ?>" inputmode="decimal" placeholder="0,00"></label>
                <label>Frete de recolhimento R$<input type="text" name="frete_recolhimento" id="frete-recolhimento" value="<?= e(old('frete_recolhimento')) ?>" inputmode="decimal" placeholder="0,00"></label>
                <label>Desconto R$<input type="text" name="desconto" id="desconto" value="<?= e(old('desconto')) ?>" inputmode="decimal" placeholder="0,00"></label>
                <?php if (!is_admin()): ?><label>Senha do administrador<input type="password" name="senha_admin" autocomplete="new-password" placeholder="Só com desconto ou acréscimo"></label><?php endif; ?>
            </div>
            <p class="total-previsto">Total previsto: <strong id="total-previsto">R$ 0,00</strong>
                <span class="muted" id="total-detalhe"></span></p>
            <label>Observações<textarea name="obs" maxlength="2000"><?= e(old('obs')) ?></textarea></label>
        </div>
        <div class="acoes">
            <button type="submit" class="btn">Salvar orçamento</button>
            <a class="btn btn-sec" href="<?= e(url('orcamentos')) ?>">Cancelar</a>
        </div>
    </form>
    <?php
    layout_end(['assets/js/app.js']);
}

function orcamentos_salvar(): void
{
    $id = post('id');
    if ($id !== '' && !is_uuid($id)) {
        throw new UserError('Orçamento inválido.');
    }
    try {
        $r = orcamento_salvar($_POST, $id === '' ? null : $id);
    } catch (UserError $e) {
        // Recusa: volta ao formulário, com o que foi digitado
        $_SESSION['old'] = array_filter($_POST, fn($k) => $k !== '_csrf' && !str_contains((string) $k, 'senha'), ARRAY_FILTER_USE_KEY);
        flash($e->getMessage(), 'erro');
        redirect(url('orcamentos', ['a' => 'novo']));
    }
    flash("Orçamento nº {$r['numero']} salvo.");
    redirect(url('orcamentos', ['a' => 'ver', 'id' => $r['id']]));
}

function orcamentos_ver(): void
{
    $o = orcamento_da_url();
    $itens = orcamento_itens($o['id']);
    $previsto = orcamento_previsto($o, $itens);
    $vencido = $o['status'] === 'aberto' && $o['validade'] < today();
    layout_start('Orçamento nº ' . $o['numero'], 'orcamentos');
    ?>
    <div class="grid-2">
        <div class="card">
            <div class="card-titulo">
                <h2>Orçamento nº <?= num($o['numero']) ?></h2>
                <?= $o['status'] === 'convertido'
                    ? '<span class="selo verde">' . ($o['locacao_id'] ? 'convertido em locação nº ' . e($o['locacao_numero']) : 'convertido em reserva nº ' . e($o['reserva_numero'])) . '</span>'
                    : ($vencido ? '<span class="selo vermelho">vencido</span>' : '<span class="selo azul">em aberto</span>') ?>
            </div>
            <dl class="dados">
                <dt>Cliente</dt><dd><?= e($o['cliente_nome']) ?> <span class="muted"><?= e(doc_formatar($o['cliente_doc'])) ?></span></dd>
                <dt>Período</dt><dd><?= e(dt($o['data_saida'])) ?> a <?= e(dt($o['data_prev_devolucao'])) ?></dd>
                <dt>Validade</dt><dd><?= e(dt($o['validade'])) ?></dd>
                <?php if ($o['local_uso'] !== ''): ?><dt>Local de uso</dt><dd><?= e($o['local_uso']) ?></dd><?php endif; ?>
                <dt>Valor previsto</dt><dd><strong><?= money($previsto) ?></strong></dd>
            </dl>
            <div class="acoes" style="margin-top:12px">
                <a class="btn btn-sec" href="<?= e(url('orcamentos', ['a' => 'imprimir', 'id' => $o['id']])) ?>">Imprimir</a>
                <?php if ($o['celular'] !== '' || $o['telefone'] !== ''): ?>
                    <?= botao_whatsapp(whatsapp_url(whatsapp_numero(['celular' => $o['celular'], 'telefone' => $o['telefone']]),
                        whatsapp_saudacao(['nome' => $o['cliente_nome'], 'tipo' => $o['cliente_tipo']]) . " Segue o orçamento nº {$o['numero']}: " . money($previsto) . ' até ' . dt($o['validade']) . '.'), 'Enviar no WhatsApp') ?>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($o['status'] === 'aberto'): ?>
        <div class="card">
            <h2>Converter</h2>
            <p class="ajuda">Ao converter, o orçamento fica registrado e não pode mais ser alterado.</p>
            <?php if ($o['data_saida'] <= today()): ?>
                <form method="post" action="<?= e(url('orcamentos', ['a' => 'converter'])) ?>" data-confirmar="Virar locação? O equipamento sai agora.">
                    <?= csrf_field() ?><input type="hidden" name="id" value="<?= e($o['id']) ?>"><input type="hidden" name="tipo" value="locacao">
                    <button class="btn">Transformar em locação</button>
                </form>
            <?php else: ?>
                <p class="muted">A saída é no futuro: só dá para transformar em reserva.</p>
            <?php endif; ?>
            <form method="post" action="<?= e(url('orcamentos', ['a' => 'converter'])) ?>" style="margin-top:8px"
                  data-confirmar="Transformar em reserva? Os equipamentos ficam garantidos no período.">
                <?= csrf_field() ?><input type="hidden" name="id" value="<?= e($o['id']) ?>"><input type="hidden" name="tipo" value="reserva">
                <button class="btn btn-sec">Transformar em reserva</button>
            </form>
            <form method="post" action="<?= e(url('orcamentos', ['a' => 'excluir'])) ?>" style="margin-top:14px"
                  data-confirmar="Excluir este orçamento?">
                <?= csrf_field() ?><input type="hidden" name="id" value="<?= e($o['id']) ?>">
                <button class="btn btn-perigo btn-peq">Excluir orçamento</button>
            </form>
        </div>
        <?php endif; ?>
    </div>
    <div class="card">
        <h2>Itens</h2>
        <div class="tabela-wrap">
            <table class="tabela">
                <thead><tr><th>Código</th><th>Produto</th><th class="num">Qtd.</th><th class="num">Preço</th></tr></thead>
                <tbody>
                <?php foreach ($itens as $it): ?>
                    <tr><td><code><?= e($it['codigo']) ?></code></td><td><?= e($it['descricao']) ?></td>
                        <td class="num"><?= num($it['quantidade']) ?> <?= e($it['unidade']) ?></td><td class="num"><?= precos_html($it) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <a class="btn btn-sec" href="<?= e(url('orcamentos')) ?>">Voltar</a>
    <?php
    layout_end();
}

function orcamentos_imprimir(): void
{
    $o = orcamento_da_url();
    $itens = orcamento_itens($o['id']);
    $previsto = orcamento_previsto($o, $itens);
    $dias = dias_cobranca($o['data_saida'], $o['data_prev_devolucao']);
    $emp = empresa();
    print_start('Orçamento nº ' . $o['numero'], url('orcamentos', ['a' => 'ver', 'id' => $o['id']]));
    cabecalho_impressao('Orçamento', 'Nº ' . $o['numero']);
    ?>
    <h2>Cliente</h2>
    <p><strong><?= e($o['cliente_nome']) ?></strong> · <?= e(doc_formatar($o['cliente_doc'])) ?></p>
    <h2>Período</h2>
    <p>Retirada em <?= e(dt($o['data_saida'])) ?> · devolução em <?= e(dt($o['data_prev_devolucao'])) ?> (<?= num($dias) ?> dia(s))
       <?= $o['local_uso'] !== '' ? ' · local: ' . e($o['local_uso']) : '' ?></p>
    <h2>Itens</h2>
    <table>
        <thead><tr><th class="num">Qtd.</th><th>Código</th><th>Descrição</th><th class="num">Preço</th><th class="num">Subtotal</th></tr></thead>
        <tbody>
        <?php foreach ($itens as $it): $sub = (int) $it['quantidade'] * preco_item($it, $dias); ?>
            <tr><td class="num"><?= num($it['quantidade']) ?> <?= e($it['unidade']) ?></td><td class="nowrap"><?= e($it['codigo']) ?></td>
                <td><?= e($it['descricao']) ?></td><td class="num"><?= precos_html($it) ?></td><td class="num"><?= money($sub) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <?php if ((float) $o['acrescimo'] > 0): ?><tr><td colspan="4" class="num">Acréscimo</td><td class="num"><?= money($o['acrescimo']) ?></td></tr><?php endif; ?>
            <?php if ((float) $o['frete_entrega'] > 0): ?><tr><td colspan="4" class="num">Frete de entrega</td><td class="num"><?= money($o['frete_entrega']) ?></td></tr><?php endif; ?>
            <?php if ((float) $o['frete_recolhimento'] > 0): ?><tr><td colspan="4" class="num">Frete de recolhimento</td><td class="num"><?= money($o['frete_recolhimento']) ?></td></tr><?php endif; ?>
            <?php if ((float) $o['desconto'] > 0): ?><tr><td colspan="4" class="num">Desconto</td><td class="num">− <?= money($o['desconto']) ?></td></tr><?php endif; ?>
            <tr><td colspan="4" class="num">Total previsto</td><td class="num"><?= money($previsto) ?></td></tr>
        </tfoot>
    </table>
    <p class="obs-fiscal">Orçamento válido até <?= e(dt($o['validade'])) ?>. Não reserva equipamento: a disponibilidade é confirmada na reserva ou na retirada.
       Valores em reais, para o período informado.<?= $o['obs'] !== '' ? ' Observações: ' . e($o['obs']) : '' ?></p>
    <?php if (trim((string) $emp['cidade']) !== ''): ?><p style="margin-top:6mm"><?= e($emp['cidade']) ?>, <?= e(dt(today())) ?>.</p><?php endif; ?>
    <?php
    print_end();
}

function orcamentos_converter(): void
{
    $id = post('id');
    if (!is_uuid($id)) {
        throw new UserError('Orçamento não encontrado.');
    }
    try {
        $r = orcamento_converter($id, post('tipo'));
    } catch (UserError $e) {
        // Recusa: volta para o próprio orçamento, com o motivo
        flash($e->getMessage(), 'erro');
        redirect(url('orcamentos', ['a' => 'ver', 'id' => $id]));
    }
    flash($r['tipo'] === 'locacao' ? "Orçamento convertido em locação nº {$r['numero']}." : "Orçamento convertido em reserva nº {$r['numero']}.");
    redirect($r['tipo'] === 'locacao' ? url('locacoes', ['a' => 'ver', 'n' => $r['numero']]) : url('reservas', ['a' => 'ver', 'n' => $r['numero']]));
}

function orcamentos_excluir(): void
{
    $id = post('id');
    if (!is_uuid($id)) {
        throw new UserError('Orçamento não encontrado.');
    }
    try {
        orcamento_excluir($id);
    } catch (UserError $e) {
        flash($e->getMessage(), 'erro');
        redirect(url('orcamentos', ['a' => 'ver', 'id' => $id]));
    }
    flash('Orçamento excluído.');
    redirect(url('orcamentos'));
}
