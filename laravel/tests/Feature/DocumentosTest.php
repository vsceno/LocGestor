<?php

namespace Tests\Feature;

use App\Support\Documentos;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Documentos impressos da locação (contrato, fatura, romaneio) e do orçamento: cálculos e textos do sistema antigo. */
class DocumentosTest extends TestCase
{
    /** Uma locação da Alfa com o status pedido, ou null se a demonstração não tiver. */
    private function locacao(string $status): ?object
    {
        return DB::selectOne("SELECT l.numero, l.id FROM locacoes l JOIN vw_locacao_valores v ON v.locacao_id = l.id
                               WHERE l.status = ? ORDER BY l.numero LIMIT 1", [$status]);
    }

    public function test_contrato_mostra_bens_clausulas_e_o_valor_total_do_banco(): void
    {
        $this->entrarComo();
        $l = $this->locacao('aberta') ?? $this->locacao('devolvida');
        $valor = DB::selectOne('SELECT valor_previsto FROM vw_locacao_valores WHERE locacao_id = ?', [$this->idDaLocacao($l->numero)])->valor_previsto;
        $this->get("/locacoes/{$l->numero}/contrato")->assertOk()
            ->assertSee('Contrato de locação')
            ->assertSee('Bens locados')
            ->assertSee('Condições gerais')
            ->assertSee('CLÁUSULA 4ª - DAS AVARIAS E EXTRAVIOS', false)
            ->assertSee('Valores de reposição dos bens locados', false)
            ->assertSee(moeda($valor), false)
            ->assertSee('print.css', false);
    }

    public function test_fatura_separa_devolvidos_pendentes_e_mostra_multa_e_avaria(): void
    {
        $this->entrarComo();
        $aberta = $this->locacao('aberta');
        if ($aberta) {
            $this->get("/locacoes/{$aberta->numero}/fatura")->assertOk()
                ->assertSee('em aberto (valores até ' . data_br(now()->toDateString()) . ')', false)
                ->assertSee('pendente', false)
                ->assertSee('Saldo a pagar', false);
        }
        // Com multa ou avaria registradas, as linhas aparecem no rodapé
        $com = DB::selectOne('SELECT l.numero, v.valor_atual, v.valor_pago FROM devolucoes d JOIN locacoes l ON l.id = d.locacao_id
                                JOIN vw_locacao_valores v ON v.locacao_id = l.id WHERE d.multa + d.avaria > 0 ORDER BY l.numero LIMIT 1');
        if ($com) {
            $this->get("/locacoes/{$com->numero}/fatura")->assertOk()
                ->assertSee('Total da locação', false)->assertSee(moeda($com->valor_atual), false)
                ->assertSee(moeda(max(0, $com->valor_atual - $com->valor_pago)), false);
        }
    }

    public function test_romaneio_de_retirada_e_de_troca(): void
    {
        $this->entrarComo();
        $l = $this->locacao('aberta') ?? $this->locacao('devolvida');
        $this->get("/locacoes/{$l->numero}/romaneio")->assertOk()->assertSee('Romaneio de retirada', false)->assertSee('Conferido', false);
        $this->get("/locacoes/{$l->numero}/romaneio?tipo=troca")->assertOk()->assertSee('Romaneio de troca', false)->assertSee('Entrou', false);
    }

    public function test_orcamento_impresso_mostra_o_valor_total_da_ficha(): void
    {
        $this->entrarComo();
        $this->post('/orcamentos', [
            'cliente' => \App\Http\Controllers\NovaLocacaoController::rotuloCliente(DB::selectOne("SELECT nome, cpf_cnpj FROM clientes WHERE ativo AND NOT bloqueado AND cpf_cnpj <> '' ORDER BY nome LIMIT 1")),
            'data_saida' => now()->addDays(5)->toDateString(), 'data_prev_devolucao' => now()->addDays(7)->toDateString(),
            'validade' => now()->addDays(7)->toDateString(), 'local_uso' => 'TESTE impressão',
            'produto_id' => [DB::selectOne("SELECT id FROM produtos WHERE codigo = 'ESC-300'")->id], 'quantidade' => ['4'], 'diaria' => [''], 'periodo' => ['diaria'],
        ])->assertSessionHasNoErrors();
        $o = DB::selectOne("SELECT id, numero, previsto FROM (SELECT o.id, o.numero, o.acrescimo + o.frete_entrega + o.frete_recolhimento - o.desconto AS previsto
                                                                 FROM orcamentos o WHERE o.local_uso = 'TESTE impressão') x");
        $this->get("/orcamentos/{$o->id}/imprimir")->assertOk()
            ->assertSee('Orçamento', false)->assertSee('Orçamento válido até', false)->assertSee('Valor Total', false);
        $this->assertNotNull($o->numero);
    }

    public function test_beta_nao_imprime_documento_da_alfa(): void
    {
        $this->entrarComo();
        $l = $this->locacao('aberta') ?? $this->locacao('devolvida');
        $this->post('/sair');
        $this->entrarComo('beta-eventos');
        $this->get("/locacoes/{$l->numero}/contrato")->assertNotFound();
        $this->get("/locacoes/{$l->numero}/fatura")->assertNotFound();
        $this->get("/locacoes/{$l->numero}/romaneio")->assertNotFound();
    }

    public function test_clausulas_separam_titulo_texto_paragrafo_e_reposicao_na_clausula_de_avarias(): void
    {
        $texto = "CLÁUSULA 1ª - DO ESTADO: o bem sai testado.\n\nParágrafo solto de aviso.\n\nCLÁUSULA 4ª - DAS AVARIAS E EXTRAVIOS: pagará a reposição.";
        $blocos = Documentos::clausulas($texto, ['Betoneira: R$ 1.200,00 por unidade']);
        $this->assertSame(['clausula', 'paragrafo', 'clausula'], array_column($blocos, 'tipo'));
        $this->assertSame('CLÁUSULA 1ª - DO ESTADO', $blocos[0]['titulo']);
        $this->assertSame([], $blocos[0]['reposicao']);
        $this->assertSame(['Betoneira: R$ 1.200,00 por unidade'], $blocos[2]['reposicao']);
        $this->assertSame('pagará a reposição.', $blocos[2]['texto']);
    }

    public function test_data_por_extenso_e_nome_do_periodo(): void
    {
        $this->assertSame('27 de outubro de 2026', Documentos::dataExtenso('2026-10-27'));
        $this->assertSame('5 de março de 2027', Documentos::dataExtenso('2027-03-05'));
        $this->assertSame('Automático', Documentos::periodoNome('automatico'));
        $this->assertSame('Quinzenal', Documentos::periodoNome('quinzenal'));
    }

    private function idDaLocacao(int $numero): string
    {
        return DB::selectOne('SELECT id FROM locacoes WHERE numero = ?', [$numero])->id;
    }
}
