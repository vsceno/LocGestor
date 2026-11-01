<?php

namespace App\Http\Controllers\Plataforma;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;

/** Minha senha: o usuário do painel troca a própria senha. A antiga para de valer na hora. */
class ContaController extends Controller
{
    public function index(): View
    {
        return view('plataforma.conta');
    }

    public function salvar(Request $request): RedirectResponse
    {
        $atual = (string) $request->input('senha_atual', '');
        $nova = (string) $request->input('senha_nova', '');
        $confirma = (string) $request->input('senha_confirma', '');
        $id = (string) session('plataforma.usuario.id', '');

        $db = DB::connection('plataforma');
        $hash = Str::isUuid($id)
            ? $db->selectOne('SELECT senha_hash FROM plataforma_usuarios WHERE id = ? AND ativo', [$id])?->senha_hash
            : null;
        if ($hash === null) {
            return $this->recusar('Sessão inválida. Entre novamente.');
        }
        if (!password_verify($atual, $hash)) {
            usleep(500000);
            Log::warning('Troca de senha da plataforma: senha atual não confere.');
            return $this->recusar('A senha atual não confere.');
        }
        if (mb_strlen($nova) < 8) {
            return $this->recusar('A nova senha deve ter pelo menos 8 caracteres.');
        }
        if ($nova !== $confirma) {
            return $this->recusar('A confirmação não é igual à nova senha.');
        }
        if (password_verify($nova, $hash)) {
            return $this->recusar('A nova senha deve ser diferente da atual.');
        }

        $db->update('UPDATE plataforma_usuarios SET senha_hash = ? WHERE id = ?', [password_hash($nova, PASSWORD_DEFAULT), $id]);
        Log::info('Senha do painel da plataforma trocada.');
        return redirect()->route('plataforma.conta')
            ->with('flash', [['tipo' => 'ok', 'msg' => 'Senha trocada. Use a nova no próximo acesso.']]);
    }

    private function recusar(string $msg): RedirectResponse
    {
        return back()->with('flash', [['tipo' => 'erro', 'msg' => $msg]]);
    }
}
