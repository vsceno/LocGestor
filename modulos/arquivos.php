<?php
/*
 * Entrega de arquivos (fotos de vistoria e assinaturas).
 * O arquivo só sai depois de o banco, com o RLS da locadora logada, confirmar que o
 * registro existe para ela: de outra locadora, a resposta é 404, como se não existisse.
 */

declare(strict_types=1);

function arquivo_enviar(?string $relativo, string $tipoMime): never
{
    $caminho = $relativo !== null ? arquivo_caminho($relativo) : null;
    if ($caminho === null || !is_file($caminho)) {
        http_response_code(404);
        exit('Arquivo não encontrado.');
    }
    header('Content-Type: ' . $tipoMime);
    header('Content-Length: ' . filesize($caminho));
    header('Cache-Control: private, max-age=86400');
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'");
    header('Content-Disposition: inline');
    readfile($caminho);
    exit;
}

/** index.php?p=arquivos&a=foto&id=<uuid da foto> */
function arquivos_foto(): void
{
    $id = get('id');
    arquivo_enviar(is_uuid($id) ? val('SELECT arquivo FROM vistoria_fotos WHERE id = :id', ['id' => $id]) : null, 'image/jpeg');
}

/** index.php?p=arquivos&a=assinatura&id=<uuid da vistoria> */
function arquivos_assinatura(): void
{
    $id = get('id');
    $rel = is_uuid($id) ? val("SELECT NULLIF(assinatura_arquivo, '') FROM vistorias WHERE id = :id", ['id' => $id]) : null;
    arquivo_enviar($rel, 'image/png');
}
