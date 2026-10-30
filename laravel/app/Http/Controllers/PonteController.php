<?php

namespace App\Http\Controllers;

use App\Support\Locadora;
use App\Support\Ponte;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Entrada e saída pela ponte de sessão com o sistema antigo (veja App\Support\Ponte). */
class PonteController extends Controller
{
    /** /ponte?t=... : chegada do sistema antigo. */
    public function entrar(Request $request): RedirectResponse
    {
        $d = Ponte::validar((string) $request->query('t', ''));
        if ($d === null) {
            Log::alert('Passagem do sistema antigo recusada (assinatura, prazo ou já usada).');
            return $this->paraLogin('O link de acesso expirou. Entre com sua senha.');
        }

        $t = DB::selectOne('SELECT id, slug, status, vencimento, carencia_dias FROM tenants WHERE id = ?', [$d['t']]);
        if ($t === null || $t->status !== 'ativa' || Locadora::inadimplente($t)) {
            return $this->paraLogin('Acesso suspenso. Entre em contato com o suporte.');
        }
        Locadora::definir($t->id);   // a busca do usuário já passa pelo RLS da locadora
        $u = DB::selectOne('SELECT id, nome, login, perfil, ativo, senha_redefinida_em FROM usuarios WHERE id = ?', [$d['u']]);
        if ($u === null || !$u->ativo || ($u->senha_redefinida_em !== null && strtotime($u->senha_redefinida_em) > (int) $d['e'])) {
            Locadora::limpar();
            return $this->paraLogin('Entre com sua senha.');
        }

        $request->session()->regenerate();
        $request->session()->put([
            'tenant_id'   => $t->id,
            'tenant_slug' => $t->slug,
            'usuario'     => ['id' => $u->id, 'nome' => $u->nome, 'login' => $u->login, 'perfil' => $u->perfil],
            'login_em'    => (int) $d['e'],   // o login original: a regra da senha redefinida continua valendo
        ]);
        Log::info('Entrada pela ponte do sistema antigo.', ['locadora' => $t->slug, 'usuario' => $u->login]);

        $destino = (string) ($d['d'] ?? '/painel');
        if (!preg_match('#^/[a-z0-9/_-]*(\?[A-Za-z0-9=&%_.-]*)?$#', $destino)) {
            $destino = '/painel';
        }
        return redirect($destino)->header('Referrer-Policy', 'no-referrer');
    }

    /** /ir?p=modulo&... : leva quem está logado aqui para uma tela do sistema antigo. */
    public function ir(Request $request): RedirectResponse
    {
        $p = (string) $request->query('p', 'dashboard');
        $params = array_filter($request->except('p'), 'is_string');
        if (!preg_match('/^[a-z_]+$/', $p) || (isset($params['a']) && !preg_match('/^[a-z_]+$/', $params['a']))) {
            abort(404);
        }
        $passagem = Ponte::gerar(
            (string) $request->session()->get('tenant_id'),
            (string) $request->session()->get('usuario.id'),
            (int) $request->session()->get('login_em', time()),
            http_build_query(['p' => $p] + $params)
        );
        $url = rtrim(config('locgestor.legado_url'), '/') . '/index.php?' . http_build_query(['p' => 'login', 'a' => 'ponte', 't' => $passagem]);
        return redirect()->away($url)->header('Referrer-Policy', 'no-referrer');
    }

    private function paraLogin(string $mensagem): RedirectResponse
    {
        return redirect()->route('login')->with('flash', [['tipo' => 'aviso', 'msg' => $mensagem]]);
    }
}
