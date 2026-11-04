<?php

namespace Tests\Feature;

use App\Support\Identidade;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Identidade visual da locadora: padrão neutro, cores e fontes validadas (contraste), logotipo quadrado e impressão. */
class IdentidadeTest extends TestCase
{
    /** Cores que passam em todas as regras de contraste (teal-700 sobre branco). */
    private function dados(array $mudar = []): array
    {
        return $mudar + [
            'cor_principal' => '#0F766E', 'cor_destaque' => '#F59E0B', 'cor_texto_escuro' => '#1F2937',
            'cor_texto_claro' => '#FFFFFF', 'cor_fundo' => '#FFFFFF', 'fonte_titulos' => 'sistema', 'fonte_textos' => 'inter',
        ];
    }

    /** Coloca a identidade da Alfa no padrão neutro (só dentro da transação do teste). */
    private function semIdentidade(): void
    {
        DB::update("UPDATE empresa SET cor_principal = '', cor_destaque = '', cor_texto_escuro = '', cor_texto_claro = '',
                                       cor_fundo = '', fonte_titulos = '', fonte_textos = '', logo_quadrada_arquivo = ''");
    }

    private function identidadeDaAlfa(): object
    {
        return DB::selectOne('SELECT * FROM empresa');
    }

    public function test_sem_configuracao_a_tela_usa_o_padrao_neutro(): void
    {
        $this->entrarComo();
        $this->semIdentidade();

        $this->get('/painel')->assertOk()
            ->assertSee('--color-marca-600: #2563EB', false)
            ->assertSee('--identidade-fundo: #F3F4F6', false)
            ->assertSee('--identidade-destaque: #10B981', false)
            ->assertSee('Sistema de Gestão', false);
    }

    public function test_a_identidade_salva_aparece_nas_telas_da_locadora(): void
    {
        $this->entrarComo();
        $this->put('/configuracoes/identidade', $this->dados())
            ->assertSessionHasNoErrors()->assertRedirect(route('config.identidade'));

        $e = $this->identidadeDaAlfa();
        $this->assertSame(['#0F766E', 'sistema'], [$e->cor_principal, $e->fonte_titulos]);
        $this->get('/painel')->assertOk()
            ->assertSee('--color-marca-600: #0F766E', false)
            ->assertSee('--identidade-fundo: #FFFFFF', false)
            ->assertSee('--identidade-fonte-titulos: ui-sans-serif', false);
    }

    public function test_outra_locadora_continua_com_o_seu_e_nao_com_a_da_alfa(): void
    {
        $this->entrarComo();
        $this->put('/configuracoes/identidade', $this->dados());
        $this->post('/sair');

        $this->entrarComo('beta-eventos');
        $this->get('/painel')->assertOk()->assertDontSee('--color-marca-600: #0F766E', false);
    }

    public function test_contraste_insuficiente_nao_grava(): void
    {
        $this->entrarComo();
        $this->semIdentidade();

        // Texto claro (branco) sobre amarelo: quase invisível nos botões
        $this->put('/configuracoes/identidade', $this->dados(['cor_principal' => '#FACC15']))
            ->assertSessionHasErrors('identidade');
        $this->assertStringContainsString('nos botões', session('errors')->first('identidade'));
        $this->assertSame('', $this->identidadeDaAlfa()->cor_principal, 'nada é gravado quando o contraste falha');
    }

    public function test_cor_e_fonte_fora_do_formato_sao_recusadas(): void
    {
        $this->entrarComo();
        $this->semIdentidade();

        $this->put('/configuracoes/identidade', $this->dados(['cor_principal' => 'azul']))->assertSessionHasErrors('identidade');
        $this->assertStringContainsString('Cor principal inválida.', session('errors')->first('identidade'));

        $this->put('/configuracoes/identidade', $this->dados(['fonte_textos' => 'comic-sans']))->assertSessionHasErrors('identidade');
        $this->assertStringContainsString('Escolha uma das fontes', session('errors')->first('identidade'));
        $this->assertSame('', $this->identidadeDaAlfa()->cor_principal);
    }

    public function test_operador_nao_altera_a_identidade(): void
    {
        $this->entrarComo();
        // Compara com o valor que já estava gravado (a base de demonstração pode ter cor própria)
        $antes = $this->identidadeDaAlfa()->cor_principal;
        DB::insert("INSERT INTO usuarios (nome, login, senha_hash, perfil) VALUES ('Operador Identidade', 'oper.identidade', ?, 'operador')",
            [password_hash('oper12345', PASSWORD_DEFAULT)]);
        $this->post('/sair');

        $this->entrarComo('alfa-locacoes', 'oper.identidade', 'oper12345');
        $this->get('/configuracoes/identidade')->assertRedirect(route('config.senha'));
        $this->put('/configuracoes/identidade', $this->dados())->assertForbidden();
        $this->post('/sair');
        $this->entrarComo();
        $this->assertSame($antes, $this->identidadeDaAlfa()->cor_principal);
    }

    public function test_voltar_ao_padrao_limpa_cores_e_fontes(): void
    {
        $this->entrarComo();
        $this->put('/configuracoes/identidade', $this->dados());

        $this->put('/configuracoes/identidade', ['restaurar_padrao' => '1'])->assertRedirect(route('config.identidade'));
        $this->assertSame('', $this->identidadeDaAlfa()->cor_principal);
        $this->get('/painel')->assertSee('--color-marca-600: #2563EB', false);
    }

    public function test_logotipo_quadrado_e_servido_so_para_a_propria_locadora(): void
    {
        $this->entrarComo();
        $this->semIdentidade();
        $this->put('/configuracoes/identidade', $this->dados() + ['logo_quadrada' => UploadedFile::fake()->image('quadrada.png', 300, 300)])
            ->assertSessionHasNoErrors();

        $this->get('/arquivos/logo-quadrada')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get('/painel')->assertSee('/arquivos/logo-quadrada', false);

        $this->post('/sair');
        $this->entrarComo('beta-eventos');
        $this->get('/arquivos/logo-quadrada')->assertNotFound();
    }

    public function test_logotipo_quadrado_pode_ser_removido(): void
    {
        $this->entrarComo();
        $this->semIdentidade();
        $this->put('/configuracoes/identidade', $this->dados() + ['logo_quadrada' => UploadedFile::fake()->image('quadrada.png', 200, 200)]);

        $this->put('/configuracoes/identidade', $this->dados() + ['logo_quadrada_remover' => '1'])->assertSessionHasNoErrors();
        $this->assertSame('', $this->identidadeDaAlfa()->logo_quadrada_arquivo);
        $this->get('/arquivos/logo-quadrada')->assertNotFound();
    }

    public function test_arquivo_que_nao_e_imagem_e_recusado_sem_gravar(): void
    {
        $this->entrarComo();
        $this->semIdentidade();

        $this->put('/configuracoes/identidade', $this->dados() + ['logo_quadrada' => UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml')])
            ->assertSessionHasErrors('identidade');
        $this->assertSame('', $this->identidadeDaAlfa()->logo_quadrada_arquivo);
    }

    public function test_a_folha_de_impressao_leva_a_cor_principal_e_o_texto(): void
    {
        $this->entrarComo();
        $this->semIdentidade();
        $this->put('/configuracoes/identidade', $this->dados());
        $this->post('/recibos', ['recebido_de' => 'TESTE Pagador', 'documento' => '529.982.247-25', 'valor' => '150,50',
            'forma' => 'PIX', 'referente' => 'TESTE identidade', 'locacao_id' => '']);
        $r = DB::selectOne("SELECT id FROM recibos WHERE referente = 'TESTE identidade'");

        $this->get("/recibos/{$r->id}/imprimir")->assertOk()
            ->assertSee('--laranja: #0F766E', false)
            ->assertSee('--texto: #1F2937', false)
            ->assertDontSee('--identidade-fundo', false);
    }

    public function test_padrao_neutro_passa_nas_regras_de_contraste(): void
    {
        [$dados, $erros] = Identidade::validar([
            'cor_principal' => '#2563EB', 'cor_destaque' => '#10B981', 'cor_texto_escuro' => '#374151',
            'cor_texto_claro' => '#FFFFFF', 'cor_fundo' => '#F3F4F6', 'fonte_titulos' => 'inter', 'fonte_textos' => 'inter',
        ]);
        $this->assertSame([], $erros);
        $this->assertSame('#2563EB', $dados['cor_principal']);
    }

    public function test_a_cor_principal_fica_exata_no_tom_600_da_escala(): void
    {
        $tons = Identidade::escala('#2563EB');

        $this->assertSame('#2563EB', $tons[600]);
        $this->assertGreaterThan(hexdec('2563EB'), hexdec(substr($tons[50], 1)), 'o tom 50 é mais claro que a cor');
        $this->assertLessThan(hexdec('2563EB'), hexdec(substr($tons[900], 1)), 'o tom 900 é mais escuro que a cor');
    }

    public function test_o_texto_do_botão_de_destaque_acompanha_a_cor(): void
    {
        $this->assertSame('#111827', Identidade::textoSobre('#10B981'), 'verde claro pede texto escuro');
        $this->assertSame('#FFFFFF', Identidade::textoSobre('#1E3A8A'), 'azul escuro pede texto branco');
    }
}
