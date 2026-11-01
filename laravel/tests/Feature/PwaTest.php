<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Instalação como aplicativo (PWA), como no sistema antigo: manifesto, ícones, sw.js e a página "sem conexão".
 * Os arquivos de public/ são servidos pelo Apache; aqui se confere o que está lá e o que as telas oferecem.
 */
class PwaTest extends TestCase
{
    public function test_manifesto_abre_o_painel_em_tela_cheia_com_icones_e_atalhos_que_existem(): void
    {
        $m = json_decode(file_get_contents(public_path('manifest.webmanifest')), true);
        $this->assertSame('painel', $m['start_url']);
        $this->assertSame('standalone', $m['display']);
        $this->assertCount(3, $m['icons']);
        foreach ($m['icons'] as $icone) {
            $this->assertFileExists(public_path($icone['src']));
        }

        $this->entrarComo();
        foreach ($m['shortcuts'] as $atalho) {
            $this->get('/' . $atalho['url'])->assertOk();
        }
    }

    public function test_sw_guarda_so_arquivos_estaticos_e_paginas_vao_sempre_a_rede(): void
    {
        $sw = file_get_contents(public_path('sw.js'));
        $this->assertStringContainsString("startsWith(base + 'build/')", $sw, 'compilados em cache (o nome muda a cada versão)');
        $this->assertStringContainsString("req.mode === 'navigate'", $sw, 'páginas sempre da rede');
        $this->assertStringContainsString('caches.match(OFFLINE)', $sw, 'sem conexão, a página de aviso');
        $this->assertFileExists(public_path('offline.html'));
        $this->assertStringContainsString('Sem conexão com a internet', file_get_contents(public_path('offline.html')));
    }

    public function test_login_e_telas_da_locadora_oferecem_a_instalacao(): void
    {
        $this->get('/login')->assertOk()
            ->assertSee('rel="manifest"', false)->assertSee('manifest.webmanifest', false)->assertSee('sw.js', false);
        $this->entrarComo()->get('/painel')->assertOk()
            ->assertSee('rel="manifest"', false)->assertSee('sw.js', false);
    }
}
