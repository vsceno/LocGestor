<?php

namespace App\Http\Controllers;

use App\Support\Configuracoes;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Logomarca da LocGestor: a enviada na tela Configurações ou, enquanto não houver, a padrão. É pública (aparece na entrada). */
class MarcaController extends Controller
{
    public function logo(): BinaryFileResponse
    {
        $caminho = Configuracoes::logoEnviada() ? Configuracoes::logoCaminho() : public_path('assets/brand/emblema-locgestor-256.png');

        return response()->file($caminho, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
