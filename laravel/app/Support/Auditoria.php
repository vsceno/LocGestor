<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Auditoria: quem incluiu, alterou ou excluiu o quê. O banco grava pelos gatilhos; aqui só se monta a leitura.
 * Mesmas regras de config_auditoria() do sistema antigo: dados pessoais e senhas aparecem como (oculto).
 */
class Auditoria
{
    public const TABELAS = [
        'locacoes' => 'Locação', 'devolucoes' => 'Devolução', 'reservas' => 'Reserva', 'clientes' => 'Cliente',
        'produtos' => 'Produto', 'kits' => 'Kit', 'categorias' => 'Categoria', 'unidades' => 'Unidade (patrimônio)',
        'manutencoes' => 'Manutenção', 'caixa' => 'Caixa', 'usuarios' => 'Usuário', 'empresa' => 'Empresa',
        'acesso_suporte' => 'Acesso de suporte', 'orcamentos' => 'Orçamento', 'recibos' => 'Recibo',
    ];
    public const ACOES = ['I' => 'incluiu', 'U' => 'alterou', 'D' => 'excluiu'];
    public const CORES = ['I' => 'selo-verde', 'U' => 'selo-azul', 'D' => 'selo-vermelho'];

    /** Valor de um campo para a tela (texto curto). */
    public static function valor(mixed $v): string
    {
        return match (true) {
            $v === null  => '—',
            $v === true  => 'sim',
            $v === false => 'não',
            $v === '***' => '(oculto)',
            is_array($v) => json_encode($v, JSON_UNESCAPED_UNICODE),
            default      => mb_strimwidth((string) $v, 0, 60, '…'),
        };
    }

    /**
     * Uma linha pronta para a tela: quando, quem, ação, o quê (com link quando o registro ainda existe) e os detalhes.
     *
     * @return array{quando: string, usuario: string, acao: string, cor: string, texto: string, url: ?string, historico: bool, registro: ?string, detalhes: array<int, string>}
     */
    public static function linha(object $a): array
    {
        $dados = json_decode((string) $a->dados, true) ?: [];
        $numerado = in_array($a->tabela, ['locacoes', 'reservas', 'manutencoes'], true);
        $texto = (self::TABELAS[$a->tabela] ?? $a->tabela) . ($a->rotulo !== '' ? ($numerado ? ' nº ' : ' ') . $a->rotulo : '');

        $url = null;
        if ($a->acao !== 'D' && $a->registro_id !== null) {
            $url = match ($a->tabela) {
                'locacoes'    => $a->rotulo !== '' ? route('locacoes.show', $a->rotulo) : null,
                'clientes'    => route('clientes.show', $a->registro_id),
                'produtos'    => route('produtos.edit', $a->registro_id),
                'unidades'    => route('unidades.show', $a->registro_id),
                'manutencoes' => route('manutencoes.show', $a->registro_id),
                'kits'        => route('kits.edit', $a->registro_id),
                default       => null,
            };
        }

        if ($a->acao === 'U') {
            // Alteração: cada campo com o antes e o depois
            $detalhes = [];
            foreach ($dados as $campo => [$antes, $depois]) {
                $mudanca = $antes === '***' ? '(oculto, alterado)' : self::valor($antes) . ' → ' . self::valor($depois);
                $detalhes[] = str_replace('_', ' ', (string) $campo) . ': ' . $mudanca;
            }
        } else {
            // Inclusão ou exclusão: só os campos preenchidos, numa linha
            $preenchidos = array_filter($dados, fn ($v, $k) => $k !== 'id' && $v !== null && $v !== '', ARRAY_FILTER_USE_BOTH);
            $detalhes = [implode(' · ', array_map(fn ($k, $v) => str_replace('_', ' ', (string) $k) . ': ' . self::valor($v),
                array_keys($preenchidos), array_values($preenchidos)))];
        }

        return [
            'quando'    => data_br($a->quando, true),
            'usuario'   => (string) $a->usuario,
            'acao'      => self::ACOES[$a->acao] ?? $a->acao,
            'cor'       => self::CORES[$a->acao] ?? 'selo-cinza',
            'texto'     => $texto,
            'url'       => $url,
            'historico' => $a->registro_id !== null && Str::isUuid((string) $a->registro_id),
            'registro'  => $a->registro_id !== null ? (string) $a->registro_id : null,
            'detalhes'  => array_values(array_filter($detalhes, fn ($d) => $d !== '')),
        ];
    }
}
