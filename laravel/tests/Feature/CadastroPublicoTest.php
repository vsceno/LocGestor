<?php

namespace Tests\Feature;

use App\Support\Locadoras;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PlataformaTestCase;

/** Cadastro público ("Teste grátis"): desligado por padrão, com as mesmas proteções contra robôs do sistema antigo. */
class CadastroPublicoTest extends PlataformaTestCase
{
    /** Liga o cadastro só para este teste. */
    private function ligado(): void
    {
        config(['locgestor.cadastro_publico' => true, 'locgestor.cadastro_dias_teste' => 14, 'locgestor.cadastro_plano' => '']);
    }

    /** O que o formulário envia (identificador único a cada teste). */
    private function formulario(array $sobre = []): array
    {
        return $sobre + [
            'nome' => 'Locadora Teste Grátis ' . Str::random(4),
            'slug' => 'gratis-' . strtolower(Str::random(10)),
            'cnpj' => '', 'email' => 'contato@exemplo.com', 'telefone' => '(85) 99999-0000',
            'admin_nome' => 'Maria Teste', 'admin_login' => 'maria',
            'senha' => 'senha-forte-123', 'senha_confirma' => 'senha-forte-123',
            'aceite' => '1', 'site' => '',
        ];
    }

    /** Sessão com o formulário aberto há mais tempo que o mínimo (um robô não espera tanto). */
    private function preenchidoComCalma(): array
    {
        return ['cadastro_inicio' => time() - 10];
    }

    public function test_desligado_a_pagina_nao_existe(): void
    {
        config(['locgestor.cadastro_publico' => false]);
        $this->get(route('cadastro'))->assertNotFound();
        $this->post(route('cadastro.criar'), $this->formulario())->assertNotFound();
    }

    public function test_ligado_mostra_o_formulario_e_o_link_na_entrada(): void
    {
        $this->ligado();
        $this->get(route('cadastro'))->assertOk()->assertSee('Teste grátis por 14 dias');
        $this->get(route('login'))->assertOk()->assertSee('Teste grátis');
    }

    public function test_cria_a_locadora_com_o_vencimento_do_teste_e_o_admin_com_a_senha_escolhida(): void
    {
        $this->ligado();
        $dados = $this->formulario();

        $this->withSession($this->preenchidoComCalma())
            ->post(route('cadastro.criar'), $dados)
            ->assertRedirect(route('cadastro.pronto'));

        $t = DB::connection('plataforma')->selectOne('SELECT id, status, vencimento, carencia_dias, email_contato FROM tenants WHERE slug = ?', [$dados['slug']]);
        $this->assertSame(['ativa', now()->addDays(14)->toDateString(), 5, 'contato@exemplo.com'],
                          [$t->status, $t->vencimento, (int) $t->carencia_dias, $t->email_contato]);

        // O admin escolheu a própria senha: não é obrigado a trocá-la no primeiro acesso
        $admin = Locadoras::noContexto($t->id, fn ($db) => $db->selectOne("SELECT login, trocar_senha FROM usuarios WHERE login = 'maria'"));
        $this->assertSame(['maria', false], [$admin->login, $admin->trocar_senha]);

        $this->get(route('cadastro.pronto'))->assertOk()->assertSee('Pronto!')->assertSee($dados['slug']);
    }

    public function test_campo_isca_preenchido_e_recusado(): void
    {
        $this->ligado();
        $this->withSession($this->preenchidoComCalma())
            ->post(route('cadastro.criar'), $this->formulario(['site' => 'sou um robô']))
            ->assertSessionHas('flash', fn ($f) => str_contains($f[0]['msg'], 'Não foi possível concluir'));
    }

    public function test_formulario_enviado_rapido_demais_e_recusado(): void
    {
        $this->ligado();
        $this->withSession(['cadastro_inicio' => time()])
            ->post(route('cadastro.criar'), $this->formulario())
            ->assertSessionHas('flash', fn ($f) => str_contains($f[0]['msg'], 'envie o formulário de novo'));
    }

    public function test_mais_de_tres_cadastros_por_ip_na_hora_sao_recusados(): void
    {
        $this->ligado();
        $chave = hash('sha256', 'cadastro|ip|127.0.0.1');
        for ($i = 0; $i < 3; $i++) {
            DB::connection('plataforma')->insert('INSERT INTO login_tentativas (chave) VALUES (?)', [$chave]);
        }
        $this->withSession($this->preenchidoComCalma())
            ->post(route('cadastro.criar'), $this->formulario())
            ->assertSessionHas('flash', fn ($f) => str_contains($f[0]['msg'], 'Muitos cadastros'));
    }

    public function test_dados_invalidos_nao_criam_a_locadora(): void
    {
        $this->ligado();
        $dados = $this->formulario(['slug' => 'A B', 'telefone' => '123', 'senha' => 'curta', 'senha_confirma' => 'curta', 'aceite' => '']);

        $this->withSession($this->preenchidoComCalma())
            ->post(route('cadastro.criar'), $dados)
            ->assertSessionHas('flash', function ($f) {
                $msg = $f[0]['msg'];
                return str_contains($msg, 'Identificador inválido') && str_contains($msg, 'telefone com DDD')
                    && str_contains($msg, '8 caracteres') && str_contains($msg, 'aceita os termos');
            });
        $this->assertNull(DB::connection('plataforma')->selectOne("SELECT id FROM tenants WHERE slug = 'A B'"));
    }

    public function test_identificador_repetido_e_recusado(): void
    {
        $this->ligado();
        $slug = 'repetido-' . strtolower(Str::random(8));
        $this->criarLocadora(['slug' => $slug]);

        $this->withSession($this->preenchidoComCalma())
            ->post(route('cadastro.criar'), $this->formulario(['slug' => $slug]))
            ->assertSessionHas('flash', fn ($f) => str_contains($f[0]['msg'], 'Já existe uma locadora'));
    }
}
