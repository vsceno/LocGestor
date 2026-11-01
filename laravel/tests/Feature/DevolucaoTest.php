<?php

namespace Tests\Feature;

use App\Http\Controllers\NovaLocacaoController;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Devolução: regras de devolucao_registrar() e devolucao_unidades() do sistema antigo. */
class DevolucaoTest extends TestCase
{
    /** Locação de teste com saída há 3 dias: 10 escoras (diária) e 2 carrinhos. Devolve [numero, id, itens por código]. */
    private function locacao(): array
    {
        $saida = now()->subDays(3)->toDateString();
        $prev = now()->addDays(2)->toDateString();
        $esc = DB::selectOne("SELECT id FROM produtos WHERE codigo = 'ESC-300'")->id;
        $car = DB::selectOne("SELECT id FROM produtos WHERE codigo = 'CAR-MAO'")->id;
        $c = DB::selectOne("SELECT nome, cpf_cnpj FROM clientes WHERE ativo AND NOT bloqueado AND cpf_cnpj <> '' ORDER BY nome LIMIT 1");
        $this->post('/locacoes', ['cliente' => NovaLocacaoController::rotuloCliente($c), 'data_saida' => $saida, 'data_prev_devolucao' => $prev,
            'produto_id' => [$esc, $car], 'quantidade' => ['10', '2'], 'diaria' => ['1,20', '5,00'], 'periodo' => ['diaria', 'diaria'],
            'prev' => [$prev, $prev], 'local_uso' => 'TESTE devolução'])->assertSessionHasNoErrors();
        $l = DB::selectOne("SELECT id, numero FROM locacoes WHERE local_uso = 'TESTE devolução'");
        $itens = [];
        foreach (DB::select('SELECT li.id, p.codigo FROM locacao_itens li JOIN produtos p ON p.id = li.produto_id WHERE li.locacao_id = ?', [$l->id]) as $i) {
            $itens[$i->codigo] = $i->id;
        }
        return [$l->numero, $l->id, $itens];
    }

    public function test_parcial_com_perda_e_multa_depois_final_encerra(): void
    {
        $this->entrarComo();
        [$n, $id, $it] = $this->locacao();
        $estoque = (int) DB::selectOne("SELECT qtd_total FROM produtos WHERE codigo = 'ESC-300'")->qtd_total;
        $reposicao = (float) DB::selectOne("SELECT valor_reposicao FROM produtos WHERE codigo = 'ESC-300'")->valor_reposicao;
        $hoje = now()->toDateString();

        $this->get("/locacoes/$n/devolucao")->assertOk()->assertSee('Itens a devolver');
        $this->post("/locacoes/$n/devolucao", ['data' => $hoje, 'qtd' => [$it['ESC-300'] => '8', $it['CAR-MAO'] => '1'], 'perda' => [$it['ESC-300'] => '1'],
                                               'multa' => '10,00', 'receber' => '50', 'forma' => 'Dinheiro'])
            ->assertSessionHasNoErrors()->assertRedirect(route('locacoes.show', $n));
        // 4 dias (conta o dia da saída): 8 x 1,20 x 4 + 1 x 5 x 4
        $d = DB::selectOne('SELECT id, multa, avaria, obs FROM devolucoes WHERE locacao_id = ?', [$id]);
        $this->assertSame(['10.00', number_format($reposicao, 2, '.', '')], [$d->multa, $d->avaria]);
        $this->assertStringContainsString('Perda/extravio: 1 x Escora', $d->obs);
        $this->assertEqualsWithDelta(8 * 1.2 * 4 + 5 * 4, (float) DB::selectOne('SELECT SUM(valor) AS v FROM devolucao_itens WHERE devolucao_id = ?', [$d->id])->v, 0.001);
        $this->assertSame($estoque - 1, (int) DB::selectOne("SELECT qtd_total FROM produtos WHERE codigo = 'ESC-300'")->qtd_total);
        $this->assertSame('aberta', DB::selectOne('SELECT status FROM locacoes WHERE id = ?', [$id])->status);
        $this->assertNotNull(DB::selectOne("SELECT 1 AS ok FROM caixa WHERE locacao_id = ? AND valor = 50 AND forma = 'Dinheiro'", [$id]));

        $this->post("/locacoes/$n/devolucao", ['data' => $hoje, 'qtd' => [$it['ESC-300'] => '1', $it['CAR-MAO'] => '1']])
            ->assertSessionHasNoErrors()->assertSessionHas('flash', [['tipo' => 'ok', 'msg' => "Devolução registrada. Locação nº $n encerrada."]]);
        $this->assertSame('devolvida', DB::selectOne('SELECT status FROM locacoes WHERE id = ?', [$id])->status);
        $this->get("/locacoes/$n/devolucao")->assertRedirect(route('locacoes.show', $n));
        $this->post("/locacoes/$n/devolucao", ['data' => $hoje, 'qtd' => [$it['ESC-300'] => '1']])
            ->assertSessionHasErrors(['devolucao' => 'Só é possível registrar devolução de locação em aberto.']);
    }

