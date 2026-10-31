<?php

namespace App\Http\Controllers\Plataforma;

use App\Http\Controllers\Controller;
use App\Support\LoginProtecao;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Login do painel da plataforma (tabela plataforma_usuarios), com as mesmas proteções do sistema antigo
 * (plataforma.php, modulos_plataforma/login.php): limite de tentativas, mensagem genérica e o mesmo tempo de resposta
 * quando o usuário não existe.
 */
class LoginController extends Controller
{
    // Hash de uma senha descartada: gasta o mesmo tempo de uma verificação real
    private const HASH_FALSO = '$2y$12$b3ZTYBwcXEHVCZ/K.j4y1u/YQ8SFgLjsCvjtO9JLNoPl0AHrbY4vO';

    public function index(Request $request): View|RedirectResponse
    {
        if ($request->session()->has('plataforma.usuario')) {
            return redirect()->route('plataforma.locadoras');
        }
        return view('plataforma.login');
    }

    public function entrar(Request $request): RedirectResponse
    {
        $login = strtolower(trim((string) $request->input('login')));
        $senha = $request->input('senha');
        $senha = is_string($senha) ? $senha : '';

        $chaves = LoginProtecao::chaves('plataforma', $login, $request->ip());
        if (LoginProtecao::bloqueado($chaves, 'plataforma')) {
            usleep(random_int(600000, 1000000));
            Log::alert('Login da plataforma bloqueado por excesso de tentativas.');
            return $this->voltar($login, 'Muitas tentativas de acesso. Aguarde ' . config('locgestor.login_janela_min') . ' minutos e tente de novo.');
        }

        $u = $login !== '' && $senha !== ''
            ? DB::connection('plataforma')->selectOne('SELECT id, nome, login, senha_hash, ativo FROM plataforma_usuarios WHERE login = ?', [$login])
            : null;
        if ($u === null) {
            password_verify($senha, self::HASH_FALSO);
        }
        if ($u === null || !$u->ativo || $senha === '' || !password_verify($senha, $u->senha_hash)) {
            LoginProtecao::falhou($chaves, 'plataforma');
            usleep(random_int(600000, 1000000));
            Log::warning('Login da plataforma recusado.');
            return $this->voltar($login, 'Dados de acesso inválidos.');
        }

        if (password_needs_rehash($u->senha_hash, PASSWORD_DEFAULT)) {
            DB::connection('plataforma')->update('UPDATE plataforma_usuarios SET senha_hash = ? WHERE id = ?',
                                                 [password_hash($senha, PASSWORD_DEFAULT), $u->id]);
        }
        DB::connection('plataforma')->update('UPDATE plataforma_usuarios SET ultimo_acesso = now() WHERE id = ?', [$u->id]);
        LoginProtecao::sucesso($chaves, 'plataforma');

        $request->session()->regenerate();
        $request->session()->put('plataforma.usuario', ['id' => $u->id, 'nome' => $u->nome, 'login' => $u->login, 'perfil' => 'suporte']);
        Log::info('Login da plataforma.', ['usuario' => $u->login]);
        return redirect()->route('plataforma.locadoras');
    }

    /** Sai só do painel da plataforma: a sessão das locadoras (se houver) não é afetada. */
    public function sair(Request $request): RedirectResponse
    {
        Log::info('Logout da plataforma.');
        $request->session()->forget('plataforma.usuario');
        $request->session()->regenerateToken();
        return redirect()->route('plataforma.login')->with('flash', [['tipo' => 'ok', 'msg' => 'Você saiu do painel da plataforma.']]);
    }

    private function voltar(string $login, string $mensagem): RedirectResponse
    {
        return redirect()->route('plataforma.login')->withInput(['login' => $login])
            ->with('flash', [['tipo' => 'erro', 'msg' => $mensagem]]);
    }
}
