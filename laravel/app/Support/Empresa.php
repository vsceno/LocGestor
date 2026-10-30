<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Dados da locadora (aparecem no menu, no contrato e na fatura), logomarca e chave PIX. Mesmas regras de
 * empresa_salvar(), empresa_logo_salvar() e pix_chave_normalizar() do sistema antigo.
 */
class Empresa
{
    public const TAMANHOS = ['nome_fantasia' => 150, 'ie' => 20, 'endereco' => 200, 'cidade' => 80, 'cep' => 9];

    /** Dados validados e prontos para gravar: [dados, erros]. Erros vazios = pode gravar. */
    public static function validar(array $d): array
    {
        $cnpj = so_digitos($d['cnpj'] ?? '');
        $e = [
            'razao_social'    => trim((string) ($d['razao_social'] ?? '')),
            'nome_fantasia'   => trim((string) ($d['nome_fantasia'] ?? '')),
            'cnpj'            => $cnpj,
            'ie'              => trim((string) ($d['ie'] ?? '')),
            'telefone'        => telefone_formatar($d['telefone'] ?? ''),
            'email'           => trim((string) ($d['email'] ?? '')),
            'endereco'        => trim((string) ($d['endereco'] ?? '')),
            'cidade'          => trim((string) ($d['cidade'] ?? '')),
            'uf'              => strtoupper(trim((string) ($d['uf'] ?? ''))),
            'cep'             => trim((string) ($d['cep'] ?? '')),
            // O navegador envia as quebras de linha do textarea como \r\n: guarda só \n
            'termos_contrato' => trim(str_replace(["\r\n", "\r"], "\n", (string) ($d['termos_contrato'] ?? ''))),
            'obs_fatura'      => trim(str_replace(["\r\n", "\r"], "\n", (string) ($d['obs_fatura'] ?? ''))),
            'pix_chave'       => trim((string) ($d['pix_chave'] ?? '')),
            'pix_nome'        => Pix::texto((string) ($d['pix_nome'] ?? ''), 25),
            'pix_cidade'      => Pix::texto((string) ($d['pix_cidade'] ?? ''), 15),
        ];
        $erros = [];
        if (mb_strlen($e['razao_social']) < 3 || mb_strlen($e['razao_social']) > 150) {
            $erros[] = 'Informe a razão social.';
        }
        if ($cnpj !== '' && (strlen($cnpj) !== 14 || !doc_valido($cnpj))) {
            $erros[] = 'CNPJ inválido.';
        }
        if ($e['email'] !== '' && filter_var($e['email'], FILTER_VALIDATE_EMAIL) === false) {
            $erros[] = 'E-mail inválido.';
        }
        if ($e['uf'] !== '' && !in_array($e['uf'], ufs(), true)) {
            $erros[] = 'UF inválida.';
        }
        foreach (self::TAMANHOS as $campo => $max) {
            if (mb_strlen($e[$campo]) > $max) {
                $erros[] = "O campo $campo aceita no máximo $max caracteres.";
            }
        }
        if ($e['termos_contrato'] === '') {
            $erros[] = 'O texto do contrato não pode ficar vazio.';
        }
        if ($e['pix_chave'] !== '') {
            $chave = self::pixChave($e['pix_chave']);
            if ($chave === null) {
                $erros[] = 'Chave PIX inválida (use CPF, CNPJ, e-mail, celular com DDD ou a chave aleatória).';
            } else {
                $e['pix_chave'] = $chave;
                // Sem nome e cidade próprios, usa os da empresa (o banco exige os dois)
                $e['pix_nome'] = $e['pix_nome'] ?: Pix::texto($e['nome_fantasia'] ?: $e['razao_social'], 25);
                $e['pix_cidade'] = $e['pix_cidade'] ?: Pix::texto($e['cidade'], 15);
                if ($e['pix_cidade'] === '') {
                    $erros[] = 'Informe a cidade do recebedor PIX (ou a cidade da empresa).';
                }
            }
        }
        return [$e, $erros];
    }

    /**
     * Grava os dados e a logomarca juntos: se um falhar, nada muda. Os arquivos (a logomarca nova que não foi
     * gravada e a antiga que saiu) só mexem no disco depois de o banco confirmar.
     */
    public static function salvar(array $dados, ?UploadedFile $logo, bool $remover): void
    {
        $novo = null;
        $saiu = null;
        try {
            DB::transaction(function () use ($dados, $logo, $remover, &$novo, &$saiu) {
                $sets = implode(', ', array_map(fn ($k) => "$k = ?", array_keys($dados)));
                DB::update("UPDATE empresa SET $sets", array_values($dados));
                [$novo, $saiu] = self::logo($logo, $remover);
            });
        } catch (Throwable $e) {
            if ($novo !== null && $novo !== '') {
                Arquivos::apagar($novo);
            }
            throw $e;
        }
        if ($saiu !== null && $saiu !== '') {
            Arquivos::apagar($saiu);
        }
    }

    /**
     * Logomarca dos documentos impressos: regravada em PNG de até LOGO_LADO_MAX pixels na pasta da locadora.
     * Devolve [novo, saiu]: o arquivo gravado (ou null) e o antigo, se mudou (ou null).
     */
    private static function logo(?UploadedFile $arquivo, bool $remover): array
    {
        if ($arquivo === null && !$remover) {
            return [null, null];
        }
        $novo = '';
        if ($arquivo !== null) {
            if (in_array($arquivo->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $arquivo->getSize() > Arquivos::LOGO_BYTES_MAX) {
                throw new Recusa('A logomarca é grande demais (máximo de 5 MB).');
            }
            if (!$arquivo->isValid()) {
                throw new Recusa('Não foi possível receber a logomarca. Tente de novo.');
            }
            $img = Arquivos::regravar((string) file_get_contents($arquivo->getRealPath()), Arquivos::LOGO_LADO_MAX, true);
            $novo = Arquivos::gravar($img['bytes'], 'png');
        }
        $antigo = (string) DB::selectOne('SELECT logo_arquivo FROM empresa')->logo_arquivo;
        DB::update('UPDATE empresa SET logo_arquivo = ?', [$novo]);
        return [$novo !== '' ? $novo : null, $antigo !== '' && $antigo !== $novo ? $antigo : null];
    }

    /**
     * Valida e padroniza a chave PIX como o banco espera; null se inválida.
     * CPF/CNPJ: só dígitos (com dígitos verificadores válidos); celular: +55DDDNÚMERO;
     * e-mail e chave aleatória: em minúsculas.
     */
    public static function pixChave(string $chave): ?string
    {
        $chave = trim($chave);
        if (Str::isUuid($chave)) {
            return strtolower($chave);
        }
        if (str_contains($chave, '@')) {
            return filter_var($chave, FILTER_VALIDATE_EMAIL) && strlen($chave) <= 77 ? strtolower($chave) : null;
        }
        $d = so_digitos($chave);
        if (str_starts_with($chave, '+')) {
            return preg_match('/^55\d{10,11}$/', $d) ? '+' . $d : null;
        }
        if ((strlen($d) === 11 || strlen($d) === 14) && doc_valido($d)) {
            return $d;
        }
        // 10 ou 11 dígitos que não são CPF: celular ou telefone sem o +55
        return (strlen($d) === 10 || strlen($d) === 11) ? '+55' . $d : null;
    }
}
