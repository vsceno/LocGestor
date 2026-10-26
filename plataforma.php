<?php
/*
 * LocGestor - painel da plataforma (dono do SaaS): cadastra, suspende e reativa locadoras.
 * Rotas: plataforma.php?p=<modulo>&a=<acao>  →  modulos_plataforma/<modulo>.php
 *
 * Sessão própria (cookie locgestor_plataforma) e conexão como locgestor_plataforma,
 * que também está sujeito ao RLS: sem app.tenant_id definido, não enxerga dados de locadora.
 */

declare(strict_types=1);

const AREA = 'plataforma';

require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/layout.php';
require __DIR__ . '/app/asaas.php';
require __DIR__ . '/app/assinatura.php';

// O usuário da plataforma pode ter sido desativado depois do login
if (user() !== null) {
    $ativo = is_uuid(uid()) ? val('SELECT ativo FROM plataforma_usuarios WHERE id = :id', ['id' => uid()]) : null;
    if (!$ativo) {
        sessao_encerrar();
        flash('Sessão encerrada. Entre novamente.', 'erro');
        redirect(url('login'));
    }
    // Alterações feitas pelo painel em dados de uma locadora aparecem assim na auditoria dela
    q("SELECT set_config('app.usuario', :u, false)", ['u' => mb_substr('plataforma: ' . (user()['login'] ?? ''), 0, 80)]);
}

executar_rota(['login', 'tenants', 'planos', 'numeros', 'uso', 'recados', 'auditoria', 'conta'], __DIR__ . '/modulos_plataforma', 'tenants');
