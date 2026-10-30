<?php

namespace Tests\Feature;

use App\Support\Arquivos;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Vistoria (checklist, fotos e assinatura) e a entrega dos arquivos: regras de vistoria_registrar() do sistema antigo. */
class VistoriasTest extends TestCase
{
    private const OBS = 'TESTE vistoria';

    /** Caminhos gravados pelo teste, guardados enquanto o login ainda é o da locadora dona (o RLS esconde o resto). */
    private array $arquivosDoTeste = [];

    private function registrarArquivos(string $vistoriaId): void
    {
        foreach (DB::select('SELECT arquivo AS a FROM vistoria_fotos WHERE vistoria_id = ?', [$vistoriaId]) as $f) {
            $this->arquivosDoTeste[] = $f->a;
        }
        foreach (DB::select("SELECT assinatura_arquivo AS a FROM vistorias WHERE id = ? AND assinatura_arquivo <> ''", [$vistoriaId]) as $v) {
            $this->arquivosDoTeste[] = $v->a;
        }
    }

    /** Locação em aberto sem vistoria de retirada, ou null se a demonstração não tiver. */
    private function locacaoSemRetirada(): ?object
    {
        return DB::selectOne("SELECT l.id, l.numero, l.cliente_id FROM locacoes l
                               WHERE l.status = 'aberta' AND NOT EXISTS (SELECT 1 FROM vistorias v WHERE v.locacao_id = l.id AND v.tipo = 'retirada')
                               ORDER BY l.numero LIMIT 1");
    }

    /** Assinatura como o navegador envia (PNG em data URL). $branco: sem nenhum traço. */
    private function assinatura(bool $branco = false): string
    {
        $im = imagecreatetruecolor(400, 120);
        imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
        if (!$branco) {
            $preto = imagecolorallocate($im, 0, 0, 0);
            for ($x = 20; $x < 380; $x += 2) {
                imagefilledellipse($im, $x, 60 + (int) (30 * sin($x / 20)), 3, 3, $preto);
            }
        }
        ob_start();
        imagepng($im);
        return 'data:image/png;base64,' . base64_encode((string) ob_get_clean());
    }

    /** Dados mínimos válidos de uma retirada: todos os itens avaliados. */
    private function dados(object $loc, array $mudar = []): array
    {
        $estados = [];
        foreach (DB::select('SELECT id FROM locacao_itens WHERE locacao_id = ?', [$loc->id]) as $it) {
            $estados[$it->id] = 'ok';
        }
        return $mudar + ['tipo' => 'retirada', 'estado' => $estados, 'obs' => self::OBS, 'assinante_nome' => 'TESTE Assinante', 'assinatura' => ''];
    }

    /** Apaga os arquivos gravados pelo teste (os registros somem com a transação do teste). */
    private function apagarArquivosDoTeste(): void
    {
        foreach ($this->arquivosDoTeste as $rel) {
            Arquivos::apagar($rel);
        }
        $this->arquivosDoTeste = [];
    }

    public function test_retirada_com_checklist_foto_e_assinatura(): void
    {
        $this->entrarComo();
        $loc = $this->locacaoSemRetirada();
        if ($loc === null) {
            $this->markTestSkipped('Sem locação em aberto sem retirada nos dados de demonstração.');
        }
        try {
            $this->get("/locacoes/{$loc->numero}/vistoria?tipo=retirada")->assertOk()->assertSee('Estado dos equipamentos', false);

            $primeiro = DB::selectOne('SELECT id FROM locacao_itens WHERE locacao_id = ? LIMIT 1', [$loc->id])->id;
            $this->post("/locacoes/{$loc->numero}/vistoria", $this->dados($loc, [
                'obs_item' => [$primeiro => 'TESTE risco'],
                'assinatura' => $this->assinatura(),
                'fotos' => [UploadedFile::fake()->image('foto.jpg', 2000, 1000)],
            ]))->assertSessionHasNoErrors()->assertRedirect(route('locacoes.show', $loc->numero));

            $v = DB::selectOne("SELECT v.id, v.assinatura_arquivo, v.assinado_em FROM vistorias v WHERE v.locacao_id = ? AND v.tipo = 'retirada'", [$loc->id]);
            $this->registrarArquivos($v->id);
            $this->assertNotNull($v);
            $this->assertNotSame('', $v->assinatura_arquivo);
            $this->assertNotNull($v->assinado_em);
            $this->assertSame(DB::selectOne('SELECT count(*) AS n FROM locacao_itens WHERE locacao_id = ?', [$loc->id])->n,
                              DB::selectOne('SELECT count(*) AS n FROM vistoria_itens WHERE vistoria_id = ?', [$v->id])->n);

            // Foto reduzida para no máximo 1600 px e regravada como JPEG (sem os metadados da origem)
            $foto = DB::selectOne('SELECT id, arquivo, largura, altura FROM vistoria_fotos WHERE vistoria_id = ?', [$v->id]);
            $this->assertSame([1600, 800], [$foto->largura, $foto->altura]);
            $caminho = Arquivos::caminho($foto->arquivo);
            $this->assertNotNull($caminho);
            $this->assertFileExists($caminho);
            $this->assertSame("\xFF\xD8", substr((string) file_get_contents($caminho), 0, 2));

            // Impressão e entrega dos arquivos (fotos e assinatura só saem para a locadora dona)
            $this->get("/vistorias/{$v->id}")->assertOk()->assertSee('Estado dos equipamentos', false)->assertSee('Assinado eletronicamente', false);
            $this->get("/arquivos/foto/{$foto->id}")->assertOk()->assertHeader('Content-Type', 'image/jpeg');
            $this->get("/arquivos/assinatura/{$v->id}")->assertOk()->assertHeader('Content-Type', 'image/png');
        } finally {
            $this->apagarArquivosDoTeste();
        }
    }

    public function test_recusas_nao_gravam_nada_e_apagam_o_que_ja_foi_escrito(): void
    {
        $this->entrarComo();
        $loc = $this->locacaoSemRetirada();
        if ($loc === null) {
            $this->markTestSkipped('Sem locação em aberto sem retirada nos dados de demonstração.');
        }
        try {
            $antes = (int) DB::selectOne('SELECT count(*) AS n FROM vistorias')->n;
            $casos = [
                'Informe o estado de' => ['estado' => []],
                'A assinatura está em branco' => ['assinatura' => $this->assinatura(true)],
                'Informe o nome de quem assinou' => ['assinatura' => $this->assinatura(), 'assinante_nome' => 'Jo'],
                'Assinatura inválida' => ['assinatura' => 'data:image/gif;base64,AAAA'],
                'Arquivo não é uma imagem válida' => ['fotos' => [UploadedFile::fake()->create('nao-e-foto.txt', 10, 'text/plain')]],
                'Envie no máximo 12 fotos' => ['fotos' => array_map(fn ($i) => UploadedFile::fake()->image("f$i.jpg", 40, 40), range(1, 13))],
            ];
            foreach ($casos as $msg => $mudar) {
                $dados = $this->dados($loc, $mudar);
                if (isset($mudar['estado'])) {
                    $dados['estado'] = [];
                }
                $this->post("/locacoes/{$loc->numero}/vistoria", $dados)->assertSessionHasErrors('vistoria');
                $this->assertStringContainsString($msg, session('errors')->first('vistoria'), "caso: $msg");
            }
            $this->assertSame($antes, (int) DB::selectOne('SELECT count(*) AS n FROM vistorias')->n, 'recusa não pode gravar vistoria');
        } finally {
            $this->apagarArquivosDoTeste();
        }
    }

    public function test_retirada_so_uma_vez(): void
    {
        $this->entrarComo();
        $loc = $this->locacaoSemRetirada();
        if ($loc === null) {
            $this->markTestSkipped('Sem locação em aberto sem retirada nos dados de demonstração.');
        }
        $this->post("/locacoes/{$loc->numero}/vistoria", $this->dados($loc))->assertSessionHasNoErrors();
        $this->get("/locacoes/{$loc->numero}/vistoria?tipo=retirada")
            ->assertRedirect(route('locacoes.show', $loc->numero))
            ->assertSessionHas('flash', [['tipo' => 'aviso', 'msg' => 'Esta locação já tem vistoria de retirada.']]);
        $this->post("/locacoes/{$loc->numero}/vistoria", $this->dados($loc))
            ->assertSessionHasErrors(['vistoria' => 'Esta locação já tem vistoria de retirada.']);
    }

    public function test_beta_nao_ve_vistoria_nem_arquivo_da_alfa(): void
    {
        $this->entrarComo();
        $loc = $this->locacaoSemRetirada();
        if ($loc === null) {
            $this->markTestSkipped('Sem locação em aberto sem retirada nos dados de demonstração.');
        }
        try {
            $this->post("/locacoes/{$loc->numero}/vistoria", $this->dados($loc, ['fotos' => [UploadedFile::fake()->image('foto.jpg', 300, 200)]]))
                ->assertSessionHasNoErrors();
            $v = DB::selectOne("SELECT id FROM vistorias WHERE locacao_id = ? AND tipo = 'retirada'", [$loc->id]);
            $this->registrarArquivos($v->id);
            $foto = DB::selectOne('SELECT id FROM vistoria_fotos WHERE vistoria_id = ?', [$v->id]);
            $this->post('/sair');

            $this->entrarComo('beta-eventos');
            $this->get("/vistorias/{$v->id}")->assertNotFound();
            $this->get("/arquivos/foto/{$foto->id}")->assertNotFound();
            $this->get("/arquivos/assinatura/{$v->id}")->assertNotFound();
            $this->get("/locacoes/{$loc->numero}/vistoria?tipo=retirada")->assertNotFound();
        } finally {
            $this->apagarArquivosDoTeste();
        }
    }
}
