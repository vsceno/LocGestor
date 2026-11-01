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
 * A sessão de suporte (ver SuporteController) não tem usuário: é só de leitura e tem prazo.
 */
class SessaoLocadora
{
    /** POST que só lê (filtros e buscas): liberado também na sessão de suporte, que não grava nada. */
    private const ROTAS_DE_LEITURA = ['*.filtrar', 'locacoes.disponivel', 'busca.*', 'sair', 'suporte.sair'];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->session()->has('suporte')) {
            return $this->sessaoSuporte($request, $next);
        }

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
        $request->session()->put('usuario', ['id' => $u->id, 'nome' => $u->nome, 'login' => $u->login, 'perfil' => $u->perfil]);
        Locadora::definirUsuario($u->id, $u->login);

        // Senha temporária: a troca de senha (Configurações) é a única tela liberada até o usuário trocá-la
        if ($u->trocar_senha && !$request->routeIs('config.senha', 'config.senha.salvar')) {
            return redirect()->route('config.senha');
        }

        View::share('usuario', $u);
        View::share('empresa', DB::selectOne('SELECT * FROM empresa'));   // o RLS devolve só a linha da locadora

        return $next($request);
    }

    /** Acesso de suporte: só leitura e com prazo. Não há usuário da locadora; quem abriu ficou na auditoria. */
    private function sessaoSuporte(Request $request, Closure $next): Response
    {
        $tenantId = (string) $request->session()->get('tenant_id', '');
        if (!Str::isUuid($tenantId) || time() >= (int) $request->session()->get('suporte.expira', 0)) {
            return $this->encerrar($request, 'O acesso de suporte expirou.', 'aviso');
        }

        Locadora::definir($tenantId);
        $t = Locadora::dados();
        if ($t === null || $t->status !== 'ativa' || Locadora::inadimplente($t)) {
            return $this->encerrar($request, 'Acesso suspenso. Entre em contato com o suporte.');
        }

        // Nada se grava nesta sessão: um POST só passa se for consulta (filtro, busca) ou o próprio sair
        if (!in_array($request->method(), ['GET', 'HEAD'], true) && !$request->routeIs(...self::ROTAS_DE_LEITURA)) {
            return back()->with('flash', [['tipo' => 'erro', 'msg' => 'Acesso de suporte: somente leitura. Nada é gravado nesta sessão.']]);
        }

        View::share('usuario', (object) [
            'id' => null, 'nome' => 'Suporte LocGestor', 'login' => 'suporte:' . $request->session()->get('suporte.login'),
            'perfil' => 'suporte', 'trocar_senha' => false,
        ]);
        View::share('empresa', DB::selectOne('SELECT * FROM empresa'));

        return $next($request);
    }

    private function encerrar(Request $request, string $mensagem, string $tipo = 'erro'): Response
    {
        Locadora::encerrarSessao($request->session());
        return redirect()->route('login')->with('flash', [['tipo' => $tipo, 'msg' => $mensagem]]);
    }
}
