<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Contas a pagar, recorrentes e previsão de caixa. Mesmas regras de conta_salvar(), recorrente_salvar(),
 * conta_pagar(), conta_desfazer_pagamento(), contas_gerar_recorrentes() e previsao_caixa() do sistema antigo.
 */
class Contas
{
    public const SITUACOES = ['abertas' => 'Em aberto', 'pagas' => 'Pagas', 'todas' => 'Todas'];

    /** Até onde as recorrentes geram parcelas (e a previsão de caixa olha). */
    public const HORIZONTE_DIAS = 90;

    /**
     * Descrição, categoria e valor, comuns à conta avulsa e à recorrente. Devolve [campos, erros].
     *
     * @return array{0: array{descricao: string, categoria: string, valor: ?float}, 1: array<string, string>}
     */
    public static function campos(array $d): array
    {
        $c = [
            'descricao' => trim((string) ($d['descricao'] ?? '')),
            'categoria' => trim((string) ($d['categoria'] ?? '')),
            'valor' => valor_br($d['valor'] ?? ''),
        ];
        $e = [];
        if (mb_strlen($c['descricao']) < 3 || mb_strlen($c['descricao']) > 200) {
            $e['descricao'] = 'Informe a descrição (3 a 200 caracteres).';
        }
        if ($c['categoria'] === '' || mb_strlen($c['categoria']) > 60) {
            $e['categoria'] = 'Informe a categoria (até 60 caracteres).';
        }
        if ($c['valor'] === null || $c['valor'] <= 0 || $c['valor'] > 9999999999) {
            $e['valor'] = 'O valor deve ser maior que zero.';
        }
        return [$c, $e];
    }

    /**
     * Gera as parcelas das recorrentes ativas até HORIZONTE_DIAS à frente. Continua de onde parou (gerado_ate):
     * parcela excluída não volta, e a primeira geração não cria parcela já vencida.
     */
    public static function gerarRecorrentes(): void
    {
        DB::transaction(function () {
            $hoje = now()->toDateString();
            $limite = now()->addDays(self::HORIZONTE_DIAS)->toDateString();
            foreach (DB::select('SELECT * FROM contas_recorrentes WHERE ativa FOR UPDATE') as $r) {
                // Primeiro mês a gerar: o seguinte ao último gerado, ou o do início
                $mes = $r->gerado_ate !== null
                    ? date('Y-m-01', strtotime($r->gerado_ate . ' first day of next month'))
                    : date('Y-m-01', strtotime($r->inicio));
                $ultimo = $r->gerado_ate;
                // Primeira geração (ou refeita) não cria parcelas já vencidas; depois segue mês a mês, sem pular
                $piso = $r->gerado_ate === null ? max($r->inicio, $hoje) : $r->inicio;
                while (true) {
                    $venc = date('Y-m-', strtotime($mes)) . sprintf('%02d', $r->dia);
                    if ($venc > $limite) {
                        break;
                    }
                    if ($venc >= $piso) {
                        DB::insert('INSERT INTO contas_pagar (descricao, categoria, valor, vencimento, recorrente_id)
                                    VALUES (?, ?, ?, ?, ?) ON CONFLICT (tenant_id, recorrente_id, vencimento) DO NOTHING',
                                   [$r->descricao, $r->categoria, $r->valor, $venc, $r->id]);
                        $ultimo = $venc;
                    }
                    $mes = date('Y-m-01', strtotime($mes . ' +1 month'));
                }
                if ($ultimo !== $r->gerado_ate) {
                    DB::update('UPDATE contas_recorrentes SET gerado_ate = ? WHERE id = ?', [$ultimo, $r->id]);
                }
            }
        });
    }

