<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Uma locadora nunca vê nem mexe nos dados de outra (o RLS do banco garante; aqui pelas telas). */
class IsolamentoTest extends TestCase
{
    private function clienteDaBeta(): object
    {
        // Um cliente que só existe na Beta (nenhum da Alfa com o mesmo CPF)
        return $this->lerComoAdmin("SELECT c.id, c.nome, c.cpf_cnpj FROM clientes c JOIN tenants t ON t.id = c.tenant_id
                                     WHERE t.slug = 'beta-eventos' AND c.cpf_cnpj <> ''
                                       AND NOT EXISTS (SELECT 1 FROM clientes a JOIN tenants ta ON ta.id = a.tenant_id
                                                        WHERE ta.slug = 'alfa-locacoes' AND a.cpf_cnpj = c.cpf_cnpj)
                                     ORDER BY c.nome LIMIT 1")[0];
    }

    public function test_alfa_nao_abre_nem_altera_cliente_da_beta(): void
    {
        $b = $this->clienteDaBeta();
        $this->entrarComo('alfa-locacoes');

        $this->get("/clientes/{$b->id}")->assertNotFound();
        $this->get("/clientes/{$b->id}/editar")->assertNotFound();
        $this->put("/clientes/{$b->id}", ['nome' => 'INVADIDO', 'tipo' => 'PF', 'ativo' => '1'])->assertNotFound();
        $this->post("/clientes/{$b->id}/bloquear")->assertNotFound();
        $this->delete("/clientes/{$b->id}")->assertNotFound();

        // Nada mudou na Beta
        $depois = $this->lerComoAdmin('SELECT nome, bloqueado FROM clientes WHERE id = ?', [$b->id])[0];
        $this->assertSame($b->nome, $depois->nome);
        $this->assertFalse($depois->bloqueado);
    }

    public function test_lista_busca_e_paleta_nao_mostram_dados_da_beta(): void
    {
        $b = $this->clienteDaBeta();
        $this->entrarComo('alfa-locacoes');

        $this->post('/clientes/filtrar', ['situacao' => 'todos'])->assertRedirect(route('clientes.index'));
        $this->get('/clientes')->assertOk()->assertDontSee($b->nome);

        $this->postJson('/busca/rapida', ['q' => $b->cpf_cnpj])->assertOk()->assertExactJson(['grupos' => []]);
        $this->post('/busca', ['q' => $b->nome]);
        $this->get('/busca')->assertOk()->assertDontSee(route('clientes.show', $b->id), false);
    }

    public function test_id_malformado_da_404_sem_erro_de_banco(): void
    {
        $this->entrarComo();
        $this->get('/clientes/nao-e-um-uuid')->assertNotFound();
        $this->get("/clientes/00000000-0000-0000-0000-000000000000")->assertNotFound();
    }
}
