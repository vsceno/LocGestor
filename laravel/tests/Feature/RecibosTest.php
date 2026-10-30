<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Recibos: regras de recibo_salvar() do sistema antigo (número da sequência, sem alterar nem apagar). */
class RecibosTest extends TestCase
{
    private function dados(array $mudar = []): array
    {
        return $mudar + ['recebido_de' => 'TESTE Pagador', 'documento' => '529.982.247-25', 'valor' => '150,50', 'forma' => 'PIX',
                         'referente' => 'TESTE pagamento', 'locacao_id' => ''];
    }

    private function contador(): int
    {
        return (int) (DB::selectOne("SELECT ultimo FROM tenant_sequencias WHERE nome = 'recibo' AND tenant_id = (SELECT id FROM tenants WHERE slug = 'alfa-locacoes')")->ultimo ?? 0);
    }

    public function test_emite_com_o_numero_da_sequencia_e_grava_so_digitos(): void
    {
        $this->entrarComo();
        $antes = $this->contador();
        $this->post('/recibos', $this->dados())->assertSessionHasNoErrors();
        $r = DB::selectOne("SELECT * FROM recibos WHERE referente = 'TESTE pagamento'");
        $this->assertSame($antes + 1, $this->contador());
        $this->assertSame([$antes + 1, '52998224725', '150.50', 'PIX'], [$r->numero, $r->documento, $r->valor, $r->forma]);
        $this->assertSame(session('usuario.id'), $r->usuario_id);
        $this->assertNull($r->locacao_id);
        $this->get("/recibos/{$r->id}")->assertOk()->assertSee('Recibo nº ' . $r->numero)->assertSee('529.982.247-25');
    }

    public function test_recusas_nao_gravam_nem_gastam_numero(): void
    {
        $this->entrarComo();
        $antes = $this->contador();
        foreach ([
            'recebido_de' => ['recebido_de' => 'X'],
            'documento'   => ['documento' => '111.111.111-11'],
            'valor'       => ['valor' => '0'],
            'referente'   => ['referente' => ''],
            'forma'       => ['forma' => 'Bitcoin'],
            'locacao_id'  => ['locacao_id' => '00000000-0000-0000-0000-000000000000'],
        ] as $campo => $mudar) {
            $this->post('/recibos', $this->dados($mudar))->assertSessionHasErrors($campo);
        }
        $this->assertNull(DB::selectOne("SELECT id FROM recibos WHERE referente = 'TESTE pagamento'"));
        $this->assertSame($antes, $this->contador(), 'recusa não pode consumir número de recibo');
    }

    public function test_novo_vindo_da_locacao_preenche_quem_pagou_e_guarda_o_cliente(): void
    {
        $this->entrarComo();
        $l = DB::selectOne('SELECT l.id, l.numero, l.cliente_id, c.nome FROM locacoes l JOIN clientes c ON c.id = l.cliente_id ORDER BY l.numero LIMIT 1');
        $this->get('/recibos/novo?locacao=' . $l->id)->assertOk()
            ->assertSee('value="' . e($l->nome) . '"', false)
            ->assertSee('Pagamento da locação nº ' . $l->numero)
            ->assertSee('Ligado à locação nº ' . $l->numero);

        $this->post('/recibos', $this->dados(['locacao_id' => $l->id]))->assertSessionHasNoErrors();
        $r = DB::selectOne("SELECT cliente_id, locacao_id FROM recibos WHERE referente = 'TESTE pagamento'");
        $this->assertSame([$l->cliente_id, $l->id], [$r->cliente_id, $r->locacao_id]);
    }

    public function test_imprime_com_o_texto_do_recibo_e_a_folha_de_impressao(): void
    {
        $this->entrarComo();
        $this->post('/recibos', $this->dados())->assertSessionHasNoErrors();
        $r = DB::selectOne("SELECT id, numero FROM recibos WHERE referente = 'TESTE pagamento'");
        $this->get("/recibos/{$r->id}/imprimir")->assertOk()
            ->assertSee('Recebemos de', false)
            ->assertSee('R$ 150,50', false)
            ->assertSee('Nº ' . $r->numero, false)
            ->assertSee('print.css', false)
            ->assertSee('Assinatura do responsável', false);
    }

    public function test_beta_nao_ve_nem_imprime_recibo_da_alfa(): void
    {
        $this->entrarComo();
        $this->post('/recibos', $this->dados())->assertSessionHasNoErrors();
        $r = DB::selectOne("SELECT id FROM recibos WHERE referente = 'TESTE pagamento'");
        $this->post('/sair');

        $this->entrarComo('beta-eventos');
        $this->get("/recibos/{$r->id}")->assertNotFound();
        $this->get("/recibos/{$r->id}/imprimir")->assertNotFound();
        $this->get('/recibos')->assertOk()->assertDontSee("/recibos/{$r->id}", false);
    }
}
