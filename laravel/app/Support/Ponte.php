<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Ponte de sessão com o sistema antigo (app/ponte.php), durante a migração. Mesmas regras dos dois lados:
 * passagem assinada com HMAC-SHA256 (segredo em LOCGESTOR_PONTE_SEGREDO = ponte_segredo do config.php),
 * válida por 60 segundos, de uso único e com destinatário ('laravel' ou 'legado').
 * Quem recebe confere de novo no banco a locadora e o usuário antes de abrir a sessão.
 */
final class Ponte
{
    private const VALIDADE = 60;

    public static function ligada(): bool
    {
        return strlen((string) config('locgestor.ponte_segredo')) >= 32;
    }

    /** Passagem para o sistema antigo; $destino é "p=modulo&a=acao&...". */
    public static function gerar(string $tenantId, string $usuarioId, int $loginEm, string $destino): string
    {
        $corpo = self::b64(json_encode([
            'para' => 'legado', 't' => $tenantId, 'u' => $usuarioId, 'e' => $loginEm, 'd' => $destino,
            'x' => time() + self::VALIDADE, 'n' => bin2hex(random_bytes(16)),
        ]));
        return $corpo . '.' . self::b64(hash_hmac('sha256', $corpo, (string) config('locgestor.ponte_segredo'), true));
    }

    /** Confere a passagem vinda do sistema antigo. Devolve os dados ou null. */
    public static function validar(string $passagem): ?array
    {
        if (!self::ligada() || !preg_match('/^([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)$/', $passagem, $m)) {
            return null;
        }
        $esperada = self::b64(hash_hmac('sha256', $m[1], (string) config('locgestor.ponte_segredo'), true));
        if (!hash_equals($esperada, $m[2])) {
            return null;
        }
        $d = json_decode((string) base64_decode(strtr($m[1], '-_', '+/')), true);
        if (!is_array($d) || ($d['para'] ?? '') !== 'laravel' || (int) ($d['x'] ?? 0) < time()
            || !Str::isUuid((string) ($d['t'] ?? '')) || !Str::isUuid((string) ($d['u'] ?? ''))
            || !preg_match('/^[0-9a-f]{32}$/', (string) ($d['n'] ?? ''))) {
            return null;
        }
        // Uso único: Cache::add só grava se a chave ainda não existe
        if (!Cache::add('ponte:' . $d['n'], true, 3600)) {
            return null;
        }
        return $d;
    }

    private static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }
}
