<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Troca da própria senha (todos os usuários). Mesmas regras de senha_trocar() do sistema antigo. */
class SenhaController extends Controller
{
    public function index(): View
    {
        $temporaria = (bool) DB::selectOne('SELECT trocar_senha FROM usuarios WHERE id = ?', [session('usuario.id')])?->trocar_senha;
        return view('configuracoes.senha', ['temporaria' => $temporaria]);
    }

    public function salvar(Request $request): RedirectResponse
    {
        $atual = (string) $request->input('senha_atual', '');
        $nova = (string) $request->input('senha_nova', '');
        $confirma = (string) $request->input('senha_confirma', '');

        $hash = DB::selectOne('SELECT senha_hash FROM usuarios WHERE id = ?', [session('usuario.id')])?->senha_hash;
        if ($hash === null || !password_verify($atual, $hash)) {
            usleep(500000);   // freia quem tenta adivinhar a senha atual
            return back()->withErrors(['senha' => 'A senha atual não confere.']);
        }

        $erro = match (true) {
            mb_strlen($nova) < 8 => 'A nova senha deve ter pelo menos 8 caracteres.',
            $nova !== $confirma  => 'A confirmação não é igual à nova senha.',
            $nova === $atual     => 'A nova senha deve ser diferente da atual.',
            default              => null,
        };
        if ($erro !== null) {
            return back()->withErrors(['senha' => $erro]);
        }

        DB::update('UPDATE usuarios SET senha_hash = ?, trocar_senha = false WHERE id = ?',
                   [password_hash($nova, PASSWORD_DEFAULT), session('usuario.id')]);
        return redirect()->route('config.senha')->with('flash', [['tipo' => 'ok', 'msg' => 'Senha alterada.']]);
    }
}
