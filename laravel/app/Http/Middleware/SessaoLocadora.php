<?php

namespace App\Http\Middleware;

use App\Support\Locadora;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Toda requisição de usuário logado passa por aqui (como tenant_iniciar_sessao() no sistema antigo):
 * define a locadora na conexão a partir da sessão e confere se a locadora continua ativa e em dia,
 * se o usuário continua ativo e se a senha não foi redefinida depois deste login.
 */
class SessaoLocadora
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = (string) $request->session()->get('tenant_id', '');
        $usuarioId = (string) $request->session()->get('usuario.id', '');
        if (!Str::isUuid($tenantId) || !Str::isUuid($usuarioId)) {
            return redirect()->route('login');
        }

        Locadora::definir($tenantId);

        $t = Locadora::dados();
        if ($t === null || $t->status !== 'ativa' || Locadora::inadimplente($t)) {
            $porPagamento = $t !== null && $t->status === 'ativa';
            Log::warning('Sessão encerrada: locadora ' . ($porPagamento ? 'inadimplente' : 'suspensa ou inexistente') . '.');
            return $this->encerrar($request, $porPagamento
                ? 'Acesso suspenso por falta de pagamento da mensalidade. Entre em contato com o suporte.'
                : 'Acesso suspenso. Entre em contato com o suporte.');
        }

        // O usuário pode ter sido desativado depois do login (consulta já filtrada pelo RLS)
        $u = DB::selectOne('SELECT id, nome, login, perfil, ativo, trocar_senha, senha_redefinida_em FROM usuarios WHERE id = ?', [$usuarioId]);
        if ($u === null || !$u->ativo) {
            return $this->encerrar($request, 'Seu usuário foi desativado. Fale com o administrador da locadora.');
        }
        // Senha redefinida pelo painel depois deste login: esta sessão não vale mais
        if ($u->senha_redefinida_em !== null && strtotime($u->senha_redefinida_em) > (int) $request->session()->get('login_em', 0)) {
            return $this->encerrar($request, 'Sua senha foi redefinida pelo administrador. Entre de novo com a senha nova.', 'aviso');
        }
        // Senha temporária: a troca ainda é feita no sistema antigo
        if ($u->trocar_senha) {
            return redirect()->away(legado('config', ['a' => 'senha']));
        }

        $request->session()->put('usuario', ['id' => $u->id, 'nome' => $u->nome, 'login' => $u->login, 'perfil' => $u->perfil]);
        Locadora::definirUsuario($u->id, $u->login);

        View::share('usuario', $u);
        View::share('empresa', DB::selectOne('SELECT * FROM empresa'));   // o RLS devolve só a linha da locadora

        return $next($request);
    }

    private function encerrar(Request $request, string $mensagem, string $tipo = 'erro'): Response
    {
        Locadora::limpar();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('login')->with('flash', [['tipo' => $tipo, 'msg' => $mensagem]]);
    }
}
