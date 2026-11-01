<?php

namespace App\Http\Controllers\Plataforma;

use App\Http\Controllers\Controller;
use App\Support\Formulario;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Recados do painel: avisos com validade, mostrados no topo do sistema de todas as locadoras até a data de validade
 * (ou até serem retirados). Mesmas regras de recados_*() do sistema antigo.
 */
class RecadosController extends Controller
{
    public const TIPOS = ['aviso' => 'Aviso', 'novidade' => 'Novidade', 'manutencao' => 'Manutenção programada'];

    public function index(): View
    {
        return view('plataforma.recados', [
            'recados' => DB::connection('plataforma')->select(
                'SELECT id, tipo, titulo, texto, expira_em, ativo, usuario, criado_em FROM recados ORDER BY criado_em DESC LIMIT 50'
            ),
            'tipos' => self::TIPOS,
        ]);
    }

    public function criar(Request $request): RedirectResponse
    {
        $tipo = (string) $request->input('tipo', '');
        $titulo = trim((string) $request->input('titulo', ''));
        $texto = trim((string) $request->input('texto', ''));
        $expira = (string) $request->input('expira_em', '');

        $erro = match (true) {
            !array_key_exists($tipo, self::TIPOS) => 'Escolha o tipo do recado.',
            mb_strlen($titulo) < 3 || mb_strlen($titulo) > 120 => 'O título deve ter de 3 a 120 caracteres.',
            mb_strlen($texto) < 5 || mb_strlen($texto) > 600 => 'O texto deve ter de 5 a 600 caracteres.',
            !Formulario::dataValida($expira) || $expira < now()->toDateString() || $expira > now()->addYear()->toDateString()
                => 'Informe até quando o recado vale (hoje ou depois, no máximo 1 ano).',
            default => null,
        };
        if ($erro !== null) {
            return back()->withInput()->with('flash', [['tipo' => 'erro', 'msg' => $erro]]);
        }

        DB::connection('plataforma')->insert('INSERT INTO recados (tipo, titulo, texto, expira_em, usuario) VALUES (?, ?, ?, ?, ?)',
                                             [$tipo, $titulo, $texto, $expira, (string) session('plataforma.usuario.login', '')]);
        return redirect()->route('plataforma.recados.index')
            ->with('flash', [['tipo' => 'ok', 'msg' => 'Recado publicado. Ele aparece nas locadoras até a data de validade.']]);
    }

    public function desativar(string $id): RedirectResponse
    {
        if (DB::connection('plataforma')->update('UPDATE recados SET ativo = false WHERE id = ? AND ativo', [$id]) === 0) {
            return back()->with('flash', [['tipo' => 'erro', 'msg' => 'Recado não encontrado.']]);
        }
        return redirect()->route('plataforma.recados.index')
            ->with('flash', [['tipo' => 'ok', 'msg' => 'Recado retirado do sistema das locadoras.']]);
    }
}
