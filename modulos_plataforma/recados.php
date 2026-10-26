<?php
/*
 * Recados do painel para todas as locadoras: avisos com validade, exibidos no topo do sistema delas.
 */

declare(strict_types=1);

const TIPOS_RECADO = ['aviso' => 'Aviso', 'novidade' => 'Novidade', 'manutencao' => 'Manutenção programada'];

function recados_index(): void
{
    $recados = all('SELECT id, tipo, titulo, texto, expira_em, ativo, usuario, criado_em FROM recados ORDER BY criado_em DESC LIMIT 50');
    layout_start('Recados', 'recados');
    ?>
    <div class="grid-2">
        <form method="post" action="<?= e(url('recados', ['a' => 'criar'])) ?>" class="form card">
            <?= csrf_field() ?>
            <h2>Novo recado</h2>
            <p class="ajuda">Aparece no topo do sistema de todas as locadoras até a data de validade (ou até você desativar).</p>
            <label class="obrig">Tipo<select name="tipo" required><?= options(TIPOS_RECADO, old('tipo', 'aviso')) ?></select></label>
            <label class="obrig">Título<input type="text" name="titulo" value="<?= e(old('titulo')) ?>" required maxlength="120"></label>
            <label class="obrig">Texto<textarea name="texto" required maxlength="600"><?= e(old('texto')) ?></textarea></label>
            <label class="obrig">Válido até<input type="date" name="expira_em" value="<?= e(old('expira_em', date('Y-m-d', strtotime('+7 days')))) ?>"
                   min="<?= e(today()) ?>" required></label>
            <button class="btn">Publicar recado</button>
        </form>
        <div class="card">
            <h2>Recados publicados</h2>
            <div class="tabela-wrap">
                <table class="tabela">
                    <thead><tr><th>Recado</th><th>Válido até</th><th>Situação</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($recados as $r):
                        $vigente = $r['ativo'] && $r['expira_em'] >= today(); ?>
                        <tr>
                            <td><strong><?= e($r['titulo']) ?></strong> <span class="selo"><?= e(TIPOS_RECADO[$r['tipo']]) ?></span><br>
                                <span class="muted"><?= e($r['texto']) ?></span><br><small class="muted">por <?= e($r['usuario']) ?> em <?= e(dt($r['criado_em'], true)) ?></small></td>
                            <td class="nowrap"><?= e(dt($r['expira_em'])) ?></td>
                            <td><?= $vigente ? '<span class="selo verde">no ar</span>' : ($r['ativo'] ? '<span class="selo">vencido</span>' : '<span class="selo vermelho">desativado</span>') ?></td>
                            <td><?php if ($r['ativo']): ?>
                                <form method="post" action="<?= e(url('recados', ['a' => 'desativar'])) ?>" data-confirmar="Retirar este recado do sistema das locadoras?">
                                    <?= csrf_field() ?><input type="hidden" name="id" value="<?= e($r['id']) ?>">
                                    <button class="btn btn-sec btn-peq">Desativar</button>
                                </form><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$recados): ?><tr><td colspan="4" class="vazio">Nenhum recado publicado.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php
    layout_end();
}

function recados_criar(): void
{
    try {
        recados_publicar();
    } catch (UserError $e) {
        // Volta para a própria tela de recados (e não para a lista de locadoras)
        flash($e->getMessage(), 'erro');
        redirect(url('recados'));
    }
}

function recados_publicar(): void
{
    $tipo = (string) ($_POST['tipo'] ?? '');
    $titulo = trim((string) ($_POST['titulo'] ?? ''));
    $texto = trim((string) ($_POST['texto'] ?? ''));
    $expira = (string) ($_POST['expira_em'] ?? '');
    if (!isset(TIPOS_RECADO[$tipo])) {
        throw new UserError('Escolha o tipo do recado.');
    }
    if (mb_strlen($titulo) < 3 || mb_strlen($titulo) > 120) {
        throw new UserError('O título deve ter de 3 a 120 caracteres.');
    }
    if (mb_strlen($texto) < 5 || mb_strlen($texto) > 600) {
        throw new UserError('O texto deve ter de 5 a 600 caracteres.');
    }
    if (!valid_date($expira) || $expira < today() || $expira > date('Y-m-d', strtotime('+1 year'))) {
        throw new UserError('Informe até quando o recado vale (hoje ou depois, no máximo 1 ano).');
    }
    q('INSERT INTO recados (tipo, titulo, texto, expira_em, usuario) VALUES (:t, :ti, :x, :e, :u)',
      ['t' => $tipo, 'ti' => $titulo, 'x' => $texto, 'e' => $expira, 'u' => user()['login'] ?? '']);
    log_app('INFO', "Recado publicado: $titulo (até $expira).");
    flash('Recado publicado. Ele aparece nas locadoras até a data de validade.');
    redirect(url('recados'));
}

function recados_desativar(): void
{
    $id = post('id');
    if (!is_uuid($id) || q('UPDATE recados SET ativo = false WHERE id = :id AND ativo', ['id' => $id])->rowCount() === 0) {
        throw new UserError('Recado não encontrado.');
    }
    log_app('INFO', 'Recado desativado.');
    flash('Recado retirado do sistema das locadoras.');
    redirect(url('recados'));
}