    public function test_recusas_nao_gravam_nada(): void
    {
        $this->entrarComo();
        [$n, $id, $it] = $this->locacao();
        $hoje = now()->toDateString();
        foreach ([
            'Informe a data da devolução.'           => ['data' => '30/10/2026', 'qtd' => [$it['ESC-300'] => '1']],
            'deve estar entre a saída'                => ['data' => now()->addDay()->toDateString(), 'qtd' => [$it['ESC-300'] => '1']],
            'só há 10 pendente(s)'                    => ['data' => $hoje, 'qtd' => [$it['ESC-300'] => '11']],
            'somam mais que o pendente'               => ['data' => $hoje, 'qtd' => [$it['ESC-300'] => '10'], 'perda' => [$it['ESC-300'] => '1']],
            'Informe a quantidade de pelo menos um'   => ['data' => $hoje, 'qtd' => [$it['ESC-300'] => '0', $it['CAR-MAO'] => '0']],
            'Item inválido na devolução.'             => ['data' => $hoje, 'qtd' => ['00000000-0000-0000-0000-000000000000' => '1']],
            'não podem ser negativos'                 => ['data' => $hoje, 'qtd' => [$it['ESC-300'] => '1'], 'multa' => '-5'],
            'Escolha a forma de pagamento.'           => ['data' => $hoje, 'qtd' => [$it['ESC-300'] => '1'], 'receber' => '10'],
        ] as $msg => $dados) {
            $this->post("/locacoes/$n/devolucao", $dados)->assertSessionHasErrors('devolucao');
            $this->assertStringContainsString($msg, session('errors')->first('devolucao'), "caso: $msg");
        }
        $this->assertNull(DB::selectOne('SELECT id FROM devolucoes WHERE locacao_id = ?', [$id]));
        $this->assertSame(0, (int) DB::selectOne('SELECT SUM(qtd_devolvida) AS n FROM locacao_itens WHERE locacao_id = ?', [$id])->n);
    }