    /**
     * Previsão para os próximos $dias: saldo de hoje, mais o que entra (saldos das locações e reservas estimadas),
     * menos o que sai (contas em aberto), semana a semana. Vencidos entram na primeira semana.
     */
    public static function previsao(int $dias): array
    {
        self::gerarRecorrentes();
        $hoje = now()->toDateString();
        $limite = now()->addDays($dias)->toDateString();
        $saldo = (float) DB::selectOne("SELECT COALESCE(SUM(CASE WHEN tipo = 'E' THEN valor ELSE -valor END), 0) AS v
                                          FROM caixa WHERE data <= CURRENT_DATE")->v;

        $itens = [];
        // A receber: locações abertas (no fim previsto) e devolvidas com saldo (já vencido)
        foreach (DB::select("SELECT l.numero, c.nome, l.status, l.data_prev_devolucao,
                                    GREATEST(v.valor_previsto, v.valor_atual) - v.valor_pago AS aberta_saldo,
                                    v.valor_atual - v.valor_pago AS saldo
                               FROM locacoes l JOIN clientes c ON c.id = l.cliente_id JOIN vw_locacao_valores v ON v.locacao_id = l.id
                              WHERE l.status <> 'cancelada'
                                AND (CASE WHEN l.status = 'aberta' THEN GREATEST(v.valor_previsto, v.valor_atual) ELSE v.valor_atual END) - v.valor_pago > 0.004") as $l) {
            $aberta = $l->status === 'aberta';
            $data = $aberta ? $l->data_prev_devolucao : $hoje;
            if ($data > $limite) {
                continue;
            }
            $itens[] = ['tipo' => 'E', 'data' => max($data, $hoje), 'vencido' => $data < $hoje || !$aberta,
                        'valor' => (float) ($aberta ? $l->aberta_saldo : $l->saldo),
                        'descricao' => "Locação nº {$l->numero} - {$l->nome}"];
        }
        // Reservas ativas que terminam no horizonte: valor estimado pelos preços atuais (automático ou período escolhido)
        foreach (DB::select("SELECT r.numero, c.nome, r.data_fim,
                                    ri.quantidade * CASE WHEN COALESCE(ri.periodo, 'automatico') = 'automatico'
                                        THEN preco_periodo(dias.n, p.valor_diaria, p.valor_semanal, p.valor_mensal)
                                        ELSE preco_plano(dias.n, ri.periodo, p.valor_diaria, p.valor_semanal, p.valor_quinzenal, p.valor_mensal) END AS valor
                               FROM reservas r JOIN clientes c ON c.id = r.cliente_id
                               JOIN reserva_itens ri ON ri.reserva_id = r.id JOIN produtos p ON p.id = ri.produto_id
                               CROSS JOIN LATERAL (SELECT GREATEST(1, r.data_fim - r.data_inicio + 1) AS n) dias
                              WHERE r.status = 'ativa' AND r.data_fim BETWEEN CURRENT_DATE AND CAST(? AS date)", [$limite]) as $r) {
            $chave = "R{$r->numero}";
            if (isset($itens[$chave])) {
                $itens[$chave]['valor'] += (float) $r->valor;
            } else {
                $itens[$chave] = ['tipo' => 'E', 'data' => $r->data_fim, 'vencido' => false, 'valor' => (float) $r->valor,
                                  'descricao' => "Reserva nº {$r->numero} - {$r->nome} (estimado)"];
            }
        }
        // A pagar: contas em aberto até o fim do horizonte
        foreach (DB::select('SELECT descricao, vencimento, valor FROM contas_pagar WHERE pago_em IS NULL AND vencimento <= CAST(? AS date)',
                            [$limite]) as $c) {
            $itens[] = ['tipo' => 'S', 'data' => max($c->vencimento, $hoje), 'vencido' => $c->vencimento < $hoje,
                        'valor' => (float) $c->valor, 'descricao' => $c->descricao];
        }
        $itens = array_values($itens);
        usort($itens, fn ($a, $b) => [$a['data'], $a['tipo']] <=> [$b['data'], $b['tipo']]);

        // Semanas a partir de hoje
        $semanas = [];
        $acumulado = $saldo;
        for ($i = 0; $i * 7 < $dias; $i++) {
            $ini = date('Y-m-d', strtotime('+' . ($i * 7) . ' days'));
            $fim = min(date('Y-m-d', strtotime('+' . ($i * 7 + 6) . ' days')), $limite);
            $doPeriodo = array_filter($itens, fn ($x) => $x['data'] >= $ini && $x['data'] <= $fim);
            $entra = array_sum(array_map(fn ($x) => $x['tipo'] === 'E' ? $x['valor'] : 0, $doPeriodo));
            $sai = array_sum(array_map(fn ($x) => $x['tipo'] === 'S' ? $x['valor'] : 0, $doPeriodo));
            $acumulado += $entra - $sai;
            $semanas[] = ['inicio' => $ini, 'fim' => $fim, 'entradas' => round($entra, 2), 'saidas' => round($sai, 2),
                          'saldo' => round($acumulado, 2)];
        }
        $vencido = fn (string $tipo) => round(array_sum(array_map(fn ($x) => $x['tipo'] === $tipo && $x['vencido'] ? $x['valor'] : 0, $itens)), 2);

        return ['saldo' => round($saldo, 2), 'semanas' => $semanas, 'itens' => $itens,
                'receber_vencido' => $vencido('E'), 'pagar_vencido' => $vencido('S')];
    }
}
