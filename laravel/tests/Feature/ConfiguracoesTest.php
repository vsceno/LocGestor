<?php

namespace Tests\Feature;

use App\Support\Arquivos;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Configurações: regras de empresa_salvar(), empresa_logo_salvar(), usuario_salvar() e senha_trocar() do sistema antigo. */
class ConfiguracoesTest extends TestCase
{
    /** Logomarcas gravadas pelo teste, apagadas no fim (os registros somem com a transação). */
    private array $arquivosDoTeste = [];

    /** Dados atuais da empresa, com o que o teste quer mudar. */
    private function dadosEmpresa(array $mudar = []): array
    {
        $a = DB::selectOne('SELECT * FROM empresa');
        return $mudar + [
            'razao_social' => $a->razao_social, 'nome_fantasia' => $a->nome_fantasia, 'cnpj' => $a->cnpj, 'ie' => $a->ie,
            'telefone' => $a->telefone, 'email' => $a->email, 'endereco' => $a->endereco, 'cidade' => $a->cidade,
            'uf' => $a->uf, 'cep' => $a->cep, 'termos_contrato' => $a->termos_contrato, 'obs_fatura' => $a->obs_fatura,
            'pix_chave' => '', 'pix_nome' => '', 'pix_cidade' => '',
        ];
    }

    public function test_operador_nao_edita_a_empresa(): void
    {
        $this->entrarComo();
        DB::insert("INSERT INTO usuarios (nome, login, senha_hash, perfil) VALUES ('Operador Teste', 'oper.config', ?, 'operador')",
                   [password_hash('oper12345', PASSWORD_DEFAULT)]);
        $this->post('/sair');

        $this->entrarComo('alfa-locacoes', 'oper.config', 'oper12345');
        $this->get('/configuracoes')->assertRedirect(route('config.senha'));
        $this->put('/configuracoes/empresa', $this->dadosEmpresa(['razao_social' => 'TESTE invasão']))->assertForbidden();
        $this->assertNotSame('TESTE invasão', DB::selectOne('SELECT razao_social FROM empresa')->razao_social);
    }

    public function test_empresa_valida_e_padroniza_a_chave_pix(): void
    {
        $this->entrarComo();
        $this->put('/configuracoes/empresa', $this->dadosEmpresa(['razao_social' => 'A', 'email' => 'nao-e-email']))
            ->assertSessionHasErrors('empresa');
        $this->assertStringContainsString('Informe a razão social.', session('errors')->first('empresa'));
        $this->assertStringContainsString('E-mail inválido.', session('errors')->first('empresa'));

        $this->put('/configuracoes/empresa', $this->dadosEmpresa(['pix_chave' => 'chave invalida']))->assertSessionHasErrors('empresa');
        $this->assertStringContainsString('Chave PIX inválida', session('errors')->first('empresa'));

        // CPF com pontuação: grava só os dígitos; nome e cidade vazios usam os da empresa
        $cpf = $this->cpfLivre();
        $this->put('/configuracoes/empresa', $this->dadosEmpresa(['pix_chave' => substr($cpf, 0, 3) . '.' . substr($cpf, 3, 3) . '.' . substr($cpf, 6, 3) . '-' . substr($cpf, 9)]))
            ->assertSessionHasNoErrors()->assertRedirect(route('config.index'));
        $e = DB::selectOne('SELECT pix_chave, pix_nome, pix_cidade FROM empresa');
        $this->assertSame([$cpf, true, true], [$e->pix_chave, $e->pix_nome !== '', $e->pix_cidade !== '']);

        // Celular sem o +55 ganha o código do país
        $this->put('/configuracoes/empresa', $this->dadosEmpresa(['pix_chave' => '(85) 99999-0000']))->assertSessionHasNoErrors();
        $this->assertSame('+5585999990000', DB::selectOne('SELECT pix_chave FROM empresa')->pix_chave);
    }

    public function test_logomarca_e_regravada_trocada_e_removida(): void
    {
        $this->entrarComo();
        try {
            $this->put('/configuracoes/empresa', $this->dadosEmpresa(['logo' => UploadedFile::fake()->create('logo.txt', 10, 'text/plain')]))
                ->assertSessionHasErrors('empresa');
            $this->assertStringContainsString('Arquivo não é uma imagem válida', session('errors')->first('empresa'));

            $this->put('/configuracoes/empresa', $this->dadosEmpresa(['logo' => UploadedFile::fake()->image('logo.png', 1200, 600)]))
                ->assertSessionHasNoErrors();
            $rel = DB::selectOne('SELECT logo_arquivo FROM empresa')->logo_arquivo;
            $this->arquivosDoTeste[] = $rel;
            $caminho = Arquivos::caminho($rel);
            $this->assertFileExists($caminho);
            $this->assertSame([800, 400], array_slice(getimagesize($caminho), 0, 2), 'reduzida para 800 px no maior lado');
            $this->get('/arquivos/logo')->assertOk()->assertHeader('Content-Type', 'image/png');

            $this->put('/configuracoes/empresa', $this->dadosEmpresa(['logo_remover' => '1']))->assertSessionHasNoErrors();
            $this->assertSame('', DB::selectOne('SELECT logo_arquivo FROM empresa')->logo_arquivo);
            $this->assertFileDoesNotExist($caminho, 'a logomarca antiga sai do disco depois de gravar');
        } finally {
            foreach ($this->arquivosDoTeste as $rel) {
                Arquivos::apagar($rel);
            }
        }
    }

