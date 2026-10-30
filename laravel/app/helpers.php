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
        // Com a ponte ligada, passa por /ir: o sistema antigo abre já logado, sem pedir a senha de novo
        if (\App\Support\Ponte::ligada()) {
            return route('ir', ['p' => $modulo] + $params);
        }
        return rtrim(config('locgestor.legado_url'), '/') . '/index.php?' . http_build_query(['p' => $modulo] + $params);
    }
}

if (!function_exists('whatsapp_numero')) {
    /** Número para o wa.me (55 + DDD + número), do celular ou do telefone; null se nenhum servir. Mesma regra do sistema antigo. */
    function whatsapp_numero(object $c): ?string
    {
        foreach ([$c->celular ?? '', $c->telefone ?? ''] as $tel) {
            $d = ltrim(so_digitos($tel), '0');
            if (strlen($d) === 10 || strlen($d) === 11) {
                return '55' . $d;
            }
            if ((strlen($d) === 12 || strlen($d) === 13) && str_starts_with($d, '55')) {
                return $d;
            }
        }
        return null;
    }
}

if (!function_exists('iniciais')) {
    /** Até duas iniciais do nome, sem as preposições ("Francisco das Chagas" → "FC"), para os avatares. */
    function iniciais(string $nome): string
    {
        $palavras = preg_split('/\s+/', trim($nome)) ?: [];
        $uteis = array_values(array_filter($palavras, fn ($p) => !in_array(mb_strtolower($p), ['da', 'das', 'de', 'do', 'dos', 'e'], true)));
        return mb_strtoupper(implode('', array_map(fn ($p) => mb_substr($p, 0, 1), array_slice($uteis ?: $palavras, 0, 2))));
    }
}

if (!function_exists('doc_valido')) {
    /** CPF (11) ou CNPJ (14) com dígitos verificadores certos. Mesma regra do sistema antigo. */
    function doc_valido($doc): bool
    {
        $d = so_digitos($doc);
        if (preg_match('/^(\d)\1*$/', $d)) {
            return false;   // 000.000.000-00, 111..., etc.
        }
        if (strlen($d) === 11) {
            for ($t = 9; $t < 11; $t++) {
                $s = 0;
                for ($i = 0; $i < $t; $i++) {
                    $s += (int) $d[$i] * (($t + 1) - $i);
                }
                if ((int) $d[$t] !== ((10 * $s) % 11) % 10) {
                    return false;
                }
            }
            return true;
        }
        if (strlen($d) === 14) {
            $pesos = [[5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2], [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]];
            foreach ($pesos as $k => $p) {
                $s = 0;
                foreach ($p as $i => $peso) {
                    $s += (int) $d[$i] * $peso;
                }
                $r = $s % 11;
                if ((int) $d[12 + $k] !== ($r < 2 ? 0 : 11 - $r)) {
                    return false;
                }
            }
            return true;
        }
        return false;
    }
}

if (!function_exists('telefone_formatar')) {
    /** (85) 99999-0000 ou (85) 3222-1000; outro formato fica como foi digitado. */
    function telefone_formatar($tel): string
    {
        $d = so_digitos($tel);
        if (strlen($d) === 11) {
            return preg_replace('/(\d{2})(\d{5})(\d{4})/', '($1) $2-$3', $d);
        }
        if (strlen($d) === 10) {
            return preg_replace('/(\d{2})(\d{4})(\d{4})/', '($1) $2-$3', $d);
        }
        return trim((string) $tel);
    }
}

if (!function_exists('ufs')) {
    function ufs(): array
    {
        return ['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB',
                'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'];
    }
}

if (!function_exists('valor_br')) {
    /** Valor digitado em reais ("1.234,50", "R$ 45", "45.5") como no parse_money() antigo. Vazio = 0; inválido = null. */
    function valor_br($s): ?float
    {
        $s = trim(str_replace(['R$', ' '], '', (string) $s));
        if ($s === '') {
            return 0.0;
        }
        if (str_contains($s, ',')) {
            $s = str_replace(['.', ','], ['', '.'], $s);
        }
        return is_numeric($s) ? round((float) $s, 2) : null;
    }
}
