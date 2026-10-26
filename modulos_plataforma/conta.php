<?php
/*
 * Minha senha: o usuário do painel da plataforma troca a própria senha.
 * A senha antiga para de valer na hora (o login confere só o hash atual).
 */

declare(strict_types=1);

function conta_senha(): void
{
    layout_start('Minha senha', 'conta');
    ?>
    <div class="card" style="max-width:520px">
        <h2>Minha senha</h2>
        <p class="ajuda">Usuário: <code><?= e(user()['login'] ?? '') ?></code>. Depois de trocar, a senha antiga deixa de valer.</p>
        <form method="post" action="<?= e(url('conta', ['a' => 'senha_salvar'])) ?>" class="form">
            <?= csrf_field() ?>
            <label class="obrig">Senha atual<input type="password" name="senha_atual" required autocomplete="current-password"></label>
            <label class="obrig">Nova senha<input type="password" name="senha_nova" required minlength="8" autocomplete="new-password">
                <span class="ajuda">Pelo menos 8 caracteres, diferente da atual.</span></label>
            <label class="obrig">Repita a nova senha<input type="password" name="senha_confirma" required minlength="8" autocomplete="new-password"></label>
            <button class="btn">Trocar senha</button>
        </form>
    </div>
    <?php
    layout_end();
}

function conta_senha_salvar(): void
{
    $atual = (string) ($_POST['senha_atual'] ?? '');
    $nova = (string) ($_POST['senha_nova'] ?? '');
    $confirma = (string) ($_POST['senha_confirma'] ?? '');
    $id = uid();
    $hash = is_uuid((string) $id) ? val('SELECT senha_hash FROM plataforma_usuarios WHERE id = :id AND ativo', ['id' => $id]) : null;
    if ($hash === null) {
        throw new UserError('Sessão inválida. Entre novamente.');
    }
    if (!password_verify($atual, $hash)) {
        usleep(500000);
        log_app('AVISO', 'Troca de senha da plataforma: senha atual não confere.');
        throw new UserError('A senha atual não confere.');
    }
    if (mb_strlen($nova) < 8) {
        throw new UserError('A nova senha deve ter pelo menos 8 caracteres.');
    }
    if ($nova !== $confirma) {
        throw new UserError('A confirmação não é igual à nova senha.');
    }
    if (password_verify($nova, $hash)) {
        throw new UserError('A nova senha deve ser diferente da atual.');
    }
    q('UPDATE plataforma_usuarios SET senha_hash = :h WHERE id = :id', ['h' => password_hash($nova, PASSWORD_DEFAULT), 'id' => $id]);
    log_app('INFO', 'Senha do painel da plataforma trocada.');
    flash('Senha trocada. Use a nova no próximo acesso.');
    redirect(url('conta', ['a' => 'senha']));
}
