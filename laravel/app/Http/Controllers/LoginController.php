<?php

namespace App\Http\Controllers;

use App\Support\Locadora;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Login das locadoras, com as mesmas proteções do sistema antigo (modulos/login.php):
 * limite de erros por conta e por IP (na mesma tabela login_tentativas, então o limite vale
 * somado entre os dois sistemas), mensagem genérica, mesmo tempo quando a empresa não existe
 * e o usuário só é procurado depois de definir a locadora (a busca já passa pelo RLS).
 */
class LoginController extends Controller
{
    private const COOKIE_EMPRESA = 'lg_empresa';
    // Hash de uma senha descartada: gasta o mesmo tempo de uma verificação real
    private const HASH_FALSO = '$2y$12$b3ZTYBwcXEHVCZ/K.j4y1u/YQ8SFgLjsCvjtO9JLNoPl0AHrbY4vO';

    public function index(Request $request): View|RedirectResponse
    {
        if ($request->session()->has('tenant_id')) {
            return redirect()->route('painel');
        }
        return view('login', ['empresaPadrao' => (string) $request->cookie(self::COOKIE_EMPRESA, '')]);
    }

    public function entrar(Request $request): RedirectResponse
    {
        $slug = strtolower(trim((string) $request->input('empresa')));
        $login = strtolower(trim((string) $request->input('login')));
        $senha = $request->input('senha');

        $chaves = $this->chaves($request, "$slug|$login");
        if ($this->bloqueado($chaves)) {
            usleep(random_int(600000, 1000000));
            Log::alert('Login bloqueado por excesso de tentativas.');
            return $this->voltar($slug, 'Muitas tentativas de acesso. Aguarde ' . config('locgestor.login_janela_min') . ' minutos e tente de novo.');
        }

        if (!preg_match('/^[a-z0-9-]{3,40}$/', $slug) || $login === '' || !is_string($senha) || $senha === '') {
            return $this->falhar($chaves, $slug);
        }

        // tenants não tem RLS; o app só lê algumas colunas. A mensagem genérica não revela se a empresa existe.
        $t = DB::selectOne('SELECT id, slug, status, vencimento, carencia_dias FROM tenants WHERE slug = ?', [$slug]);
        if ($t === null || $t->status !== 'ativa' || Locadora::inadimplente($t)) {
            password_verify($senha, self::HASH_FALSO);
            return $this->falhar($chaves, $slug);
        }

        // A partir daqui a busca do usuário já é filtrada pelo RLS da locadora informada
        Locadora::definir($t->id);
        $u = DB::selectOne('SELECT id, nome, login, senha_hash, perfil, ativo FROM usuarios WHERE login = ?', [$login]);
        if ($u === null || !$u->ativo || !password_verify($senha, $u->senha_hash)) {
            return $this->falhar($chaves, $slug);
        }

        // Mesmas funções nativas do sistema antigo, para os dois não ficarem regravando o hash um do outro
        if (password_needs_rehash($u->senha_hash, PASSWORD_DEFAULT)) {
            DB::update('UPDATE usuarios SET senha_hash = ? WHERE id = ?', [password_hash($senha, PASSWORD_DEFAULT), $u->id]);
        }
        DB::update('UPDATE usuarios SET ultimo_acesso = now() WHERE id = ?', [$u->id]);
        DB::delete('DELETE FROM login_tentativas WHERE chave = ?', [$chaves['conta']]);

        $request->session()->regenerate();
        $request->session()->put([
            'tenant_id'   => $t->id,
            'tenant_slug' => $t->slug,
            'usuario'     => ['id' => $u->id, 'nome' => $u->nome, 'login' => $u->login, 'perfil' => $u->perfil],
            'login_em'    => time(),   // sessões abertas antes de uma redefinição de senha deixam de valer
        ]);
        Cookie::queue(self::COOKIE_EMPRESA, $t->slug, 365 * 24 * 60);
        Log::info('Login.', ['locadora' => $t->slug, 'usuario' => $u->login]);
        return redirect()->intended(route('painel'));
    }

    public function sair(Request $request): RedirectResponse
    {
        Locadora::limpar();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('login');
    }

    /** Chaves das tentativas: mesma fórmula do sistema antigo (login_chaves), área "locadora". */
    private function chaves(Request $request, string $conta): array
    {
        return [
            'ip'    => hash('sha256', 'ip|' . $request->ip()),
            'conta' => hash('sha256', 'conta|locadora|' . mb_strtolower($conta)),
        ];
    }

    private function bloqueado(array $chaves): bool
    {
        $r = DB::selectOne('SELECT count(*) FILTER (WHERE chave = ?) AS conta, count(*) FILTER (WHERE chave = ?) AS ip
                              FROM login_tentativas
                             WHERE chave IN (?, ?) AND criado_em > now() - make_interval(mins => ?)',
            [$chaves['conta'], $chaves['ip'], $chaves['conta'], $chaves['ip'], config('locgestor.login_janela_min')]);
        return $r->conta >= config('locgestor.login_max_conta') || $r->ip >= config('locgestor.login_max_ip');
    }

    private function falhar(array $chaves, string $slug): RedirectResponse
    {
        Locadora::limpar();
        DB::insert('INSERT INTO login_tentativas (chave) VALUES (?), (?)', [$chaves['conta'], $chaves['ip']]);
        DB::delete("DELETE FROM login_tentativas WHERE criado_em < now() - INTERVAL '1 day'");
        usleep(random_int(600000, 1000000));   // atraso contra tentativa e erro
        Log::warning('Login recusado.');
        return $this->voltar($slug, 'Dados de acesso inválidos.');
    }

    private function voltar(string $slug, string $mensagem): RedirectResponse
    {
        return redirect()->route('login')->withInput(['empresa' => $slug])->with('flash', [['tipo' => 'erro', 'msg' => $mensagem]]);
    }
}
