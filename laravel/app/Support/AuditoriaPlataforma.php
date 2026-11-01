<?php

namespace App\Support;

/**
 * Auditoria do painel da plataforma (tabela auditoria_plataforma): quem criou, alterou ou excluiu locadoras, planos,
 * pagamentos, recados e usuários do painel. O banco grava pelos gatilhos; aqui só se monta a leitura.
 * Mesma regra de auditoria_index() do sistema antigo.
 */
class AuditoriaPlataforma
{
    public const TABELAS = [
        'tenants' => 'Locadora',
        'planos' => 'Plano',
        'assinatura_pagamentos' => 'Pagamento de mensalidade',
        'recados' => 'Recado',
        'plataforma_usuarios' => 'Usuário do painel',
    ];
    public const ACOES = ['I' => 'incluiu', 'U' => 'alterou', 'D' => 'excluiu'];
    public const CORES = ['I' => 'selo-verde', 'U' => 'selo-azul', 'D' => 'selo-vermelho'];

    /**
     * Uma linha pronta para a tela: quando, quem, ação, o quê e os detalhes.
     *
     * @return array{quando: string, usuario: string, acao: string, cor: string, texto: string, detalhes: array<int, string>}
     */
    public static function linha(object $a): array
    {
        $dados = json_decode((string) $a->dados, true) ?: [];
        $texto = (self::TABELAS[$a->tabela] ?? $a->tabela) . ($a->rotulo !== '' ? ' · ' . $a->rotulo : '');

        if ($a->acao === 'U') {
            // Alteração: cada campo com o antes e o depois
            $detalhes = [];
            foreach ($dados as $campo => [$antes, $depois]) {
                $mudanca = $antes === '***' ? '(oculto, alterado)' : Auditoria::valor($antes) . ' → ' . Auditoria::valor($depois);
                $detalhes[] = str_replace('_', ' ', (string) $campo) . ': ' . $mudanca;
            }
        } else {
            // Inclusão ou exclusão: só os campos preenchidos, numa linha
            $preenchidos = array_filter($dados, fn ($v, $k) => $k !== 'id' && $v !== null && $v !== '', ARRAY_FILTER_USE_BOTH);
            $detalhes = [implode(' · ', array_map(fn ($k, $v) => str_replace('_', ' ', (string) $k) . ': ' . Auditoria::valor($v),
                array_keys($preenchidos), array_values($preenchidos)))];
        }

        return [
            'quando' => data_br($a->quando, true),
            'usuario' => (string) $a->usuario,
            'acao' => self::ACOES[$a->acao] ?? $a->acao,
            'cor' => self::CORES[$a->acao] ?? 'selo-cinza',
            'texto' => $texto,
            'detalhes' => array_values(array_filter($detalhes, fn ($d) => $d !== '')),
        ];
    }
}
