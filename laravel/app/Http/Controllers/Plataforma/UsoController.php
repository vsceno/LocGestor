<?php

namespace App\Http\Controllers\Plataforma;

use App\Http\Controllers\Controller;
use App\Support\Locadoras;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Uso por locadora: o que cada uma faz no sistema, sem dados pessoais. Só leitura. */
class UsoController extends Controller
{
    public function index(): View
    {
        $locadoras = DB::connection('plataforma')->select('SELECT id, slug, nome, status, limite_usuarios FROM tenants ORDER BY nome');
        Locadoras::uso($locadoras);
        // As que mais locaram no mês primeiro; empate por nome
        usort($locadoras, fn ($a, $b) => [$b->locacoes_mes, $a->nome] <=> [$a->locacoes_mes, $b->nome]);

        return view('plataforma.uso', [
            'locadoras' => $locadoras,
            'locacoes' => array_sum(array_column($locadoras, 'locacoes_mes')),
            'semAcesso' => count(array_filter($locadoras, fn ($t) => $t->status === 'ativa' && $t->usuarios_30d === 0)),
            'fotosMb' => array_sum(array_column($locadoras, 'fotos_bytes')) / 1048576,
            'totalRecursos' => count(Locadoras::RECURSOS_USO),
        ]);
    }
}
