<?php

namespace Tests\Feature;

use App\Support\Locacoes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * WhatsApp (wa.me com a mensagem pronta): número, mensagens da ficha e das listas, e o cliente sem telefone.
 * Usa a demonstração da Alfa: a locação 141 (PJ, atrasada, com saldo) e a 142 (PF, com saldo).
 * Regras de whatsapp_numero() e Locacoes::whatsapp() do sistema antigo (modulos/locacoes.php, app/helpers.php).
 */
class WhatsappTest extends TestCase
{
    /** Links wa.me da página: [número => [mensagens decodificadas]]. */
    private function linksWa(string $html): array
    {
        preg_match_all('#href="https://wa\.me/(\d+)\?text=([^"]*)"#', $html, $m, PREG_SET_ORDER);
        $r = [];
        foreach ($m as $x) {
            $r[$x[1]][] = rawurldecode(html_entity_decode($x[2], ENT_QUOTES, 'UTF-8'));
        }
        return $r;
    }

    /** Texto de um link wa.me (o que vem depois de ?text=), já decodificado. */
    private function mensagem(string $url): string
    {
        return rawurldecode(substr($url, strpos($url, '?text=') + 6));
    }

    private function empresa(): object
    {
        return (object) ['nome_fantasia' => 'Alfa Locações', 'razao_social' => 'Alfa Locações Ltda', 'pix_chave' => '', 'pix_nome' => '', 'pix_cidade' => ''];
    }

    public function test_numero_do_whatsapp_usa_o_celular_depois_o_telefone_com_codigo_do_pais(): void
    {
        $numero = fn (string $celular, string $telefone = '') => whatsapp_numero((object) ['celular' => $celular, 'telefone' => $telefone]);
        $this->assertSame('5585988887777', $numero('(85) 98888-7777'));
        $this->assertSame('5585988887777', $numero('085 98888-7777'));              // zero à frente do DDD
        $this->assertSame('5585988887777', $numero('+55 85 98888-7777'));           // com o 55 já digitado
        $this->assertSame('558532221000', $numero('', '(85) 3222-1000'));            // sem celular: usa o telefone
        $this->assertSame('558532221000', $numero('(85) 9999', '(85) 3222-1000'));   // celular incompleto: usa o telefone
        $this->assertNull($numero('', ''));
        $this->assertNull($numero('123'));
    }

    public function test_devolvida_com_saldo_so_oferece_resumo_e_cobranca(): void
    {
        $this->entrarComo();
        $loc = (object) ['id' => (string) Str::uuid(), 'numero' => 150, 'status' => 'devolvida', 'cliente_nome' => 'Maria Silva', 'cliente_tipo' => 'PF',
                         'cliente_celular' => '(85) 98888-7777', 'cliente_telefone' => '', 'data_saida' => '2026-10-01',
                         'data_prev_devolucao' => '2026-10-10', 'valor_previsto' => 300.0, 'valor_pago' => 100.0, 'valor_atual' => 335.0];
        $links = Locacoes::whatsapp($loc, $this->empresa())['links'];
        $this->assertSame(['resumo', 'saldo'], array_keys($links), 'sem lembrete de devolução depois da devolução');
        $this->assertStringContainsString('Consta um saldo de *R$ 235,00*', $this->mensagem($links['saldo']));
    }

    public function test_lembrete_diz_se_a_devolucao_esta_atrasada_hoje_ou_amanha(): void
    {
        $this->entrarComo();
        $lembrete = function (string $data): string {
            $loc = (object) ['id' => (string) Str::uuid(), 'numero' => 150, 'status' => 'aberta', 'cliente_nome' => 'Maria Silva', 'cliente_tipo' => 'PF',
                             'cliente_celular' => '85988887777', 'cliente_telefone' => '', 'data_saida' => $data, 'data_prev_devolucao' => $data,
                             'valor_previsto' => 0.0, 'valor_pago' => 0.0, 'valor_atual' => 0.0];
            return $this->mensagem(Locacoes::whatsapp($loc, $this->empresa())['links']['lembrete']);
        };
        $this->assertStringContainsString('está *atrasada* desde ' . data_br(now()->subDays(3)->toDateString()) . ' (3 dia(s))',
                                          $lembrete(now()->subDays(3)->toDateString()));
        $this->assertStringContainsString('está prevista para *hoje*', $lembrete(now()->toDateString()));
        $this->assertStringContainsString('está prevista para *amanhã*', $lembrete(now()->addDay()->toDateString()));
    }

