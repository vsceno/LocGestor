<?php
/*
 * LocGestor - arquivos enviados (fotos de vistoria e assinaturas).
 *
 * Segurança:
 *   - toda imagem é aberta e REGRAVADA pelo GD: o que fica no disco é sempre um JPEG/PNG
 *     limpo, sem metadados (o GPS do celular some) e sem conteúdo escondido;
 *   - nomes aleatórios (128 bits) numa pasta por locadora; a pasta é bloqueada para a web
 *     e os arquivos só saem por modulos/arquivos.php, depois de o banco (com RLS)
 *     confirmar que o registro é da locadora logada.
 * Em produção, prefira 'pasta_arquivos' FORA da pasta do site (config.php).
 */

declare(strict_types=1);

const FOTO_LADO_MAX = 1600;        // pixels no maior lado
const FOTO_BYTES_MAX = 15728640;   // 15 MB por arquivo enviado (o navegador já reduz antes)
const FOTOS_POR_VISTORIA = 12;
const IMAGEM_PIXELS_MAX = 40000000; // 40 megapixels: acima disso, recusa (evita estourar a memória)

function arquivos_base(): string
{
    return rtrim((string) config('pasta_arquivos', RAIZ . '/storage'), '/\\');
}

/** Caminho absoluto de um arquivo guardado; null se o caminho relativo não tiver o formato esperado. */
function arquivo_caminho(string $relativo): ?string
{
    if (!preg_match('#^[0-9a-f-]{36}/\d{4}/[0-9a-f]{32}\.(jpg|png)$#', $relativo)) {
        return null;
    }
    return arquivos_base() . '/' . $relativo;
}

/**
 * Abre a imagem (JPEG, PNG ou WebP), corrige a rotação da câmera, reduz para no máximo
 * $ladoMax e devolve os bytes regravados: JPEG (fotos) ou PNG com fundo branco (assinatura).
 * @return array{bytes:string, largura:int, altura:int}
 */
function imagem_regravar(string $bytes, int $ladoMax, bool $png = false): array
{
    if (!function_exists('imagecreatefromstring')) {
        throw new UserError('O servidor está sem a extensão gd do PHP, necessária para fotos e assinaturas. Avise o suporte.');
    }
    $info = @getimagesizefromstring($bytes);
    if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
        throw new UserError('Arquivo não é uma imagem válida (use JPG, PNG ou WebP).');
    }
    [$w, $h] = $info;
    if ($w < 1 || $h < 1 || $w * $h > IMAGEM_PIXELS_MAX) {
        throw new UserError('Imagem grande demais. Envie uma foto de até 40 megapixels.');
    }
    $img = @imagecreatefromstring($bytes);
    if ($img === false) {
        throw new UserError('Não foi possível ler a imagem enviada.');
    }

    // Foto de celular "deitada": aplica a orientação gravada pela câmera
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($bytes));
        $giro = [3 => 180, 6 => -90, 8 => 90][(int) ($exif['Orientation'] ?? 1)] ?? 0;
        if ($giro !== 0) {
            $img = imagerotate($img, $giro, 0);
            [$w, $h] = [imagesx($img), imagesy($img)];
        }
    }

    $escala = min(1, $ladoMax / max($w, $h));
    $nw = max(1, (int) round($w * $escala));
    $nh = max(1, (int) round($h * $escala));
    $saida = imagecreatetruecolor($nw, $nh);
    imagefill($saida, 0, 0, imagecolorallocate($saida, 255, 255, 255)); // fundo branco (PNG transparente)
    imagecopyresampled($saida, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);

    ob_start();
    $png ? imagepng($saida, null, 9) : imagejpeg($saida, null, 82);
    return ['bytes' => (string) ob_get_clean(), 'largura' => $nw, 'altura' => $nh, 'gd' => $saida];
}

/** Assinatura em branco (quase nenhum traço escuro)? */
function assinatura_vazia(GdImage $img): bool
{
    $w = imagesx($img);
    $h = imagesy($img);
    $tracos = 0;
    for ($y = 0; $y < $h; $y += 3) {
        for ($x = 0; $x < $w; $x += 3) {
            $c = imagecolorat($img, $x, $y);
            if ((($c >> 16) & 0xFF) + (($c >> 8) & 0xFF) + ($c & 0xFF) < 384) {
                if (++$tracos >= 30) {
                    return false;
                }
            }
        }
    }
    return true;
}

/** Grava os bytes numa pasta da locadora atual e devolve o caminho relativo. */
function arquivo_gravar(string $bytes, string $ext): string
{
    $relativo = tenant_id() . '/' . date('Y') . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
    $destino = arquivo_caminho($relativo);
    if ($destino === null) {
        throw new RuntimeException('Caminho de arquivo inválido.');
    }
    $pasta = dirname($destino);
    if (!is_dir($pasta) && !@mkdir($pasta, 0770, true) && !is_dir($pasta)) {
        throw new RuntimeException("Não foi possível criar a pasta de arquivos ($pasta).");
    }
    // Programas de sincronização/antivírus podem travar a pasta por instantes: tenta algumas vezes
    for ($i = 0; $i < 5; $i++) {
        if (@file_put_contents($destino, $bytes, LOCK_EX) === strlen($bytes)) {
            return $relativo;
        }
        usleep(100000 * ($i + 1));
    }
    throw new RuntimeException('Não foi possível gravar o arquivo enviado.');
}

function arquivo_apagar(string $relativo): void
{
    $c = arquivo_caminho($relativo);
    if ($c !== null && is_file($c)) {
        @unlink($c);
    }
}

/** Lista de uploads de um campo "nome[]" no formato [['tmp', 'nome', 'tamanho', 'erro'], ...] (sem os vazios). */
function uploads(string $campo): array
{
    $f = $_FILES[$campo] ?? null;
    if (!is_array($f) || !is_array($f['name'] ?? null)) {
        return [];
    }
    $lista = [];
    foreach ($f['name'] as $i => $nome) {
        if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $lista[] = ['tmp' => $f['tmp_name'][$i], 'nome' => (string) $nome, 'tamanho' => (int) $f['size'][$i], 'erro' => (int) $f['error'][$i]];
    }
    return $lista;
}
