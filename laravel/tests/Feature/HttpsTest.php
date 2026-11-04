<?php

namespace Tests\Feature;

use Tests\TestCase;

/** HTTPS obrigatório em produção (LOCGESTOR_FORCAR_HTTPS): redirecionamento e HSTS. */
class HttpsTest extends TestCase
{
    public function test_desligado_no_ambiente_local_nao_redireciona(): void
    {
        config(['locgestor.forcar_https' => false]);

        $this->get('http://localhost/login')->assertOk();
    }

    public function test_ligado_manda_o_http_para_o_https_com_301(): void
    {
        config(['locgestor.forcar_https' => true]);

        $this->get('http://localhost/login')->assertStatus(301)->assertRedirect('https://localhost/login');
    }

    public function test_por_https_a_resposta_leva_o_cabecalho_hsts(): void
    {
        config(['locgestor.forcar_https' => true]);

        $this->get('https://localhost/login')->assertOk()
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }
}
