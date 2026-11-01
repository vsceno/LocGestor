<?php

namespace App\Http\Controllers;

use App\Support\Locadora;
use App\Support\Suporte;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Acesso de suporte no sistema da locadora ("Entrar como a locadora"). A entrada troca o código de uso único, criado no
 * painel da plataforma, por uma sessão só de leitura com prazo. Mesmas regras de suporte_entrar() e suporte_sair()
 * do sistema antigo (app/suporte.php e modulos/suporte.php).
 */
class SuporteController extends Controller
{
    /** Entrada pelo link do painel. Não exige login: o código de uso único é a autorização. */
    public function entrar(Request $request): RedirectResponse
    {
        $partes = Suporte::separar((string) $request->query('t', ''));
        if ($partes === null) {
            Log::warning('Link de suporte recusado: formato inválido.');
            return $this->recusar('Este link de suporte é inválido.');
        }
        [$tenantId, $hash] = $partes;
        Locadora::definir($tenantId);

        // Consome o código: só vale se for desta locadora, não tiver sido usado e ainda estiver no prazo
        $acesso = DB::selectOne(
            'UPDATE acessos_suporte SET usado_em = now()
              WHERE token_hash = ? AND usado_em IS NULL AND expira_em > now() AND tenant_id = CAST(? AS uuid)
          RETURNING id, login_suporte, motivo',
            [$hash, $tenantId]
        );
        if ($acesso === null) {
            Locadora::limpar();
            Log::warning('Link de suporte recusado: já usado, expirado ou de outra locadora.');
            return $this->recusar('Este link de suporte já foi usado, expirou ou não é desta locadora.');
        }
        $t = Locadora::dados();
        if ($t === null || $t->status !== 'ativa' || Locadora::inadimplente($t)) {
            Locadora::limpar();
            return $this->recusar('A locadora não está ativa: o acesso de suporte não pode ser aberto.');
        }

        // Registro na auditoria da locadora: quem entrou, quando e por quê (o código nunca é gravado)
        DB::insert(
            "INSERT INTO auditoria (tenant_id, usuario_id, usuario, tabela, registro_id, rotulo, acao, dados)
             VALUES (?, NULL, ?, 'acesso_suporte', ?, ?, 'I', CAST(? AS jsonb))",
            [$tenantId, mb_substr('suporte: ' . $acesso->login_suporte, 0, 80), $acesso->id,
             mb_substr($acesso->login_suporte, 0, 160),
             json_encode(['motivo' => $acesso->motivo, 'duracao_min' => Suporte::SESSAO_MIN], JSON_UNESCAPED_UNICODE)]
        );
        Log::info('Acesso de suporte aberto.', ['locadora' => $t->slug, 'login' => $acesso->login_suporte]);

        // Troca qualquer sessão de locadora que estiver aberta neste navegador pela de suporte
        Locadora::encerrarSessao($request->session());
        $request->session()->put([
            'tenant_id' => $tenantId,
            'tenant_slug' => $t->slug,
            'suporte' => [
                'id' => $acesso->id, 'login' => $acesso->login_suporte, 'motivo' => $acesso->motivo,
                'expira' => time() + Suporte::SESSAO_MIN * 60,
            ],
        ]);

        return redirect()->route('painel')
            ->with('flash', [['tipo' => 'aviso', 'msg' => 'Você está no acesso de suporte: somente leitura, por ' . Suporte::SESSAO_MIN . ' minutos.']]);
    }

    /** Encerra o acesso de suporte e volta à entrada do sistema. */
    public function sair(Request $request): RedirectResponse
    {
        if (!$request->session()->has('suporte')) {
            return redirect()->route('painel');
        }
        Locadora::encerrarSessao($request->session());
        return redirect()->route('login')->with('flash', [['tipo' => 'ok', 'msg' => 'Acesso de suporte encerrado.']]);
    }

    private function recusar(string $mensagem): RedirectResponse
    {
        return redirect()->route('login')->with('flash', [['tipo' => 'erro', 'msg' => $mensagem]]);
    }
}
