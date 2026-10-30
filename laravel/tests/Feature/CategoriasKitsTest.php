<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Categorias e kits de produtos: regras de categoria_salvar() e kit_salvar() do sistema antigo. */
class CategoriasKitsTest extends TestCase
{
    private function produto(string $codigo, string $slug = 'alfa-locacoes'): string
    {
        return $this->lerComoAdmin('SELECT p.id FROM produtos p JOIN tenants t ON t.id = p.tenant_id WHERE t.slug = ? AND p.codigo = ?', [$slug, $codigo])[0]->id;
    }

    public function test_categoria_adiciona_renomeia_recusa_repetida_e_exclui_so_vazia(): void
    {
        $this->entrarComo();
        $this->post('/produtos/categorias', ['nome' => ' TESTE Cat '])->assertRedirect(route('categorias.index'));
        $id = DB::selectOne("SELECT id FROM categorias WHERE nome = 'TESTE Cat'")->id;

        $this->post('/produtos/categorias', ['nome' => 'X'])->assertSessionHasErrors('nome');
        $this->post('/produtos/categorias', ['nome' => 'TESTE Cat'])->assertSessionHasErrors(['nome' => 'Já existe uma categoria com este nome.']);
        $outra = DB::selectOne("SELECT nome FROM categorias WHERE id <> ? ORDER BY nome LIMIT 1", [$id])->nome;
        $this->put("/produtos/categorias/$id", ['nome' => $outra])->assertSessionHasErrors("nome_$id");

        $this->put("/produtos/categorias/$id", ['nome' => 'TESTE Renomeada'])->assertRedirect(route('categorias.index'));
        $this->assertSame('TESTE Renomeada', DB::selectOne('SELECT nome FROM categorias WHERE id = ?', [$id])->nome);
        $this->delete("/produtos/categorias/$id")->assertRedirect(route('categorias.index'));
        $this->assertNull(DB::selectOne('SELECT id FROM categorias WHERE id = ?', [$id]));

        $comProdutos = DB::selectOne('SELECT categoria_id FROM produtos WHERE categoria_id IS NOT NULL LIMIT 1')->categoria_id;
        $this->delete("/produtos/categorias/$comProdutos")->assertRedirect();
        $this->assertNotNull(DB::selectOne('SELECT id FROM categorias WHERE id = ?', [$comProdutos]));
    }

    public function test_kit_soma_linhas_do_mesmo_produto_e_ignora_linhas_vazias(): void
    {
        $this->entrarComo();
        $bet = $this->produto('BET-400');
        $car = $this->produto('CAR-MAO');
        $this->post('/produtos/kits', ['codigo' => 'teste-kit', 'nome' => 'TESTE Kit', 'ativo' => '1',
                                       'produto_id' => [$bet, $car, $bet, ''], 'quantidade' => ['1', '2', '3', '1']])
            ->assertRedirect(route('kits.index'));
        $k = DB::selectOne("SELECT id, ativo FROM kits WHERE codigo = 'TESTE-KIT'");
        $this->assertTrue($k->ativo);
        $itens = DB::select('SELECT produto_id, quantidade FROM kit_itens WHERE kit_id = ? ORDER BY quantidade DESC', [$k->id]);
        $this->assertEquals([[$bet, 4], [$car, 2]], array_map(fn ($i) => [$i->produto_id, $i->quantidade], $itens));

        // Edição troca a composição inteira
        $this->put("/produtos/kits/{$k->id}", ['codigo' => 'TESTE-KIT', 'nome' => 'TESTE Kit', 'produto_id' => [$car], 'quantidade' => ['5']])
            ->assertRedirect(route('kits.index'));
        $this->assertEquals([[$car, 5]], array_map(fn ($i) => [$i->produto_id, $i->quantidade],
            DB::select('SELECT produto_id, quantidade FROM kit_itens WHERE kit_id = ?', [$k->id])));
        $this->assertFalse(DB::selectOne('SELECT ativo FROM kits WHERE id = ?', [$k->id])->ativo);

        $this->delete("/produtos/kits/{$k->id}")->assertRedirect(route('kits.index'));
        $this->assertNull(DB::selectOne('SELECT id FROM kits WHERE id = ?', [$k->id]));
        $this->assertNotNull(DB::selectOne('SELECT id FROM produtos WHERE id = ?', [$car]));
    }

