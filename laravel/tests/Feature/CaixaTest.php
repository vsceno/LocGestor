<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Fluxo de caixa: regras de lancamento_registrar() e lancamento_excluir() do sistema antigo. */
class CaixaTest extends TestCase
{
    public function test_extrato_mostra_saldos_do_periodo(): void
    {
        $this->entrarComo();
        $de = now()->format('Y-m-01');
        $hoje = now()->toDateString();
        $anterior = (float) DB::selectOne("SELECT COALESCE(SUM(CASE WHEN tipo = 'E' THEN valor ELSE -valor END), 0) AS v FROM caixa WHERE data < ?", [$de])->v;
        $mov = (float) DB::selectOne("SELECT COALESCE(SUM(CASE WHEN tipo = 'E' THEN valor ELSE -valor END), 0) AS v FROM caixa WHERE data BETWEEN ? AND ?", [$de, $hoje])->v;
        $this->get('/caixa')->assertOk()->assertSee(moeda($anterior))->assertSee(moeda($anterior + $mov));
        $this->get('/caixa?de=lixo&ate=2026-13-45')->assertOk();
    }

    public function test_lanca_recusa_e_exclui(): void
    {
        $this->entrarComo();
        $base = ['data' => now()->toDateString(), 'tipo' => 'S', 'categoria' => 'Teste', 'descricao' => 'TESTE despesa', 'valor' => '12,34', 'forma' => 'PIX'];
        $this->post('/caixa', ['valor' => '0', 'data' => now()->addDay()->toDateString(), 'tipo' => 'X', 'forma' => 'Bitcoin'] + $base)
            ->assertSessionHasErrors(['valor', 'data', 'tipo', 'forma']);
        $this->post('/caixa', $base)->assertSessionHasNoErrors();
        $c = DB::selectOne("SELECT * FROM caixa WHERE descricao = 'TESTE despesa'");
        $this->assertSame(['S', '12.34', 'PIX', session('usuario.id')], [$c->tipo, $c->valor, $c->forma, $c->usuario_id]);

        $this->delete("/caixa/{$c->id}")->assertRedirect();
        $this->assertNull(DB::selectOne("SELECT id FROM caixa WHERE descricao = 'TESTE despesa'"));

        $loc = DB::selectOne('SELECT id FROM caixa WHERE locacao_id IS NOT NULL LIMIT 1');
        $this->delete("/caixa/{$loc->id}")->assertSessionHas('flash');
        $this->assertStringContainsString('Lançamentos de locação não podem ser excluídos', session('flash')[0]['msg']);
        $this->assertNotNull(DB::selectOne('SELECT id FROM caixa WHERE id = ?', [$loc->id]));
    }

    public function test_operador_nao_exclui(): void
    {
        $this->entrarComo();
        DB::insert("INSERT INTO usuarios (nome, login, senha_hash, perfil) VALUES ('Operador Teste', 'oper.teste', ?, 'operador')", [password_hash('oper12345', PASSWORD_DEFAULT)]);
        $this->post('/caixa', ['data' => now()->toDateString(), 'tipo' => 'S', 'categoria' => 'Teste', 'descricao' => 'TESTE despesa', 'valor' => '1', 'forma' => 'PIX']);
        $id = DB::selectOne("SELECT id FROM caixa WHERE descricao = 'TESTE despesa'")->id;
        $this->post('/sair');
        $this->entrarComo('alfa-locacoes', 'oper.teste', 'oper12345');
        $this->delete("/caixa/$id")->assertForbidden();
        $this->assertNotNull(DB::selectOne('SELECT id FROM caixa WHERE id = ?', [$id]));
    }

    public function test_beta_nao_ve_nem_exclui_lancamento_da_alfa(): void
    {
        $c = $this->lerComoAdmin("SELECT c.id, c.descricao FROM caixa c JOIN tenants t ON t.id = c.tenant_id WHERE t.slug = 'alfa-locacoes' AND c.locacao_id IS NULL
                                   AND NOT EXISTS (SELECT 1 FROM caixa b JOIN tenants tb ON tb.id = b.tenant_id WHERE tb.slug = 'beta-eventos' AND b.descricao = c.descricao) LIMIT 1")[0] ?? null;
        if ($c === null) {
            $this->markTestSkipped('Sem lançamento manual da Alfa nos dados de demonstração.');
        }
        $this->entrarComo('beta-eventos');
        $this->delete("/caixa/{$c->id}")->assertNotFound();
        $this->get('/caixa?de=2000-01-01')->assertOk()->assertDontSee($c->descricao);
        $this->assertNotEmpty($this->lerComoAdmin('SELECT id FROM caixa WHERE id = ?', [$c->id]));
    }
}
