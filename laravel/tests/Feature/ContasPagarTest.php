<?php

namespace Tests\Feature;

use App\Support\Contas;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Contas a pagar, recorrentes e previsão de caixa: regras de conta_*(), contas_gerar_recorrentes() e previsao_caixa() do sistema antigo. */
class ContasPagarTest extends TestCase
{
    private const DESCRICAO = 'TESTE conta';

    /** Conta avulsa em aberto, criada pela tela (vence daqui a 10 dias). */
    private function contaAberta(string $descricao = self::DESCRICAO, string $valor = '123,45'): object
    {
        $this->post('/caixa/contas', ['descricao' => $descricao, 'categoria' => 'TESTE', 'valor' => $valor,
                                      'vencimento' => now()->addDays(10)->toDateString()])
            ->assertSessionHasNoErrors();
        return DB::selectOne('SELECT * FROM contas_pagar WHERE descricao = ?', [$descricao]);
    }

    public function test_conta_avulsa_valida_e_recusa_dados_ruins(): void
    {
        $this->entrarComo();
        $antes = (int) DB::selectOne('SELECT count(*) AS n FROM contas_pagar')->n;
        $this->post('/caixa/contas', ['descricao' => 'ab', 'categoria' => '', 'valor' => '0', 'vencimento' => ''])
            ->assertSessionHasErrors(['descricao', 'categoria', 'valor', 'vencimento'], null, 'conta');
        $this->assertSame($antes, (int) DB::selectOne('SELECT count(*) AS n FROM contas_pagar')->n, 'recusa não pode gravar conta');

        $c = $this->contaAberta();
        $this->assertSame(['123.45', null, 'TESTE'], [$c->valor, $c->pago_em, $c->categoria]);
    }

    public function test_pagar_lanca_a_saida_no_caixa_e_nao_paga_duas_vezes(): void
    {
        $this->entrarComo();
        $c = $this->contaAberta();
        $hoje = now()->toDateString();

        $this->post("/caixa/contas/{$c->id}/pagar", ['data' => $hoje, 'forma' => 'Bitcoin'])
            ->assertSessionHas('flash', [['tipo' => 'erro', 'msg' => 'Escolha a forma de pagamento.']]);
        $this->assertNull(DB::selectOne('SELECT caixa_id FROM contas_pagar WHERE id = ?', [$c->id])->caixa_id);

        $this->post("/caixa/contas/{$c->id}/pagar", ['data' => $hoje, 'forma' => 'PIX'])->assertRedirect(route('caixa.contas'));
        $p = DB::selectOne('SELECT pago_em, caixa_id FROM contas_pagar WHERE id = ?', [$c->id]);
        $this->assertSame($hoje, $p->pago_em);
        $k = DB::selectOne('SELECT * FROM caixa WHERE id = ?', [$p->caixa_id]);
        $this->assertSame(['S', 'TESTE', self::DESCRICAO, '123.45', 'PIX', $hoje], [$k->tipo, $k->categoria, $k->descricao, $k->valor, $k->forma, $k->data]);

        $this->post("/caixa/contas/{$c->id}/pagar", ['data' => $hoje, 'forma' => 'PIX'])
            ->assertSessionHas('flash', [['tipo' => 'erro', 'msg' => 'Esta conta já foi paga.']]);
        $this->assertSame(1, (int) DB::selectOne('SELECT count(*) AS n FROM caixa WHERE descricao = ?', [self::DESCRICAO])->n);
    }

    public function test_pagar_e_do_operador_desfazer_e_excluir_so_do_admin(): void
    {
        $this->entrarComo();
        DB::insert("INSERT INTO usuarios (nome, login, senha_hash, perfil) VALUES ('Operador Teste', 'oper.contas', ?, 'operador')",
                   [password_hash('oper12345', PASSWORD_DEFAULT)]);
        $c = $this->contaAberta();
        $hoje = now()->toDateString();
        $this->post('/sair');

        $this->entrarComo('alfa-locacoes', 'oper.contas', 'oper12345');
        $this->post("/caixa/contas/{$c->id}/pagar", ['data' => $hoje, 'forma' => 'PIX'])->assertRedirect(route('caixa.contas'));
        $this->post("/caixa/contas/{$c->id}/desfazer")->assertForbidden();
        $this->delete("/caixa/contas/{$c->id}")->assertForbidden();
        $this->post('/caixa/recorrentes', ['descricao' => 'TESTE recorrente', 'categoria' => 'TESTE', 'valor' => '1', 'dia' => '5', 'inicio' => $hoje, 'ativa' => '1'])
            ->assertForbidden();
        $this->assertNotNull(DB::selectOne('SELECT caixa_id FROM contas_pagar WHERE id = ?', [$c->id])->caixa_id);
        $this->post('/sair');

        $this->entrarComo();
        $this->post("/caixa/contas/{$c->id}/desfazer")->assertRedirect(route('caixa.contas', ['situacao' => 'todas']));
        $d = DB::selectOne('SELECT pago_em, caixa_id FROM contas_pagar WHERE id = ?', [$c->id]);
        $this->assertSame([null, null], [$d->pago_em, $d->caixa_id], 'desfazer volta a conta para aberta');
        $this->assertNull(DB::selectOne('SELECT id FROM caixa WHERE descricao = ?', [self::DESCRICAO]), 'desfazer apaga a saída do caixa');

        $this->delete("/caixa/contas/{$c->id}")->assertRedirect(route('caixa.contas'));
        $this->assertNull(DB::selectOne('SELECT id FROM contas_pagar WHERE id = ?', [$c->id]));

        $paga = $this->contaAberta('TESTE conta paga');
        $this->post("/caixa/contas/{$paga->id}/pagar", ['data' => $hoje, 'forma' => 'PIX']);
        $this->delete("/caixa/contas/{$paga->id}")->assertRedirect();
        $this->assertSame('Só é possível excluir conta em aberto.', session('flash')[0]['msg']);
        $this->assertNotNull(DB::selectOne('SELECT id FROM contas_pagar WHERE id = ? AND pago_em IS NOT NULL', [$paga->id]));
    }

