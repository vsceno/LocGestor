<?php

namespace Tests\Feature\Plataforma;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\PlataformaTestCase;

/**
 * Cobrança automática das mensalidades pelo Asaas. A API é simulada com Http::fake(): nenhuma chamada sai da máquina.
 * Mesmas regras de app/assinatura.php, app/asaas.php, webhook_asaas.php e tarefas/diaria.php do sistema antigo.
 */
class CobrancaAsaasTest extends PlataformaTestCase
{
    /** CNPJ com dígitos verificadores certos (a cobrança exige o documento). */
    private const CNPJ = '11222333000181';

    protected function setUp(): void
    {
        parent::setUp();
        config(['locgestor.asaas' => [
            'ambiente' => 'sandbox', 'url' => 'https://asaas.teste/v3', 'chave' => 'chave-de-teste',
            'webhook_token' => 'token-de-teste', 'dias_antes' => 10,
        ]]);
    }

    /** Respostas do Asaas simulado. O coringa no fim impede chamadas de rede reais por outras locadoras da base. */
    private function asaas(array $extra = []): void
    {
        Http::fake(array_merge([
            '*/v3/customers*' => Http::response(['id' => 'cus_TESTE1', 'data' => []]),
            '*/v3/payments' => Http::response(['id' => 'pay_TESTE1', 'status' => 'PENDING', 'value' => 99,
                                               'invoiceUrl' => 'https://asaas.teste/fatura/pay_TESTE1']),
        ], $extra, ['*' => Http::response(['data' => []])]));
    }

    /** Cobrança reservada/criada pela rotina, como fica no banco. */
    private function cobranca(string $locadoraId, string $vencimento, ?string $gatewayId, string $status = 'pendente'): string
    {
        return DB::connection('plataforma')->selectOne(
            "INSERT INTO assinatura_cobrancas (locadora_id, vencimento, valor, status, gateway_id, link)
             VALUES (?, ?, 99, ?, ?, ?) RETURNING id",
            [$locadoraId, $vencimento, $status, $gatewayId, $gatewayId ? 'https://asaas.teste/fatura/' . $gatewayId : '']
        )->id;
    }

    private function cobrancaNoBanco(string $id): object
    {
        return DB::connection('plataforma')->selectOne('SELECT status, gateway_id, pagamento_id FROM assinatura_cobrancas WHERE id = ?', [$id]);
    }

    public function test_gera_a_cobranca_no_asaas_e_guarda_o_id_e_o_link(): void
    {
        $this->entrarNaPlataforma();
        $this->asaas();
        $id = $this->criarLocadora(['cnpj' => self::CNPJ]);

        $this->post(route('plataforma.locadoras.cobranca.gerar', $id))
            ->assertSessionHas('flash', fn ($f) => str_contains($f[0]['msg'], 'disponível no Asaas'));

        $c = DB::connection('plataforma')->selectOne('SELECT status, gateway_id, link FROM assinatura_cobrancas WHERE locadora_id = ?', [$id]);
        $this->assertSame(['pendente', 'pay_TESTE1', 'https://asaas.teste/fatura/pay_TESTE1'], [$c->status, $c->gateway_id, $c->link]);
        $this->assertSame('cus_TESTE1', $this->locadoraNoBanco($id)->asaas_cliente_id);

        // A cobrança sai com o id da nossa cobrança como referência e a forma de pagamento em aberto (o cliente escolhe)
        Http::assertSent(fn ($req) => $req->method() === 'POST' && str_ends_with($req->url(), '/payments')
            && $req['billingType'] === 'UNDEFINED' && $req['value'] == 99);
    }

    public function test_pagamento_recebido_no_asaas_da_baixa_na_mensalidade(): void
    {
        $this->entrarNaPlataforma();
        $this->asaas(['*/v3/payments/pay_PAGO1' => Http::response([
            'id' => 'pay_PAGO1', 'status' => 'RECEIVED', 'value' => 99, 'billingType' => 'PIX', 'paymentDate' => '2026-05-09',
        ])]);
        $id = $this->criarLocadora(['cnpj' => self::CNPJ, 'vencimento' => '2026-05-10']);
        $cobranca = $this->cobranca($id, '2026-05-10', 'pay_PAGO1');

        $this->post(route('plataforma.locadoras.cobranca.sincronizar', $id))
            ->assertSessionHas('flash', fn ($f) => str_contains($f[0]['msg'], '1 pagamento(s) recebido(s)'));

        $this->assertSame('paga', $this->cobrancaNoBanco($cobranca)->status);
        $this->assertSame('2026-06-10', $this->locadoraNoBanco($id)->vencimento);
        $pagamento = DB::connection('plataforma')->selectOne('SELECT data, forma, valor, obs FROM assinatura_pagamentos WHERE locadora_id = ?', [$id]);
        $this->assertSame(['2026-05-09', 'PIX', 99.0, 'Asaas pay_PAGO1'], [$pagamento->data, $pagamento->forma, (float) $pagamento->valor, $pagamento->obs]);
    }

