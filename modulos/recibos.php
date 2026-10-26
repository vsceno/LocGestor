<?php
/*
 * Recibos numerados: lista, emissão (avulso ou a partir de uma locação) e impressão (A4).
 */

declare(strict_types=1);

function recibo_da_url(): array
{
    $id = get('id');
    $r = is_uuid($id) ? one('SELECT r.*, l.numero AS locacao_numero FROM recibos r
                              LEFT JOIN locacoes l ON l.id = r.locacao_id WHERE r.id = :id', ['id' => $id]) : null;
    if ($r === null) {
        pagina_erro(404, 'Recibo não encontrado.');
    }
    return $r;
}

function recibos_index(): void
{
    $pg = paginate('SELECT count(*) FROM recibos');
    $lista = all("SELECT r.id, r.numero, r.emissao, r.recebido_de, r.valor, r.referente, l.numero AS locacao_numero
                    FROM recibos r LEFT JOIN locacoes l ON l.id = r.locacao_id
                   ORDER BY r.numero DESC LIMIT {$pg['por']} OFFSET {$pg['offset']}");

    layout_start('Recibos', 'recibos');
    ?>
    <div class="card">
        <div class="card-titulo">
            <h2>Recibos</h2>
            <div class="acoes">
                <a class="btn" href="<?= e(url('recibos', ['a' => 'novo'])) ?>">+ Novo recibo</a>
            </div>
        </div>
        <p class="ajuda">Recibo tem número fixo e não se altera nem se apaga. O pagamento continua sendo lançado no caixa.</p>
        <div class="tabela-wrap">
            <table class="tabela">
                <thead><tr><th class="num">Nº</th><th>Emissão</th><th>Recebido de</th><th>Referente a</th><th>Locação</th><th class="num">Valor</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($lista as $r): ?>
                    <tr>
                        <td class="num"><a href="<?= e(url('recibos', ['a' => 'ver', 'id' => $r['id']])) ?>"><?= num($r['numero']) ?></a></td>
                        <td class="nowrap"><?= e(dt($r['emissao'])) ?></td>
                        <td><?= e($r['recebido_de']) ?></td>
                        <td><?= e($r['referente']) ?></td>
                        <td><?= $r['locacao_numero'] !== null ? 'nº ' . e($r['locacao_numero']) : '—' ?></td>
                        <td class="num"><?= money($r['valor']) ?></td>
                        <td><a class="btn btn-sec btn-peq" href="<?= e(url('recibos', ['a' => 'imprimir', 'id' => $r['id']])) ?>" target="_blank">Imprimir</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$lista): ?><tr><td colspan="7" class="vazio">Nenhum recibo emitido ainda.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <?= pager($pg, 'recibos') ?>
    </div>
    <?php
    layout_end();
}

function recibos_novo(): void
{
    // Vindo de uma locação: já preenche quem pagou (cliente) e a referência
    $loc = is_uuid(get('locacao')) ? one('SELECT l.id, l.numero, c.nome, c.cpf_cnpj FROM locacoes l
                                           JOIN clientes c ON c.id = l.cliente_id WHERE l.id = :id', ['id' => get('locacao')]) : null;
    $pre = [
        'recebido_de' => $loc['nome'] ?? '',
        'documento'   => $loc['cpf_cnpj'] ?? '',
        'referente'   => $loc ? 'Pagamento da locação nº ' . $loc['numero'] : '',
        'locacao_id'  => $loc['id'] ?? '',
    ];

    layout_start('Novo recibo', 'recibos');
    ?>
    <form method="post" action="<?= e(url('recibos', ['a' => 'salvar'])) ?>" class="form">
        <?= csrf_field() ?>
        <input type="hidden" name="locacao_id" value="<?= e(old('locacao_id', $pre['locacao_id'])) ?>">
        <div class="card">
            <h2>Recibo</h2>
            <?php if ($loc): ?><p class="ajuda">Ligado à locação nº <?= num($loc['numero']) ?>.</p><?php endif; ?>
            <div class="linha">
                <label class="obrig">Recebido de
                    <input type="text" name="recebido_de" value="<?= e(old('recebido_de', $pre['recebido_de'])) ?>" required maxlength="150">
                </label>
                <label>CPF ou CNPJ
                    <input type="text" name="documento" value="<?= e(old('documento', $pre['documento'])) ?>" maxlength="18" inputmode="numeric">
                </label>
            </div>
            <div class="linha">
                <label class="obrig">Valor (R$)
                    <input type="text" name="valor" value="<?= e(old('valor')) ?>" required inputmode="decimal" placeholder="0,00">
                </label>
                <label class="obrig">Forma
                    <select name="forma" required><?= options(FORMAS_PGTO, old('forma', 'PIX')) ?></select>
                </label>
            </div>
            <label class="obrig">Referente a
                <input type="text" name="referente" value="<?= e(old('referente', $pre['referente'])) ?>" required maxlength="200">
            </label>
        </div>
        <div class="acoes">
            <button class="btn">Emitir recibo</button>
            <a class="btn btn-sec" href="<?= e(url('recibos')) ?>">Cancelar</a>
        </div>
    </form>
    <?php
    layout_end();
}

function recibos_salvar(): void
{
    try {
        $r = recibo_salvar($_POST);
    } catch (UserError $e) {
        // Recusa: volta ao formulário com o que foi digitado (o documento não vai para a URL)
        $_SESSION['old'] = array_filter($_POST, fn($k) => $k !== '_csrf', ARRAY_FILTER_USE_KEY);
        flash($e->getMessage(), 'erro');
        $loc = is_uuid(post('locacao_id')) ? ['locacao' => post('locacao_id')] : [];
        redirect(url('recibos', ['a' => 'novo'] + $loc));
    }
    flash("Recibo nº {$r['numero']} emitido.");
    redirect(url('recibos', ['a' => 'ver', 'id' => $r['id']]));
}

function recibos_ver(): void
{
    $r = recibo_da_url();

    layout_start('Recibo nº ' . $r['numero'], 'recibos');
    ?>
    <div class="card">
        <div class="card-titulo">
            <h2>Recibo nº <?= num($r['numero']) ?></h2>
            <div class="acoes">
                <a class="btn" href="<?= e(url('recibos', ['a' => 'imprimir', 'id' => $r['id']])) ?>" target="_blank">Imprimir</a>
                <a class="btn btn-sec" href="<?= e(url('recibos')) ?>">Voltar</a>
            </div>
        </div>
        <p><strong>Recebido de:</strong> <?= e($r['recebido_de']) ?><?= $r['documento'] !== '' ? ' · ' . e(doc_formatar($r['documento'])) : '' ?></p>
        <p><strong>Valor:</strong> <?= money($r['valor']) ?> · <?= e($r['forma']) ?></p>
        <p><strong>Referente a:</strong> <?= e($r['referente']) ?></p>
        <p><strong>Emissão:</strong> <?= e(dt($r['emissao'])) ?>
           <?php if ($r['locacao_numero'] !== null): ?> · <strong>Locação:</strong>
           <a href="<?= e(url('locacoes', ['a' => 'ver', 'n' => $r['locacao_numero']])) ?>">nº <?= num($r['locacao_numero']) ?></a><?php endif; ?></p>
    </div>
    <?php
    layout_end();
}

function recibos_imprimir(): void
{
    $r = recibo_da_url();

    print_start('Recibo nº ' . $r['numero'], url('recibos', ['a' => 'ver', 'id' => $r['id']]));
    cabecalho_impressao('Recibo', 'Nº ' . $r['numero']);
    ?>
    <p style="font-size:1.1em; line-height:1.7">
        Recebemos de <strong><?= e($r['recebido_de']) ?></strong>
        <?= $r['documento'] !== '' ? '(' . e(doc_formatar($r['documento'])) . ')' : '' ?>
        a importância de <strong><?= money($r['valor']) ?></strong>
        (<?= e($r['forma']) ?>), referente a <?= e($r['referente']) ?>.
    </p>
    <p>Data: <?= e(dt($r['emissao'])) ?></p>
    <p style="margin-top:60px">______________________________________________<br>Assinatura do responsável</p>
    <?php
    print_end();
}
