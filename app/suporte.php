<?php
/*
 * Acesso de suporte a uma locadora ("Entrar como a locadora"), usado pelo painel da plataforma e pelo sistema.
 *
 * O painel cria um código de uso único: "<id da locadora>.<64 hex aleatórios>". Só o hash (sha256 da parte
 * aleatória) é guardado. O código vale 2 minutos e é trocado uma única vez por uma sessão de 30 minutos,
 * só de leitura, que aparece com faixa de aviso e fica registrada na auditoria da locadora.
 */

declare(strict_types=1);

const SUPORTE_CODIGO_MIN = 2;      // minutos que o código de uso único vale
const SUPORTE_SESSAO_MIN = 30;     // minutos que a sessão de suporte dura

/** Código novo para a locadora. Devolve [codigo para o link, hash guardado no banco]. */
function suporte_codigo(string $tenantId): array
{
    $aleatorio = bin2hex(random_bytes(32));
    return ["$tenantId.$aleatorio", hash('sha256', $aleatorio)];
}

/** Separa o código do link: [tenant_id, hash] ou null se o formato não for o esperado. */
function suporte_codigo_separar(string $codigo): ?array
{
    if (!preg_match('/^([0-9a-f-]{36})\.([0-9a-f]{64})$/', $codigo, $m) || !is_uuid($m[1])) {
        return null;
    }
    return [$m[1], hash('sha256', $m[2])];
}

/** Há uma sessão de suporte aberta e ainda dentro do prazo? */
function suporte_sessao_ativa(): bool
{
    return !empty($_SESSION['suporte']) && time() < (int) ($_SESSION['suporte']['expira'] ?? 0);
}
