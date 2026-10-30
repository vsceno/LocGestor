<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Patrimônio (unidades): regras de unidade_salvar() e unidade_baixar() do sistema antigo. */
class UnidadesTest extends TestCase
{
    private function produto(string $codigo): object
    {
        return DB::selectOne('SELECT id, qtd_total FROM produtos WHERE codigo = ?', [$codigo]);
    }

    public function test_cadastra_edita_baixa_e_exclui_unidade(): void
    {
        $this->entrarComo();
        $p = $this->produto('FUR-750');
        $this->post("/produtos/{$p->id}/unidades", ['patrimonio' => 'tst-fur-01', 'serie' => 'SN1', 'aquisicao_data' => '2026-01-10', 'aquisicao_valor' => '450,00'])
            ->assertRedirect();
        $u = DB::selectOne("SELECT * FROM unidades WHERE patrimonio = 'TST-FUR-01'");
        $this->assertSame('450.00', $u->aquisicao_valor);
        $this->assertSame($p->id, $u->produto_id);

        $this->put("/produtos/unidades/{$u->id}", ['patrimonio' => 'TST-FUR-01', 'serie' => 'SN2'])->assertRedirect(route('unidades.show', $u->id));
        $this->assertSame('SN2', DB::selectOne('SELECT serie FROM unidades WHERE id = ?', [$u->id])->serie);

        $this->post("/produtos/unidades/{$u->id}/baixar", ['baixa_data' => now()->addDay()->toDateString(), 'baixa_motivo' => 'vendida'])
            ->assertSessionHasErrors('baixa');
        $this->post("/produtos/unidades/{$u->id}/baixar", ['baixa_data' => now()->toDateString(), 'baixa_motivo' => 'vendida'])
            ->assertRedirect(route('unidades.show', $u->id));
        $this->assertFalse(DB::selectOne('SELECT ativa FROM unidades WHERE id = ?', [$u->id])->ativa);
        $this->assertSame($p->qtd_total - 1, DB::selectOne('SELECT qtd_total FROM produtos WHERE id = ?', [$p->id])->qtd_total);
        $this->post("/produtos/unidades/{$u->id}/baixar", ['baixa_data' => now()->toDateString(), 'baixa_motivo' => 'de novo'])
            ->assertSessionHasErrors(['baixa' => 'Esta unidade já foi baixada.']);

        $this->delete("/produtos/unidades/{$u->id}")->assertRedirect(route('produtos.edit', $p->id));
        $this->assertNull(DB::selectOne('SELECT id FROM unidades WHERE id = ?', [$u->id]));
    }

    public function test_nao_passa_do_estoque_nem_repete_patrimonio(): void
    {
        $this->entrarComo();
        $cheio = DB::selectOne('SELECT p.id FROM produtos p WHERE p.qtd_total > 0 AND p.qtd_total = (SELECT count(*) FROM unidades u WHERE u.produto_id = p.id AND u.ativa) LIMIT 1');
        $this->post("/produtos/{$cheio->id}/unidades", ['patrimonio' => 'TST-X'])->assertSessionHasErrors('patrimonio');
        $this->assertNull(DB::selectOne("SELECT id FROM unidades WHERE patrimonio = 'TST-X'"));

        $existente = DB::selectOne('SELECT patrimonio FROM unidades LIMIT 1')->patrimonio;
        $this->post("/produtos/{$this->produto('FUR-750')->id}/unidades", ['patrimonio' => $existente])
            ->assertSessionHasErrors(['patrimonio' => 'Já existe uma unidade com este número de patrimônio.']);
        $this->post("/produtos/{$this->produto('FUR-750')->id}/unidades", ['patrimonio' => 'com espaço', 'aquisicao_data' => '2026-02-30', 'aquisicao_valor' => 'x'])
            ->assertSessionHasErrors(['patrimonio', 'aquisicao_data', 'aquisicao_valor']);
    }

    public function test_unidade_com_historico_nao_e_excluida(): void
    {
        $this->entrarComo();
        $u = DB::selectOne('SELECT u.id FROM unidades u WHERE EXISTS (SELECT 1 FROM manutencoes m WHERE m.unidade_id = u.id) LIMIT 1');
        $this->get("/produtos/unidades/{$u->id}")->assertOk()->assertSee('Manutenção nº');
        $this->delete("/produtos/unidades/{$u->id}")->assertRedirect();
        $this->assertStringContainsString('histórico', session('flash')[0]['msg']);
        $this->assertNotNull(DB::selectOne('SELECT id FROM unidades WHERE id = ?', [$u->id]));
    }

    public function test_beta_nao_abre_nem_baixa_unidade_da_alfa(): void
    {
        $u = $this->lerComoAdmin("SELECT u.id, u.produto_id FROM unidades u JOIN tenants t ON t.id = u.tenant_id WHERE t.slug = 'alfa-locacoes' AND u.ativa LIMIT 1")[0];
        $this->entrarComo('beta-eventos');
        $this->get("/produtos/unidades/{$u->id}")->assertNotFound();
        $this->put("/produtos/unidades/{$u->id}", ['patrimonio' => 'INVADIDO'])->assertNotFound();
        $this->post("/produtos/unidades/{$u->id}/baixar", ['baixa_data' => now()->toDateString(), 'baixa_motivo' => 'invasão'])->assertNotFound();
        $this->delete("/produtos/unidades/{$u->id}")->assertNotFound();
        $this->get("/produtos/{$u->produto_id}/unidades/nova")->assertNotFound();
        $this->post("/produtos/{$u->produto_id}/unidades", ['patrimonio' => 'TST-INV'])->assertNotFound();
        $this->assertTrue($this->lerComoAdmin('SELECT ativa FROM unidades WHERE id = ?', [$u->id])[0]->ativa);
    }
}
