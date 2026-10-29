<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Entrega de arquivos da pasta do sistema antigo. O caminho vem do banco (com RLS da locadora logada)
 * e precisa ter o formato que o sistema grava; nada vem da URL.
 */
class ArquivosController extends Controller
{
    public function logo(): BinaryFileResponse
    {
        $rel = (string) (DB::selectOne("SELECT logo_arquivo FROM empresa")->logo_arquivo ?? '');
        abort_unless(preg_match('#^[0-9a-f-]{36}/\d{4}/[0-9a-f]{32}\.png$#', $rel) === 1, 404);
        $caminho = rtrim(config('locgestor.pasta_arquivos'), '/\\') . '/' . $rel;
        abort_unless(is_file($caminho), 404);

        return response()->file($caminho, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=86400',
            'Content-Security-Policy' => "default-src 'none'",
        ]);
    }
}