    public function test_ficha_da_locacao_atrasada_oferece_resumo_lembrete_e_cobranca_para_o_celular_do_cliente(): void
    {
        $this->entrarComo();
        $loc = DB::selectOne('SELECT l.numero, l.data_prev_devolucao, c.celular, c.telefone FROM locacoes l JOIN clientes c ON c.id = l.cliente_id WHERE l.numero = 141');
        $numero = whatsapp_numero((object) ['celular' => $loc->celular, 'telefone' => $loc->telefone]);
        $atraso = (new \DateTime($loc->data_prev_devolucao))->diff(new \DateTime(now()->toDateString()))->format('%r%a');

        $html = $this->get("/locacoes/{$loc->numero}")->assertOk()
            ->assertSee('Enviar resumo')->assertSee('Lembrete de devolução')->assertSee('Cobrar saldo')
            ->getContent();
        $mensagens = $this->linksWa($html)[$numero] ?? [];
        $this->assertCount(3, $mensagens, 'resumo, lembrete e cobrança, todos para o mesmo número');
        $this->assertTrue((bool) array_filter($mensagens, fn ($t) => str_contains($t, 'Olá, Construtora Jaguaribe Ltda!')
            && str_contains($t, 'está *atrasada* desde ' . data_br($loc->data_prev_devolucao) . " ($atraso dia(s))"))
            , 'lembrete da locação atrasada de cliente PJ: razão social e dias de atraso');
    }

    public function test_sem_telefone_os_botoes_somem_e_a_tela_orienta_o_cadastro(): void
    {
        $this->entrarComo();
        $loc = DB::selectOne('SELECT numero, cliente_id FROM locacoes WHERE numero = 142');
        DB::update("UPDATE clientes SET celular = '', telefone = '' WHERE id = ?", [$loc->cliente_id]);
        $this->get("/locacoes/{$loc->numero}")->assertOk()
            ->assertSee('Cadastre o celular do cliente para usar o WhatsApp.')
            ->assertDontSee('https://wa.me/');
    }

    public function test_lista_de_atrasadas_tem_o_lembrete_pelo_whatsapp(): void
    {
        $this->entrarComo();
        $this->post('/locacoes/filtrar', ['aba' => 'atrasadas', 'q' => '141'], ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->assertSee('Lembrete de devolução da locação 141 pelo WhatsApp');
    }

    public function test_clientes_mostram_o_whatsapp_so_quando_ha_numero(): void
    {
        $this->entrarComo();
        $this->post('/clientes/filtrar', ['q' => 'Jaguaribe'], ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->assertSee('WhatsApp de Construtora Jaguaribe Ltda');

        DB::update("UPDATE clientes SET celular = '', telefone = '' WHERE nome = 'José Airton Holanda'");
        $this->post('/clientes/filtrar', ['q' => 'José Airton'], ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->assertDontSee('WhatsApp de José Airton Holanda');
    }

    public function test_beta_nao_ve_a_locacao_nem_o_whatsapp_da_alfa(): void
    {
        $this->entrarComo('beta-eventos');
        $this->get('/locacoes/141')->assertNotFound();
        $this->post('/clientes/filtrar', ['q' => 'Jaguaribe'], ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->assertSee('Nenhum cliente')->assertDontSee('Construtora Jaguaribe Ltda');
    }
}
