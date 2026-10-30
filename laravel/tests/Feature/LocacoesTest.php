<?php

namespace Tests\Feature;

use App\Support\Pix;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Locações: lista, ficha e ações com as regras do sistema antigo (recebimento_registrar, locacao_prorrogar, locacao_cancelar...). */
class LocacoesTest extends TestCase
{
    private function aberta(bool $comPagamento = false): object
    {
        $cond = $comPagamento ? 'AND v.valor_pago > 0' : '';
        return DB::selectOne("SELECT l.*, v.valor_previsto, v.valor_atual, v.valor_pago FROM locacoes l JOIN vw_locacao_valores v ON v.locacao_id = l.id
                               WHERE l.status = 'aberta' AND NOT EXISTS (SELECT 1 FROM devolucoes d WHERE d.locacao_id = l.id) $cond
                               ORDER BY l.numero LIMIT 1");
    }

    public function test_lista_e_ficha_mostram_os_valores_da_view_do_banco(): void
    {
        $this->entrarComo();
        $l = $this->aberta(true);
        $this->post('/locacoes/filtrar', ['aba' => 'abertas', 'q' => (string) $l->numero], ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->assertSee(moeda($l->valor_atual))->assertSee(moeda($l->valor_pago));
        $this->get("/locacoes/{$l->numero}")->assertOk()
            ->assertSee('Locação nº ' . $l->numero)
            ->assertSee(moeda($l->valor_previsto))->assertSee(moeda($l->valor_atual))->assertSee(moeda($l->valor_atual - $l->valor_pago));
        $this->get('/locacoes/999999')->assertNotFound();
    }

    public function test_pix_e_igual_ao_do_sistema_antigo(): void
    {
        require_once base_path('../app/pix.php');
        foreach ([['11444777000161', 'Alfa Locações Ltda', 'Fortaleza', 3457.0, 'LOC141'], ['email@exemplo.com', 'José Ção', 'São Paulo', null, '***'],
                  ['+5585999990000', str_repeat('Nome Muito Comprido ', 3), 'Cidade', 0.5, 'LOC-1/2']] as $c) {
            $this->assertSame(pix_payload(...$c), Pix::payload(...$c));
        }
    }

    public function test_receber_lanca_no_caixa_e_recusa_valor_e_data_invalidos(): void
    {
        $this->entrarComo();
        $l = $this->aberta();
        $this->post("/locacoes/{$l->numero}/receber", ['valor' => '0', 'forma' => 'PIX', 'data' => now()->toDateString()])->assertSessionHasErrors('receber');
        $this->post("/locacoes/{$l->numero}/receber", ['valor' => '10', 'forma' => 'PIX', 'data' => now()->addDay()->toDateString()])->assertSessionHasErrors('receber');
        $this->post("/locacoes/{$l->numero}/receber", ['valor' => '10', 'forma' => 'Bitcoin', 'data' => now()->toDateString()])->assertSessionHasErrors('receber');

        $this->post("/locacoes/{$l->numero}/receber", ['valor' => '1.234,56', 'forma' => 'Dinheiro', 'data' => now()->toDateString()])
            ->assertRedirect(route('locacoes.show', $l->numero));
        $c = DB::selectOne("SELECT * FROM caixa WHERE locacao_id = ? AND valor = 1234.56", [$l->id]);
        $this->assertSame('E', $c->tipo);
        $this->assertSame('Dinheiro', $c->forma);
        $this->assertSame(session('usuario.id'), $c->usuario_id);
        $this->assertStringStartsWith("Recebimento locação nº {$l->numero} - ", $c->descricao);
        $this->assertEqualsWithDelta($l->valor_pago + 1234.56, (float) DB::selectOne('SELECT valor_pago FROM vw_locacao_valores WHERE locacao_id = ?', [$l->id])->valor_pago, 0.001);
    }

    public function test_prorrogar_grava_historico_e_respeita_as_regras(): void
    {
        $this->entrarComo();
        $l = $this->aberta();
        $this->post("/locacoes/{$l->numero}/prorrogar", ['data' => $l->data_prev_devolucao])->assertSessionHasErrors(['prorrogar' => 'A nova data é igual à atual.']);
        $antes = date('Y-m-d', strtotime($l->data_saida . ' -1 day'));
        $this->post("/locacoes/{$l->numero}/prorrogar", ['data' => $antes])->assertSessionHasErrors(['prorrogar' => 'A nova data não pode ser antes da saída.']);

        $nova = date('Y-m-d', strtotime(max($l->data_prev_devolucao, now()->toDateString()) . ' +3 days'));
        $this->post("/locacoes/{$l->numero}/prorrogar", ['data' => $nova, 'motivo' => 'pedido do cliente'])->assertRedirect(route('locacoes.show', $l->numero));
        $depois = DB::selectOne('SELECT data_prev_devolucao, obs FROM locacoes WHERE id = ?', [$l->id]);
        $this->assertSame($nova, $depois->data_prev_devolucao);
        $this->assertStringContainsString('Prorrogada de ' . data_br($l->data_prev_devolucao) . ' para ' . data_br($nova) . '. Motivo: pedido do cliente', $depois->obs);
    }

    public function test_prorrogar_nao_invade_reserva_sem_estoque(): void
    {
        $this->entrarComo();
        $l = $this->aberta();
        $item = DB::selectOne('SELECT li.produto_id, li.quantidade - li.qtd_devolvida AS pend FROM locacao_itens li WHERE li.locacao_id = ? AND li.quantidade > li.qtd_devolvida LIMIT 1', [$l->id]);
        // Uma reserva que ocupa todo o estoque livre do produto logo depois da devolução prevista
        $inicio = date('Y-m-d', strtotime(max($l->data_prev_devolucao, now()->toDateString()) . ' +5 days'));
        $cliente = DB::selectOne('SELECT id FROM clientes LIMIT 1')->id;
        $r = DB::selectOne("INSERT INTO reservas (numero, cliente_id, data_inicio, data_fim, status) VALUES (proximo_numero('reserva'), ?, ?, ?, 'ativa') RETURNING id",
                           [$cliente, $inicio, date('Y-m-d', strtotime($inicio . ' +2 days'))]);
        $livre = \App\Support\Estoque::disponivel($item->produto_id, $inicio, $inicio, null, $l->id) - $item->pend;
        DB::insert('INSERT INTO reserva_itens (reserva_id, produto_id, quantidade) VALUES (?, ?, ?)', [$r->id, $item->produto_id, $livre + 1]);

        $this->post("/locacoes/{$l->numero}/prorrogar", ['data' => date('Y-m-d', strtotime($inicio . ' +1 day'))])
            ->assertSessionHasErrors('prorrogar');
        $this->assertStringContainsString('por causa de reservas no período', session('errors')->first('prorrogar'));
        $this->assertSame($l->data_prev_devolucao, DB::selectOne('SELECT data_prev_devolucao FROM locacoes WHERE id = ?', [$l->id])->data_prev_devolucao);
    }

    public function test_cancelar_estorna_o_pago_e_libera_as_unidades(): void
    {
        $this->entrarComo();
        $l = $this->aberta(true);
        $this->post("/locacoes/{$l->numero}/cancelar", ['motivo' => 'desistiu'])->assertSessionHasErrors(['cancelar' => 'Escolha a forma de pagamento.']);
        $this->assertSame('aberta', DB::selectOne('SELECT status FROM locacoes WHERE id = ?', [$l->id])->status);

        $this->post("/locacoes/{$l->numero}/cancelar", ['motivo' => 'desistiu', 'forma' => 'PIX'])->assertRedirect(route('locacoes.show', $l->numero));
        $depois = DB::selectOne('SELECT l.status, l.obs, v.valor_pago, v.valor_atual FROM locacoes l JOIN vw_locacao_valores v ON v.locacao_id = l.id WHERE l.id = ?', [$l->id]);
        $this->assertSame('cancelada', $depois->status);
        $this->assertEquals(0, (float) $depois->valor_pago);
        $this->assertEquals(0, (float) $depois->valor_atual);
        // Mesmo texto do sistema antigo (sem ponto depois do motivo quando há estorno)
        $this->assertStringContainsString('Cancelada. Motivo: desistiu Estorno de ' . moeda($l->valor_pago) . ' (PIX).', $depois->obs);
        $this->assertNotNull(DB::selectOne("SELECT 1 AS ok FROM caixa WHERE locacao_id = ? AND tipo = 'S' AND valor = ?", [$l->id, $l->valor_pago]));
        $this->post("/locacoes/{$l->numero}/receber", ['valor' => '10', 'forma' => 'PIX', 'data' => now()->toDateString()])
            ->assertSessionHasErrors(['receber' => 'Locação cancelada não recebe pagamentos.']);
    }

    public function test_entrega_e_patrimonio_com_desfazer_em_caso_de_erro(): void
    {
        $this->entrarComo();
        $l = DB::selectOne("SELECT l.id, l.numero, li.id AS item, li.produto_id FROM locacoes l JOIN locacao_itens li ON li.locacao_id = l.id
                             WHERE l.status = 'aberta' AND li.quantidade - li.qtd_devolvida = 1
                               AND (SELECT count(*) FROM unidades u WHERE u.produto_id = li.produto_id AND u.ativa) >= 2 LIMIT 1");
        $this->post("/locacoes/{$l->numero}/entrega", ['frete_entrega' => '-5'])->assertSessionHasErrors('entrega');
        $this->post("/locacoes/{$l->numero}/entrega", ['frete_entrega' => '35,50', 'frete_recolhimento' => '20'])->assertRedirect(route('locacoes.show', $l->numero));
        $e = DB::selectOne('SELECT frete_entrega, frete_recolhimento, obs FROM locacoes WHERE id = ?', [$l->id]);
        $this->assertSame(['35.50', '20.00'], [$e->frete_entrega, $e->frete_recolhimento]);
        $this->assertStringContainsString('Entrega: frete de entrega', $e->obs);

        $livres = array_column(DB::select('SELECT u.id FROM unidades u WHERE u.produto_id = ? AND u.ativa
                                             AND NOT EXISTS (SELECT 1 FROM locacao_unidades lu WHERE lu.unidade_id = u.id AND lu.devolucao_id IS NULL)
                                             AND NOT EXISTS (SELECT 1 FROM manutencoes m WHERE m.unidade_id = u.id AND m.data_fim IS NULL)
                                           ORDER BY u.patrimonio LIMIT 2', [$l->produto_id]), 'id');
        $conta = fn () => (int) DB::selectOne('SELECT count(*) AS n FROM locacao_unidades WHERE locacao_item_id = ? AND devolucao_id IS NULL', [$l->item])->n;
        $this->post("/locacoes/{$l->numero}/unidades", ['unidades' => [$l->item => [$livres[0]]]])->assertRedirect(route('locacoes.show', $l->numero));
        $this->assertSame(1, $conta());
        // Duas para uma peça na rua: recusa e não apaga a escolha anterior (a transação é desfeita)
        $this->post("/locacoes/{$l->numero}/unidades", ['unidades' => [$l->item => $livres]])->assertSessionHasErrors('patrimonio');
        $this->assertSame(1, $conta());
        $outra = DB::selectOne('SELECT id FROM locacao_itens WHERE locacao_id <> ? LIMIT 1', [$l->id])->id;
        $this->post("/locacoes/{$l->numero}/unidades", ['unidades' => [$outra => []]])->assertSessionHasErrors(['patrimonio' => 'Item inválido.']);
    }

    public function test_beta_nao_ve_nem_mexe_em_locacao_da_alfa(): void
    {
        $l = $this->lerComoAdmin("SELECT l.numero, l.id, l.data_prev_devolucao FROM locacoes l JOIN tenants t ON t.id = l.tenant_id
                                   WHERE t.slug = 'alfa-locacoes' AND l.status = 'aberta'
                                     AND NOT EXISTS (SELECT 1 FROM locacoes b JOIN tenants tb ON tb.id = b.tenant_id WHERE tb.slug = 'beta-eventos' AND b.numero = l.numero)
                                   ORDER BY l.numero DESC LIMIT 1")[0];
        $caixaAntes = $this->lerComoAdmin('SELECT count(*) AS n FROM caixa WHERE locacao_id = ?', [$l->id])[0]->n;
        $this->entrarComo('beta-eventos');
        $this->get("/locacoes/{$l->numero}")->assertNotFound();
        foreach (['receber' => ['valor' => '10', 'forma' => 'PIX'], 'prorrogar' => ['data' => '2030-01-01'], 'cancelar' => ['forma' => 'PIX'],
                  'entrega' => ['frete_entrega' => '1'], 'unidades' => []] as $acao => $dados) {
            $this->post("/locacoes/{$l->numero}/$acao", $dados)->assertNotFound();
        }
        $depois = $this->lerComoAdmin('SELECT status, data_prev_devolucao FROM locacoes WHERE id = ?', [$l->id])[0];
        $this->assertSame(['aberta', $l->data_prev_devolucao], [$depois->status, $depois->data_prev_devolucao]);
        $this->assertSame($caixaAntes, $this->lerComoAdmin('SELECT count(*) AS n FROM caixa WHERE locacao_id = ?', [$l->id])[0]->n);
    }
}
