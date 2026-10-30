<?php

namespace Tests\Feature;

use App\Support\Ponte;
use Tests\TestCase;

/** Ponte de sessão com o sistema antigo: assinatura, prazo, uso único e destinatário. */
class PonteTest extends TestCase
{
    private string $segredo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->segredo = str_repeat('s', 64);
        config(['locgestor.ponte_segredo' => $this->segredo]);
    }

    /** Passagem como o sistema antigo gera (app/ponte.php), para o Laravel. */
    private function passagem(array $mudar = [], ?string $segredo = null): string
    {
        $u = $this->lerComoAdmin("SELECT u.id FROM usuarios u JOIN tenants t ON t.id = u.tenant_id WHERE t.slug = 'alfa-locacoes' AND u.login = 'admin'")[0]->id;
        $b64 = fn ($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $corpo = $b64(json_encode($mudar + ['para' => 'laravel', 't' => $this->idLocadora('alfa-locacoes'), 'u' => $u, 'e' => time(),
                                             'd' => '/clientes', 'x' => time() + 60, 'n' => bin2hex(random_bytes(16))]));
        return $corpo . '.' . $b64(hash_hmac('sha256', $corpo, $segredo ?? $this->segredo, true));
    }

    public function test_passagem_valida_abre_a_sessao_e_so_vale_uma_vez(): void
    {
        $t = $this->passagem();
        $this->get('/ponte?t=' . $t)->assertRedirect('/clientes');
        $this->assertSame($this->idLocadora('alfa-locacoes'), session('tenant_id'));

        $this->post('/sair');
        $this->get('/ponte?t=' . $t)->assertRedirect(route('login'));
        $this->assertNull(session('tenant_id'));
    }

    public function test_passagens_invalidas_sao_recusadas(): void
    {
        foreach ([
            'outro segredo' => $this->passagem([], str_repeat('x', 64)),
            'vencida'       => $this->passagem(['x' => time() - 1]),
            'para o antigo' => $this->passagem(['para' => 'legado']),
            'locadora errada' => $this->passagem(['t' => $this->idLocadora('beta-eventos')]),
            'lixo'          => 'abc.def',
        ] as $caso => $t) {
            $this->get('/ponte?t=' . urlencode($t))->assertRedirect(route('login'));
            $this->assertNull(session('tenant_id'), "passagem $caso não pode abrir sessão");
        }
    }

    public function test_destino_externo_vira_o_painel(): void
    {
        $this->get('/ponte?t=' . $this->passagem(['d' => '//site-malicioso.com/x']))->assertRedirect('/painel');
    }

    public function test_ir_para_o_sistema_antigo_gera_passagem_assinada_para_ele(): void
    {
        $this->entrarComo();
        $r = $this->get('/ir?p=locacoes&a=ver&n=141');
        $destino = $r->headers->get('Location');
        $this->assertStringStartsWith(rtrim(config('locgestor.legado_url'), '/') . '/index.php?p=login&a=ponte&t=', $destino);

        parse_str(parse_url($destino, PHP_URL_QUERY), $q);
        [$corpo, $assinatura] = explode('.', $q['t']);
        $esperada = rtrim(strtr(base64_encode(hash_hmac('sha256', $corpo, $this->segredo, true)), '+/', '-_'), '=');
        $this->assertSame($esperada, $assinatura);
        $d = json_decode(base64_decode(strtr($corpo, '-_', '+/')), true);
        $this->assertSame('legado', $d['para']);
        $this->assertSame('p=locacoes&a=ver&n=141', $d['d']);

        $this->get('/ir?p=../etc')->assertNotFound();
    }

    public function test_ponte_desligada_sem_segredo(): void
    {
        config(['locgestor.ponte_segredo' => '']);
        $this->assertFalse(Ponte::ligada());
        $this->get('/ponte?t=' . $this->passagem())->assertRedirect(route('login'));
    }
}
