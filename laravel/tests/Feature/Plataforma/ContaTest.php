<?php

namespace Tests\Feature\Plataforma;

use Illuminate\Support\Facades\DB;
use Tests\PlataformaTestCase;

/** Minha senha: a senha atual confere, a nova tem 8 caracteres, é confirmada e é diferente da antiga. */
class ContaTest extends PlataformaTestCase
{
    private function hashAtual(): string
    {
        return DB::connection('plataforma')->selectOne("SELECT senha_hash FROM plataforma_usuarios WHERE login = 'suporte'")->senha_hash;
    }

    public function test_troca_a_senha_quando_a_atual_confere(): void
    {
        $this->entrarNaPlataforma();
        $this->definirSenhaSuporte('antiga-123');

        $this->put(route('plataforma.conta.senha'), ['senha_atual' => 'antiga-123', 'senha_nova' => 'nova-12345', 'senha_confirma' => 'nova-12345'])
            ->assertRedirect(route('plataforma.conta'))
            ->assertSessionHas('flash', fn ($f) => $f[0]['tipo'] === 'ok');
        $this->assertTrue(password_verify('nova-12345', $this->hashAtual()));
    }

    public function test_senha_atual_errada_nao_troca(): void
    {
        $this->entrarNaPlataforma();
        $this->definirSenhaSuporte('antiga-123');

        $this->put(route('plataforma.conta.senha'), ['senha_atual' => 'errada', 'senha_nova' => 'nova-12345', 'senha_confirma' => 'nova-12345'])
            ->assertSessionHas('flash', fn ($f) => str_contains($f[0]['msg'], 'não confere'));
        $this->assertTrue(password_verify('antiga-123', $this->hashAtual()));
    }

    public function test_nova_senha_curta_diferente_ou_nao_confirmada_nao_troca(): void
    {
        $this->entrarNaPlataforma();
        $this->definirSenhaSuporte('antiga-123');

        $this->put(route('plataforma.conta.senha'), ['senha_atual' => 'antiga-123', 'senha_nova' => 'curta', 'senha_confirma' => 'curta'])
            ->assertSessionHas('flash', fn ($f) => str_contains($f[0]['msg'], '8 caracteres'));
        $this->put(route('plataforma.conta.senha'), ['senha_atual' => 'antiga-123', 'senha_nova' => 'nova-12345', 'senha_confirma' => 'outra-12345'])
            ->assertSessionHas('flash', fn ($f) => str_contains($f[0]['msg'], 'não é igual'));
        $this->put(route('plataforma.conta.senha'), ['senha_atual' => 'antiga-123', 'senha_nova' => 'antiga-123', 'senha_confirma' => 'antiga-123'])
            ->assertSessionHas('flash', fn ($f) => str_contains($f[0]['msg'], 'diferente da atual'));
        $this->assertTrue(password_verify('antiga-123', $this->hashAtual()));
    }
}
