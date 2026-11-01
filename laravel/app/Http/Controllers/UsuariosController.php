<?php

namespace App\Http\Controllers;

use App\Support\Locadora;
use App\Support\Recusa;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Usuários da locadora (só administrador). O plano limita os ativos; ninguém desativa nem rebaixa a si mesmo;
 * a senha definida pelo administrador vale só até o próximo acesso de quem a recebe. Mesmas regras de usuario_salvar().
 */
class UsuariosController extends Controller
{
    public function index(Request $request): View
    {
        $this->soAdmin();
        $usuarios = DB::select('SELECT id, nome, login, perfil, ativo, ultimo_acesso FROM usuarios ORDER BY ativo DESC, lower(nome)');
        $editar = Str::isUuid((string) $request->query('id', ''))
            ? DB::selectOne('SELECT id, nome, login, perfil, ativo FROM usuarios WHERE id = ?', [$request->query('id')])
            : null;
        $locadora = Locadora::dados();

        return view('configuracoes.usuarios', [
            'usuarios' => $usuarios,
            'ativos' => count(array_filter($usuarios, fn ($u) => $u->ativo)),
            'limite' => (int) $locadora->limite_usuarios,
            'plano' => $locadora->plano,
            'editar' => $editar,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->gravar($request, null);
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        abort_unless(Str::isUuid($id), 404);
        return $this->gravar($request, $id);
    }

    private function gravar(Request $request, ?string $id): RedirectResponse
    {
        $this->soAdmin();
        $nome = trim((string) $request->input('nome', ''));
        $login = strtolower(trim((string) $request->input('login', '')));
        $perfil = $request->input('perfil') === 'admin' ? 'admin' : 'operador';
        $ativo = $request->boolean('ativo');
        $senha = (string) $request->input('senha', '');

        $erros = [];
        if ($nome === '' || mb_strlen($nome) > 100) {
            $erros[] = 'Informe o nome.';
        }
        if (!preg_match('/^[a-z0-9._-]{3,40}$/', $login)) {
            $erros[] = 'Login inválido (3 a 40 letras minúsculas, números, ponto, hífen ou sublinhado).';
        }
        if (($id === null || $senha !== '') && mb_strlen($senha) < 8) {
            $erros[] = 'A senha deve ter pelo menos 8 caracteres.';
        }
        if ($id !== null && $id === session('usuario.id') && (!$ativo || $perfil !== 'admin')) {
            $erros[] = 'Você não pode desativar nem rebaixar o seu próprio usuário.';
        }
        if ($erros) {
            return back()->withErrors(['usuario' => implode(' ', $erros)])->withInput();
        }

        try {
            DB::transaction(function () use ($id, $nome, $login, $perfil, $ativo, $senha) {
                // Trava os usuários da locadora para a contagem do limite não mudar no meio
                DB::select('SELECT id FROM usuarios FOR UPDATE');
                if ($id === null) {
                    if ($ativo) {
                        $this->verificarLimite();
                    }
                    // A senha foi definida pelo administrador: o novo usuário troca no primeiro acesso
                    DB::insert('INSERT INTO usuarios (nome, login, senha_hash, perfil, ativo, trocar_senha) VALUES (?, ?, ?, ?, ?, true)',
                               [$nome, $login, password_hash($senha, PASSWORD_DEFAULT), $perfil, $ativo ? 'true' : 'false']);
                    return;
                }
                $atual = DB::selectOne('SELECT ativo FROM usuarios WHERE id = ?', [$id]);
                if ($atual === null) {
                    throw new Recusa('Usuário não encontrado.');
                }
                if ($ativo && !$atual->ativo) {
                    $this->verificarLimite($id);
                }
                DB::update('UPDATE usuarios SET nome = ?, login = ?, perfil = ?, ativo = ? WHERE id = ?',
                           [$nome, $login, $perfil, $ativo ? 'true' : 'false', $id]);
                if ($senha !== '') {
                    // Senha redefinida pelo administrador para outro usuário vale só até o próximo acesso dele
                    DB::update('UPDATE usuarios SET senha_hash = ?, trocar_senha = ? WHERE id = ?',
                               [password_hash($senha, PASSWORD_DEFAULT), $id !== session('usuario.id') ? 'true' : 'false', $id]);
                }
            });
        } catch (Recusa $e) {
            return back()->withErrors(['usuario' => $e->getMessage()])->withInput();
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                return back()->withErrors(['usuario' => 'Já existe um usuário com este login.'])->withInput();
            }
            throw $e;
        }

        return redirect()->route('config.usuarios')
            ->with('flash', [['tipo' => 'ok', 'msg' => $id === null ? 'Usuário criado.' : 'Usuário atualizado.']]);
    }

    /** Respeita o limite do plano, contando só os ativos (quem está sendo alterado não conta). */
    private function verificarLimite(?string $ignorar = null): void
    {
        $limite = (int) Locadora::dados()->limite_usuarios;
        $ativos = (int) DB::selectOne('SELECT count(*) AS n FROM usuarios WHERE ativo AND id IS DISTINCT FROM CAST(? AS uuid)', [$ignorar])->n;
        if ($ativos >= $limite) {
            throw new Recusa("Seu plano permite até $limite usuário(s) ativo(s). Desative um usuário ou fale com o suporte para ampliar o plano.");
        }
    }

    private function soAdmin(): void
    {
        abort_unless(session('usuario.perfil') === 'admin', 403, 'Acesso restrito ao administrador.');
    }
}
