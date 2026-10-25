<?php
/*
 * LocGestor - PIX "copia e cola" (BR Code estático, padrão EMV do Banco Central).
 *
 * Gera o texto que vira o QR Code e o "copia e cola". Não fala com banco nenhum:
 * o cliente paga pelo app do banco dele e a locadora registra o recebimento no
 * sistema quando o dinheiro cair (não há baixa automática sem API do banco).
 *
 * Funções puras (sem banco de dados nem sessão), testadas em tests/funcional.php.
 */

declare(strict_types=1);

/** Campo EMV: identificador (2) + tamanho (2) + valor. */
function pix_campo(string $id, string $valor): string
{
    return $id . str_pad((string) strlen($valor), 2, '0', STR_PAD_LEFT) . $valor;
}

/** CRC16-CCITT (polinômio 0x1021, início 0xFFFF), em 4 dígitos hexadecimais maiúsculos. */
function pix_crc16(string $dados): string
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
function pix_texto(string $s, int $max): string
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

/**
 * Monta o BR Code. $valor null = o pagador digita o valor.
 * $txid: identificação que aparece no extrato (até 25 letras/números; "***" = sem identificação).
 */
function pix_payload(string $chave, string $nome, string $cidade, ?float $valor = null, string $txid = '***'): string
{
    $txid = $txid === '***' ? $txid : substr(preg_replace('/[^A-Za-z0-9]/', '', $txid), 0, 25);
    $conta = pix_campo('00', 'br.gov.bcb.pix') . pix_campo('01', $chave);
    $p = pix_campo('00', '01')
       . pix_campo('26', $conta)
       . pix_campo('52', '0000')                       // categoria do comerciante: não informada
       . pix_campo('53', '986');                       // moeda: real
    if ($valor !== null && $valor > 0) {
        $p .= pix_campo('54', number_format($valor, 2, '.', ''));
    }
    $p .= pix_campo('58', 'BR')
        . pix_campo('59', pix_texto($nome, 25) ?: 'RECEBEDOR')
        . pix_campo('60', pix_texto($cidade, 15) ?: 'BRASIL')
        . pix_campo('62', pix_campo('05', $txid ?: '***'))
        . '6304';                                      // CRC: calculado sobre tudo, inclusive "6304"
    return $p . pix_crc16($p);
}
