<?php

namespace App\Http\Controllers\Plataforma;

use App\Http\Controllers\Controller;
use App\Support\Recibos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Recibo de uma mensalidade da locadora. Só o painel da plataforma acessa (a rota fica no grupo SessaoPlataforma),
 * a página nunca é guardada em cache e só sai com os dados do emitente preenchidos.
 */
class RecibosController extends Controller
{
    public function show(string $id, string $pagamento): Response|RedirectResponse
    {
        abort_unless(Str::isUuid($id) && Str::isUuid($pagamento), 404);

        $p = DB::connection('plataforma')->selectOne(
            'SELECT p.*, t.nome AS locadora, t.cnpj AS locadora_cnpj, t.slug
               FROM assinatura_pagamentos p JOIN tenants t ON t.id = p.locadora_id
              WHERE p.id = ? AND p.locadora_id = ? AND p.recibo_numero IS NOT NULL',
            [$pagamento, $id]
        ) ?? abort(404);

        if (! Recibos::emitenteCompleto()) {
            return redirect()->route('plataforma.locadoras.editar', $id)->with('flash', [['tipo' => 'erro',
                'msg' => 'Preencha a razão social e o CNPJ da LocGestor (LOCGESTOR_EMITENTE_RAZAO e LOCGESTOR_EMITENTE_CNPJ) antes de emitir recibos.']]);
        }

        return response()->view('plataforma.recibo', [
            'p' => $p,
            'emitente' => Recibos::emitente(),
            'titulo' => 'Recibo nº '.$p->recibo_numero.' · LocGestor',
            'voltar' => route('plataforma.locadoras.editar', $id),
        ])->header('Cache-Control', 'no-store');
    }
}