    public function test_rotina_diaria_gera_a_cobranca_dos_vencimentos_proximos(): void
    {
        $this->asaas();
        $id = $this->criarLocadora(['cnpj' => self::CNPJ, 'vencimento' => now()->addDays(5)->toDateString()]);
        DB::connection('plataforma')->update('UPDATE tenants SET cobranca_automatica = true WHERE id = ?', [$id]);

        // A rotina confere todas as locadoras da base; aqui só interessa a desta teste
        $this->artisan('locgestor:rotina-diaria');

        $c = DB::connection('plataforma')->selectOne("SELECT status, gateway_id FROM assinatura_cobrancas WHERE locadora_id = ?", [$id]);
        $this->assertSame(['pendente', 'pay_TESTE1'], [$c->status, $c->gateway_id]);
    }

    public function test_rotina_diaria_suspende_quem_passou_da_carencia(): void
    {
        $this->asaas();
        $id = $this->criarLocadora(['vencimento' => '2026-01-10', 'carencia' => 5]);

        $this->artisan('locgestor:rotina-diaria');

        $this->assertSame(['suspensa', 'inadimplencia'], [$this->locadoraNoBanco($id)->status, $this->locadoraNoBanco($id)->motivo_suspensao]);
    }

    public function test_webhook_recusa_token_errado_e_confere_a_cobranca_na_api(): void
    {
        $this->asaas(['*/v3/payments/pay_WH1' => Http::response([
            'id' => 'pay_WH1', 'status' => 'RECEIVED', 'value' => 99, 'billingType' => 'PIX', 'paymentDate' => '2026-05-09',
        ])]);
        $id = $this->criarLocadora(['cnpj' => self::CNPJ, 'vencimento' => '2026-05-10']);
        $cobranca = $this->cobranca($id, '2026-05-10', 'pay_WH1');
        $aviso = ['id' => 'evt_TESTE_1', 'event' => 'PAYMENT_RECEIVED', 'payment' => ['id' => 'pay_WH1']];

        // Sem token, ou com outro: nada é lido nem gravado
        $this->postJson(route('webhook.asaas'), $aviso)->assertStatus(401);
        $this->withHeaders(['asaas-access-token' => 'outro'])->postJson(route('webhook.asaas'), $aviso)->assertStatus(401);
        $this->assertSame('pendente', $this->cobrancaNoBanco($cobranca)->status);

        // Com o token certo, a situação vem da API (o aviso só diz qual cobrança mudou)
        $this->withHeaders(['asaas-access-token' => 'token-de-teste'])->postJson(route('webhook.asaas'), $aviso)
            ->assertOk()->assertJson(['ok' => true, 'msg' => 'Cobrança paga.']);
        $this->assertSame('paga', $this->cobrancaNoBanco($cobranca)->status);

        // O mesmo aviso de novo é ignorado
        $this->withHeaders(['asaas-access-token' => 'token-de-teste'])->postJson(route('webhook.asaas'), $aviso)
            ->assertOk()->assertJson(['msg' => 'Aviso já processado.']);
    }

    public function test_webhook_de_cobranca_que_nao_e_do_locgestor_so_responde(): void
    {
        $this->asaas();
        $this->withHeaders(['asaas-access-token' => 'token-de-teste'])
            ->postJson(route('webhook.asaas'), ['id' => 'evt_TESTE_2', 'event' => 'PAYMENT_CREATED', 'payment' => ['id' => 'pay_ESTRANHA']])
            ->assertOk()->assertJson(['msg' => 'Cobrança desconhecida.']);
    }

    public function test_cancelar_o_contrato_cancela_as_cobrancas_no_asaas(): void
    {
        $this->entrarNaPlataforma();
        // Ainda pendente lá: o cancelamento consulta e depois apaga a cobrança no Asaas
        $this->asaas(['*/v3/payments/pay_CANC1' => Http::response(['id' => 'pay_CANC1', 'status' => 'PENDING', 'value' => 99])]);
        $id = $this->criarLocadora(['cnpj' => self::CNPJ]);
        $cobranca = $this->cobranca($id, now()->addDays(20)->toDateString(), 'pay_CANC1');

        $this->post(route('plataforma.locadoras.cancelar', $id))
            ->assertSessionHas('flash', fn ($f) => str_contains($f[0]['msg'], 'cancelado'));

        $this->assertSame('cancelada', $this->cobrancaNoBanco($cobranca)->status);
        $t = $this->locadoraNoBanco($id);
        $this->assertSame(['cancelada', null], [$t->status, $t->vencimento]);
        Http::assertSent(fn ($req) => $req->method() === 'DELETE' && str_ends_with($req->url(), '/payments/pay_CANC1'));
    }

