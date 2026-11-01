<?php

namespace Tests;

use App\Support\Configuracoes;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

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

        // Logomarca, fotos e assinaturas dos testes vão para uma pasta temporária: a pasta de verdade tem os arquivos
        // das locadoras, e trocar uma logomarca apaga a anterior do disco (o banco volta, o arquivo não).
        $pasta = sys_get_temp_dir().'/locgestor-teste-'.bin2hex(random_bytes(6));
        config(['locgestor.pasta_arquivos' => $pasta]);
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($pasta));
    }

    /** Configurações da LocGestor da base, guardadas antes do primeiro ajuste do teste para voltar ao original. */
    private ?array $configuracoesOriginais = null;

    /**
     * Grava a tela Configurações da LocGestor para o teste. Vai pela conexão de superusuário e já confirmada, porque a
     * área das locadoras lê por outra conexão (uma transação não aparece para a outra). Sem auditoria (os gatilhos ficam
     * desligados nessa gravação) e volta ao original no fim do teste.
     */
    protected function definirConfiguracoes(array $valores): void
    {
        $admin = DB::connection('teste_admin');
        if ($this->configuracoesOriginais === null) {
            $this->configuracoesOriginais = (array) $admin->selectOne('SELECT * FROM plataforma_configuracoes WHERE id = ?', [Configuracoes::ID]);
            $this->beforeApplicationDestroyed(fn () => $this->gravarConfiguracoes($admin, $this->configuracoesOriginais));
        }
        $this->gravarConfiguracoes($admin, $valores);
    }

    private function gravarConfiguracoes(ConnectionInterface $admin, array $valores): void
    {
        $colunas = array_values(array_diff(array_keys($valores), ['id', 'atualizado_em']));
        $atribuicoes = implode(', ', array_map(fn (string $coluna): string => "{$coluna} = ?", $colunas));

        $admin->statement('SET session_replication_role = replica');
        try {
            $admin->update(
                "UPDATE plataforma_configuracoes SET {$atribuicoes} WHERE id = ?",
                [...array_map(fn (string $coluna): mixed => $valores[$coluna], $colunas), Configuracoes::ID]
            );
        } finally {
            $admin->statement('SET session_replication_role = origin');
        }
    }

    /** Leitura como superusuário (enxerga todas as locadoras). Só SELECT. */
    protected function lerComoAdmin(string $sql, array $params = []): array
    {
        if (! preg_match('/^\s*SELECT\b/i', $sql)) {
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
        } while (! doc_valido($b) || $this->lerComoAdmin('SELECT 1 FROM clientes WHERE cpf_cnpj = ?', [$b]));

        return $b;
    }
}
