<?php

namespace Tests\Feature\Plataforma;

use App\Support\Assinaturas;
use App\Support\Recibos;
use Illuminate\Support\Facades\DB;
use Tests\PlataformaTestCase;

/** Recibos e estornos da mensalidade: numeração sem buracos, estorno sem apagar, acesso restrito e dados do emitente. */
class ReciboTest extends PlataformaTestCase
{
    private const EMITENTE = [
        'razao_social' => 'LocGestor Sistemas Ltda', 'cnpj' => '11444777000161',
        'endereco' => 'Rua Exemplo, 100 - Fortaleza/CE', 'contato' => 'contato@exemplo.com',
    ];

    private function pagar(string $locadoraId, float $valor = 99.0): string
    {
        return Assinaturas::registrarPagamento($locadoraId, 1, now()->toDateString(), $valor, 'PIX', '', null)['pagamento_id'];
    }

    private function reciboDo(string $pagamentoId): ?int
    {
        return DB::connection('plataforma')->selectOne('SELECT recibo_numero FROM assinatura_pagamentos WHERE id = ?', [$pagamentoId])->recibo_numero;
    }

    public function test_valor_por_extenso_segue_a_escrita_de_recibo(): void
    {
        foreach ([
            [99.0, 'noventa e nove reais'],
            [1.0, 'um real'],
            [0.5, 'zero reais e cinquenta centavos'],
            [0.01, 'zero reais e um centavo'],
            [100.0, 'cem reais'],
            [299.9, 'duzentos e noventa e nove reais e noventa centavos'],
            [1000.0, 'mil reais'],
            [1050.0, 'mil e cinquenta reais'],
            [1250.3, 'mil duzentos e cinquenta reais e trinta centavos'],
            [2000000.0, 'dois milhões de reais'],
        ] as [$valor, $esperado]) {
            $this->assertSame($esperado, Recibos::valorExtenso($valor));
        }
    }

    public function test_pagamentos_recebem_numeros_de_recibo_em_sequencia(): void
    {
        $id = $this->criarLocadora();

        $primeiro = $this->reciboDo($this->pagar($id));
        $segundo = $this->reciboDo($this->pagar($id));

        $this->assertSame($primeiro + 1, $segundo, 'sem buracos na numeração');
    }

    public function test_estorno_marca_o_pagamento_e_nao_apaga_o_recibo(): void
    {
        $this->entrarNaPlataforma();
        $id = $this->criarLocadora();
        $pagamento = $this->pagar($id);
        $numero = $this->reciboDo($pagamento);

        $this->post(route('plataforma.locadoras.estornar', $id))->assertRedirect();

        $linha = DB::connection('plataforma')->selectOne('SELECT recibo_numero, estornado_em FROM assinatura_pagamentos WHERE id = ?', [$pagamento]);
        $this->assertNotNull($linha, 'o pagamento continua no banco');
        $this->assertNotNull($linha->estornado_em);
        $this->assertSame($numero, (int) $linha->recibo_numero, 'o número do recibo fica registrado');
    }

    public function test_recibo_so_abre_para_a_plataforma_e_so_com_dados_do_emitente(): void
    {
        $id = $this->criarLocadora(['cnpj' => '11222333000181']);
        $pagamento = $this->pagar($id);
        $url = route('plataforma.locadoras.recibo', [$id, $pagamento]);

        $this->get($url)->assertRedirect(route('plataforma.login'));

        $this->entrarNaPlataforma();
        config(['locgestor.emitente' => ['razao_social' => '', 'cnpj' => '', 'endereco' => '', 'contato' => '']]);
        $this->get($url)->assertRedirect(route('plataforma.locadoras.editar', $id))
            ->assertSessionHas('flash', fn ($f) => str_contains($f[0]['msg'], 'LOCGESTOR_EMITENTE_CNPJ'));

        config(['locgestor.emitente' => self::EMITENTE]);
        $this->get($url)->assertOk()
            ->assertSee('Recibo de mensalidade')->assertSee('11.222.333/0001-81')
            ->assertSee('noventa e nove reais')->assertSee('LocGestor Sistemas Ltda')
            ->assertSee('emblema-locgestor-256.png', false);
        $this->assertStringContainsString('no-store', $this->get($url)->headers->get('Cache-Control'));
    }

    public function test_recibo_estornado_aparece_como_sem_validade(): void
    {
        $this->entrarNaPlataforma();
        config(['locgestor.emitente' => self::EMITENTE]);
        $id = $this->criarLocadora(['cnpj' => '11222333000181']);
        $pagamento = $this->pagar($id);
        $this->post(route('plataforma.locadoras.estornar', $id));

        $this->get(route('plataforma.locadoras.recibo', [$id, $pagamento]))->assertOk()
            ->assertSee('ESTORNADO')->assertSee('não tem mais validade');
    }
}