    public function test_a_tela_da_locadora_lista_as_cobrancas_e_permite_cancelar_as_pendentes(): void
    {
        $this->entrarNaPlataforma();
        $this->asaas();
        $id = $this->criarLocadora(['cnpj' => self::CNPJ]);
        $this->cobranca($id, now()->addDays(3)->toDateString(), 'pay_TELA1');

        $this->get(route('plataforma.locadoras.editar', $id))->assertOk()
            ->assertSee('Cobrança automática (Asaas)')->assertSee('Gerar cobrança do vencimento')
            ->assertSee('pay_TELA1')->assertSee('pendente')->assertSee('>Cancelar<', false);
    }

    public function test_ligar_a_cobranca_automatica_exige_cnpj_e_plano_pago(): void
    {
        $this->entrarNaPlataforma();
        $id = $this->criarLocadora();   // sem CNPJ

        $this->post(route('plataforma.locadoras.cobranca.config', $id), ['cobranca_automatica' => '1'])
            ->assertSessionHas('flash', fn ($f) => str_contains($f[0]['msg'], 'CNPJ, próximo vencimento e um plano pago'));
        $this->assertFalse($this->locadoraNoBanco($id)->cobranca_automatica);
    }

    public function test_sem_chave_do_asaas_a_tela_avisa_e_a_rotina_so_suspende(): void
    {
        config(['locgestor.asaas.chave' => '']);
        $this->entrarNaPlataforma();
        $id = $this->criarLocadora(['vencimento' => '2026-01-10', 'carencia' => 5]);

        $this->get(route('plataforma.locadoras.editar', $id))->assertOk()->assertSee('Asaas não configurado');
        $this->artisan('locgestor:rotina-diaria');
        $this->assertSame('suspensa', $this->locadoraNoBanco($id)->status);
        Http::assertNothingSent();
    }

    /** Conferência pela rotina, sem webhook: a cobrança paga no Asaas dá baixa e o vencimento avança um mês. */
    public function test_rotina_confere_a_cobranca_paga_sem_webhook_e_avanca_o_vencimento(): void
    {
        $vencimento = now()->addDays(5)->toDateString();
        $this->asaas(['*/v3/payments/pay_CARTAO1' => Http::response([
            'id' => 'pay_CARTAO1', 'status' => 'RECEIVED', 'value' => 99, 'billingType' => 'CREDIT_CARD', 'paymentDate' => now()->toDateString(),
        ])]);
        $id = $this->criarLocadora(['cnpj' => self::CNPJ, 'vencimento' => $vencimento]);
        DB::connection('plataforma')->update('UPDATE tenants SET cobranca_automatica = true WHERE id = ?', [$id]);
        $cobranca = $this->cobranca($id, $vencimento, 'pay_CARTAO1');

        $this->artisan('locgestor:rotina-diaria')->assertSuccessful();

        $this->assertSame('paga', $this->cobrancaNoBanco($cobranca)->status);
        $pagamento = DB::connection('plataforma')->selectOne('SELECT forma, vencimento_novo FROM assinatura_pagamentos WHERE locadora_id = ?', [$id]);
        $this->assertSame(['Cartão de crédito', now()->addDays(5)->addMonth()->toDateString()], [$pagamento->forma, $pagamento->vencimento_novo]);
        $this->assertSame(1, (int) DB::connection('plataforma')->selectOne('SELECT count(*) AS n FROM assinatura_cobrancas WHERE locadora_id = ?', [$id])->n,
                          'nenhuma cobrança nova: o vencimento novo está longe');
    }