    public function test_unidades_de_patrimonio_que_voltaram(): void
    {
        $this->entrarComo();
        // Locação com 2 betoneiras e duas unidades na rua (se a demonstração tiver estoque livre)
        $bet = DB::selectOne("SELECT id FROM produtos WHERE codigo = 'BET-400'")->id;
        $hoje = now()->toDateString();
        if (\App\Support\Estoque::disponivel($bet, $hoje, $hoje) < 2) {
            $this->markTestSkipped('Sem duas betoneiras livres hoje nos dados de demonstração.');
        }
        $c = DB::selectOne("SELECT nome, cpf_cnpj FROM clientes WHERE ativo AND NOT bloqueado AND cpf_cnpj <> '' ORDER BY nome LIMIT 1");
        $this->post('/locacoes', ['cliente' => NovaLocacaoController::rotuloCliente($c), 'data_saida' => $hoje, 'data_prev_devolucao' => $hoje,
            'produto_id' => [$bet], 'quantidade' => ['2'], 'diaria' => [''], 'periodo' => ['diaria'], 'prev' => [$hoje], 'local_uso' => 'TESTE devolução'])->assertSessionHasNoErrors();
        $l = DB::selectOne("SELECT l.id, l.numero, li.id AS item FROM locacoes l JOIN locacao_itens li ON li.locacao_id = l.id WHERE l.local_uso = 'TESTE devolução'");
        $u = array_column(DB::select('SELECT u.id FROM unidades u WHERE u.produto_id = ? AND u.ativa
                                        AND NOT EXISTS (SELECT 1 FROM locacao_unidades lu WHERE lu.unidade_id = u.id AND lu.devolucao_id IS NULL)
                                        AND NOT EXISTS (SELECT 1 FROM manutencoes m WHERE m.unidade_id = u.id AND m.data_fim IS NULL)
                                      ORDER BY u.patrimonio LIMIT 2', [$bet]), 'id');
        $this->post("/locacoes/{$l->numero}/unidades", ['unidades' => [$l->item => $u]])->assertSessionHasNoErrors();

        // Parcial de 1 sem dizer qual voltou: recusa
        $this->post("/locacoes/{$l->numero}/devolucao", ['data' => $hoje, 'qtd' => [$l->item => '1'], 'unidades' => []])
            ->assertSessionHasErrors(['devolucao' => 'Marque quais unidades (patrimônio) de Betoneira 400 L voltaram.']);
        // Perda de item com patrimônio: recusa
        $this->post("/locacoes/{$l->numero}/devolucao", ['data' => $hoje, 'qtd' => [$l->item => '0'], 'perda' => [$l->item => '1']])
            ->assertSessionHasErrors(['devolucao' => 'Betoneira 400 L tem patrimônio: a perda de unidade não é registrada aqui.']);
        // Parcial de 1 com a primeira unidade marcada
        $this->post("/locacoes/{$l->numero}/devolucao", ['data' => $hoje, 'qtd' => [$l->item => '1'], 'unidades' => [$u[0]]])->assertSessionHasNoErrors();
        $naRua = array_column(DB::select('SELECT unidade_id FROM locacao_unidades WHERE locacao_item_id = ? AND devolucao_id IS NULL', [$l->item]), 'unidade_id');
        $this->assertSame([$u[1]], $naRua);
        // Final: a que restou volta sozinha
        $this->post("/locacoes/{$l->numero}/devolucao", ['data' => $hoje, 'qtd' => [$l->item => '1']])->assertSessionHasNoErrors();
        $this->assertNull(DB::selectOne('SELECT 1 AS x FROM locacao_unidades WHERE locacao_item_id = ? AND devolucao_id IS NULL', [$l->item]));
    }

    public function test_beta_nao_devolve_locacao_da_alfa(): void
    {
        $l = $this->lerComoAdmin("SELECT l.numero, l.id FROM locacoes l JOIN tenants t ON t.id = l.tenant_id WHERE t.slug = 'alfa-locacoes' AND l.status = 'aberta'
                                   AND NOT EXISTS (SELECT 1 FROM locacoes b JOIN tenants tb ON tb.id = b.tenant_id WHERE tb.slug = 'beta-eventos' AND b.numero = l.numero)
                                 ORDER BY l.numero DESC LIMIT 1")[0];
        $this->entrarComo('beta-eventos');
        $this->get("/locacoes/{$l->numero}/devolucao")->assertNotFound();
        $this->post("/locacoes/{$l->numero}/devolucao", ['data' => now()->toDateString()])->assertNotFound();
        $this->assertEmpty($this->lerComoAdmin('SELECT 1 FROM devolucoes WHERE locacao_id = ? AND criado_em > now()', [$l->id]));
    }
}
