<?php

namespace Tests\Feature\Plataforma;

use App\Support\Suporte;
use Illuminate\Support\Facades\DB;
use Tests\PlataformaTestCase;

/** Acesso de suporte pelo painel: o código de uso único vai para a locadora e só o hash fica no banco. */
class SuporteTest extends PlataformaTestCase
{
    public function test_abre_o_acesso_e_manda_o_codigo_para_a_locadora(): void
    {
        $this->entrarNaPlataforma();
        $id = $this->criarLocadora();

        $resposta = $this->post(route('plataforma.locadoras.suporte', $id), ['motivo' => 'Cliente relatou erro no contrato nº 123']);
        $resposta->assertRedirectContains('/suporte/entrar?t=');

        // O código tem "<id da locadora>.<64 hex>"; no banco fica só o sha256 da parte aleatória
        parse_str(parse_url($resposta->headers->get('Location'), PHP_URL_QUERY), $consulta);
        $codigo = $consulta['t'];
        $this->assertSame($id . '.', substr($codigo, 0, 37));

        $acesso = DB::connection('plataforma')->selectOne(
            'SELECT tenant_id, login_suporte, motivo, token_hash, usado_em, expira_em > now() AS vigente
               FROM acessos_suporte WHERE tenant_id = ?', [$id]
        );
        $this->assertSame([$id, 'suporte', 'Cliente relatou erro no contrato nº 123', hash('sha256', substr($codigo, 37)), null, true],
                          [$acesso->tenant_id, $acesso->login_suporte, $acesso->motivo, $acesso->token_hash, $acesso->usado_em, $acesso->vigente]);
    }

    public function test_motivo_curto_nao_abre_o_acesso(): void
    {
        $this->entrarNaPlataforma();
        $id = $this->criarLocadora();

        $this->post(route('plataforma.locadoras.suporte', $id), ['motivo' => 'curto'])
            ->assertSessionHas('flash', fn ($f) => str_contains($f[0]['msg'], 'de 10 a 300 caracteres'));
        $this->assertNull(DB::connection('plataforma')->selectOne('SELECT id FROM acessos_suporte WHERE tenant_id = ?', [$id]));
    }

    public function test_locadora_suspensa_nao_recebe_acesso_de_suporte(): void
    {
        $this->entrarNaPlataforma();
        $id = $this->criarLocadora();
        DB::connection('plataforma')->update("UPDATE tenants SET status = 'suspensa', motivo_suspensao = 'manual' WHERE id = ?", [$id]);

        $this->post(route('plataforma.locadoras.suporte', $id), ['motivo' => 'Cliente relatou erro no contrato nº 123'])
            ->assertSessionHas('flash', fn ($f) => str_contains($f[0]['msg'], 'locadora ativa'));
        $this->assertNull(DB::connection('plataforma')->selectOne('SELECT id FROM acessos_suporte WHERE tenant_id = ?', [$id]));
    }

    public function test_a_tela_da_locadora_lista_os_acessos_recentes(): void
    {
        $this->entrarNaPlataforma();
        $id = $this->criarLocadora();
        $this->post(route('plataforma.locadoras.suporte', $id), ['motivo' => 'Chamado 45: cliente sem acesso ao caixa']);

        $this->get(route('plataforma.locadoras.editar', $id))->assertOk()
            ->assertSee('Acesso de suporte')->assertSee('Chamado 45: cliente sem acesso ao caixa')->assertSee('não usado');
    }

    public function test_separa_o_codigo_do_link_em_locadora_e_hash(): void
    {
        $id = '01a18e13-cf1c-791b-964e-3132a6f3c722';
        $this->assertSame([$id, hash('sha256', str_repeat('f', 64))], Suporte::separar($id . '.' . str_repeat('f', 64)));
        $this->assertNull(Suporte::separar($id . '.' . str_repeat('f', 63)));
        $this->assertNull(Suporte::separar('nao-e-um-codigo'));
    }
}
