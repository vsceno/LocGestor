<?php

namespace App\Http\Controllers;

use App\Support\Empresa;
use App\Support\Identidade;
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

    /** Identidade visual: cores, fontes e logotipo quadrado (só administrador). */
    public function identidade(): View|RedirectResponse
    {
        if (session('usuario.perfil') !== 'admin') {
            return redirect()->route('config.senha');
        }
        $e = DB::selectOne('SELECT * FROM empresa');

        return view('configuracoes.identidade', ['e' => $e, 'v' => Identidade::resolver($e)]);
    }

    public function salvarIdentidade(Request $request): RedirectResponse
    {
        abort_unless(session('usuario.perfil') === 'admin', 403, 'Acesso restrito ao administrador.');
        if ($request->boolean('restaurar_padrao')) {
            DB::update("UPDATE empresa SET cor_principal = '', cor_destaque = '', cor_texto_escuro = '', cor_texto_claro = '',
                                           cor_fundo = '', fonte_titulos = '', fonte_textos = ''");

            return redirect()->route('config.identidade')->with('flash', [['tipo' => 'ok', 'msg' => 'Identidade visual voltou ao padrão neutro.']]);
        }

        [$dados, $erros] = Identidade::validar($request->all());
        if ($erros) {
            return back()->withErrors(['identidade' => implode(' ', $erros)])->withInput();
        }
        try {
            Identidade::salvarLogoQuadrada($request->file('logo_quadrada'), $request->boolean('logo_quadrada_remover'));
        } catch (Recusa $e) {
            return back()->withErrors(['identidade' => $e->getMessage()])->withInput();
        }
        $sets = implode(', ', array_map(fn (string $coluna): string => "$coluna = ?", array_keys($dados)));
        DB::update("UPDATE empresa SET $sets", array_values($dados));

        return redirect()->route('config.identidade')->with('flash', [['tipo' => 'ok', 'msg' => 'Identidade visual salva.']]);
    }
}
