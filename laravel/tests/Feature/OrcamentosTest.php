<?php

namespace Tests\Feature;

use App\Support\Formulario;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Orçamentos: regras de app/orcamento.php do sistema antigo (salvar, converter e excluir). */
class OrcamentosTest extends TestCase
{
    private function dados(array $mudar = []): array
    {
        $c = DB::selectOne("SELECT nome, cpf_cnpj FROM clientes WHERE ativo AND NOT bloqueado AND cpf_cnpj <> '' ORDER BY nome LIMIT 1");
        return $mudar + ['cliente' => Formulario::rotuloCliente($c), 'data_saida' => now()->addDays(5)->toDateString(), 'data_prev_devolucao' => now()->addDays(19)->toDateString(),
                         'validade' => now()->addDays(7)->toDateString(), 'local_uso' => 'TESTE orçamento',
                         'produto_id' => [DB::selectOne("SELECT id FROM produtos WHERE codigo = 'ESC-300'")->id], 'quantidade' => ['20'], 'diaria' => [''], 'periodo' => ['semanal'],
                         'frete_entrega' => '40', 'desconto' => '10'];
    }

    private function salvo(): ?object
    {
        return DB::selectOne("SELECT * FROM orcamentos WHERE local_uso = 'TESTE orçamento'");
    }

    public function test_salva_com_o_valor_total_igual_ao_do_sistema_antigo(): void
    {
        $this->entrarComo();
        $esc = DB::selectOne("SELECT valor_diaria, valor_semanal FROM produtos WHERE codigo = 'ESC-300'");
        $this->post('/orcamentos', $this->dados())->assertSessionHasNoErrors();
        $o = $this->salvo();
        // 15 dias no semanal: 2 semanas + 1 diária, x 20, + frete 40 - desconto 10
        $esperado = 20 * (2 * $esc->valor_semanal + $esc->valor_diaria) + 40 - 10;
        $this->get("/orcamentos/{$o->id}")->assertOk()->assertSee(moeda($esperado));
    }

    public function test_recusas(): void
    {
        $this->entrarComo();
        foreach ([
            'A validade deve ser de hoje até 90 dias.' => ['validade' => now()->addDays(91)->toDateString()],
            'não pode ser antes da saída'               => ['data_prev_devolucao' => now()->addDays(4)->toDateString()],
            'Inclua pelo menos um item no orçamento.'   => ['produto_id' => ['']],
            'não podem ser negativos'                   => ['desconto' => '-1'],
        ] as $msg => $mudar) {
            $this->post('/orcamentos', $this->dados($mudar))->assertSessionHasErrors('orcamento');
            $this->assertStringContainsString($msg, session('errors')->first('orcamento'), "caso: $msg");
        }
        $this->assertNull($this->salvo());
    }

    public function test_converte_em_reserva_e_em_locacao_e_nao_exclui_convertido(): void
    {
        $this->entrarComo();
        $this->post('/orcamentos', $this->dados())->assertSessionHasNoErrors();
        $o = $this->salvo();
        $this->post("/orcamentos/{$o->id}/converter", ['tipo' => 'locacao'])->assertSessionHas('flash', [['tipo' => 'erro', 'msg' => 'A saída é no futuro: converta em reserva (a locação só registra o que já saiu).']]);
        $this->post("/orcamentos/{$o->id}/converter", ['tipo' => 'reserva'])->assertRedirect();
        $o = $this->salvo();
        $this->assertSame('convertido', $o->status);
        $r = DB::selectOne('SELECT r.status, ri.quantidade, ri.periodo FROM reservas r JOIN reserva_itens ri ON ri.reserva_id = r.id WHERE r.id = ?', [$o->reserva_id]);
        $this->assertSame(['ativa', 20, 'semanal'], [$r->status, (int) $r->quantidade, $r->periodo]);
        $this->post("/orcamentos/{$o->id}/converter", ['tipo' => 'reserva'])->assertSessionHas('flash', [['tipo' => 'erro', 'msg' => 'Este orçamento já foi convertido.']]);
        $this->delete("/orcamentos/{$o->id}")->assertSessionHas('flash', [['tipo' => 'erro', 'msg' => 'Só é possível excluir orçamento que ainda não foi convertido.']]);

        // Locação: saída hoje, com o preço negociado e o desconto autorizado no orçamento
        DB::delete("DELETE FROM orcamento_itens WHERE orcamento_id = ?", [$o->id]);
        DB::delete("DELETE FROM orcamentos WHERE id = ?", [$o->id]);
        $hoje = now()->toDateString();
        $this->post('/orcamentos', $this->dados(['data_saida' => $hoje, 'data_prev_devolucao' => now()->addDays(3)->toDateString(), 'periodo' => ['diaria'], 'diaria' => ['1,00'], 'frete_entrega' => '']))
            ->assertSessionHasNoErrors();
        $o = $this->salvo();
        $this->post("/orcamentos/{$o->id}/converter", ['tipo' => 'locacao'])->assertRedirect();
        $l = DB::selectOne('SELECT l.desconto, l.obs, v.valor_previsto FROM locacoes l JOIN vw_locacao_valores v ON v.locacao_id = l.id
                             WHERE l.id = (SELECT locacao_id FROM orcamentos WHERE id = ?)', [$o->id]);
        $this->assertEqualsWithDelta(20 * 1.00 * 4 - 10, (float) $l->valor_previsto, 0.001);
        $this->assertStringStartsWith("Do orçamento nº {$o->numero}.", $l->obs);
    }

    public function test_vencido_nao_converte_e_aberto_pode_ser_excluido(): void
    {
        $this->entrarComo();
        $this->post('/orcamentos', $this->dados())->assertSessionHasNoErrors();
        $o = $this->salvo();
        DB::update('UPDATE orcamentos SET validade = ? WHERE id = ?', [now()->subDay()->toDateString(), $o->id]);
        $this->post("/orcamentos/{$o->id}/converter", ['tipo' => 'reserva'])->assertSessionHas('flash');
        $this->assertStringStartsWith('Orçamento vencido em', session('flash')[0]['msg']);
        $this->delete("/orcamentos/{$o->id}")->assertRedirect(route('orcamentos.index'));
        $this->assertNull($this->salvo());
    }

    public function test_beta_nao_ve_nem_converte_orcamento_da_alfa(): void
    {
        $o = $this->lerComoAdmin("SELECT o.id FROM orcamentos o JOIN tenants t ON t.id = o.tenant_id WHERE t.slug = 'alfa-locacoes' LIMIT 1")[0];
        $this->entrarComo('beta-eventos');
        $this->get("/orcamentos/{$o->id}")->assertNotFound();
        $this->post("/orcamentos/{$o->id}/converter", ['tipo' => 'reserva'])->assertNotFound();
        $this->delete("/orcamentos/{$o->id}")->assertNotFound();
        $this->assertNotEmpty($this->lerComoAdmin('SELECT id FROM orcamentos WHERE id = ?', [$o->id]));
    }
}
