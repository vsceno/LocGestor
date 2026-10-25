<?php
/*
 * Painel da plataforma: planos de assinatura (nome, preço mensal e limite de usuários).
 * Mudar o preço vale para as próximas mensalidades; o limite de cada locadora só muda
 * quando ela troca de plano (ou pela tela da locadora).
 */

declare(strict_types=1);

function planos_index(): void
{
    $planos = all("SELECT p.*, count(t.id) AS locadoras,
                          count(t.id) FILTER (WHERE t.status = 'ativa' AND t.vencimento IS NOT NULL) AS pagantes
                     FROM planos p LEFT JOIN tenants t ON t.plano_id = p.id
                    GROUP BY p.id ORDER BY p.preco_mensal, p.nome");
    $editar = null;
    if (is_uuid(get('id'))) {
        foreach ($planos as $p) {
            if ($p['id'] === get('id')) {
                $editar = $p;
            }
        }
    }
    $v = fn($c, $pad = '') => has_old() ? old($c, $pad) : ($editar[$c] ?? $pad);

    layout_start('Planos', 'planos');
    ?>
    <div class="grid-2">
        <div class="card">
            <h2>Planos</h2>
            <div class="tabela-wrap">
                <table class="tabela">
                    <thead><tr><th>Plano</th><th class="num">Preço/mês</th><th class="num">Usuários</th><th class="num">Locadoras</th>
                        <th class="num">Receita mensal</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($planos as $p): ?>
                        <tr><td><strong><?= e($p['nome']) ?></strong><?= $p['ativo'] ? '' : ' <span class="selo">inativo</span>' ?></td>
                            <td class="num"><?= money($p['preco_mensal']) ?></td>
                            <td class="num"><?= num($p['limite_usuarios']) ?></td>
                            <td class="num"><?= num($p['locadoras']) ?></td>
                            <td class="num"><?= money($p['preco_mensal'] * $p['pagantes']) ?></td>
                            <td class="nowrap"><a class="btn btn-sec btn-peq" href="<?= e(url('planos', ['id' => $p['id']])) ?>">Editar</a>
                                <?php if ((int) $p['locadoras'] === 0): ?>
                                    <form method="post" action="<?= e(url('planos', ['a' => 'excluir'])) ?>" style="display:inline" data-confirmar="Excluir o plano <?= e($p['nome']) ?>?">
                                        <?= csrf_field() ?><input type="hidden" name="id" value="<?= e($p['id']) ?>">
                                        <button class="btn btn-perigo btn-peq">Excluir</button></form>
                                <?php endif; ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card">
            <h2><?= $editar ? 'Editar ' . e($editar['nome']) : 'Novo plano' ?></h2>
            <form method="post" action="<?= e(url('planos', ['a' => 'salvar'])) ?>" class="form">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= e($editar['id'] ?? '') ?>">
                <label class="obrig">Nome<input type="text" name="nome" value="<?= e($v('nome')) ?>" required maxlength="40"></label>
                <div class="linha">
                    <label class="obrig">Preço mensal R$<input type="text" name="preco_mensal" required inputmode="decimal"
                           value="<?= e(has_old() ? old('preco_mensal') : ($editar ? num($editar['preco_mensal'], 2) : '')) ?>"></label>
                    <label class="obrig">Limite de usuários<input type="number" name="limite_usuarios" value="<?= e($v('limite_usuarios', '3')) ?>" min="1" max="1000" required></label>
                </div>
                <label class="check"><input type="checkbox" name="ativo" value="1" <?= (has_old() ? old('ativo') !== '' : ($editar['ativo'] ?? true)) ? 'checked' : '' ?>>
                    Ativo (aparece para novas locadoras)</label>
                <div class="acoes">
                    <button class="btn">Salvar</button>
                    <?php if ($editar): ?><a class="btn btn-sec" href="<?= e(url('planos')) ?>">Novo plano</a><?php endif; ?>
                </div>
            </form>
        </div>
    </div>
    <?php
    layout_end();
}

function planos_salvar(): void
{
    $id = post('id');
    $nome = post('nome');
    $preco = parse_money(post('preco_mensal'));
    $limite = filter_var(post('limite_usuarios'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
    if (($id !== '' && !is_uuid($id)) || mb_strlen($nome) < 2 || mb_strlen($nome) > 40 || $preco < 0 || $limite === false) {
        throw new UserError('Confira o nome (2 a 40), o preço e o limite de usuários (1 a 1000).');
    }
    $p = ['n' => $nome, 'pr' => $preco, 'l' => $limite, 'a' => post('ativo') !== '' ? 'true' : 'false'];
    if ($id === '') {
        q('INSERT INTO planos (nome, preco_mensal, limite_usuarios, ativo) VALUES (:n, :pr, :l, :a)', $p);
    } elseif (val('UPDATE planos SET nome = :n, preco_mensal = :pr, limite_usuarios = :l, ativo = :a WHERE id = :id RETURNING id',
                  $p + ['id' => $id]) === null) {
        throw new UserError('Plano não encontrado.');
    } else {
        // Mantém o nome do plano que aparece para as locadoras
        q('UPDATE tenants SET plano = :n WHERE plano_id = :id', ['n' => $nome, 'id' => $id]);
    }
    flash($id === '' ? 'Plano criado.' : 'Plano atualizado.');
    redirect(url('planos'));
}

function planos_excluir(): void
{
    $id = post('id');
    if (!is_uuid($id) || q('DELETE FROM planos WHERE id = :id', ['id' => $id])->rowCount() === 0) {
        throw new UserError('Plano não encontrado.');
    }
    flash('Plano excluído.');
    redirect(url('planos'));
}
