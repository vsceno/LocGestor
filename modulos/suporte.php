<?php
/*
 * Acesso de suporte: entrada pelo código de uso único (criado no painel da plataforma) e saída.
 * A sessão aberta aqui é só de leitura (ver executar_rota e tenant_iniciar_sessao).
 */

declare(strict_types=1);

function suporte_entrar(): void
{
    $partes = suporte_codigo_separar((string) get('t'));
    if ($partes === null) {
        log_app('AVISO', 'Link de suporte recusado: formato inválido.');
        throw new UserError('Este link de suporte é inválido.');
    }
    [$tenantId] = $partes;
    tenant_definir($tenantId);
    // Consome o código: só vale se for desta locadora, não tiver sido usado e ainda estiver no prazo
    $acesso = one('UPDATE acessos_suporte SET usado_em = now()
                    WHERE token_hash = :h AND usado_em IS NULL AND expira_em > now() AND tenant_id = CAST(:t AS uuid)
                RETURNING id, login_suporte, motivo', ['h' => $partes[1], 't' => $tenantId]);
    if ($acesso === null) {
        tenant_limpar();
        log_app('AVISO', 'Link de suporte recusado: já usado, expirado ou de outra locadora.');
        throw new UserError('Este link de suporte já foi usado, expirou ou não é desta locadora.');
    }
    $t = tenant();
    if ($t === null || $t['status'] !== 'ativa' || tenant_inadimplente($t)) {
        tenant_limpar();
        throw new UserError('A locadora não está ativa: o acesso de suporte não pode ser aberto.');
    }

    sessao_encerrar();
    $_SESSION['tenant_id'] = $tenantId;
    $_SESSION['tenant_slug'] = $t['slug'];
    $_SESSION['usuario'] = ['id' => null, 'nome' => 'Suporte LocGestor', 'login' => 'suporte:' . $acesso['login_suporte'],
                            'perfil' => 'suporte', 'trocar_senha' => false];
    $_SESSION['suporte'] = ['id' => $acesso['id'], 'login' => $acesso['login_suporte'], 'motivo' => $acesso['motivo'],
                            'expira' => time() + SUPORTE_SESSAO_MIN * 60];
    // Registro na auditoria da locadora: quem entrou, quando e por quê (o código nunca é gravado)
    q("INSERT INTO auditoria (tenant_id, usuario_id, usuario, tabela, registro_id, rotulo, acao, dados)
       VALUES (:t, NULL, :u, 'acesso_suporte', :r, :rot, 'I', CAST(:d AS jsonb))",
      ['t' => $tenantId, 'u' => 'suporte: ' . $acesso['login_suporte'], 'r' => $acesso['id'],
       'rot' => mb_substr($acesso['login_suporte'], 0, 160),
       'd' => json_encode(['motivo' => $acesso['motivo'], 'duracao_min' => SUPORTE_SESSAO_MIN], JSON_UNESCAPED_UNICODE)]);
    log_app('INFO', "Acesso de suporte aberto ({$acesso['login_suporte']}).");
    flash('Você está no acesso de suporte: somente leitura, por ' . SUPORTE_SESSAO_MIN . ' minutos.', 'aviso');
    redirect(url('dashboard'));
}

function suporte_sair(): void
{
    if (!suporte_sessao_ativa()) {
        redirect(url('dashboard'));
    }
    tenant_limpar();
    sessao_encerrar();
    flash('Acesso de suporte encerrado.');
    redirect(url('login'));
}
