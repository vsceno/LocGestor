<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Busca geral e paleta de comandos (LGPD: termo por POST, nunca no endereço). */
class BuscaTest extends TestCase
{
    public function test_busca_por_cpf_com_um_resultado_abre_o_cliente_direto(): void
    {
        $this->entrarComo();
        $c = DB::selectOne("SELECT id, cpf_cnpj FROM clientes WHERE length(cpf_cnpj) = 11 ORDER BY nome LIMIT 1");
        $this->post('/busca', ['q' => doc_formatar($c->cpf_cnpj)])->assertRedirect(route('busca.index'));
        $this->get('/busca')->assertRedirect(route('clientes.show', $c->id));
    }

    public function test_paleta_responde_json_agrupado_e_so_por_post(): void
    {
        $this->entrarComo();
        $nome = DB::selectOne('SELECT nome FROM clientes ORDER BY nome LIMIT 1')->nome;
        $this->postJson('/busca/rapida', ['q' => mb_substr($nome, 0, 5)])
            ->assertOk()
            ->assertJsonPath('grupos.0.titulo', 'Clientes')
            ->assertJsonStructure(['grupos' => [['titulo', 'itens' => [['rotulo', 'detalhe', 'url', 'ico']]]]]);
        $this->get('/busca/rapida?q=' . urlencode($nome))->assertStatus(405);
        $this->postJson('/busca/rapida', ['q' => 'x'])->assertExactJson(['grupos' => []]);   // menos de 2 letras
    }

    public function test_filtro_de_clientes_guarda_na_sessao_e_responde_so_a_tabela_no_fetch(): void
    {
        $this->entrarComo();
        $nome = DB::selectOne('SELECT nome FROM clientes WHERE ativo ORDER BY nome LIMIT 1')->nome;
        $this->post('/clientes/filtrar', ['q' => $nome, 'situacao' => 'ativos'], ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->assertSee($nome)->assertDontSee('<html', false);
        $this->assertSame($nome, session('filtro_clientes.q'));
    }
}
