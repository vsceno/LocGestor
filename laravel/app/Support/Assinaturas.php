<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Mensalidade e situação das locadoras no painel da plataforma. Mesmas regras de suspender_inadimplentes(),
 * assinatura_situacao(), locadora_alertas(), locadora_passa_filtro() e assinatura_do_form() do sistema antigo.
 * Tudo roda pela conexão 'plataforma' (papel locgestor_plataforma).
 */
class Assinaturas
{
    public const FILTROS = [
        'todas' => 'Todas',
        'atencao' => 'Precisam de atenção',
        'vencidas' => 'Mensalidade vencida',
        'vencendo' => 'Vencem em até 5 dias',
        'suspensas' => 'Suspensas',
        'sem_acesso' => 'Sem acesso há 7 dias',
        'sem_cobranca' => 'Sem cobrança',
    ];

    /** Locadoras ativas com mensalidade vencida além da carência ficam suspensas por inadimplência. */
    public static function suspenderInadimplentes(): void
    {
        $suspensas = DB::connection('plataforma')->select("UPDATE tenants SET status = 'suspensa', motivo_suspensao = 'inadimplencia'
                WHERE status = 'ativa' AND vencimento IS NOT NULL AND vencimento + carencia_dias < CURRENT_DATE
                RETURNING slug");
        foreach ($suspensas as $t) {
            Log::warning("Locadora {$t->slug} suspensa por inadimplência.");
        }
    }

    /** Dias até o vencimento (negativo = já passou). */
    public static function diasAte(string $vencimento): int
    {
        return (int) now()->startOfDay()->diffInDays(Carbon::parse($vencimento)->startOfDay(), false);
    }

    /** Último dia em que a locadora usa o sistema sem pagar (vencimento + carência); null = sem cobrança. */
    public static function limitePagamento(object $t): ?string
    {
        if (empty($t->vencimento)) {
            return null;
        }
        return Carbon::parse($t->vencimento)->addDays((int) $t->carencia_dias)->toDateString();
    }

    /** Passou da carência: o acesso da locadora fica bloqueado. */
    public static function inadimplente(object $t): bool
    {
        $limite = self::limitePagamento($t);
        return $limite !== null && now()->toDateString() > $limite;
    }

    /** Situação da mensalidade: [rótulo, classe do selo]. */
    public static function situacao(object $t): array
    {
        if (empty($t->vencimento)) {
            return ['sem cobrança', 'selo-cinza'];
        }
        if (self::inadimplente($t)) {
            return ['inadimplente', 'selo-vermelho'];
        }
        $dias = self::diasAte($t->vencimento);
        if ($dias < 0) {
            return ['vencida há ' . (-$dias) . ' dia(s)', 'selo-amarelo'];
        }
        if ($dias <= 5) {
            return [$dias === 0 ? 'vence hoje' : "vence em $dias dia(s)", 'selo-amarelo'];
        }
        return ['em dia', 'selo-verde'];
    }

    /** Motivos para olhar a locadora agora (lista vazia = tudo certo). */
    public static function alertas(object $t): array
    {
        $motivos = [];
        if ($t->status === 'suspensa') {
            $motivos[] = $t->motivo_suspensao === 'inadimplencia' ? 'suspensa por inadimplência' : 'suspensa';
        }
        if (!empty($t->vencimento)) {
            $dias = self::diasAte($t->vencimento);
            if ($dias < 0) {
                $motivos[] = 'mensalidade vencida há ' . (-$dias) . ' dia(s)';
            } elseif ($dias <= 5) {
                $motivos[] = $dias === 0 ? 'mensalidade vence hoje' : "mensalidade vence em $dias dia(s)";
            }
        }
        $referencia = $t->ultimo_acesso ?? $t->criado_em;
        if ($t->status === 'ativa' && $referencia !== null && Carbon::parse($referencia)->lt(now()->subDays(7))) {
            $motivos[] = $t->ultimo_acesso !== null ? 'sem acesso desde ' . data_br($t->ultimo_acesso) : 'nunca acessou';
        }
        return $motivos;
    }

    /** Atende ao filtro escolhido na lista (ver FILTROS). $t precisa de 'alertas' (ver alertas()). */
    public static function passaFiltro(object $t, string $filtro): bool
    {
        return match ($filtro) {
            'atencao' => $t->alertas !== [],
            'vencidas' => !empty($t->vencimento) && self::diasAte($t->vencimento) < 0,
            'vencendo' => !empty($t->vencimento) && ($dias = self::diasAte($t->vencimento)) >= 0 && $dias <= 5,
            'suspensas' => $t->status === 'suspensa',
            'sem_acesso' => array_filter($t->alertas, fn ($m) => str_contains($m, 'acesso') || str_contains($m, 'acessou')) !== [],
            'sem_cobranca' => empty($t->vencimento),
            default => true,
        };
    }

    /** Planos que as locadoras podem escolher (os ativos, do mais barato ao mais caro). */
    public static function planosAtivos(): array
    {
        return DB::connection('plataforma')->select('SELECT id, nome, preco_mensal, limite_usuarios FROM planos WHERE ativo ORDER BY preco_mensal, nome');
    }

    /**
     * Plano, vencimento e carência do formulário, com os erros (lista vazia = tudo certo).
     *
     * @return array{plano: ?object, vencimento: ?string, carencia: int|false, erros: array<int, string>}
     */
    public static function formulario(array $d): array
    {
        $erros = [];
        $planoId = (string) ($d['plano_id'] ?? '');
        $plano = Str::isUuid($planoId)
            ? DB::connection('plataforma')->selectOne('SELECT id, nome, limite_usuarios FROM planos WHERE id = ?', [$planoId])
            : null;
        if ($plano === null) {
            $erros[] = 'Escolha o plano.';
        }
        $venc = trim((string) ($d['vencimento'] ?? ''));
        if ($venc !== '' && !Formulario::dataValida($venc)) {
            $erros[] = 'Data de vencimento inválida.';
        }
        $carencia = filter_var($d['carencia_dias'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 60]]);
        if ($carencia === false) {
            $erros[] = 'A carência deve ser de 0 a 60 dias.';
        }
        return ['plano' => $plano, 'vencimento' => $venc === '' ? null : $venc, 'carencia' => $carencia, 'erros' => $erros];
    }

