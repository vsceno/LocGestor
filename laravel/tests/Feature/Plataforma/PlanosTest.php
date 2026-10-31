<?php

namespace Tests\Feature\Plataforma;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PlataformaTestCase;

/** Planos: preço e limite. Mudar o nome acompanha as locadoras; plano com locadora não se exclui. */
class PlanosTest extends PlataformaTestCase
{
    public function test_cria_plano_com_preco_em_reais(): void
    {
        $this->entrarNaPlataforma();
        $nome = 'Plano ' . Str::random(8);
        $this->post(route('plataforma.planos.criar'), ['nome' => $nome, 'preco_mensal' => '149,90', 'limite_usuarios' => 4, 'ativo' => '1'])
            ->assertRedirect(route('plataforma.planos.index'));

        $p = DB::connection('plataforma')->selectOne('SELECT preco_mensal, limite_usuarios, ativo FROM planos WHERE nome = ?', [$nome]);
        $this->assertSame([149.9, 4, true], [(float) $p->preco_mensal, (int) $p->limite_usuarios, $p->ativo]);
    }

    public function test_nome_repetido_e_recusado(): void
    {
        $this->entrarNaPlataforma();
        $plano = $this->criarPlano();
        $this->post(route('plataforma.planos.criar'), ['nome' => $plano->nome, 'preco_mensal' => '10', 'limite_usuarios' => 2])
            ->assertSessionHas('flash', fn ($f) => str_contains($f[0]['msg'], 'Já existe um plano'));
    }

    public function test_mudar_o_nome_do_plano_acompanha_as_locadoras(): void
    {
        $this->entrarNaPlataforma();
        $plano = $this->criarPlano();
        $id = $this->criarLocadora(['plano' => $plano]);
        $novoNome = 'Renomeado ' . Str::random(6);

        $this->put(route('plataforma.planos.atualizar', $plano->id), ['nome' => $novoNome, 'preco_mensal' => '120', 'limite_usuarios' => 7, 'ativo' => '1'])
            ->assertRedirect(route('plataforma.planos.index'));

        $this->assertSame($novoNome, $this->locadoraNoBanco($id)->plano);
    }

    public function test_plano_com_locadora_nao_se_exclui(): void
    {
        $this->entrarNaPlataforma();
        $plano = $this->criarPlano();
        $this->criarLocadora(['plano' => $plano]);

        $this->delete(route('plataforma.planos.excluir', $plano->id))
            ->assertSessionHas('flash', fn ($f) => str_contains($f[0]['msg'], 'não pode ser excluído'));
        $this->assertNotNull(DB::connection('plataforma')->selectOne('SELECT id FROM planos WHERE id = ?', [$plano->id]));
    }

    public function test_plano_sem_locadora_se_exclui(): void
    {
        $this->entrarNaPlataforma();
        $plano = $this->criarPlano();

        $this->delete(route('plataforma.planos.excluir', $plano->id))
            ->assertSessionHas('flash', fn ($f) => $f[0]['tipo'] === 'ok');
        $this->assertNull(DB::connection('plataforma')->selectOne('SELECT id FROM planos WHERE id = ?', [$plano->id]));
    }
}
