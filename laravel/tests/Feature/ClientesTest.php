<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Cadastro de clientes: as mesmas regras de cliente_salvar() do sistema antigo. */
class ClientesTest extends TestCase
{
    private function dados(array $mudar = []): array
    {
        return $mudar + ['tipo' => 'PF', 'nome' => 'TESTE Laravel', 'cpf_cnpj' => '', 'celular' => '85988887777',
                         'cep' => '60165121', 'uf' => 'CE', 'ativo' => '1', '_enviado' => '1'];
    }

    public function test_cadastra_cliente_e_grava_formatado(): void
    {
        $this->entrarComo();
        $cpf = $this->cpfLivre();
        $r = $this->post('/clientes', $this->dados(['cpf_cnpj' => doc_formatar($cpf)]));

        $c = DB::selectOne('SELECT * FROM clientes WHERE cpf_cnpj = ?', [$cpf]);   // RLS: só a Alfa
        $this->assertNotNull($c);
        $r->assertRedirect(route('clientes.show', $c->id));
        $this->assertSame('(85) 98888-7777', $c->celular);
        $this->assertSame('60165-121', $c->cep);
        $this->assertSame($this->idLocadora('alfa-locacoes'), $c->tenant_id);
        $this->assertSame('Cliente cadastrado.', session('flash')[0]['msg']);
    }

    public function test_recusa_nome_curto_cpf_invalido_email_cep_e_uf(): void
    {
        $this->entrarComo();
        $this->post('/clientes', $this->dados(['nome' => 'AB', 'cpf_cnpj' => '529.982.247-26', 'email' => 'x@', 'cep' => '123', 'uf' => 'ZZ']))
            ->assertSessionHasErrors(['nome', 'cpf_cnpj', 'email', 'cep', 'uf']);
        $this->post('/clientes', $this->dados(['tipo' => 'PJ', 'cpf_cnpj' => '11.111.111/1111-11']))->assertSessionHasErrors(['cpf_cnpj' => 'CNPJ inválido.']);
        $this->post('/clientes', $this->dados(['tipo' => 'PF', 'cpf_cnpj' => '111.111.111-11']))->assertSessionHasErrors('cpf_cnpj');
    }

    public function test_cpf_repetido_na_mesma_locadora_e_recusado_mas_aceito_em_outra(): void
    {
        $existente = DB::connection('teste_admin')->selectOne("SELECT c.cpf_cnpj, c.nome FROM clientes c JOIN tenants t ON t.id = c.tenant_id
                                                                 WHERE t.slug = 'alfa-locacoes' AND length(c.cpf_cnpj) = 11 ORDER BY c.nome LIMIT 1");
        $this->entrarComo('alfa-locacoes');
        $this->post('/clientes', $this->dados(['cpf_cnpj' => $existente->cpf_cnpj]))
            ->assertSessionHasErrors(['cpf_cnpj' => "Já existe um cliente com este CPF: {$existente->nome}."]);

        $this->post('/sair');
        $this->entrarComo('beta-eventos');
        $this->post('/clientes', $this->dados(['nome' => 'TESTE Beta mesmo CPF', 'cpf_cnpj' => $existente->cpf_cnpj]))->assertSessionHasNoErrors();
    }

    public function test_edita_bloqueia_e_exclui_cliente_sem_locacoes(): void
    {
        $this->entrarComo();
        $this->post('/clientes', $this->dados());
        $id = DB::selectOne("SELECT id FROM clientes WHERE nome = 'TESTE Laravel'")->id;

        $this->put("/clientes/$id", $this->dados(['nome' => 'TESTE Laravel Ltda', 'tipo' => 'PJ', 'ativo' => '1']))
            ->assertRedirect(route('clientes.show', $id));
        $this->assertSame('PJ', DB::selectOne('SELECT tipo FROM clientes WHERE id = ?', [$id])->tipo);

        $this->post("/clientes/$id/bloquear");
        $this->assertTrue(DB::selectOne('SELECT bloqueado FROM clientes WHERE id = ?', [$id])->bloqueado);
        $this->post("/clientes/$id/bloquear");
        $this->assertFalse(DB::selectOne('SELECT bloqueado FROM clientes WHERE id = ?', [$id])->bloqueado);

        $this->delete("/clientes/$id")->assertRedirect(route('clientes.index'));
        $this->assertNull(DB::selectOne('SELECT id FROM clientes WHERE id = ?', [$id]));
    }

    public function test_cliente_com_locacoes_nao_e_excluido(): void
    {
        $this->entrarComo();
        $id = DB::selectOne('SELECT cliente_id FROM locacoes LIMIT 1')->cliente_id;
        $this->delete("/clientes/$id")->assertRedirect();
        $this->assertStringContainsString('não pode ser excluído', session('flash')[0]['msg']);
        $this->assertNotNull(DB::selectOne('SELECT id FROM clientes WHERE id = ?', [$id]));
    }

    public function test_auditoria_registra_quem_cadastrou(): void
    {
        $this->entrarComo();
        $this->post('/clientes', $this->dados(['nome' => 'TESTE Auditoria']));
        $a = DB::selectOne("SELECT usuario, acao FROM auditoria WHERE tabela = 'clientes' AND rotulo = 'TESTE Auditoria' ORDER BY quando DESC LIMIT 1");
        $this->assertSame('admin', $a->usuario);
        $this->assertSame('I', $a->acao);
    }
}
