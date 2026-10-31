<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cobrança automática das mensalidades pelo Asaas. Vida de uma cobrança (assinatura_cobrancas):
 *   1. a rotina diária reserva o vencimento (linha sem gateway_id; o índice único impede duas), cria a cobrança no Asaas
 *      com a nossa id como referência e guarda o id e o link de lá;
 *   2. o pagamento chega pelo webhook ou pela conferência da rotina: as duas consultam a cobrança na API (nunca confiam
 *      só no aviso) e dão baixa com o mesmo registro do pagamento manual.
 * Mesmas regras de app/assinatura.php e de tarefas/diaria.php do sistema antigo.
 */
final class Cobrancas
{
    /** Cobrança do vencimento atual da locadora. Se já existe (pendente ou paga), devolve a existente. */
    public static function gerar(string $locadoraId): object
    {
        $db = DB::connection('plataforma');
        $t = $db->selectOne('SELECT t.*, p.nome AS plano_nome, p.preco_mensal FROM tenants t LEFT JOIN planos p ON p.id = t.plano_id WHERE t.id = ?', [$locadoraId]);
        if ($t === null) {
            throw new Recusa('Locadora não encontrada.');
        }
        if (empty($t->vencimento) || (float) $t->preco_mensal <= 0) {
            throw new Recusa("A locadora {$t->nome} não tem mensalidade a cobrar (sem vencimento ou plano gratuito).");
        }
        if ($t->asaas_cliente_id === '' && strlen(so_digitos($t->cnpj)) !== 14) {
            throw new Recusa("A locadora {$t->slug} está sem CNPJ no cadastro: o Asaas exige o documento para gerar a cobrança.");
        }

        $c = $db->selectOne(
            "INSERT INTO assinatura_cobrancas (locadora_id, vencimento, valor) VALUES (?, ?, ?)
             ON CONFLICT (locadora_id, vencimento) WHERE status <> 'cancelada' DO NOTHING RETURNING *",
            [$t->id, $t->vencimento, $t->preco_mensal]
        );
        if ($c === null) {
            return $db->selectOne("SELECT * FROM assinatura_cobrancas WHERE locadora_id = ? AND vencimento = ? AND status <> 'cancelada'",
                                  [$t->id, $t->vencimento]);
        }

        try {
            // O Asaas não aceita vencimento no passado: mensalidade já vencida é cobrada para hoje
            $p = Asaas::cobrancaCriar(
                Asaas::cliente($t), $c->id, max($t->vencimento, now()->toDateString()), (float) $t->preco_mensal,
                "LocGestor - plano {$t->plano_nome} - mensalidade com vencimento em " . data_br($t->vencimento)
            );
        } catch (AsaasErro $e) {
            // Recusa do Asaas (dados inválidos) libera o vencimento para nova tentativa; falha de rede mantém a reserva
            if ($e->getCode() >= 400 && $e->getCode() < 500) {
                $db->delete('DELETE FROM assinatura_cobrancas WHERE id = ? AND gateway_id IS NULL', [$c->id]);
            }
            throw $e;
        }
        return self::vincular($c->id, $p);
    }

    /** Guarda na cobrança o id e o link do Asaas. */
    public static function vincular(string $id, array $p): object
    {
        if (!is_string($p['id'] ?? null) || !Asaas::idValido($p['id'])) {
            throw new AsaasErro('Resposta inesperada do Asaas ao criar a cobrança.');
        }
        $link = is_string($p['invoiceUrl'] ?? null) && preg_match('#^https?://#', $p['invoiceUrl'])
            ? mb_substr($p['invoiceUrl'], 0, 300)
            : '';
        Log::info("Cobrança {$p['id']} gerada no Asaas.");

        return DB::connection('plataforma')->selectOne(
            'UPDATE assinatura_cobrancas SET gateway_id = ?, link = ?, atualizado_em = now() WHERE id = ? RETURNING *',
            [$p['id'], $link, $id]
        );
    }

