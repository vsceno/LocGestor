<?php

namespace App\Support;

/**
 * Recibo da mensalidade, documento da LocGestor para cada locadora. Aqui fica o que só o recibo precisa:
 * o valor por extenso (exigido na quitação em papel) e os dados do emitente, lidos da configuração.
 */
final class Recibos
{
    private const UNIDADES = ['', 'um', 'dois', 'três', 'quatro', 'cinco', 'seis', 'sete', 'oito', 'nove', 'dez', 'onze',
        'doze', 'treze', 'quatorze', 'quinze', 'dezesseis', 'dezessete', 'dezoito', 'dezenove'];

    private const DEZENAS = ['', '', 'vinte', 'trinta', 'quarenta', 'cinquenta', 'sessenta', 'setenta', 'oitenta', 'noventa'];

    private const CENTENAS = ['', 'cento', 'duzentos', 'trezentos', 'quatrocentos', 'quinhentos', 'seiscentos', 'setecentos',
        'oitocentos', 'novecentos'];

    /** Valor em reais por extenso: "mil duzentos e cinquenta reais e trinta centavos". */
    public static function valorExtenso(float $valor): string
    {
        $centavos = (int) round($valor * 100);
        $reais = intdiv($centavos, 100);
        $sobra = $centavos % 100;

        $texto = self::inteiro($reais);
        if ($reais >= 1000000 && $reais % 1000000 === 0) {
            $texto .= ' de';   // "dois milhões de reais"
        }
        $texto .= $reais === 1 ? ' real' : ' reais';
        if ($sobra > 0) {
            $texto .= ' e '.self::inteiro($sobra).($sobra === 1 ? ' centavo' : ' centavos');
        }

        return $texto;
    }

    /** Número inteiro por extenso (até 999 milhões). */
    private static function inteiro(int $n): string
    {
        if ($n === 0) {
            return 'zero';
        }
        $grupos = [];
        if ($n >= 1000000) {
            $q = intdiv($n, 1000000);
            $grupos[] = $q === 1 ? 'um milhão' : self::ate999($q).' milhões';
            $n %= 1000000;
        }
        if ($n >= 1000) {
            $q = intdiv($n, 1000);
            $grupos[] = $q === 1 ? 'mil' : self::ate999($q).' mil';
            $n %= 1000;
        }
        if ($n > 0) {
            $grupos[] = self::ate999($n);
        }

        // Liga os grupos: "mil e cinquenta" quando o último é menor que cem, "mil duzentos" quando não é
        $texto = array_shift($grupos);
        foreach ($grupos as $i => $grupo) {
            $ultimo = $i === count($grupos) - 1 && $n > 0 && $n < 100;
            $texto .= ($ultimo ? ' e ' : ' ').$grupo;
        }

        return $texto;
    }

    private static function ate999(int $n): string
    {
        if ($n === 100) {
            return 'cem';
        }
        $partes = [];
        $centenas = intdiv($n, 100);
        $resto = $n % 100;
        if ($centenas > 0) {
            $partes[] = self::CENTENAS[$centenas];
        }
        if ($resto > 0) {
            $partes[] = $resto < 20
                ? self::UNIDADES[$resto]
                : self::DEZENAS[intdiv($resto, 10)].(($resto % 10) > 0 ? ' e '.self::UNIDADES[$resto % 10] : '');
        }

        return implode(' e ', $partes);
    }

    /** Dados da LocGestor que aparecem no recibo. Vazio em razão social ou CNPJ = não emite. */
    public static function emitente(): array
    {
        return (array) config('locgestor.emitente', []);
    }

    public static function emitenteCompleto(): bool
    {
        $e = self::emitente();

        return trim((string) ($e['razao_social'] ?? '')) !== '' && trim((string) ($e['cnpj'] ?? '')) !== '';
    }
}
