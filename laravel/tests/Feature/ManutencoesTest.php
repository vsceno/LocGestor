<?php

namespace Tests\Feature;

use App\Support\Estoque;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Manutenção: regras de manutencao_abrir() e manutencao_concluir() do sistema antigo. */
class ManutencoesTest extends TestCase
{
    private const DESCRICAO = 'TESTE conserto';

    /** Unidade ativa, fora de manutenção e fora da rua (null se a demonstração não tiver). */
    private function unidadeLivre(): ?object
    {
        return DB::selectOne("SELECT u.id, u.produto_id, u.patrimonio, p.descricao AS produto FROM unidades u
                                JOIN produtos p ON p.id = u.produto_id
                               WHERE u.ativa
                                 AND NOT EXISTS (SELECT 1 FROM manutencoes m WHERE m.unidade_id = u.id AND m.data_fim IS NULL)
                                 AND NOT EXISTS (SELECT 1 FROM locacao_unidades lu WHERE lu.unidade_id = u.id AND lu.devolucao_id IS NULL)
                               ORDER BY u.patrimonio LIMIT 1");
    }

    /** Produto com ao menos uma peça livre hoje (null se nenhum). */
    private function produtoComFolga(): ?object
    {
        $hoje = now()->toDateString();
        foreach (DB::select('SELECT id, descricao FROM produtos WHERE ativo AND qtd_total > 0 ORDER BY descricao') as $p) {
            if (Estoque::disponivel($p->id, $hoje, $hoje) >= 1) {
                return $p;
            }
        }
        return null;
    }

    /** Campos válidos de uma manutenção; $mudar troca o que o teste quer provar. */
    private function dados(array $mudar = []): array
    {
        return array_merge(['unidade_id' => '', 'produto_id' => '', 'quantidade' => '1', 'tipo' => 'corretiva',
                            'data_inicio' => now()->toDateString(), 'fornecedor' => 'TESTE oficina', 'descricao' => self::DESCRICAO], $mudar);
    }

    /** Abre a manutenção de uma unidade e devolve a linha gravada. */
    private function abrirUnidade(object $u): object
    {
        $this->post('/manutencoes', $this->dados(['unidade_id' => $u->id]))->assertSessionHasNoErrors();
        return DB::selectOne('SELECT * FROM manutencoes WHERE unidade_id = ? ORDER BY numero DESC LIMIT 1', [$u->id]);
    }

    public function test_abre_manutencao_de_unidade_e_tira_a_peca_do_estoque(): void
    {
        $this->entrarComo();
        $u = $this->unidadeLivre();
        if ($u === null) {
            $this->markTestSkipped('Sem unidade livre nos dados de demonstração.');
        }
        $hoje = now()->toDateString();
        $livre = Estoque::disponivel($u->produto_id, $hoje, $hoje);

        $m = $this->abrirUnidade($u);
        $this->assertSame([$u->produto_id, $u->id, 1, 'corretiva', 'TESTE oficina', null],
                          [$m->produto_id, $m->unidade_id, $m->quantidade, $m->tipo, $m->fornecedor, $m->data_fim]);
        $this->assertSame($livre - 1, Estoque::disponivel($u->produto_id, $hoje, $hoje));

        // A unidade já está na manutenção: não abre outra por cima
        $this->post('/manutencoes', $this->dados(['unidade_id' => $u->id]))->assertSessionHasErrors('manutencao');
        $this->assertStringContainsString("já está na manutenção nº {$m->numero}", session('errors')->first('manutencao'));

        $this->get("/manutencoes/{$m->id}")->assertOk()->assertSee("Manutenção nº {$m->numero}")->assertSee('em manutenção');
        $this->get('/manutencoes')->assertOk()->assertSee(self::DESCRICAO);
        $this->get("/produtos/unidades/{$u->id}")->assertOk()->assertSee('em manutenção');
    }

    public function test_tela_nova_vem_com_a_unidade_escolhida(): void
    {
        $this->entrarComo();
        $u = $this->unidadeLivre();
        if ($u === null) {
            $this->markTestSkipped('Sem unidade livre nos dados de demonstração.');
        }
        $this->get("/manutencoes/nova?unidade={$u->id}")->assertOk()->assertSee("value=\"{$u->id}\" selected>", false);
    }

    public function test_recusas_nao_gravam_nada(): void
    {
        $this->entrarComo();
        $u = $this->unidadeLivre();
        if ($u === null) {
            $this->markTestSkipped('Sem unidade livre nos dados de demonstração.');
        }
        $antes = (int) DB::selectOne('SELECT count(*) AS n FROM manutencoes')->n;
        $casos = [
            'Escolha o tipo de manutenção.' => ['tipo' => 'outra'],
            'Descreva o problema ou o serviço' => ['descricao' => 'ab'],
            'A data de início deve ser hoje ou antes.' => ['data_inicio' => now()->addDay()->toDateString()],
            'Escolha o produto ou a unidade.' => ['unidade_id' => ''],
        ];
        foreach ($casos as $msg => $mudar) {
            $this->post('/manutencoes', $this->dados(array_merge(['unidade_id' => $u->id], $mudar)))->assertSessionHasErrors('manutencao');
            $this->assertStringContainsString($msg, session('errors')->first('manutencao'), "caso: $msg");
        }
        $this->assertSame($antes, (int) DB::selectOne('SELECT count(*) AS n FROM manutencoes')->n, 'recusa não pode gravar manutenção');
    }

    public function test_quantidade_acima_do_livre_e_recusada(): void
    {
        $this->entrarComo();
        $p = $this->produtoComFolga();
        if ($p === null) {
            $this->markTestSkipped('Nenhum produto com peça livre nos dados de demonstração.');
        }
        $hoje = now()->toDateString();
        $livre = Estoque::disponivel($p->id, $hoje, $hoje);

        $this->post('/manutencoes', $this->dados(['produto_id' => $p->id, 'quantidade' => (string) ($livre + 1)]))->assertSessionHasErrors('manutencao');
        $this->assertStringContainsString("Só há $livre peça(s) de {$p->descricao} livre(s) agora", session('errors')->first('manutencao'));

        // Exatamente o livre abre, e o disponível vai a zero
        $this->post('/manutencoes', $this->dados(['produto_id' => $p->id, 'quantidade' => (string) $livre]))->assertSessionHasNoErrors();
        $this->assertSame(0, Estoque::disponivel($p->id, $hoje, $hoje));
    }

    public function test_concluir_devolve_a_peca_e_lanca_o_custo_no_caixa(): void
    {
        $this->entrarComo();
        $u = $this->unidadeLivre();
        if ($u === null) {
            $this->markTestSkipped('Sem unidade livre nos dados de demonstração.');
        }
        $hoje = now()->toDateString();
        $livre = Estoque::disponivel($u->produto_id, $hoje, $hoje);
        $m = $this->abrirUnidade($u);

        $this->post("/manutencoes/{$m->id}/concluir", ['data_fim' => $hoje, 'custo' => '150,50', 'solucao' => 'TESTE troca', 'lancar_caixa' => '1', 'forma' => 'PIX'])
            ->assertRedirect(route('manutencoes.show', $m->id))
            ->assertSessionHasNoErrors();

        $m = DB::selectOne('SELECT * FROM manutencoes WHERE id = ?', [$m->id]);
        $this->assertSame([$hoje, '150.50', 'TESTE troca'], [$m->data_fim, $m->custo, $m->solucao]);
        $this->assertSame($livre, Estoque::disponivel($u->produto_id, $hoje, $hoje));

        $c = DB::selectOne('SELECT * FROM caixa WHERE id = ?', [$m->caixa_id]);
        $this->assertSame(['S', 'Manutenção', '150.50', 'PIX', $hoje], [$c->tipo, $c->categoria, $c->valor, $c->forma, $c->data]);
        $this->assertSame("Manutenção nº {$m->numero} - {$u->produto} ({$u->patrimonio})", $c->descricao);
        $this->get('/caixa')->assertOk()->assertSee("Manutenção nº {$m->numero}");
    }

    public function test_conclusao_recusada_sem_custo_nao_lanca_e_nao_repete(): void
    {
        $this->entrarComo();
        $u = $this->unidadeLivre();
        if ($u === null) {
            $this->markTestSkipped('Sem unidade livre nos dados de demonstração.');
        }
        $hoje = now()->toDateString();
        $m = $this->abrirUnidade($u);

        $this->post("/manutencoes/{$m->id}/concluir", ['data_fim' => now()->subYear()->toDateString(), 'custo' => '10'])->assertSessionHasErrors('concluir');
        $this->assertStringContainsString('A data de conclusão deve estar entre', session('errors')->first('concluir'));
        $this->post("/manutencoes/{$m->id}/concluir", ['data_fim' => $hoje, 'custo' => 'abc'])->assertSessionHasErrors('concluir');
        $this->assertStringContainsString('Confira o custo', session('errors')->first('concluir'));
        $this->post("/manutencoes/{$m->id}/concluir", ['data_fim' => $hoje, 'custo' => '50', 'lancar_caixa' => '1', 'forma' => 'Bitcoin'])->assertSessionHasErrors('concluir');
        $this->assertStringContainsString('Escolha a forma de pagamento.', session('errors')->first('concluir'));

        // Sem custo, o caixa não recebe nada, mesmo com a caixa marcada
        $this->post("/manutencoes/{$m->id}/concluir", ['data_fim' => $hoje, 'custo' => '', 'solucao' => 'TESTE sem custo', 'lancar_caixa' => '1'])
            ->assertSessionHasNoErrors();
        $m = DB::selectOne('SELECT * FROM manutencoes WHERE id = ?', [$m->id]);
        $this->assertSame(['0.00', null], [$m->custo, $m->caixa_id]);

        // Concluída: não conclui de novo e não exclui
        $this->post("/manutencoes/{$m->id}/concluir", ['data_fim' => $hoje, 'custo' => '10'])->assertSessionHasErrors('concluir');
        $this->assertStringContainsString('já foi concluída', session('errors')->first('concluir'));
        $this->delete("/manutencoes/{$m->id}")->assertRedirect();
        $this->assertStringContainsString('Só é possível excluir manutenção em aberto.', session('flash')[0]['msg']);
        $this->assertNotNull(DB::selectOne('SELECT id FROM manutencoes WHERE id = ?', [$m->id]));
    }

    public function test_excluir_so_quando_aberta_e_devolve_a_peca(): void
    {
        $this->entrarComo();
        $u = $this->unidadeLivre();
        if ($u === null) {
            $this->markTestSkipped('Sem unidade livre nos dados de demonstração.');
        }
        $hoje = now()->toDateString();
        $livre = Estoque::disponivel($u->produto_id, $hoje, $hoje);
        $m = $this->abrirUnidade($u);

        $this->delete("/manutencoes/{$m->id}")->assertRedirect(route('manutencoes.index'));
        $this->assertNull(DB::selectOne('SELECT id FROM manutencoes WHERE id = ?', [$m->id]));
        $this->assertSame($livre, Estoque::disponivel($u->produto_id, $hoje, $hoje));
    }

    public function test_beta_nao_ve_nem_mexe_em_manutencao_da_alfa(): void
    {
        $this->entrarComo();
        $u = $this->unidadeLivre();
        if ($u === null) {
            $this->markTestSkipped('Sem unidade livre nos dados de demonstração.');
        }
        $m = $this->abrirUnidade($u);
        $this->post('/sair');

        $this->entrarComo('beta-eventos');
        $this->get("/manutencoes/{$m->id}")->assertNotFound();
        $this->post("/manutencoes/{$m->id}/concluir", ['data_fim' => now()->toDateString(), 'custo' => '10'])->assertNotFound();
        $this->delete("/manutencoes/{$m->id}")->assertNotFound();
        $this->get('/manutencoes?situacao=todas')->assertOk()->assertDontSee(self::DESCRICAO);
    }
}
