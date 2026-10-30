<?php

namespace App\Http\Controllers;

use App\Support\Arquivos;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Entrega de arquivos (logomarca, fotos e assinaturas de vistoria). O caminho vem do banco, nunca da URL, e o banco
 * (com RLS da locadora logada) só acha o registro da própria locadora: de outra, a resposta é 404.
 */
class ArquivosController extends Controller
{
    public function logo(): BinaryFileResponse
    {
        return $this->enviar(DB::selectOne("SELECT NULLIF(logo_arquivo, '') AS a FROM empresa")?->a, 'image/png');
    }

    public function foto(string $id): BinaryFileResponse
    {
        abort_unless(Str::isUuid($id), 404);
        return $this->enviar(DB::selectOne('SELECT arquivo AS a FROM vistoria_fotos WHERE id = ?', [$id])?->a, 'image/jpeg');
    }

    public function assinatura(string $id): BinaryFileResponse
    {
        abort_unless(Str::isUuid($id), 404);
        return $this->enviar(DB::selectOne("SELECT NULLIF(assinatura_arquivo, '') AS a FROM vistorias WHERE id = ?", [$id])?->a, 'image/png');
    }

    private function enviar(?string $relativo, string $mime): BinaryFileResponse
    {
        $caminho = Arquivos::caminho($relativo);
        abort_if($caminho === null || !is_file($caminho), 404);

        return response()->file($caminho, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=86400',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'",
        ]);
    }
}
