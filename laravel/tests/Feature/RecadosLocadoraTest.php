<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Recados do painel da plataforma na tela da locadora: só os ativos e no prazo, e no máximo 3 (como no sistema antigo).
 * O painel grava por outra conexão, então o recado de teste é gravado já confirmado pela conexão de superusuário e
 * apagado depois que a transação do teste é desfeita.
 */
class RecadosLocadoraTest extends TestCase
{
    /** Recados gravados confirmados por estes testes. */
    private array $recadosGravados = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Roda depois do rollback da transação do teste
        $this->beforeApplicationDestroyed(function () {
            foreach ($this->recadosGravados as $id) {
                DB::connection('teste_admin')->delete('DELETE FROM recados WHERE id = ?', [$id]);
            }
        });
    }

    /** Recado como o painel grava. Devolve o id. */
    private function recado(string $titulo, int $diasDeValidade, bool $ativo = true): string
    {
        $id = DB::connection('teste_admin')->selectOne(
            'INSERT INTO recados (tipo, titulo, texto, expira_em, ativo, usuario) VALUES (?, ?, ?, ?, ?, ?) RETURNING id',
            ['aviso', $titulo, 'Texto do recado de teste.', now()->addDays($diasDeValidade)->toDateString(), $ativo ? 'true' : 'false', 'teste']
        )->id;
        $this->recadosGravados[] = $id;
        return $id;
    }

    public function test_mostra_so_os_recados_ativos_e_no_prazo_na_tela_da_locadora(): void
    {
        $this->recado('Recado TESTE vigente', 3);
        $this->recado('Recado TESTE vencido', -1);
        $this->recado('Recado TESTE desativado', 3, false);

        $this->entrarComo()->get(route('painel'))->assertOk()
            ->assertSee('Recado TESTE vigente')
            ->assertDontSee('Recado TESTE vencido')
            ->assertDontSee('Recado TESTE desativado');
    }
}
