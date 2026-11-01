<?php

namespace App\Support;

/**
 * PIX "copia e cola" (BR Code estático, padrão EMV do Banco Central). Cópia fiel de ../app/pix.php:
 * mude os dois juntos (o teste PixTest compara as saídas).
 */
class Pix
{
    /** Campo EMV: identificador (2) + tamanho (2) + valor. */
    public static function campo(string $id, string $valor): string
    {
        return $id . str_pad((string) strlen($valor), 2, '0', STR_PAD_LEFT) . $valor;
    }

    /** CRC16-CCITT (polinômio 0x1021, início 0xFFFF), em 4 dígitos hexadecimais maiúsculos. */
    public static function crc16(string $dados): string
    {
        $crc = 0xFFFF;
        $n = strlen($dados);
        for ($i = 0; $i < $n; $i++) {
            $crc ^= ord($dados[$i]) << 8;
            for ($b = 0; $b < 8; $b++) {
                $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) : ($crc << 1);
                $crc &= 0xFFFF;
            }
        }
        return sprintf('%04X', $crc);
    }

    /** Nome e cidade do recebedor: sem acentos, só caracteres aceitos, cortado no tamanho máximo. */
    public static function texto(string $s, int $max): string
    {
        $s = strtr($s, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'ê' => 'e', 'è' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ò' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n',
            'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'É' => 'E', 'Ê' => 'E', 'È' => 'E', 'Ë' => 'E',
            'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ò' => 'O', 'Ö' => 'O',
            'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ç' => 'C', 'Ñ' => 'N',
        ]);
        $s = preg_replace('/[^A-Za-z0-9 .\-]/', '', $s);
        return substr(trim(preg_replace('/\s+/', ' ', $s)), 0, $max);
    }

    /** Monta o BR Code. $valor null = o pagador digita o valor. $txid: até 25 letras/números ("***" = sem identificação). */
    public static function payload(string $chave, string $nome, string $cidade, ?float $valor = null, string $txid = '***'): string
    {
        $txid = $txid === '***' ? $txid : substr(preg_replace('/[^A-Za-z0-9]/', '', $txid), 0, 25);
        $conta = self::campo('00', 'br.gov.bcb.pix') . self::campo('01', $chave);
        $p = self::campo('00', '01')
           . self::campo('26', $conta)
           . self::campo('52', '0000')
           . self::campo('53', '986');
        if ($valor !== null && $valor > 0) {
            $p .= self::campo('54', number_format($valor, 2, '.', ''));
        }
        $p .= self::campo('58', 'BR')
            . self::campo('59', self::texto($nome, 25) ?: 'RECEBEDOR')
            . self::campo('60', self::texto($cidade, 15) ?: 'BRASIL')
            . self::campo('62', self::campo('05', $txid ?: '***'))
            . '6304';
        return $p . self::crc16($p);
    }
}
