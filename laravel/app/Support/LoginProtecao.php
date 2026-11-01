<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Proteção do login contra tentativa e erro, igual ao sistema antigo (login_chaves(), login_falhou() e afins):
 * no máximo login_max_conta erros por conta e login_max_ip por IP a cada login_janela_min minutos. A tabela
 * login_tentativas é a mesma para as duas áreas (locadora e plataforma); só a chave da conta leva o nome da área.
 * Guarda hashes do IP e da conta, nunca o texto.
 */
class LoginProtecao
{
    /** Chaves (hash) do IP e da conta de uma área ('locadora' ou 'plataforma'). */
    public static function chaves(string $area, string $conta, string $ip): array
    {
        return [
            'ip'    => hash('sha256', 'ip|' . $ip),
            'conta' => hash('sha256', 'conta|' . $area . '|' . mb_strtolower($conta)),
        ];
    }

    public static function bloqueado(array $chaves, ?string $conexao = null): bool
    {
        $r = DB::connection($conexao)->selectOne(
            'SELECT count(*) FILTER (WHERE chave = ?) AS conta, count(*) FILTER (WHERE chave = ?) AS ip
               FROM login_tentativas
              WHERE chave IN (?, ?) AND criado_em > now() - make_interval(mins => ?)',
            [$chaves['conta'], $chaves['ip'], $chaves['conta'], $chaves['ip'], config('locgestor.login_janela_min')]);
        return $r->conta >= config('locgestor.login_max_conta') || $r->ip >= config('locgestor.login_max_ip');
    }

    public static function falhou(array $chaves, ?string $conexao = null): void
    {
        DB::connection($conexao)->insert('INSERT INTO login_tentativas (chave) VALUES (?), (?)', [$chaves['conta'], $chaves['ip']]);
        DB::connection($conexao)->delete("DELETE FROM login_tentativas WHERE criado_em < now() - INTERVAL '1 day'");
    }

    /** Login certo zera os erros daquela conta (os do IP continuam contando). */
    public static function sucesso(array $chaves, ?string $conexao = null): void
    {
        DB::connection($conexao)->delete('DELETE FROM login_tentativas WHERE chave = ?', [$chaves['conta']]);
    }
}
