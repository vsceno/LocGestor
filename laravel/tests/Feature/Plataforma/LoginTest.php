<?php

namespace Tests\Feature\Plataforma;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PlataformaTestCase;

/** Login do painel da plataforma: as mesmas proteções do sistema antigo (plataforma.php e modulos_plataforma/login.php). */
class LoginTest extends PlataformaTestCase
{
    public function test_painel_pede_login_sem_sessao(): void
    {
        $this->get(route('plataforma.locadoras'))->assertRedirect(route('plataforma.login'));
    }

    public function test_suporte_com_senha_certa_abre_o_painel(): void
    {
        $this->zerarTentativasDeLogin();
        $this->definirSenhaSuporte('senha-teste-login');
        $this->post(route('plataforma.entrar'), ['login' => 'suporte', 'senha' => 'senha-teste-login'])
            ->assertRedirect(route('plataforma.locadoras'));
        $this->get(route('plataforma.locadoras'))->assertOk()->assertSee('Painel da plataforma');
    }

    public function test_senha_errada_e_recusada_com_mensagem_generica(): void
    {
        $this->zerarTentativasDeLogin();
        $this->definirSenhaSuporte('senha-teste-login');
        $this->post(route('plataforma.entrar'), ['login' => 'suporte', 'senha' => 'errada'])
            ->assertRedirect(route('plataforma.login'))
            ->assertSessionHas('flash', [['tipo' => 'erro', 'msg' => 'Dados de acesso inválidos.']]);
        $this->get(route('plataforma.locadoras'))->assertRedirect(route('plataforma.login'));
    }

    public function test_muitas_tentativas_bloqueiam_o_login(): void
    {
        $this->zerarTentativasDeLogin();
        $login = 'nao-existe-' . Str::random(6);
        for ($i = 0; $i < config('locgestor.login_max_conta'); $i++) {
            $this->post(route('plataforma.entrar'), ['login' => $login, 'senha' => 'x']);
        }
        $this->post(route('plataforma.entrar'), ['login' => $login, 'senha' => 'x'])
            ->assertSessionHas('flash', fn ($f) => str_contains($f[0]['msg'], 'Muitas tentativas'));
    }

    public function test_usuario_desativado_perde_o_acesso_na_hora(): void
    {
        $this->entrarNaPlataforma();
        DB::connection('plataforma')->update('UPDATE plataforma_usuarios SET ativo = false WHERE login = ?', ['suporte']);
        $this->get(route('plataforma.locadoras'))->assertRedirect(route('plataforma.login'));
    }

    public function test_sair_encerra_o_painel(): void
    {
        $this->entrarNaPlataforma();
        $this->post(route('plataforma.sair'))->assertRedirect(route('plataforma.login'));
        $this->get(route('plataforma.locadoras'))->assertRedirect(route('plataforma.login'));
    }
}