    public function test_kit_recusa_sem_itens_quantidade_invalida_e_codigo_repetido(): void
    {
        $this->entrarComo();
        $bet = $this->produto('BET-400');
        $base = ['codigo' => 'TESTE-KIT', 'nome' => 'TESTE Kit'];
        $this->post('/produtos/kits', $base + ['produto_id' => [''], 'quantidade' => ['1']])
            ->assertSessionHasErrors(['itens' => 'Inclua pelo menos um item na composição do kit.']);
        $this->post('/produtos/kits', $base + ['produto_id' => [$bet], 'quantidade' => ['0']])->assertSessionHasErrors('itens');
        $this->post('/produtos/kits', $base + ['produto_id' => ['nao-e-uuid'], 'quantidade' => ['1']])->assertSessionHasErrors('itens');
        $this->post('/produtos/kits', ['codigo' => 'com espaço', 'nome' => 'X', 'produto_id' => [$bet], 'quantidade' => ['1']])
            ->assertSessionHasErrors(['codigo', 'nome']);

        $existente = DB::selectOne('SELECT codigo FROM kits LIMIT 1')->codigo;
        $this->post('/produtos/kits', ['codigo' => $existente, 'nome' => 'TESTE Kit', 'produto_id' => [$bet], 'quantidade' => ['1']])
            ->assertSessionHasErrors(['codigo' => 'Já existe um kit com este código.']);
        $this->assertNull(DB::selectOne("SELECT id FROM kits WHERE codigo = 'TESTE-KIT'"));
    }

    public function test_alfa_nao_usa_produto_nem_abre_kit_ou_categoria_da_beta(): void
    {
        $prodBeta = $this->lerComoAdmin("SELECT p.id FROM produtos p JOIN tenants t ON t.id = p.tenant_id WHERE t.slug = 'beta-eventos' LIMIT 1")[0]->id;
        $catBeta = $this->lerComoAdmin("SELECT c.id, c.nome FROM categorias c JOIN tenants t ON t.id = c.tenant_id WHERE t.slug = 'beta-eventos' LIMIT 1")[0] ?? null;
        $kitBeta = $this->lerComoAdmin("SELECT k.id FROM kits k JOIN tenants t ON t.id = k.tenant_id WHERE t.slug = 'beta-eventos' LIMIT 1")[0] ?? null;
        $this->entrarComo('alfa-locacoes');

        $this->post('/produtos/kits', ['codigo' => 'TESTE-KIT', 'nome' => 'TESTE Kit', 'produto_id' => [$prodBeta], 'quantidade' => ['1']])
            ->assertSessionHasErrors(['itens' => 'Produto não encontrado na composição do kit.']);
        $this->post('/produtos', ['codigo' => 'TST-LAR-01', 'descricao' => 'TESTE', 'qtd_total' => '1', 'valor_diaria' => '1',
                                  'categoria_id' => $catBeta?->id ?? '00000000-0000-0000-0000-000000000000'])->assertSessionHasErrors('categoria_id');

        if ($catBeta) {
            $this->put("/produtos/categorias/{$catBeta->id}", ['nome' => 'INVADIDO'])->assertNotFound();
            $this->delete("/produtos/categorias/{$catBeta->id}")->assertNotFound();
            $this->get('/produtos/categorias')->assertOk()->assertDontSee(route('categorias.update', $catBeta->id), false);
            $this->assertSame($catBeta->nome, $this->lerComoAdmin('SELECT nome FROM categorias WHERE id = ?', [$catBeta->id])[0]->nome);
        }
        if ($kitBeta) {
            $this->get("/produtos/kits/{$kitBeta->id}/editar")->assertNotFound();
            $this->delete("/produtos/kits/{$kitBeta->id}")->assertNotFound();
            $this->assertNotEmpty($this->lerComoAdmin('SELECT id FROM kits WHERE id = ?', [$kitBeta->id]));
        }
    }
}
