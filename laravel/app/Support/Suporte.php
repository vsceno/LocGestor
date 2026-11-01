<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Acesso de suporte a uma locadora ("Entrar como a locadora"). O painel da plataforma cria um código de uso único
 * "<id da locadora>.<64 hex aleatórios>" e só o hash (sha256 da parte aleatória) é guardado. O código vale 2 minutos e
 * é trocado uma única vez por uma sessão de 30 minutos, só de leitura, que aparece com faixa de aviso e fica registrada
 * na auditoria da locadora. Mesmas regras de app/suporte.php do sistema antigo.
 */
final class Suporte
{
    public const CODIGO_MIN = 2;   // minutos que o código de uso único vale
    public const SESSAO_MIN = 30;  // minutos que a sessão de suporte dura

    /** Código novo para a locadora. Devolve [código para o link, hash guardado no banco]. */
    public static function codigo(string $tenantId): array
    {
        $aleatorio = bin2hex(random_bytes(32));
        return ["$tenantId.$aleatorio", hash('sha256', $aleatorio)];
    }

    /** Separa o código do link: [tenant_id, hash] ou null se o formato não for o esperado. */
    public static function separar(string $codigo): ?array
    {
        if (!preg_match('/^([0-9a-f-]{36})\.([0-9a-f]{64})$/', $codigo, $m) || !Str::isUuid($m[1])) {
            return null;
        }
        return [$m[1], hash('sha256', $m[2])];
    }
}