    public function test_recorrente_gera_parcelas_editar_refaz_e_desativar_remove_as_futuras(): void
    {
        $this->entrarComo();
        $hoje = now()->toDateString();
        $this->post('/caixa/recorrentes', ['descricao' => 'TESTE aluguel', 'categoria' => 'TESTE', 'valor' => '500', 'dia' => '29', 'inicio' => $hoje, 'ativa' => '1'])
            ->assertSessionHasErrors(['dia'], null, 'recorrente');

        $this->post('/caixa/recorrentes', ['descricao' => 'TESTE aluguel', 'categoria' => 'TESTE', 'valor' => '500', 'dia' => '10', 'inicio' => $hoje, 'ativa' => '1'])
            ->assertSessionHasNoErrors();
        $r = DB::selectOne("SELECT * FROM contas_recorrentes WHERE descricao = 'TESTE aluguel'");
        $parcelas = fn () => DB::select('SELECT vencimento, valor FROM contas_pagar WHERE recorrente_id = ? AND pago_em IS NULL ORDER BY vencimento', [$r->id]);

        $geradas = $parcelas();
        $this->assertNotEmpty($geradas, 'a recorrente gera parcelas nos próximos 90 dias');
        $this->assertSame(['10'], array_values(array_unique(array_map(fn ($x) => substr($x->vencimento, 8, 2), $geradas))), 'todas vencem no dia 10');
        $this->assertLessThanOrEqual(now()->addDays(Contas::HORIZONTE_DIAS)->toDateString(), max(array_column($geradas, 'vencimento')));

        // Mudar o valor atualiza só as parcelas futuras em aberto
        $this->put("/caixa/recorrentes/{$r->id}", ['descricao' => 'TESTE aluguel', 'categoria' => 'TESTE', 'valor' => '600', 'dia' => '10',
                                                  'inicio' => $r->inicio, 'ativa' => '1'])
            ->assertSessionHasNoErrors();
        $this->assertSame(['600.00'], array_values(array_unique(array_map(fn ($x) => $x->valor, $parcelas()))));

        // Desativar remove as futuras em aberto, e a tela de contas não gera de novo
        $this->put("/caixa/recorrentes/{$r->id}", ['descricao' => 'TESTE aluguel', 'categoria' => 'TESTE', 'valor' => '600', 'dia' => '10',
                                                  'inicio' => $r->inicio])
            ->assertSessionHasNoErrors();
        $this->get('/caixa/contas')->assertOk();
        $this->assertSame([], $parcelas());
        $this->assertFalse(DB::selectOne('SELECT ativa FROM contas_recorrentes WHERE id = ?', [$r->id])->ativa);
    }

    public function test_previsao_mostra_o_saldo_de_hoje_e_as_contas_em_aberto(): void
    {
        $this->entrarComo();
        $this->contaAberta('TESTE previsao', '250,00');
        $saldo = (float) DB::selectOne("SELECT COALESCE(SUM(CASE WHEN tipo = 'E' THEN valor ELSE -valor END), 0) AS v
                                          FROM caixa WHERE data <= CURRENT_DATE")->v;

        $this->get('/caixa/previsao?dias=30')->assertOk()
            ->assertSee(moeda($saldo))
            ->assertSee('TESTE previsao')
            ->assertSee(moeda(250));
        $this->get('/caixa/previsao?dias=lixo')->assertOk()->assertSee('Saldo em 60 dias');
    }

    public function test_beta_nao_ve_nem_paga_conta_da_alfa(): void
    {
        $this->entrarComo();
        $c = $this->contaAberta('TESTE conta alfa');
        $this->post('/sair');

        $this->entrarComo('beta-eventos');
        $this->get('/caixa/contas?situacao=todas')->assertOk()->assertDontSee('TESTE conta alfa');
        $this->post("/caixa/contas/{$c->id}/pagar", ['data' => now()->toDateString(), 'forma' => 'PIX'])
            ->assertSessionHas('flash', [['tipo' => 'erro', 'msg' => 'Conta não encontrada.']]);
        $this->delete("/caixa/contas/{$c->id}")
            ->assertSessionHas('flash', [['tipo' => 'erro', 'msg' => 'Só é possível excluir conta em aberto.']]);
        $this->post('/sair');

        $this->entrarComo();
        $this->assertNull(DB::selectOne('SELECT pago_em FROM contas_pagar WHERE id = ? AND pago_em IS NOT NULL', [$c->id]));
        $this->assertNotNull(DB::selectOne('SELECT id FROM contas_pagar WHERE id = ?', [$c->id]), 'a conta da Alfa continua intacta');
    }
}
