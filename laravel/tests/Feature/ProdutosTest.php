<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Produtos: as mesmas regras de produto_salvar() do sistema antigo e o mesmo cálculo de disponível. */
class ProdutosTest extends TestCase
{
    private function dados(array $mudar = []): array
    {
        return $mudar + ['codigo' => 'tst-lar-01', 'descricao' => 'TESTE produto', 'unidade' => 'un', 'qtd_total' => '5',
                         'valor_diaria' => '25,50', 'valor_semanal' => '', 'valor_quinzenal' => '', 'valor_mensal' => '',
                         'valor_reposicao' => '1.200,00', 'ncm' => '', 'codigos_barras' => '', 'ativo' => '1', '_enviado' => '1'];
    }

    private function idProduto(string $codigo): string
    {
        return DB::selectOne('SELECT id FROM produtos WHERE codigo = ?', [$codigo])->id;
    }

    public function test_lista_mostra_o_disponivel_igual_ao_sistema_antigo(): void
    {
        $this->entrarComo();
        // Mesma conta do sistema antigo: estoque - na rua - reservado hoje - manutenção
        $p = DB::selectOne("SELECT p.descricao, p.qtd_total - COALESCE(a.qtd_alugada, 0) - COALESCE(m.qtd_manutencao, 0)
                                   - COALESCE((SELECT SUM(ri.quantidade) FROM reserva_itens ri JOIN reservas r ON r.id = ri.reserva_id
                                                WHERE r.status = 'ativa' AND ri.produto_id = p.id AND CURRENT_DATE BETWEEN r.data_inicio AND r.data_fim), 0) AS disp
                              FROM produtos p LEFT JOIN vw_produto_alugado a ON a.produto_id = p.id LEFT JOIN vw_produto_manutencao m ON m.produto_id = p.id
                             WHERE p.codigo = 'BET-400'");
        $this->post('/produtos/filtrar', ['q' => 'BET-400', 'situacao' => 'todos'], ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->assertSee($p->descricao)->assertSeeInOrder(['Betoneira', '>' . $p->disp . '<'], false);
    }

    public function test_cadastra_com_codigo_em_maiusculas_valores_e_codigos_de_barras(): void
    {
        $this->entrarComo();
        $this->post('/produtos', $this->dados(['codigos_barras' => "7890000000017\n7890000000024", 'valor_semanal' => '120']))
            ->assertRedirect(route('produtos.index'));
        $p = DB::selectOne("SELECT * FROM produtos WHERE codigo = 'TST-LAR-01'");
        $this->assertSame('UN', $p->unidade);
        $this->assertSame('25.50', $p->valor_diaria);
        $this->assertSame('1200.00', $p->valor_reposicao);
        $this->assertSame($this->idLocadora('alfa-locacoes'), $p->tenant_id);
        $b = DB::select('SELECT codigo, principal FROM produto_barras WHERE produto_id = ? ORDER BY principal DESC', [$p->id]);
        $this->assertSame(['7890000000017', '7890000000024'], array_column($b, 'codigo'));
        $this->assertTrue($b[0]->principal);
    }

    public function test_recusa_dados_invalidos_com_as_regras_do_sistema_antigo(): void
    {
        $this->entrarComo();
        $this->post('/produtos', $this->dados(['codigo' => 'com espaço', 'descricao' => 'X', 'qtd_total' => '-1', 'ncm' => '123',
                                              'valor_reposicao' => 'abc', 'codigos_barras' => '12ab']))
            ->assertSessionHasErrors(['codigo', 'descricao', 'qtd_total', 'ncm', 'valor_reposicao', 'codigos_barras']);
        $this->post('/produtos', $this->dados(['valor_diaria' => '', 'valor_semanal' => '100']))->assertSessionHasErrors('valor_diaria');
        $this->post('/produtos', $this->dados(['valor_semanal' => '100', 'valor_mensal' => '90']))->assertSessionHasErrors('valor_mensal');
        $this->post('/produtos', $this->dados(['codigos_barras' => '12345 12345']))->assertSessionHasErrors('codigos_barras');
        $this->post('/produtos', $this->dados(['categoria_id' => '00000000-0000-0000-0000-000000000000']))->assertSessionHasErrors('categoria_id');
        $this->assertNull(DB::selectOne("SELECT id FROM produtos WHERE codigo = 'TST-LAR-01'"));
    }

    public function test_codigo_e_codigo_de_barras_repetidos_viram_mensagem_no_campo(): void
    {
        $this->entrarComo();
        $this->post('/produtos', $this->dados(['codigo' => 'bet-400']))->assertSessionHasErrors(['codigo' => 'Já existe um produto com este código.']);
        $this->post('/produtos', $this->dados(['codigos_barras' => '7890000000017']));
        $this->post('/produtos', $this->dados(['codigo' => 'TST-LAR-02', 'codigos_barras' => '7890000000017']))
            ->assertSessionHasErrors(['codigos_barras' => 'Este código de barras já está em outro produto.']);
        $this->assertNull(DB::selectOne("SELECT id FROM produtos WHERE codigo = 'TST-LAR-02'"));
    }

    public function test_estoque_nao_fica_menor_que_o_que_esta_na_rua(): void
    {
        $this->entrarComo();
        $p = DB::selectOne("SELECT p.*, a.qtd_alugada FROM produtos p JOIN vw_produto_alugado a ON a.produto_id = p.id WHERE a.qtd_alugada > 0 ORDER BY p.codigo LIMIT 1");
        $this->put("/produtos/{$p->id}", $this->dados(['codigo' => $p->codigo, 'descricao' => $p->descricao, 'qtd_total' => (string) ($p->qtd_alugada - 1)]))
            ->assertSessionHasErrors('qtd_total');
        $this->assertSame($p->qtd_total, DB::selectOne('SELECT qtd_total FROM produtos WHERE id = ?', [$p->id])->qtd_total);
    }

    public function test_edita_e_exclui_produto_sem_uso_mas_nao_o_usado(): void
    {
        $this->entrarComo();
        $this->post('/produtos', $this->dados(['codigos_barras' => '7890000000017']));
        $id = $this->idProduto('TST-LAR-01');

        $this->put("/produtos/$id", $this->dados(['valor_quinzenal' => '200', 'ativo' => '0']))->assertRedirect(route('produtos.index'));
        $p = DB::selectOne('SELECT valor_quinzenal, ativo FROM produtos WHERE id = ?', [$id]);
        $this->assertSame('200.00', $p->valor_quinzenal);
        $this->assertFalse($p->ativo);
        // Na transação do teste o now() não muda: confere que a alteração foi auditada, sem depender da ordem
        $this->assertNotNull(DB::selectOne("SELECT 1 AS ok FROM auditoria WHERE tabela = 'produtos' AND rotulo = 'TST-LAR-01' AND acao = 'U' AND usuario = 'admin'"));

        $this->delete("/produtos/$id")->assertRedirect(route('produtos.index'));
        $this->assertNull(DB::selectOne('SELECT id FROM produtos WHERE id = ?', [$id]));
        $this->assertNull(DB::selectOne("SELECT id FROM produto_barras WHERE codigo = '7890000000017'"));

        $usado = DB::selectOne('SELECT produto_id FROM locacao_itens LIMIT 1')->produto_id;
        $this->delete("/produtos/$usado")->assertRedirect();
        $this->assertStringContainsString('não pode ser excluído', session('flash')[0]['msg']);
        $this->assertNotNull(DB::selectOne('SELECT id FROM produtos WHERE id = ?', [$usado]));
    }

    public function test_alfa_nao_ve_nem_altera_produto_da_beta(): void
    {
        $b = $this->lerComoAdmin("SELECT p.id, p.codigo, p.descricao, p.valor_diaria FROM produtos p JOIN tenants t ON t.id = p.tenant_id
                                   WHERE t.slug = 'beta-eventos' ORDER BY p.codigo LIMIT 1")[0];
        $this->entrarComo('alfa-locacoes');

        $this->get("/produtos/{$b->id}/editar")->assertNotFound();
        $this->put("/produtos/{$b->id}", $this->dados(['codigo' => $b->codigo, 'valor_diaria' => '0,01']))->assertNotFound();
        $this->delete("/produtos/{$b->id}")->assertNotFound();
        $this->post('/produtos/filtrar', ['q' => $b->codigo, 'situacao' => 'todos'], ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->assertDontSee(route('produtos.edit', $b->id), false);

        $depois = $this->lerComoAdmin('SELECT valor_diaria FROM produtos WHERE id = ?', [$b->id])[0];
        $this->assertSame($b->valor_diaria, $depois->valor_diaria);
    }
}
