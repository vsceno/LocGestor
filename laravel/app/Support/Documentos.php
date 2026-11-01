<?php

namespace App\Support;

use App\Http\Controllers\NovaLocacaoController;

/**
 * Partes comuns dos documentos impressos (contrato, fatura, romaneio e orçamento): nomes, textos e cálculos que o
 * sistema antigo faz em modulos/locacoes.php, app/layout.php e modulos/orcamentos.php. Os valores de preço vêm das
 * funções do banco (preco_periodo/preco_plano), nunca de conta feita em PHP.
 */
class Documentos
{
    public const MESES = ['', 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto',
                          'setembro', 'outubro', 'novembro', 'dezembro'];

    /** Nome do período na tabela de impressão ("Automático" para itens antigos). */
    public static function periodoNome(string $periodo): string
    {
        return $periodo === 'automatico' ? 'Automático' : (NovaLocacaoController::PERIODOS[$periodo] ?? $periodo);
    }

    /** Preço de 1 unidade no período do item (preco_unitario() do sistema antigo). */
    public static function precoUnitario(object $it): float
    {
        return (float) match ($it->periodo) {
            'semanal'   => $it->valor_semanal,
            'quinzenal' => $it->valor_quinzenal,
            'mensal'    => $it->valor_mensal,
            default     => $it->valor_diaria,   // diária e automático
        };
    }

    /** Acrescenta à lista o nome do período e o preço unitário, como a tabela de impressão mostra. */
    public static function preparar(array $itens): array
    {
        foreach ($itens as $it) {
            $it->periodo_nome = self::periodoNome($it->periodo);
            $it->preco_un = self::precoUnitario($it);
        }
        return $itens;
    }

    /** "27 de outubro de 2026" (data_extenso() do sistema antigo). */
    public static function dataExtenso(string $d): string
    {
        $ts = strtotime($d);
        return date('j', $ts) . ' de ' . self::MESES[(int) date('n', $ts)] . ' de ' . date('Y', $ts);
    }

    /** Dados do locatário como aparecem no documento (locatario_impressao() do sistema antigo). */
    public static function locatario(object $c): array
    {
        $end = trim(implode(', ', array_filter([$c->endereco, $c->numero, $c->bairro])));
        return [
            'nome'      => $c->nome,
            'docRotulo' => $c->tipo === 'PJ' ? 'CNPJ' : 'CPF',
            'doc'       => doc_formatar($c->cpf_cnpj),
            'ieRotulo'  => $c->tipo === 'PJ' ? 'Insc. estadual' : 'RG',
            'ie'        => $c->rg_ie,
            'endereco'  => implode(' · ', array_filter([$end, $c->cidade !== '' ? $c->cidade . '/' . $c->uf : '',
                                                        $c->cep !== '' ? 'CEP ' . $c->cep : ''])),
            'telefones' => array_values(array_filter([$c->telefone, $c->celular])),
            'email'     => $c->email,
        ];
    }

    /**
     * Cláusulas do texto guardado como "CLÁUSULA 1ª - TÍTULO: texto", com parágrafos separados por linha em branco
     * (clausulas_html() do sistema antigo). Devolve blocos: cláusula (título, texto e, na de avarias, a lista de
     * reposição) ou parágrafo solto.
     */
    public static function clausulas(string $texto, array $reposicao = []): array
    {
        $blocos = [];
        foreach (preg_split('/\n\s*\n/', trim($texto)) as $paragrafo) {
            $paragrafo = trim($paragrafo);
            if ($paragrafo === '') {
                continue;
            }
            if (preg_match('/^(CLÁUSULA[^:\n]*):\s*(.*)$/su', $paragrafo, $m)) {
                $blocos[] = [
                    'tipo'      => 'clausula',
                    'titulo'    => $m[1],
                    'texto'     => $m[2],
                    'reposicao' => $reposicao !== [] && str_contains($m[1], 'AVARIAS') ? $reposicao : [],
                ];
            } else {
                $blocos[] = ['tipo' => 'paragrafo', 'texto' => $paragrafo];
            }
        }
        return $blocos;
    }
}
