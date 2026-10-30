<?php

namespace Tests\Feature;

use App\Support\Relatorios;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Relatórios e exportação para o contador: regras de modulos/relatorios.php (período, CSV, impressão e só admin). */
class RelatoriosTest extends TestCase
{
    private const PERIODO = ['de' => '2026-01-01', 'ate' => '2026-10-30'];

    public function test_todos_os_relatorios_abrem_imprimem_e_exportam_csv(): void
    {
        $this->entrarComo();
        foreach (Relatorios::definicoes() as $chave => $rel) {
            $q = $rel['periodo'] ? self::PERIODO : [];
            $this->get("/relatorios/$chave?" . http_build_query($q))->assertOk()->assertSee($rel['titulo']);
            $csv = $this->get("/relatorios/$chave?" . http_build_query($q + ['csv' => 1]))->assertOk()
                ->assertHeader('Content-Type', 'text/csv; charset=utf-8');
            $this->assertStringStartsWith("\xEF\xBB\xBF", $csv->getContent(), "BOM no CSV de $chave");
            $this->get("/relatorios/$chave?" . http_build_query($q + ['imprimir' => 1]))->assertOk()->assertSee($rel['titulo']);
        }
        $this->get('/relatorios/nao_existe')->assertNotFound();
    }

    public function test_periodo_inverte_datas_trocadas_e_imprime_o_periodo(): void
    {
        $this->entrarComo();
        $this->get('/relatorios/periodo?de=2026-10-30&ate=2026-01-01&imprimir=1')
            ->assertOk()->assertSee('Período: 01/01/2026 a 30/10/2026');
        $this->get('/relatorios/periodo?de=lixo&ate=2026-13-45')->assertOk()->assertSee('name="de"', false);
    }

    public function test_contador_e_so_do_administrador(): void
    {
        $this->entrarComo();
        DB::insert("INSERT INTO usuarios (nome, login, senha_hash, perfil) VALUES ('Operador Teste', 'oper.rel', ?, 'operador')",
                   [password_hash('oper12345', PASSWORD_DEFAULT)]);
        $this->post('/sair');

        $this->entrarComo('alfa-locacoes', 'oper.rel', 'oper12345');
        $this->get('/relatorios')->assertOk()->assertDontSee('Exportação para o contador');
        $this->get('/relatorios/contador')->assertForbidden();
        $this->get('/relatorios/contador/baixar?mes=2026-10&arquivo=caixa_2026-10.csv')->assertForbidden();
    }

    public function test_contador_traz_os_lancamentos_do_mes_em_csv_e_zip(): void
    {
        $this->entrarComo();
        $mes = now()->format('Y-m');
        DB::insert("INSERT INTO caixa (data, tipo, categoria, descricao, valor, forma, usuario_id) VALUES (?, 'E', 'TESTE', 'TESTE contador', 99.90, 'PIX', ?)",
                   [now()->toDateString(), session('usuario.id')]);

        $this->get("/relatorios/contador?mes=$mes")->assertOk()->assertSee("caixa_$mes.csv");
        $csv = $this->get("/relatorios/contador/baixar?mes=$mes&arquivo=caixa_$mes.csv")->assertOk();
        $this->assertStringContainsString('TESTE contador', $csv->getContent());
        $this->assertStringContainsString('99,90', $csv->getContent(), 'valor no padrão brasileiro (vírgula decimal)');

        if (class_exists(\ZipArchive::class)) {
            $this->get("/relatorios/contador/baixar?mes=$mes&arquivo=zip")->assertOk()->assertHeader('Content-Type', 'application/zip');
        } else {
            $this->get("/relatorios/contador/baixar?mes=$mes&arquivo=zip")->assertNotFound();
        }
    }

    public function test_beta_nao_ve_lancamentos_da_alfa_na_exportacao(): void
    {
        $this->entrarComo();
        $mes = now()->format('Y-m');
        DB::insert("INSERT INTO caixa (data, tipo, categoria, descricao, valor, forma, usuario_id) VALUES (?, 'E', 'TESTE', 'TESTE isolamento', 15.00, 'PIX', ?)",
                   [now()->toDateString(), session('usuario.id')]);
        $this->post('/sair');

        $this->entrarComo('beta-eventos');
        $csv = $this->get("/relatorios/contador/baixar?mes=$mes&arquivo=caixa_$mes.csv")->assertOk();
        $this->assertStringNotContainsString('TESTE isolamento', $csv->getContent());
    }
}
