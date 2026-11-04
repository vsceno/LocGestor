<?php

namespace Tests\Feature\Plataforma;

use App\Support\Configuracoes;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\PlataformaTestCase;

/** Tela Configurações da LocGestor: acesso, validação de cada seção, logomarca e trava do cadastro público. */
class ConfiguracoesTest extends PlataformaTestCase
{
    private function linha(): object
    {
        return DB::connection('plataforma')->selectOne('SELECT * FROM plataforma_configuracoes WHERE id = ?', [Configuracoes::ID]);
    }

    public function test_so_a_plataforma_abre_a_tela(): void
    {
        $this->get(route('plataforma.configuracoes'))->assertRedirect(route('plataforma.login'));

        $this->entrarNaPlataforma();
        $this->get(route('plataforma.configuracoes'))->assertOk()
            ->assertSee('Dados da empresa')->assertSee('Identidade visual')->assertSee('Documentos')
            ->assertSee('Cadastro público')->assertSee('Suporte')->assertSee('Integrações (somente leitura)');
    }

    public function test_dados_da_empresa_validam_o_cnpj_e_gravam_so_digitos(): void
    {
        $this->entrarNaPlataforma();

        $this->put(route('plataforma.configuracoes.empresa'), ['emitente_razao' => '', 'emitente_cnpj' => ''])
            ->assertSessionHasErrors(['emitente_razao' => 'Preencha este campo.'], null, 'empresa');

        $cnpjAntes = $this->linha()->emitente_cnpj;
        $this->put(route('plataforma.configuracoes.empresa'), ['emitente_razao' => 'LocGestor Ltda', 'emitente_cnpj' => '11.222.333/0001-00'])
            ->assertSessionHasErrors(['emitente_cnpj' => 'CNPJ inválido. Confira os 14 dígitos.'], null, 'empresa');
        $this->assertSame($cnpjAntes, $this->linha()->emitente_cnpj, 'CNPJ inválido não é gravado');

        $this->put(route('plataforma.configuracoes.empresa'), [
            'emitente_razao' => 'LocGestor Sistemas Ltda', 'emitente_cnpj' => '11.444.777/0001-61', 'emitente_endereco' => 'Rua Exemplo, 100',
        ])->assertSessionHasNoErrors();
        $this->assertSame('11444777000161', $this->linha()->emitente_cnpj);
        $this->assertTrue(Configuracoes::emitenteCompleto());
    }

    public function test_documentos_so_aceitam_endereco_https(): void
    {
        $this->entrarNaPlataforma();

        foreach (['http://termos.exemplo.com', 'javascript:alert(1)', 'termos.exemplo.com'] as $ruim) {
            $this->put(route('plataforma.configuracoes.documentos'), ['termos_url' => $ruim])
                ->assertSessionHasErrors('termos_url', null, 'documentos');
        }
        $this->put(route('plataforma.configuracoes.documentos'), ['termos_url' => 'https://termos.exemplo.com/uso'])
            ->assertSessionHasNoErrors();
        $this->assertSame('https://termos.exemplo.com/uso', $this->linha()->termos_url);
    }

    public function test_cadastro_publico_nao_abre_sem_termos_publicados(): void
    {
        $this->entrarNaPlataforma();
        $this->definirTermos('');
        DB::connection('plataforma')->update('UPDATE plataforma_configuracoes SET cadastro_publico = false WHERE id = ?', [Configuracoes::ID]);

        $this->put(route('plataforma.configuracoes.cadastro'), ['cadastro_publico' => '1', 'cadastro_dias_teste' => 14])
            ->assertSessionHasErrors('cadastro_publico', null, 'cadastro');
        $this->assertFalse((bool) $this->linha()->cadastro_publico, 'a chave não liga sem os termos');

        $this->definirTermos('https://termos.exemplo.com/uso');
        $this->put(route('plataforma.configuracoes.cadastro'), ['cadastro_publico' => '1', 'cadastro_dias_teste' => 14])
            ->assertSessionHasNoErrors();
        $this->assertTrue((bool) $this->linha()->cadastro_publico);
    }

    public function test_dias_de_teste_ficam_entre_1_e_90(): void
    {
        $this->entrarNaPlataforma();
        foreach ([0, 91] as $ruim) {
            $this->put(route('plataforma.configuracoes.cadastro'), ['cadastro_dias_teste' => $ruim])
                ->assertSessionHasErrors(['cadastro_dias_teste' => 'Informe um valor entre 1 e 90.'], null, 'cadastro');
        }
    }

    public function test_logomarca_e_reprocessada_em_png_pequeno(): void
    {
        $this->entrarNaPlataforma();

        $this->put(route('plataforma.configuracoes.identidade'), [
            'nome_sistema' => 'Gestor Rosa',
            'logo' => UploadedFile::fake()->image('logo.png', 1200, 600),
        ])->assertSessionHasNoErrors();

        $caminho = Configuracoes::logoCaminho();
        $this->assertFileExists($caminho);
        [$largura, $altura, $tipo] = getimagesize($caminho);
        $this->assertSame([512, 256, IMAGETYPE_PNG], [$largura, $altura, $tipo], 'redesenhada em PNG de até 512 px');
        $this->assertSame('Gestor Rosa', $this->linha()->nome_sistema);
        $this->assertNotSame('', $this->linha()->logo_versao);
    }

    public function test_arquivo_que_nao_e_imagem_e_recusado(): void
    {
        $this->entrarNaPlataforma();

        $this->put(route('plataforma.configuracoes.identidade'), [
            'nome_sistema' => 'LocGestor',
            'logo' => UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml'),
        ])->assertSessionHasErrors('logo', null, 'identidade');
        $this->assertFileDoesNotExist(Configuracoes::logoCaminho());
    }

    public function test_logomarca_publica_cai_no_padrao_quando_nao_ha_envio(): void
    {
        $this->get(route('marca.logo'))->assertOk()->assertHeader('Content-Type', 'image/png');
    }

    public function test_alteracoes_ficam_na_auditoria(): void
    {
        $this->entrarNaPlataforma();
        $antes = $this->auditoriasDaConfiguracao();
        $this->put(route('plataforma.configuracoes.suporte'), ['suporte_contato' => 'suporte@exemplo.com']);

        $this->assertSame($antes + 1, $this->auditoriasDaConfiguracao());
    }

    /** Quantas alterações da tela já estão na auditoria (a base de desenvolvimento acumula as suas). */
    private function auditoriasDaConfiguracao(): int
    {
        return (int) DB::connection('plataforma')->selectOne(
            "SELECT count(*) AS n FROM auditoria_plataforma WHERE tabela = 'plataforma_configuracoes' AND acao = 'U'"
        )->n;
    }

    /** Grava o endereço dos termos pela conexão da plataforma, dentro da transação do teste. */
    private function definirTermos(string $url): void
    {
        DB::connection('plataforma')->update('UPDATE plataforma_configuracoes SET termos_url = ? WHERE id = ?', [$url, Configuracoes::ID]);
    }
}
