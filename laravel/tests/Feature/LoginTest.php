<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Login e sessão: as mesmas proteções do sistema antigo. */
class LoginTest extends TestCase
{
    public function test_tela_de_login_abre_e_rotas_protegidas_pedem_login(): void
    {
        $this->get('/login')->assertOk()->assertSee('Entrar');
        foreach (['/painel', '/clientes', '/clientes/novo', '/busca', '/arquivos/logo', '/ir?p=locacoes'] as $rota) {
            $this->get($rota)->assertRedirect(route('login'));
        }
    }

    public function test_login_certo_abre_a_sessao_da_locadora(): void
    {
        $this->entrarComo('alfa-locacoes');
        $this->assertSame($this->idLocadora('alfa-locacoes'), session('tenant_id'));
        $this->get('/painel')->assertOk()->assertSee('Alfa');
    }

    public function test_senha_errada_e_empresa_inexistente_recebem_a_mesma_mensagem_e_contam_tentativa(): void
    {
        $antes = DB::selectOne('SELECT count(*) AS n FROM login_tentativas')->n;

        $this->post('/login', ['empresa' => 'alfa-locacoes', 'login' => 'admin', 'senha' => 'errada'])->assertRedirect(route('login'));
        $this->assertSame('Dados de acesso inválidos.', session('flash')[0]['msg']);
        $this->post('/login', ['empresa' => 'nao-existe-mesmo', 'login' => 'admin', 'senha' => 'x'])->assertRedirect(route('login'));
        $this->assertSame('Dados de acesso inválidos.', session('flash')[0]['msg']);

        // Cada erro grava a chave da conta e a do IP
        $this->assertSame($antes + 4, DB::selectOne('SELECT count(*) AS n FROM login_tentativas')->n);
        $this->assertNull(session('tenant_id'));
    }

    public function test_cinco_erros_bloqueiam_a_conta_mesmo_com_a_senha_certa(): void
    {
        // Mesma chave que o sistema antigo usa (login_chaves), área "locadora"
        $chave = hash('sha256', 'conta|locadora|alfa-locacoes|admin');
        DB::insert("INSERT INTO login_tentativas (chave) SELECT ? FROM generate_series(1, 5)", [$chave]);

        $this->post('/login', ['empresa' => 'alfa-locacoes', 'login' => 'admin', 'senha' => 'admin123'])->assertRedirect(route('login'));
        $this->assertStringContainsString('Muitas tentativas', session('flash')[0]['msg']);
        $this->assertNull(session('tenant_id'));
    }

    public function test_sair_encerra_a_sessao(): void
    {
        $this->entrarComo();
        $this->post('/sair')->assertRedirect(route('login'));
        $this->get('/painel')->assertRedirect(route('login'));
    }

    public function test_sessao_com_usuario_desativado_e_encerrada(): void
    {
        $this->entrarComo();
        // Desativado depois do login (a transação do teste desfaz no fim)
        DB::update("UPDATE usuarios SET ativo = false WHERE id = ?", [session('usuario.id')]);
        $this->get('/painel')->assertRedirect(route('login'));
        $this->assertStringContainsString('desativado', session('flash')[0]['msg']);
    }

    public function test_senha_redefinida_depois_do_login_derruba_a_sessao(): void
    {
        $this->entrarComo();
        DB::update("UPDATE usuarios SET senha_redefinida_em = now() + interval '1 minute' WHERE id = ?", [session('usuario.id')]);
        $this->get('/painel')->assertRedirect(route('login'));
    }
}
