<?php

namespace Tests\Feature;

use App\Support\Locadora;
use App\Support\Suporte;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Acesso de suporte no sistema da locadora: o código de uso único abre uma sessão só de leitura, com prazo, e fica
 * registrado na auditoria. O painel grava o código por outra conexão (que a transação do teste não enxerga), então
 * aqui o acesso é gravado já confirmado pela conexão de superusuário e apagado depois que o teste é desfeito.
 */
class SuporteLocadoraTest extends TestCase
{
    /** Acessos gravados confirmados por estes testes. */
    private array $acessosGravados = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Roda depois do rollback da transação do teste (que segura o acesso enquanto o teste usa o código)
        $this->beforeApplicationDestroyed(function () {
            foreach ($this->acessosGravados as $id) {
                DB::connection('teste_admin')->delete('DELETE FROM acessos_suporte WHERE id = ?', [$id]);
            }
        });
    }

    /** Acesso de suporte da locadora, como o painel grava. Devolve o código do link. */
    private function acesso(string $tenantId, int $minutos = Suporte::CODIGO_MIN, bool $usado = false): string
    {
        [$codigo, $hash] = Suporte::codigo($tenantId);
        $id = DB::connection('teste_admin')->selectOne(
            'INSERT INTO acessos_suporte (tenant_id, login_suporte, motivo, token_hash, expira_em, usado_em)
             VALUES (?, ?, ?, ?, now() + make_interval(mins => ?), ' . ($usado ? 'now()' : 'NULL') . ') RETURNING id',
            [$tenantId, 'suporte', 'Teste de acesso de suporte', $hash, $minutos]
        )->id;
        $this->acessosGravados[] = $id;
        return $codigo;
    }

    /** Registros de acesso de suporte na auditoria da locadora (a base pode ter os de outros testes ou de uso real). */
    private function contarAcessosDeSuporte(): int
    {
        return (int) DB::selectOne("SELECT count(*) AS n FROM auditoria WHERE tabela = 'acesso_suporte' AND usuario = 'suporte: suporte'")->n;
    }

    public function test_codigo_valido_abre_a_sessao_de_leitura_e_fica_na_auditoria(): void
    {
        $id = $this->idLocadora('alfa-locacoes');
        Locadora::definir($id);
        $antes = $this->contarAcessosDeSuporte();

        $this->get(route('suporte.entrar', ['t' => $this->acesso($id)]))->assertRedirect(route('painel'))
            ->assertSessionHas('flash', fn ($f) => $f[0]['tipo'] === 'aviso');
        $this->get(route('painel'))->assertOk()->assertSee('Acesso de suporte (somente leitura)');

        Locadora::definir($id);
        $this->assertSame($antes + 1, $this->contarAcessosDeSuporte(), 'o acesso entra na auditoria (uma linha a mais)');
    }

    public function test_sessao_de_suporte_nao_grava_nada(): void
    {
        $this->get(route('suporte.entrar', ['t' => $this->acesso($this->idLocadora('alfa-locacoes'))]));

        $this->post(route('clientes.store'), ['nome' => 'TESTE Suporte não grava'])
            ->assertRedirect()
            ->assertSessionHas('flash', fn ($f) => str_contains($f[0]['msg'], 'somente leitura'));
        $this->assertNull(DB::selectOne("SELECT 1 FROM clientes WHERE nome = 'TESTE Suporte não grava'"));
    }

    public function test_filtros_continuam_funcionando_no_suporte(): void
    {
        $this->get(route('suporte.entrar', ['t' => $this->acesso($this->idLocadora('alfa-locacoes'))]));

        // Filtro é um POST que só lê: chega ao controller (que guarda o filtro na sessão), sem o aviso de somente leitura
        $this->post(route('clientes.filtrar'), ['q' => 'alfa', 'situacao' => 'ativos'])
            ->assertSessionMissing('flash')
            ->assertSessionHas('filtro_clientes.q', 'alfa');
    }

    public function test_codigo_usado_ou_expirado_nao_abre(): void
    {
        $id = $this->idLocadora('alfa-locacoes');
        foreach ([$this->acesso($id, Suporte::CODIGO_MIN, true), $this->acesso($id, -1)] as $codigo) {
            $this->get(route('suporte.entrar', ['t' => $codigo]))->assertRedirect(route('login'))
                ->assertSessionHas('flash', fn ($f) => str_contains($f[0]['msg'], 'já foi usado, expirou'));
        }
        $this->get(route('painel'))->assertRedirect(route('login'));
    }

    public function test_codigo_de_outra_locadora_nao_abre(): void
    {
        $alfa = $this->idLocadora('alfa-locacoes');
        $outra = $this->lerComoAdmin("SELECT id FROM tenants WHERE slug <> 'alfa-locacoes' ORDER BY slug LIMIT 1")[0]->id;
        $codigo = $this->acesso($alfa);

        // O mesmo código, apresentado com o identificador de outra locadora: não é dela, não abre
        $this->get(route('suporte.entrar', ['t' => $outra . '.' . substr($codigo, 37)]))->assertRedirect(route('login'))
            ->assertSessionHas('flash', fn ($f) => str_contains($f[0]['msg'], 'não é desta locadora'));
    }

    public function test_formato_invalido_do_link_e_recusado(): void
    {
        $this->get(route('suporte.entrar', ['t' => 'lixo']))->assertRedirect(route('login'))
            ->assertSessionHas('flash', fn ($f) => str_contains($f[0]['msg'], 'é inválido'));
    }

    public function test_sessao_de_suporte_vence_no_prazo(): void
    {
        $this->get(route('suporte.entrar', ['t' => $this->acesso($this->idLocadora('alfa-locacoes'))]));
        $this->withSession(['suporte' => array_merge(session('suporte'), ['expira' => time() - 1])]);

        $this->get(route('painel'))->assertRedirect(route('login'))
            ->assertSessionHas('flash', fn ($f) => $f[0]['tipo'] === 'aviso' && str_contains($f[0]['msg'], 'expirou'));
    }

    public function test_sair_encerra_so_o_suporte(): void
    {
        $this->get(route('suporte.entrar', ['t' => $this->acesso($this->idLocadora('alfa-locacoes'))]));

        $this->post(route('suporte.sair'))->assertRedirect(route('login'));
        $this->get(route('painel'))->assertRedirect(route('login'));
    }

    public function test_sair_da_locadora_nao_derruba_o_painel_da_plataforma(): void
    {
        // As duas áreas dividem a sessão do navegador: o sair da locadora leva só a sessão da locadora
        $this->withSession(['plataforma' => ['usuario' => ['id' => 'x', 'nome' => 'Suporte', 'login' => 'suporte', 'perfil' => 'suporte']]]);
        $this->entrarComo();

        $this->post('/sair')->assertRedirect(route('login'));
        $this->assertNull(session('tenant_id'));
        $this->assertSame('suporte', session('plataforma.usuario.login'));
    }
}
