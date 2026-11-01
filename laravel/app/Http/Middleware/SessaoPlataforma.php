<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Painel da plataforma: só entra quem fez login na plataforma (sessão 'plataforma.usuario', separada da sessão
 * das locadoras). Confere a cada requisição se o usuário continua ativo e grava quem fez a alteração na auditoria.
 */
class SessaoPlataforma
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->session()->get('plataforma.usuario');
        if ($usuario === null) {
            return redirect()->route('plataforma.login');
        }

        $ativo = DB::connection('plataforma')->selectOne('SELECT ativo FROM plataforma_usuarios WHERE id = ?', [$usuario['id']])?->ativo;
        if (!$ativo) {
            $request->session()->forget('plataforma.usuario');
            return redirect()->route('plataforma.login')
                ->with('flash', [['tipo' => 'erro', 'msg' => 'Sessão encerrada. Entre novamente.']]);
        }

        // Alterações feitas pelo painel em dados de uma locadora aparecem assim na auditoria dela
        DB::connection('plataforma')->select("SELECT set_config('app.usuario', ?, false)",
                                             [mb_substr('plataforma: ' . $usuario['login'], 0, 80)]);
        View::share('plataformaUsuario', $usuario);

        return $next($request);
    }
}
