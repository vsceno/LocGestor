<?php

namespace Tests;

use App\Support\Locadoras;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Base dos testes do painel da plataforma. O painel grava nas próprias tabelas (planos, locadoras, recados) pela
 * conexão 'plataforma', então as duas conexões rodam em transação e tudo é desfeito no fim de cada teste.
 */
abstract class PlataformaTestCase extends TestCase
{
    protected $connectionsToTransact = ['pgsql', 'plataforma'];

    /** Abre o painel como o suporte, com a sessão de login (o login em si é testado à parte). */
    protected function entrarNaPlataforma(): static
    {
        $suporte = $this->suporte();
        return $this->withSession(['plataforma.usuario' => ['id' => $suporte->id, 'nome' => $suporte->nome, 'login' => 'suporte', 'perfil' => 'suporte']]);
    }

    protected function suporte(): object
    {
        return DB::connection('plataforma')->selectOne("SELECT id, nome FROM plataforma_usuarios WHERE login = 'suporte'");
    }

    /** Senha do suporte definida só para o teste (a transação a desfaz no fim). */
    protected function definirSenhaSuporte(string $senha): void
    {
        DB::connection('plataforma')->update("UPDATE plataforma_usuarios SET senha_hash = ? WHERE login = 'suporte'",
                                             [password_hash($senha, PASSWORD_DEFAULT)]);
    }

    protected function criarPlano(float $preco = 99.0, int $limite = 3): object
    {
        return DB::connection('plataforma')->selectOne(
            'INSERT INTO planos (nome, preco_mensal, limite_usuarios) VALUES (?, ?, ?) RETURNING id, nome, preco_mensal, limite_usuarios',
            ['Plano ' . Str::random(10), $preco, $limite]
        );
    }

    /** Locadora de teste, criada pelo mesmo caminho do painel. $sobre troca os campos padrão. Devolve o id. */
    protected function criarLocadora(array $sobre = []): string
    {
        return Locadoras::provisionar($sobre + [
            'nome' => 'Locadora Teste ' . Str::random(6),
            'slug' => 'teste-' . strtolower(Str::random(10)),
            'cnpj' => '', 'email' => '', 'telefone' => '',
            'plano' => $this->criarPlano(),
            'vencimento' => now()->addDays(30)->toDateString(),
            'carencia' => 5,
            'admin_nome' => 'Administrador', 'admin_login' => 'admin', 'admin_senha' => 'senha-teste-123', 'trocar_senha' => false,
        ]);
    }

    /**
     * Zera, só dentro da transação do teste, as tentativas de login da conta do suporte e do IP de teste. O desfazer
     * da transação devolve as linhas reais: um bloqueio de verdade não muda o resultado dos testes.
     */
    protected function zerarTentativasDeLogin(): void
    {
        DB::connection('plataforma')->delete('DELETE FROM login_tentativas WHERE chave IN (?, ?, ?)', [
            hash('sha256', 'conta|plataforma|suporte'), hash('sha256', 'ip|127.0.0.1'), hash('sha256', 'ip|::1'),
        ]);
    }

    /** A locadora como está no banco agora (lida pela mesma conexão do painel, então vê o que ainda não foi confirmado). */
    protected function locadoraNoBanco(string $id): object
    {
        return DB::connection('plataforma')->selectOne('SELECT * FROM tenants WHERE id = ?', [$id]);
    }
}