    /** Reservas sem id do Asaas (a rotina caiu no meio): vincula a cobrança criada lá ou libera o vencimento. */
    public static function recuperar(): int
    {
        $db = DB::connection('plataforma');
        $n = 0;
        foreach ($db->select("SELECT id FROM assinatura_cobrancas WHERE gateway_id IS NULL AND status = 'pendente'
                               AND criado_em < now() - INTERVAL '2 minutes'") as $c) {
            $p = Asaas::cobrancaPorReferencia($c->id);
            if ($p !== null && empty($p['deleted'])) {
                self::vincular($c->id, $p);
            } else {
                $db->delete('DELETE FROM assinatura_cobrancas WHERE id = ? AND gateway_id IS NULL', [$c->id]);
            }
            $n++;
        }
        return $n;
    }

    /**
     * Confere uma cobrança pendente no Asaas e aplica o resultado. Devolve a situação final ('pendente', 'paga' ou
     * 'cancelada'). Pode ser chamada várias vezes (webhook e rotina juntos).
     */
    public static function sincronizar(string $cobrancaId): string
    {
        $db = DB::connection('plataforma');
        $c = $db->selectOne('SELECT id, status, gateway_id FROM assinatura_cobrancas WHERE id = ?', [$cobrancaId]);
        if ($c === null || $c->status !== 'pendente' || $c->gateway_id === null) {
            return $c?->status ?? '';
        }

        $p = Asaas::cobranca($c->gateway_id);
        if (($p['id'] ?? '') !== $c->gateway_id) {
            throw new AsaasErro('Resposta inesperada do Asaas ao consultar a cobrança.');
        }
        if (in_array($p['status'] ?? '', Asaas::PAGO, true)) {
            self::quitar($c->id, $p);
            return 'paga';
        }
        if (!empty($p['deleted']) || ($p['status'] ?? '') === 'REFUNDED') {
            $db->update("UPDATE assinatura_cobrancas SET status = 'cancelada', atualizado_em = now() WHERE id = ? AND status = 'pendente'", [$c->id]);
            return 'cancelada';
        }
        return 'pendente';
    }

    /** Baixa da cobrança paga: registra 1 mensalidade (igual ao pagamento manual) e liga o pagamento à cobrança. */
    public static function quitar(string $cobrancaId, array $p): void
    {
        $db = DB::connection('plataforma');
        $db->transaction(function () use ($db, $cobrancaId, $p) {
            $c = $db->selectOne('SELECT * FROM assinatura_cobrancas WHERE id = ? FOR UPDATE', [$cobrancaId]);
            if ($c === null || $c->status !== 'pendente') {
                return; // outra chamada já deu baixa
            }

            $data = now()->toDateString();
            foreach (['paymentDate', 'clientPaymentDate', 'confirmedDate'] as $campo) {
                if (is_string($p[$campo] ?? null) && Formulario::dataValida($p[$campo])) {
                    $data = min($p[$campo], now()->toDateString());
                    break;
                }
            }
            $forma = ($p['status'] ?? '') === 'RECEIVED_IN_CASH' ? 'Dinheiro' : (Asaas::FORMAS[$p['billingType'] ?? ''] ?? 'PIX');
            $valor = is_numeric($p['value'] ?? null) ? round((float) $p['value'], 2) : (float) $c->valor;

            $r = Assinaturas::registrarPagamento($c->locadora_id, 1, $data, $valor, $forma, "Asaas {$c->gateway_id}", null);
            $db->update("UPDATE assinatura_cobrancas SET status = 'paga', pago_em = ?, pagamento_id = ?, atualizado_em = now() WHERE id = ?",
                        [$data, $r['pagamento_id'], $c->id]);
        });
    }

    /** Cancela a cobrança pendente, lá e aqui (ex.: vencimento corrigido à mão). */
    public static function cancelar(string $cobrancaId): void
    {
        $db = DB::connection('plataforma');
        $c = $db->selectOne('SELECT id, status, gateway_id FROM assinatura_cobrancas WHERE id = ?', [$cobrancaId]);
        if ($c === null || $c->status !== 'pendente') {
            throw new Recusa('Só cobranças pendentes podem ser canceladas.');
        }
        // Já paga lá? Então dá baixa em vez de cancelar
        if ($c->gateway_id !== null && self::sincronizar($c->id) !== 'pendente') {
            throw new Recusa('Esta cobrança já foi paga ou cancelada no Asaas. A lista foi atualizada.');
        }
        if ($c->gateway_id !== null) {
            Asaas::cancelarCobranca($c->gateway_id);
        }
        $db->update("UPDATE assinatura_cobrancas SET status = 'cancelada', atualizado_em = now() WHERE id = ?", [$c->id]);
        Log::info('Cobrança cancelada.', ['gateway' => $c->gateway_id]);
    }

    /**
     * Rotina diária: confere as pendentes, suspende as inadimplentes e gera as cobranças que vencem em até 'dias_antes'
     * dias. Devolve as linhas do relatório e a quantidade de erros; o erro de uma locadora não para as outras.
     */
    public static function rotina(): array
    {
        $db = DB::connection('plataforma');
        $linhas = [];
        $erros = 0;
        $ativo = Asaas::ativo();

        if ($ativo) {
            try {
                $n = self::recuperar();
                if ($n > 0) {
                    $linhas[] = "$n cobrança(s) interrompida(s) conferida(s).";
                }
            } catch (Throwable $e) {
                $erros++;
                $linhas[] = 'ERRO ao recuperar cobranças: ' . $e->getMessage();
            }
            foreach ($db->select("SELECT c.id, t.slug FROM assinatura_cobrancas c JOIN tenants t ON t.id = c.locadora_id
                                   WHERE c.status = 'pendente' AND c.gateway_id IS NOT NULL ORDER BY c.vencimento") as $c) {
                try {
                    $s = self::sincronizar($c->id);
                    if ($s !== 'pendente') {
                        $linhas[] = "{$c->slug}: cobrança $s.";
                    }
                } catch (Throwable $e) {
                    $erros++;
                    $linhas[] = "ERRO {$c->slug}: " . $e->getMessage();
                }
            }
        } else {
            $linhas[] = 'Asaas não configurado (LOCGESTOR_ASAAS_CHAVE): cobranças automáticas desligadas.';
        }

        Assinaturas::suspenderInadimplentes();

        if ($ativo) {
            $dias = Asaas::config()['dias_antes'];
            foreach ($db->select("SELECT t.id, t.slug FROM tenants t JOIN planos p ON p.id = t.plano_id
                                   WHERE t.cobranca_automatica AND t.vencimento IS NOT NULL AND p.preco_mensal > 0
                                     AND t.vencimento <= CURRENT_DATE + CAST(? AS integer)
                                     AND NOT EXISTS (SELECT 1 FROM assinatura_cobrancas c
                                                      WHERE c.locadora_id = t.id AND c.vencimento = t.vencimento AND c.status <> 'cancelada')
                                   ORDER BY t.vencimento", [$dias]) as $t) {
                try {
                    $c = self::gerar($t->id);
                    $linhas[] = "{$t->slug}: cobrança de " . moeda($c->valor) . ' com vencimento em ' . data_br($c->vencimento) . ' gerada.';
                } catch (Throwable $e) {
                    $erros++;
                    $linhas[] = "ERRO {$t->slug}: " . $e->getMessage();
                }
            }
        }

        foreach ($linhas as $linha) {
            if (str_starts_with($linha, 'ERRO')) {
                Log::error("Rotina de cobranças: $linha");
            }
        }
        return ['linhas' => $linhas, 'erros' => $erros];
    }
}
