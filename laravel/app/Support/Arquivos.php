<?php

namespace App\Support;

use GdImage;
use RuntimeException;

/**
 * Arquivos enviados (fotos de vistoria, assinaturas e logomarca), com as garantias do sistema antigo (app/arquivos.php):
 * toda imagem é aberta e REGRAVADA pelo GD (sem metadados, sem GPS, sem conteúdo escondido); cada arquivo recebe nome
 * aleatório dentro de uma pasta da locadora; e a entrega só acontece depois de o banco (com RLS) confirmar o registro.
 */
class Arquivos
{
    public const FOTO_LADO_MAX = 1600;           // pixels no maior lado
    public const FOTO_BYTES_MAX = 15728640;      // 15 MB por arquivo enviado (o navegador já reduz antes)
    public const FOTOS_POR_VISTORIA = 12;
    public const IMAGEM_PIXELS_MAX = 40000000;   // 40 megapixels: acima disso, recusa (evita estourar a memória)
    public const LOGO_LADO_MAX = 800;            // logomarca: cabe em 60 x 24 mm na impressão
    public const LOGO_BYTES_MAX = 5242880;       // 5 MB

    /** Pasta raiz dos arquivos (fora do site, em produção: LOCGESTOR_PASTA_ARQUIVOS). */
    public static function base(): string
    {
        return rtrim((string) config('locgestor.pasta_arquivos'), '/\\');
    }

    /** Caminho absoluto de um arquivo guardado; null se o caminho relativo não tiver o formato esperado. */
    public static function caminho(?string $relativo): ?string
    {
        if ($relativo === null || !preg_match('#^[0-9a-f-]{36}/\d{4}/[0-9a-f]{32}\.(jpg|png)$#', $relativo)) {
            return null;
        }
        return self::base() . '/' . $relativo;
    }

    /**
     * Abre a imagem (JPEG, PNG ou WebP), corrige a rotação da câmera, reduz para no máximo $ladoMax e devolve os bytes
     * regravados: JPEG (fotos) ou PNG com fundo branco (assinatura).
     * @return array{bytes: string, largura: int, altura: int, gd: GdImage}
     */
    public static function regravar(string $bytes, int $ladoMax, bool $png = false): array
    {
        if (!function_exists('imagecreatefromstring')) {
            throw new Recusa('O servidor está sem a extensão gd do PHP, necessária para fotos e assinaturas. Avise o suporte.');
        }
        $info = @getimagesizefromstring($bytes);
        if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw new Recusa('Arquivo não é uma imagem válida (use JPG, PNG ou WebP).');
        }
        [$w, $h] = $info;
        if ($w < 1 || $h < 1 || $w * $h > self::IMAGEM_PIXELS_MAX) {
            throw new Recusa('Imagem grande demais. Envie uma foto de até 40 megapixels.');
        }
        $img = @imagecreatefromstring($bytes);
        if ($img === false) {
            throw new Recusa('Não foi possível ler a imagem enviada.');
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
        imagefill($saida, 0, 0, imagecolorallocate($saida, 255, 255, 255));   // fundo branco (PNG transparente)
        imagecopyresampled($saida, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);

        ob_start();
        $png ? imagepng($saida, null, 9) : imagejpeg($saida, null, 82);
        return ['bytes' => (string) ob_get_clean(), 'largura' => $nw, 'altura' => $nh, 'gd' => $saida];
    }

    /** Assinatura em branco (quase nenhum traço escuro)? */
    public static function assinaturaVazia(GdImage $img): bool
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

    /** Grava os bytes numa pasta da locadora logada e devolve o caminho relativo. */
    public static function gravar(string $bytes, string $ext): string
    {
        $relativo = Locadora::id() . '/' . date('Y') . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
        $destino = self::caminho($relativo);
        if ($destino === null) {
            throw new RuntimeException('Caminho de arquivo inválido.');
        }
        $pasta = dirname($destino);
        if (!is_dir($pasta) && !@mkdir($pasta, 0770, true) && !is_dir($pasta)) {
            throw new RuntimeException("Não foi possível criar a pasta de arquivos ($pasta).");
        }
        // Programas de sincronização ou antivírus podem travar a pasta por instantes: tenta algumas vezes
        for ($i = 0; $i < 5; $i++) {
            if (@file_put_contents($destino, $bytes, LOCK_EX) === strlen($bytes)) {
                return $relativo;
            }
            usleep(100000 * ($i + 1));
        }
        throw new RuntimeException('Não foi possível gravar o arquivo enviado.');
    }

    public static function apagar(string $relativo): void
    {
        $caminho = self::caminho($relativo);
        if ($caminho !== null && is_file($caminho)) {
            @unlink($caminho);
        }
    }
}
