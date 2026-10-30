<?php

namespace App\Http\Controllers;

use App\Support\Formulario;
use App\Support\Recusa;
use App\Support\Roteiro;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Roteiro do motorista (feito para o celular): entregas e coletas do dia, com mapa, ligação, WhatsApp e o registro
 * de "feito". Mesmas regras de roteiro_marcar() e roteiro_desmarcar() do sistema antigo.
 */
class RoteiroController extends Controller
{
    public function index(Request $request): View
    {
        $data = Formulario::dataValida((string) $request->query('data', '')) ? (string) $request->query('data') : now()->toDateString();
        $paradas = Roteiro::paradas($data);
        return view('roteiro.index', [
            'data' => $data,
            'paradas' => $paradas,
            'feitas' => count(array_filter($paradas, fn ($p) => $p['feita'] !== null)),
            'hoje' => now()->toDateString(),
            'anterior' => Carbon::parse($data)->subDay()->toDateString(),
            'seguinte' => Carbon::parse($data)->addDay()->toDateString(),
            'empresa' => DB::selectOne('SELECT * FROM empresa'),
        ]);
    }

    public function marcar(Request $request): RedirectResponse
    {
        try {
            [$p, $data] = $this->paradaDoPost($request);
            if ($data > now()->toDateString()) {
                throw new Recusa('Só é possível marcar paradas de hoje ou de dias anteriores.');
            }
            DB::insert('INSERT INTO entregas (locacao_id, tipo, data, obs, usuario_id) VALUES (?, ?, ?, ?, ?)
                        ON CONFLICT (tenant_id, locacao_id, tipo, data) DO NOTHING',
                       [$p['id'], $p['tipo'], $data, mb_substr((string) $request->input('obs', ''), 0, 200), session('usuario.id')]);
        } catch (Recusa $e) {
            return back()->with('flash', [['tipo' => 'erro', 'msg' => $e->getMessage()]]);
        }
        $msg = (Roteiro::TIPOS[$p['tipo']]) . " da locação nº {$p['numero']} registrada."
             . ($p['tipo'] === 'coleta' ? ' Na loja, registre a devolução para conferir os itens.' : '');
        return redirect()->route('roteiro.index', ['data' => $data])->with('flash', [['tipo' => 'ok', 'msg' => $msg]]);
    }

    public function desmarcar(Request $request): RedirectResponse
    {
        try {
            [$p, $data] = $this->paradaDoPost($request);
        } catch (Recusa $e) {
            return back()->with('flash', [['tipo' => 'erro', 'msg' => $e->getMessage()]]);
        }
        DB::delete('DELETE FROM entregas WHERE locacao_id = ? AND tipo = ? AND data = ?', [$p['id'], $p['tipo'], $data]);
        return redirect()->route('roteiro.index', ['data' => $data])->with('flash', [['tipo' => 'ok', 'msg' => 'Registro desfeito.']]);
    }

    /** Locação, tipo e data do formulário, conferidos contra o roteiro daquele dia: [parada, data]. */
    private function paradaDoPost(Request $request): array
    {
        $id = (string) $request->input('id', '');
        $tipo = (string) $request->input('tipo', '');
        $data = (string) $request->input('data', '');
        if (!Str::isUuid($id) || !array_key_exists($tipo, Roteiro::TIPOS) || !Formulario::dataValida($data)) {
            throw new Recusa('Parada inválida.');
        }
        foreach (Roteiro::paradas($data) as $p) {
            if ($p['id'] === $id && $p['tipo'] === $tipo) {
                return [$p, $data];
            }
        }
        throw new Recusa('Esta parada não está no roteiro do dia.');
    }
}
