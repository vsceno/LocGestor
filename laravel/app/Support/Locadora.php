<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Contexto da locadora (tenant) na conexão com o banco.
 *
 * O isolamento entre locadoras é garantido pelo PostgreSQL (RLS): toda tabela de locadora
 * filtra por tenant_id = app_tenant_id(), que lê a variável de sessão app.tenant_id.
 * Esta classe é o ÚNICO lugar do Laravel que a define (como app/tenant.php no sistema antigo).
 *
 * Regras:
 *   - a única fonte da locadora depois do login é a sessão (session('tenant_id'));
 *     nunca aceite tenant_id vindo de formulário, URL ou cookie;
 *   - a conexão não é persistente (config/database.php), então o valor não sobra
 *     para a próxima requisição. Não use Laravel Octane nem PgBouncer em modo transaction
 *     sem tratar isso: o app.tenant_id vazaria para outra requisição.
 */
final class Locadora
{
    private static ?string $id = null;
    private static ?object $dados = null;

    /** Define a locadora da conexão atual. Tudo que vier depois só enxerga os dados dela. */
    public static function definir(string $id): void
    {
        if (!Str::isUuid($id)) {
            throw new RuntimeException('Identificador de locadora inválido.');
        }
        DB::select("SELECT set_config('app.tenant_id', ?, false)", [$id]);
        self::$id = $id;
        self::$dados = null;
    }

    /** Remove o contexto: a conexão volta a não enxergar nenhum dado de locadora. */
    public static function limpar(): void
    {
        DB::select("SELECT set_config('app.tenant_id', '', false)");
        self::$id = null;
        self::$dados = null;
    }

    public static function id(): ?string
    {
        return self::$id;
    }

    /** Linha da locadora atual em tenants (sem RLS; o app só lê estas colunas). */
    public static function dados(): ?object
    {
        if (self::$id === null) {
            return null;
        }
        return self::$dados ??= DB::selectOne(
            'SELECT id, slug, nome, status, plano, limite_usuarios, vencimento, carencia_dias FROM tenants WHERE id = ?', [self::$id]
        );
    }

    /** Último dia de uso sem pagar (vencimento + carência); null = isenta. */
    public static function limitePagamento(object $t): ?string
    {
        if (empty($t->vencimento)) {
            return null;
        }
        return date('Y-m-d', strtotime($t->vencimento . ' +' . (int) $t->carencia_dias . ' days'));
    }

    /** Mensalidade vencida além da carência: o acesso fica bloqueado na hora. */
    public static function inadimplente(object $t): bool
    {
        $limite = self::limitePagamento($t);
        return $limite !== null && date('Y-m-d') > $limite;
    }

    /** Quem está alterando: os gatilhos de auditoria do banco gravam este usuário. */
    public static function definirUsuario(string $id, string $login): void
    {
        DB::select("SELECT set_config('app.usuario_id', ?, false), set_config('app.usuario', ?, false)", [$id, mb_substr($login, 0, 80)]);
    }
}
