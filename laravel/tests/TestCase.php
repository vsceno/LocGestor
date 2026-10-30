<?php

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

/**
 * Base dos testes do Laravel.
 *
 * - Usa o mesmo banco e o mesmo papel do sistema (locgestor_app): o RLS vale, sem atalho.
 * - Cada teste roda numa transação desfeita no fim (DatabaseTransactions): nada fica gravado.
 * - A conexão "teste_admin" (superusuário) existe só aqui e serve só para LER o que o RLS esconde
 *   (ids de outra locadora). Nunca grave por ela: o que ela grava não é desfeito.
 */
abstract class TestCase extends BaseTestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();   // os testes não dependem do npm run build

        $app = config('database.connections.pgsql');
        config(['database.connections.teste_admin' => array_merge($app, [
            'username' => env('DB_ADMIN_USERNAME', env('PGUSER', 'postgres')),
            'password' => env('DB_ADMIN_PASSWORD', env('PGPASSWORD', '')),
        ])]);
    }

    /** Leitura como superusuário (enxerga todas as locadoras). Só SELECT. */
    protected function lerComoAdmin(string $sql, array $params = []): array
    {
        if (!preg_match('/^\s*SELECT\b/i', $sql)) {
            throw new \LogicException('A conexão de superusuário dos testes é só para leitura.');
        }
        return DB::connection('teste_admin')->select($sql, $params);
    }

    protected function idLocadora(string $slug): string
    {
        return $this->lerComoAdmin('SELECT id FROM tenants WHERE slug = ?', [$slug])[0]->id;
    }

    /** Entra como o admin da demonstração e confere que caiu no painel. */
    protected function entrarComo(string $slug = 'alfa-locacoes', string $login = 'admin', string $senha = 'admin123'): static
    {
        $this->post('/login', ['empresa' => $slug, 'login' => $login, 'senha' => $senha])->assertRedirect(route('painel'));
        return $this;
    }

    /** CPF válido que não existe em nenhuma locadora. */
    protected function cpfLivre(): string
    {
        do {
            $b = (string) random_int(100000000, 999999999);
            for ($t = 9; $t < 11; $t++) {
                $s = 0;
                for ($k = 0; $k < $t; $k++) {
                    $s += (int) $b[$k] * (($t + 1) - $k);
                }
                $b .= ((10 * $s) % 11) % 10;
            }
        } while (!doc_valido($b) || $this->lerComoAdmin('SELECT 1 FROM clientes WHERE cpf_cnpj = ?', [$b]));
        return $b;
    }
}
