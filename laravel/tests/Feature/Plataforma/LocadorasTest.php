<?php

namespace Tests\Feature\Plataforma;

use App\Support\Locadoras;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PlataformaTestCase;

/** Locadoras no painel da plataforma: cadastro, plano e mensalidade, suspensão e reativação. Mesmas regras do sistema antigo. */
class LocadorasTest extends PlataformaTestCase
{
    /** O que o formulário de nova locadora envia (identificador único a cada teste). */
    private function formulario(array $sobre = []): array
    {
        return $sobre + [
            'nome' => 'Locadora Nova ' . Str::random(4),
            'slug' => 'nova-' . strtolower(Str::random(10)),
            'cnpj' => '', 'email_contato' => '', 'telefone' => '',
            'plano_id' => $this->criarPlano()->id,
            'vencimento' => now()->addDays(30)->toDateString(),
            'carencia_dias' => 5,
            'admin_nome' => 'Administrador', 'admin_login' => 'admin',
        ];
    }

    public function test_lista_mostra_as_locadoras_e_os_indicadores(): void
    {
        $this->entrarNaPlataforma();
        $this->criarLocadora(['nome' => 'Alfa Teste Lista']);
        $this->get(route('plataforma.locadoras'))->assertOk()
            ->assertSee('Alfa Teste Lista')->assertSee('Receita mensal recorrente');
    }

    public function test_cria_locadora_com_admin_e_mostra_a_senha_temporaria_uma_vez(): void
    {
        $this->entrarNaPlataforma();
        $dados = $this->formulario(['nome' => 'Locadora Criada Teste', 'slug' => 'criada-' . strtolower(Str::random(8))]);

        $this->post(route('plataforma.locadoras.criar'), $dados)->assertRedirect(route('plataforma.locadoras.criada'));
        $this->get(route('plataforma.locadoras.criada'))->assertOk()->assertSee($dados['slug'])->assertSee('Senha temporária');
        // Mostrada uma única vez: depois disso a tela não existe mais
        $this->get(route('plataforma.locadoras.criada'))->assertRedirect(route('plataforma.locadoras'));

        $t = DB::connection('plataforma')->selectOne('SELECT id, status, vencimento, carencia_dias FROM tenants WHERE slug = ?', [$dados['slug']]);
        $this->assertSame(['ativa', now()->addDays(30)->toDateString(), 5], [$t->status, $t->vencimento, (int) $t->carencia_dias]);

        $admin = Locadoras::noContexto($t->id, fn ($db) => $db->selectOne("SELECT login, perfil, trocar_senha FROM usuarios WHERE login = 'admin'"));
        $this->assertSame(['admin', 'admin', true], [$admin->login, $admin->perfil, $admin->trocar_senha]);
    }

    public function test_identificador_invalido_ou_repetido_nao_cria_a_locadora(): void
    {
        $this->entrarNaPlataforma();
        $this->post(route('plataforma.locadoras.criar'), $this->formulario(['slug' => 'A B']))
            ->assertSessionHas('flash', fn ($f) => str_contains($f[0]['msg'], 'Identificador inválido'));

        $slug = 'repetida-' . strtolower(Str::random(8));
        $this->criarLocadora(['slug' => $slug]);
        $this->post(route('plataforma.locadoras.criar'), $this->formulario(['slug' => $slug]))
            ->assertSessionHas('flash', fn ($f) => str_contains($f[0]['msg'], 'Já existe uma locadora'));
    }

    public function test_salvar_troca_plano_vencimento_e_carencia(): void
    {
        $this->entrarNaPlataforma();
        $id = $this->criarLocadora(['nome' => 'Locadora Antes']);
        $novoPlano = $this->criarPlano(199, 5);
        $venc = now()->addDays(60)->toDateString();

        $this->put(route('plataforma.locadoras.salvar', $id), [
            'nome' => 'Locadora Depois', 'cnpj' => '', 'email_contato' => 'contato@exemplo.com', 'telefone' => '(85) 3000-0000',
            'plano_id' => $novoPlano->id, 'vencimento' => $venc, 'carencia_dias' => 10, 'limite_usuarios' => '',
        ])->assertRedirect(route('plataforma.locadoras.editar', $id));

        $t = $this->locadoraNoBanco($id);
        // Limite em branco vira o limite do plano escolhido
        $this->assertSame(['Locadora Depois', $novoPlano->nome, 5, $venc, 10],
                          [$t->nome, $t->plano, (int) $t->limite_usuarios, $t->vencimento, (int) $t->carencia_dias]);
    }

    public function test_suspender_e_reativar_pelo_painel(): void
    {
        $this->entrarNaPlataforma();
        $id = $this->criarLocadora();

        $this->post(route('plataforma.locadoras.status', $id), ['status' => 'suspensa'])->assertRedirect(route('plataforma.locadoras.editar', $id));
        $this->assertSame(['suspensa', 'manual'], [$this->locadoraNoBanco($id)->status, $this->locadoraNoBanco($id)->motivo_suspensao]);

        $this->post(route('plataforma.locadoras.status', $id), ['status' => 'ativa']);
        $this->assertSame(['ativa', ''], [$this->locadoraNoBanco($id)->status, $this->locadoraNoBanco($id)->motivo_suspensao]);
    }

    public function test_mensalidade_vencida_alem_da_carencia_suspende_ao_abrir_a_lista(): void
    {
        $this->entrarNaPlataforma();
        $id = $this->criarLocadora(['vencimento' => now()->subDays(10)->toDateString(), 'carencia' => 5]);

        $this->get(route('plataforma.locadoras'))->assertOk()->assertSee('inadimplente');
        $this->assertSame(['suspensa', 'inadimplencia'], [$this->locadoraNoBanco($id)->status, $this->locadoraNoBanco($id)->motivo_suspensao]);
    }

    public function test_reativar_locadora_ainda_inadimplente_avisa_que_o_acesso_segue_bloqueado(): void
    {
        $this->entrarNaPlataforma();
        $id = $this->criarLocadora(['vencimento' => now()->subDays(10)->toDateString(), 'carencia' => 5]);

        $this->post(route('plataforma.locadoras.status', $id), ['status' => 'ativa'])
            ->assertSessionHas('flash', fn ($f) => $f[0]['tipo'] === 'aviso' && str_contains($f[0]['msg'], 'seguirá bloqueado'));
    }

    public function test_locadora_cancelada_nao_volta_pelo_painel(): void
    {
        $this->entrarNaPlataforma();
        $id = $this->criarLocadora();
        DB::connection('plataforma')->update("UPDATE tenants SET status = 'cancelada', cancelada_em = CURRENT_DATE, vencimento = NULL WHERE id = ?", [$id]);

        $this->post(route('plataforma.locadoras.status', $id), ['status' => 'ativa'])
            ->assertSessionHas('flash', fn ($f) => str_contains($f[0]['msg'], 'Locadora cancelada'));
        $this->assertSame('cancelada', $this->locadoraNoBanco($id)->status);
    }

    public function test_filtro_mensalidade_vencida_mostra_so_as_vencidas(): void
    {
        $this->entrarNaPlataforma();
        $this->criarLocadora(['nome' => 'Vencida Teste Filtro', 'vencimento' => now()->subDays(2)->toDateString()]);
        $this->criarLocadora(['nome' => 'Em Dia Teste Filtro']);

        $this->get(route('plataforma.locadoras', ['filtro' => 'vencidas']))->assertOk()
            ->assertSee('Vencida Teste Filtro')->assertDontSee('Em Dia Teste Filtro');
    }
}
