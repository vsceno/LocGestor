<?php

namespace Tests\Feature\Plataforma;

use App\Support\Locadoras;
use Illuminate\Support\Facades\DB;
use Tests\PlataformaTestCase;

/** Mensalidade no painel: registrar pagamento, estornar o último e redefinir a senha de um usuário da locadora. */
class PagamentosTest extends PlataformaTestCase
{
    private function pagamentos(string $id): int
    {
        return (int) DB::connection('plataforma')->selectOne('SELECT count(*) AS n FROM assinatura_pagamentos WHERE locadora_id = ?', [$id])->n;
    }

    public function test_pagamento_avanca_o_vencimento_e_reativa_a_inadimplente(): void
    {
        $this->entrarNaPlataforma();
        $id = $this->criarLocadora(['vencimento' => '2026-01-10', 'carencia' => 5]);
        // A lista suspende quem passou da carência
        $this->get(route('plataforma.locadoras'))->assertOk();
        $this->assertSame(['suspensa', 'inadimplencia'], [$this->locadoraNoBanco($id)->status, $this->locadoraNoBanco($id)->motivo_suspensao]);

        $this->post(route('plataforma.locadoras.pagamento', $id), ['meses' => 2, 'data' => now()->toDateString(), 'forma' => 'PIX', 'valor' => ''])
            ->assertRedirect(route('plataforma.locadoras.editar', $id))
            ->assertSessionHas('flash', fn ($f) => str_contains($f[0]['msg'], 'Locadora reativada'));

        $t = $this->locadoraNoBanco($id);
        $this->assertSame(['ativa', '', '2026-03-10'], [$t->status, $t->motivo_suspensao, $t->vencimento]);
        // Sem valor digitado: preço do plano (R$ 99) × 2 meses
        $pagamento = DB::connection('plataforma')->selectOne('SELECT valor, meses FROM assinatura_pagamentos WHERE locadora_id = ?', [$id]);
        $this->assertSame([198.0, 2], [(float) $pagamento->valor, (int) $pagamento->meses]);
    }

    public function test_valor_digitado_e_validacoes_do_pagamento(): void
    {
        $this->entrarNaPlataforma();
        $id = $this->criarLocadora();
        $hoje = now()->toDateString();

        $this->post(route('plataforma.locadoras.pagamento', $id), ['meses' => 1, 'data' => $hoje, 'forma' => 'PIX', 'valor' => '1.234,50'])
            ->assertSessionHas('flash', fn ($f) => str_contains($f[0]['msg'], 'R$ 1.234,50'));
        $this->post(route('plataforma.locadoras.pagamento', $id), ['meses' => 0, 'data' => $hoje, 'forma' => 'PIX'])
            ->assertSessionHas('flash', fn ($f) => str_contains($f[0]['msg'], 'Confira os meses'));
        $this->post(route('plataforma.locadoras.pagamento', $id), ['meses' => 1, 'data' => now()->addDay()->toDateString(), 'forma' => 'PIX'])
            ->assertSessionHas('flash', fn ($f) => str_contains($f[0]['msg'], 'Confira os meses'));
        $this->post(route('plataforma.locadoras.pagamento', $id), ['meses' => 1, 'data' => $hoje, 'forma' => 'Escambo'])
            ->assertSessionHas('flash', fn ($f) => str_contains($f[0]['msg'], 'forma de pagamento'));
        $this->post(route('plataforma.locadoras.pagamento', $id), ['meses' => 1, 'data' => $hoje, 'forma' => 'PIX', 'valor' => 'abc'])
            ->assertSessionHas('flash', fn ($f) => str_contains($f[0]['msg'], 'Valor inválido'));

        $this->assertSame(1, $this->pagamentos($id));
    }

    public function test_estorno_devolve_o_vencimento_anterior(): void
    {
        $this->entrarNaPlataforma();
        $id = $this->criarLocadora(['vencimento' => '2026-05-10']);
        $this->post(route('plataforma.locadoras.pagamento', $id), ['meses' => 1, 'data' => now()->toDateString(), 'forma' => 'PIX']);
        $this->assertSame('2026-06-10', $this->locadoraNoBanco($id)->vencimento);

        $this->post(route('plataforma.locadoras.estornar', $id))
            ->assertSessionHas('flash', fn ($f) => $f[0]['tipo'] === 'ok');
        $this->assertSame('2026-05-10', $this->locadoraNoBanco($id)->vencimento);
        // O pagamento não some: fica marcado como estornado (o recibo continua existindo)
        $this->assertSame(1, $this->pagamentos($id));
        $this->assertNotNull(DB::connection('plataforma')->selectOne('SELECT estornado_em FROM assinatura_pagamentos WHERE locadora_id = ?', [$id])->estornado_em);
    }

    public function test_estorno_recusa_se_o_vencimento_mudou_depois_do_pagamento(): void
    {
        $this->entrarNaPlataforma();
        $id = $this->criarLocadora(['vencimento' => '2026-05-10']);
        $this->post(route('plataforma.locadoras.pagamento', $id), ['meses' => 1, 'data' => now()->toDateString(), 'forma' => 'PIX']);
        DB::connection('plataforma')->update("UPDATE tenants SET vencimento = '2026-09-01' WHERE id = ?", [$id]);

        $this->post(route('plataforma.locadoras.estornar', $id))
            ->assertSessionHas('flash', fn ($f) => str_contains($f[0]['msg'], 'foi alterado depois'));
        $this->assertSame(1, $this->pagamentos($id));
    }

    public function test_locadora_cancelada_nao_recebe_pagamento(): void
    {
        $this->entrarNaPlataforma();
        $id = $this->criarLocadora();
        DB::connection('plataforma')->update("UPDATE tenants SET status = 'cancelada', cancelada_em = CURRENT_DATE, vencimento = NULL WHERE id = ?", [$id]);

        $this->post(route('plataforma.locadoras.pagamento', $id), ['meses' => 1, 'data' => now()->toDateString(), 'forma' => 'PIX'])
            ->assertSessionHas('flash', fn ($f) => str_contains($f[0]['msg'], 'Locadora cancelada'));
        $this->assertSame(0, $this->pagamentos($id));
    }

    public function test_redefinir_senha_mostra_a_senha_uma_vez_e_obriga_a_trocar(): void
    {
        $this->entrarNaPlataforma();
        $id = $this->criarLocadora();
        $usuario = Locadoras::noContexto($id, fn ($db) => $db->selectOne("SELECT id FROM usuarios WHERE login = 'admin'"))->id;

        $this->post(route('plataforma.locadoras.senha', $id), ['usuario' => $usuario])
            ->assertRedirect(route('plataforma.locadoras.editar', $id))
            ->assertSessionHas('senhaRedefinida.login', 'admin');

        $u = Locadoras::noContexto($id, fn ($db) => $db->selectOne(
            "SELECT trocar_senha, senha_redefinida_em IS NOT NULL AS redefinida FROM usuarios WHERE login = 'admin'"
        ));
        $this->assertSame([true, true], [$u->trocar_senha, $u->redefinida]);

        // A senha aparece na tela seguinte e some depois
        $this->get(route('plataforma.locadoras.editar', $id))->assertOk()->assertSee('Senha temporária de');
        $this->get(route('plataforma.locadoras.editar', $id))->assertDontSee('Senha temporária de');
    }
}
