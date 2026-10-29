<?php

/*
 * Formatação usada nas telas (mesmas regras do sistema antigo, app/bootstrap.php).
 */

if (!function_exists('so_digitos')) {
    function so_digitos($v): string
    {
        return preg_replace('/\D+/', '', (string) $v);
    }
}

if (!function_exists('doc_formatar')) {
    /** CPF ou CNPJ com pontuação. */
    function doc_formatar($doc): string
    {
        $d = so_digitos($doc);
        if (strlen($d) === 11) {
            return preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $d);
        }
        if (strlen($d) === 14) {
            return preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $d);
        }
        return (string) $doc;
    }
}

if (!function_exists('moeda')) {
    function moeda($v): string
    {
        $v = round((float) $v, 2);
        return ($v < 0 ? '-' : '') . 'R$ ' . number_format(abs($v), 2, ',', '.');
    }
}

if (!function_exists('numero')) {
    function numero($v, int $casas = 0): string
    {
        return number_format((float) $v, $casas, ',', '.');
    }
}

if (!function_exists('data_br')) {
    function data_br($d, bool $hora = false): string
    {
        if ($d === null || $d === '') {
            return '';
        }
        return date($hora ? 'd/m/Y H:i' : 'd/m/Y', strtotime((string) $d));
    }
}

if (!function_exists('legado')) {
    /** Endereço de uma tela que ainda está no sistema antigo (index.php?p=...). */
    function legado(string $modulo, array $params = []): string
    {
        return rtrim(config('locgestor.legado_url'), '/') . '/index.php?' . http_build_query(['p' => $modulo] + $params);
    }
}