    public function test_usuarios_criar_alterar_e_proteger_o_proprio_acesso(): void
    {
        $this->entrarComo();
        $this->post('/configuracoes/usuarios', ['nome' => 'TESTE novo', 'login' => 'teste novo', 'perfil' => 'operador', 'senha' => 'curta', 'ativo' => '1'])
            ->assertSessionHasErrors('usuario');
        $this->assertStringContainsString('Login inválido', session('errors')->first('usuario'));
        $this->assertStringContainsString('A senha deve ter pelo menos 8 caracteres.', session('errors')->first('usuario'));

        $this->post('/configuracoes/usuarios', ['nome' => 'TESTE novo', 'login' => 'teste.novo', 'perfil' => 'operador', 'senha' => 'senha12345', 'ativo' => '1'])
            ->assertSessionHasNoErrors()->assertRedirect(route('config.usuarios'));
        $u = DB::selectOne("SELECT id, trocar_senha FROM usuarios WHERE login = 'teste.novo'");
        $this->assertTrue((bool) $u->trocar_senha, 'quem recebe a senha do administrador troca no primeiro acesso');

        $this->put("/configuracoes/usuarios/{$u->id}", ['nome' => 'TESTE novo', 'login' => 'teste.novo', 'perfil' => 'admin', 'senha' => 'outra12345', 'ativo' => '1'])
            ->assertSessionHasNoErrors();
        $this->assertSame('admin', DB::selectOne('SELECT perfil FROM usuarios WHERE id = ?', [$u->id])->perfil);

        $this->post('/configuracoes/usuarios', ['nome' => 'TESTE repetido', 'login' => 'teste.novo', 'perfil' => 'operador', 'senha' => 'senha12345', 'ativo' => '1'])
            ->assertSessionHasErrors(['usuario' => 'Já existe um usuário com este login.']);

        $eu = session('usuario.id');
        $this->put("/configuracoes/usuarios/$eu", ['nome' => 'Administrador', 'login' => 'admin', 'perfil' => 'operador', 'ativo' => '1'])
            ->assertSessionHasErrors(['usuario' => 'Você não pode desativar nem rebaixar o seu próprio usuário.']);
    }

    public function test_troca_de_senha_confere_a_atual_e_a_nova(): void
    {
        $this->entrarComo();
        $this->put('/configuracoes/senha', ['senha_atual' => 'errada', 'senha_nova' => 'nova12345', 'senha_confirma' => 'nova12345'])
            ->assertSessionHasErrors(['senha' => 'A senha atual não confere.']);
        $this->put('/configuracoes/senha', ['senha_atual' => 'admin123', 'senha_nova' => 'curta', 'senha_confirma' => 'curta'])
            ->assertSessionHasErrors(['senha' => 'A nova senha deve ter pelo menos 8 caracteres.']);
        $this->put('/configuracoes/senha', ['senha_atual' => 'admin123', 'senha_nova' => 'nova12345', 'senha_confirma' => 'outra12345'])
            ->assertSessionHasErrors(['senha' => 'A confirmação não é igual à nova senha.']);
        $this->put('/configuracoes/senha', ['senha_atual' => 'admin123', 'senha_nova' => 'admin123', 'senha_confirma' => 'admin123'])
            ->assertSessionHasErrors(['senha' => 'A nova senha deve ser diferente da atual.']);

        $this->put('/configuracoes/senha', ['senha_atual' => 'admin123', 'senha_nova' => 'nova12345', 'senha_confirma' => 'nova12345'])
            ->assertRedirect(route('config.senha'));
        $this->post('/sair');
        $this->entrarComo('alfa-locacoes', 'admin', 'nova12345');
    }

    public function test_senha_temporaria_so_libera_a_troca_de_senha(): void
    {
        $this->entrarComo();
        DB::insert("INSERT INTO usuarios (nome, login, senha_hash, perfil, trocar_senha) VALUES ('Temporario', 'temp.config', ?, 'operador', true)",
                   [password_hash('temp12345', PASSWORD_DEFAULT)]);
        $this->post('/sair');

        $this->post('/login', ['empresa' => 'alfa-locacoes', 'login' => 'temp.config', 'senha' => 'temp12345']);
        $this->get('/painel')->assertRedirect(route('config.senha'));
        $this->get('/clientes')->assertRedirect(route('config.senha'));
        $this->get('/configuracoes/senha')->assertOk()->assertSee('senha temporária', false);
    }

    public function test_auditoria_mostra_quem_mudou_o_que_e_so_para_o_administrador(): void
    {
        $this->entrarComo();
        DB::insert("INSERT INTO usuarios (nome, login, senha_hash, perfil) VALUES ('TESTE auditoria', 'teste.aud', ?, 'operador')",
                   [password_hash('senha12345', PASSWORD_DEFAULT)]);
        $this->get('/configuracoes/auditoria?tabela=usuarios&acao=I')->assertOk()
            ->assertSee('TESTE auditoria')->assertSee('incluiu');
        $this->post('/sair');

        $this->entrarComo('alfa-locacoes', 'teste.aud', 'senha12345');
        $this->get('/configuracoes/auditoria')->assertForbidden();
    }

    public function test_beta_nao_ve_usuarios_nem_auditoria_da_alfa(): void
    {
        $this->entrarComo();
        DB::insert("INSERT INTO usuarios (nome, login, senha_hash, perfil) VALUES ('TESTE isolado', 'teste.iso', ?, 'operador')",
                   [password_hash('senha12345', PASSWORD_DEFAULT)]);
        $this->post('/sair');

        $this->entrarComo('beta-eventos');
        $this->get('/configuracoes/usuarios')->assertOk()->assertDontSee('teste.iso');
        $this->get('/configuracoes/auditoria?tabela=usuarios&acao=I&de=2000-01-01')->assertOk()->assertDontSee('TESTE isolado');
    }
}