    /** Reserva de uma rotina que caiu antes do id do Asaas: a rotina seguinte acha a cobrança pela referência e não cobra de novo. */
    public function test_rotina_recupera_a_cobranca_interrompida_pela_referencia_sem_cobrar_de_novo(): void
    {
        $vencimento = now()->addDays(5)->toDateString();
        $id = $this->criarLocadora(['cnpj' => self::CNPJ, 'vencimento' => $vencimento]);
        DB::connection('plataforma')->update('UPDATE tenants SET cobranca_automatica = true WHERE id = ?', [$id]);
        $reserva = $this->cobranca($id, $vencimento, null);
        DB::connection('plataforma')->update("UPDATE assinatura_cobrancas SET criado_em = now() - INTERVAL '5 minutes' WHERE id = ?", [$reserva]);
        $this->asaas([
            '*/v3/payments?externalReference=*' => Http::response(['data' => [
                ['id' => 'pay_RECUP1', 'status' => 'PENDING', 'value' => 99, 'deleted' => false, 'externalReference' => $reserva],
            ]]),
            '*/v3/payments/pay_RECUP1' => Http::response(['id' => 'pay_RECUP1', 'status' => 'PENDING', 'value' => 99]),
        ]);

        $this->artisan('locgestor:rotina-diaria')
            ->expectsOutputToContain('1 cobrança(s) interrompida(s) conferida(s).')
            ->assertSuccessful();

        $this->assertSame('pay_RECUP1', $this->cobrancaNoBanco($reserva)->gateway_id);
        Http::assertNotSent(fn ($req) => $req->method() === 'POST' && str_contains($req->url(), '/payments'));
    }

    /** Uma locadora com erro no Asaas não para a rotina: a outra recebe a cobrança e o comando termina com falha. */
    public function test_falha_do_asaas_numa_locadora_nao_para_as_outras(): void
    {
        DB::connection('plataforma')->update('UPDATE tenants SET cobranca_automatica = false');
        $this->asaas(['*/v3/payments' => Http::sequence()
            ->pushStatus(500)
            ->push(['id' => 'pay_SEGUNDA1', 'status' => 'PENDING', 'value' => 99, 'invoiceUrl' => 'https://asaas.teste/fatura/pay_SEGUNDA1'])]);
        $primeira = $this->criarLocadora(['cnpj' => self::CNPJ, 'vencimento' => now()->addDays(2)->toDateString()]);
        $segunda = $this->criarLocadora(['cnpj' => self::CNPJ, 'vencimento' => now()->addDays(4)->toDateString()]);
        DB::connection('plataforma')->update('UPDATE tenants SET cobranca_automatica = true WHERE id IN (?, ?)', [$primeira, $segunda]);

        $this->artisan('locgestor:rotina-diaria')->expectsOutputToContain('ERRO')->assertFailed();

        $reserva = DB::connection('plataforma')->selectOne('SELECT status, gateway_id FROM assinatura_cobrancas WHERE locadora_id = ?', [$primeira]);
        $this->assertSame(['pendente', null], [$reserva->status, $reserva->gateway_id], 'a reserva da que falhou fica para a próxima rotina');
        $this->assertSame('pay_SEGUNDA1', DB::connection('plataforma')->selectOne('SELECT gateway_id FROM assinatura_cobrancas WHERE locadora_id = ?', [$segunda])->gateway_id);
    }

    /** Recusa do Asaas (400): o motivo aparece no relatório, a chave não, e a rotina seguinte gera a cobrança. */
    public function test_recusa_do_asaas_libera_o_vencimento_e_a_proxima_rotina_gera(): void
    {
        DB::connection('plataforma')->update('UPDATE tenants SET cobranca_automatica = false');
        $this->asaas(['*/v3/payments' => Http::sequence()
            ->push(['errors' => [['description' => 'O valor da cobrança é inválido']]], 400)
            ->push(['id' => 'pay_RETRY1', 'status' => 'PENDING', 'value' => 99, 'invoiceUrl' => 'https://asaas.teste/fatura/pay_RETRY1'])]);
        $id = $this->criarLocadora(['cnpj' => self::CNPJ, 'vencimento' => now()->addDays(5)->toDateString()]);
        DB::connection('plataforma')->update('UPDATE tenants SET cobranca_automatica = true WHERE id = ?', [$id]);

        $this->artisan('locgestor:rotina-diaria')
            ->expectsOutputToContain('O valor da cobrança é inválido')
            ->doesntExpectOutputToContain('chave-de-teste')
            ->assertFailed();
        $this->assertSame(0, (int) DB::connection('plataforma')->selectOne('SELECT count(*) AS n FROM assinatura_cobrancas WHERE locadora_id = ?', [$id])->n,
                          'a reserva da recusa é desfeita: o vencimento fica livre');

        $this->artisan('locgestor:rotina-diaria')->assertSuccessful();
        $this->assertSame('pay_RETRY1', DB::connection('plataforma')->selectOne('SELECT gateway_id FROM assinatura_cobrancas WHERE locadora_id = ?', [$id])->gateway_id);
    }
}
