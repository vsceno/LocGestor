<?php
/*
 * LocGestor - contexto da locadora (tenant).
 *
 * O isolamento entre locadoras é garantido pelo PostgreSQL (RLS): toda tabela
 * de locadora filtra por tenant_id = app_tenant_id(), que lê a variável de
 * sessão do banco app.tenant_id. Este arquivo é o ÚNICO lugar que a define.
 *
 * Regras:
 *   - a única fonte do tenant depois do login é $_SESSION['tenant_id'];
 *     nunca aceite tenant_id vindo de formulário, URL ou cookie;
 *   - a conexão PDO não é persistente (ver db()), então o valor não sobra
 *     para a próxima requisição.
 *
 * ATENÇÃO - PgBouncer: se um dia for usado, configure pool_mode = session.
 * Em modo transaction, o set_config(..., false) desta conexão fica no servidor
 * e VAZA para o próximo cliente que pegar a mesma conexão do pool, que passaria
 * a enxergar os dados de outra locadora.
 */

declare(strict_types=1);

/** Define a locadora da conexão atual. Tudo que vier depois só enxerga os dados dela. */
function tenant_definir(string $tenantId): void
{
    if (!is_uuid($tenantId)) {
        throw new RuntimeException('Identificador de locadora inválido.');
    }
    q("SELECT set_config('app.tenant_id', :t, false)", ['t' => $tenantId]);
    $GLOBALS['_TENANT_ID'] = $tenantId;
    $GLOBALS['_TENANT'] = null;
}

/** Remove o contexto: a conexão volta a não enxergar nenhum dado de locadora. */
function tenant_limpar(): void
{
    q("SELECT set_config('app.tenant_id', '', false)");
    $GLOBALS['_TENANT_ID'] = null;
    $GLOBALS['_TENANT'] = null;
}

function tenant_id(): ?string
{
    return $GLOBALS['_TENANT_ID'] ?? null;
}

/** Dados da locadora atual (tabela tenants), com cache por requisição. */
function tenant(): ?array
{
    if (tenant_id() === null) {
        return null;
    }
    if (empty($GLOBALS['_TENANT'])) {
        $GLOBALS['_TENANT'] = one('SELECT id, slug, nome, status, plano, limite_usuarios, vencimento, carencia_dias
                                     FROM tenants WHERE id = :id', ['id' => tenant_id()]);
    }
    return $GLOBALS['_TENANT'];
}

/** Último dia em que a locadora ainda usa o sistema sem pagar (vencimento + carência); null = isenta. */
function tenant_limite_pagamento(array $t): ?string
{
    if (empty($t['vencimento'])) {
        return null;
    }
    return date('Y-m-d', strtotime($t['vencimento'] . ' +' . (int) $t['carencia_dias'] . ' days'));
}

/**
 * Mensalidade vencida além da carência: o acesso fica bloqueado na hora, sem depender de
 * rotina agendada (o painel da plataforma também marca a locadora como suspensa).
 */
function tenant_inadimplente(array $t): bool
{
    $limite = tenant_limite_pagamento($t);
    return $limite !== null && today() > $limite;
}

/**
 * Chamada em toda requisição autenticada: define o contexto a partir da sessão e
 * confere se a locadora continua ativa e se o usuário continua ativo.
 */
function tenant_iniciar_sessao(): void
{
    tenant_definir((string) $_SESSION['tenant_id']);

    $t = tenant();
    if ($t === null || $t['status'] !== 'ativa' || tenant_inadimplente($t)) {
        $porPagamento = $t !== null && $t['status'] === 'ativa';
        log_app('AVISO', 'Sessão encerrada: locadora ' . ($porPagamento ? 'inadimplente' : 'suspensa ou inexistente') . '.');
        tenant_limpar();
        sessao_encerrar();
        flash($porPagamento
            ? 'Acesso suspenso por falta de pagamento da mensalidade. Entre em contato com o suporte.'
            : 'Acesso suspenso. Entre em contato com o suporte.', 'erro');
        redirect(url('login'));
    }

    // Sessão de suporte: não há usuário da locadora; vale só até o prazo, e é sempre somente leitura
    if (!empty($_SESSION['suporte'])) {
        if (!suporte_sessao_ativa()) {
            tenant_limpar();
            sessao_encerrar();
            flash('O acesso de suporte expirou.', 'aviso');
            redirect(url('login'));
        }
        return;
    }

    // O usuário pode ter sido desativado depois do login (consulta já filtrada pelo RLS)
    $u = is_uuid(uid())
        ? one('SELECT id, nome, login, perfil, ativo, trocar_senha, senha_redefinida_em FROM usuarios WHERE id = :id', ['id' => uid()])
        : null;
    if ($u === null || !$u['ativo']) {
        tenant_limpar();
        sessao_encerrar();
        flash('Seu usuário foi desativado. Fale com o administrador da locadora.', 'erro');
        redirect(url('login'));
    }
    // Senha redefinida pelo painel depois deste login: esta sessão não vale mais
    if ($u['senha_redefinida_em'] !== null && strtotime((string) $u['senha_redefinida_em']) > (int) ($_SESSION['login_em'] ?? 0)) {
        tenant_limpar();
        sessao_encerrar();
        flash('Sua senha foi redefinida pelo administrador. Entre de novo com a senha nova.', 'aviso');
        redirect(url('login'));
    }
    unset($u['ativo'], $u['senha_redefinida_em']);
    $_SESSION['usuario'] = $u; // perfil/nome sempre atualizados
    // Quem está alterando: os gatilhos de auditoria gravam este usuário
    q("SELECT set_config('app.usuario_id', :id, false), set_config('app.usuario', :login, false)",
      ['id' => $u['id'], 'login' => mb_substr($u['login'], 0, 80)]);
}
