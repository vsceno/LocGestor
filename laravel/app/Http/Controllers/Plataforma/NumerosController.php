<?php

namespace App\Http\Controllers\Plataforma;

use App\Http\Controllers\Controller;
use App\Support\NumerosFinanceiros;
use App\Support\NumerosNegocio;
use Illuminate\View\View;

/** Números do negócio: receita, locadoras novas e canceladas (12 meses) e a situação da assinatura. Só leitura. */
class NumerosController extends Controller
{
    public function index(): View
    {
        return view('plataforma.numeros', NumerosNegocio::painel() + ['fin' => NumerosFinanceiros::painel()]);
    }
}
