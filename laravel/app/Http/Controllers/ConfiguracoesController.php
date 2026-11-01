<?php

namespace App\Http\Controllers;

use App\Support\Empresa;
use App\Support\Recusa;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Configurações: dados da locadora, logomarca e os textos do contrato, da fatura e do PIX (só administrador).
 * Quem não é administrador cai direto na troca da própria senha, como no sistema antigo.
 */
class ConfiguracoesController extends Controller
{
    public function index(): View|RedirectResponse
    {
        if (session('usuario.perfil') !== 'admin') {
            return redirect()->route('config.senha');
        }
        return view('configuracoes.empresa', ['e' => DB::selectOne('SELECT * FROM empresa')]);
    }

    public function salvar(Request $request): RedirectResponse
    {
        abort_unless(session('usuario.perfil') === 'admin', 403, 'Acesso restrito ao administrador.');
        [$dados, $erros] = Empresa::validar($request->all());
        if ($erros) {
            return back()->withErrors(['empresa' => implode(' ', $erros)])->withInput();
        }
        try {
            Empresa::salvar($dados, $request->file('logo'), $request->boolean('logo_remover'));
        } catch (Recusa $e) {
            return back()->withErrors(['empresa' => $e->getMessage()])->withInput();
        }
        return redirect()->route('config.index')->with('flash', [['tipo' => 'ok', 'msg' => 'Dados da locadora salvos.']]);
    }
}