    /**
     * Registra o pagamento de $meses mensalidades: avança o vencimento (a partir do vencimento atual; sem cobrança, a
     * partir de hoje) e reativa a locadora suspensa por inadimplência. $valor null = preço do plano × meses.
     * Mesma regra de assinatura_registrar_pagamento() do sistema antigo.
     *
     * @return array{t: object, novo: string, valor: float, reativou: bool, pagamento_id: string}
     */
    public static function registrarPagamento(string $locadoraId, int $meses, string $data, ?float $valor, string $forma,
                                              string $obs, ?string $usuarioId): array
    {
        $db = DB::connection('plataforma');
        return $db->transaction(function () use ($db, $locadoraId, $meses, $data, $valor, $forma, $obs, $usuarioId) {
            $t = $db->selectOne(
                'SELECT t.id, t.slug, t.nome, t.vencimento, t.status, t.motivo_suspensao, p.preco_mensal
                   FROM tenants t LEFT JOIN planos p ON p.id = t.plano_id WHERE t.id = ? FOR UPDATE OF t',
                [$locadoraId]
            );
            if ($t === null) {
                throw new Recusa('Locadora não encontrada.');
            }
            $valor ??= round((float) $t->preco_mensal * $meses, 2);
            if ($valor < 0) {
                throw new Recusa('O valor não pode ser negativo.');
            }
            $base = $t->vencimento ?? now()->toDateString();
            // Soma de meses pelo banco: 31/01 + 1 mês = 28/02 (e não 03/03)
            $novo = $db->selectOne('SELECT (CAST(? AS date) + make_interval(months => ?))::date AS novo', [$base, $meses])->novo;
            $pagamentoId = $db->selectOne(
                'INSERT INTO assinatura_pagamentos (locadora_id, data, meses, valor, forma, vencimento_anterior, vencimento_novo, obs, usuario_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id',
                [$locadoraId, $data, $meses, $valor, $forma, $base, $novo, $obs, $usuarioId]
            )->id;
            $reativou = $t->status === 'suspensa' && $t->motivo_suspensao === 'inadimplencia';
            $db->update("UPDATE tenants SET vencimento = ?,
                    status = CASE WHEN status = 'suspensa' AND motivo_suspensao = 'inadimplencia' THEN 'ativa' ELSE status END,
                    motivo_suspensao = CASE WHEN motivo_suspensao = 'inadimplencia' THEN '' ELSE motivo_suspensao END
                  WHERE id = ?", [$novo, $locadoraId]);
            Log::info('Pagamento de mensalidade registrado.', ['slug' => $t->slug, 'meses' => $meses, 'forma' => $forma, 'vencimento' => $novo]);

            return ['t' => $t, 'novo' => $novo, 'valor' => $valor, 'reativou' => $reativou, 'pagamento_id' => $pagamentoId];
        });
    }
}
