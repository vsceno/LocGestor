<?php
/*
 * LocGestor - mensalidades das locadoras: pagamento, suspensão e cobrança automática pelo Asaas.
 * Usado pelo painel da plataforma, pelo webhook do Asaas e pela rotina diária (todos como locgestor_plataforma).
 *
 * Vida de uma cobrança automática (assinatura_cobrancas):
 *   1. a rotina diária reserva o vencimento (linha sem gateway_id; o índice único impede duas),
 *      cria a cobrança no Asaas com a nossa id como referência e guarda o id e o link de lá;
 *   2. o pagamento chega pelo webhook ou pela conferência da rotina: as duas consultam a cobrança
 *      na API (nunca confiam só no aviso) e dão baixa com o mesmo registro do pagamento manual.
 */

declare(strict_types=1);

/**
 * Suspende (status) quem passou da carência sem pagar. O bloqueio de acesso já acontece
 * sozinho no sistema da locadora; aqui o painel passa a mostrar a situação real.
 */
function suspender_inadimplentes(): void
{
    foreach (all("UPDATE tenants SET status = 'suspensa', motivo_suspensao = 'inadimplencia'
                   WHERE status = 'ativa' AND vencimento IS NOT NULL AND vencimento + carencia_dias < CURRENT_DATE
                RETURNING slug") as $t) {
        log_app('AVISO', "Locadora {$t['slug']} suspensa por inadimplência.");
    }
}

/**
 * Cria a locadora (já validada), os dados padrão (empresa e categorias) e o administrador, numa transação.
 * $t: nome, slug, cnpj, email, telefone, plano [id, nome, limite_usuarios], vencimento (ou null), carencia,
 *     admin_nome, admin_login, admin_senha, trocar_senha. Usado pelo painel e pelo cadastro público. Devolve o id.
 */
function locadora_provisionar(array $t): string
{
    return tx(function () use ($t) {
        $id = (string) val('INSERT INTO tenants (slug, nome, cnpj, email_contato, telefone, plano, limite_usuarios, plano_id, vencimento, carencia_dias)
                            VALUES (:slug, :nome, :cnpj, :email, :tel, :plano, :limite, :pid, :venc, :car) RETURNING id',
            ['slug' => $t['slug'], 'nome' => $t['nome'], 'cnpj' => $t['cnpj'], 'email' => $t['email'], 'tel' => $t['telefone'],
             'plano' => $t['plano']['nome'], 'limite' => $t['plano']['limite_usuarios'], 'pid' => $t['plano']['id'],
             'venc' => $t['vencimento'], 'car' => $t['carencia']]);
        // Contexto da nova locadora só até o fim desta transação (true = SET LOCAL)
        q("SELECT set_config('app.tenant_id', :t, true)", ['t' => $id]);
        // Linha da empresa (com os textos padrão de contrato e fatura) e as 5 categorias padrão
        q('SELECT provisionar_dados_padrao(:nome)', ['nome' => $t['nome']]);
        q("INSERT INTO usuarios (nome, login, senha_hash, perfil, trocar_senha) VALUES (:nome, :login, :hash, 'admin', :trocar)",
          ['nome' => $t['admin_nome'], 'login' => $t['admin_login'], 'hash' => password_hash($t['admin_senha'], PASSWORD_DEFAULT),
           'trocar' => $t['trocar_senha'] ? 'true' : 'false']);
        return $id;
    });
}

/**
 * Pagamento de $meses mensalidades: avança o vencimento e reativa quem estava suspensa por falta de pagamento.
 * $valor null = preço do plano × meses. Devolve [t, novo, valor, reativou, pagamento_id].
 */
function assinatura_registrar_pagamento(string $locadoraId, int $meses, string $data, ?float $valor, string $forma,
                                        string $obs, ?string $usuarioId): array
{
    return tx(function () use ($locadoraId, $meses, $data, $valor, $forma, $obs, $usuarioId) {
        $t = one('SELECT t.id, t.slug, t.nome, t.vencimento, t.status, t.motivo_suspensao, p.preco_mensal
                    FROM tenants t LEFT JOIN planos p ON p.id = t.plano_id WHERE t.id = :id FOR UPDATE OF t', ['id' => $locadoraId]);
        if ($t === null) {
            throw new UserError('Locadora não encontrada.');
        }
        $valor ??= round((float) $t['preco_mensal'] * $meses, 2);
        if ($valor < 0) {
            throw new UserError('O valor não pode ser negativo.');
        }
        $base = $t['vencimento'] ?? today();
        // Soma de meses pelo banco: 31/01 + 1 mês = 28/02 (e não 03/03)
        $novo = (string) val('SELECT (CAST(:b AS date) + make_interval(months => :m))::date', ['b' => $base, 'm' => $meses]);
        $pagamentoId = (string) val('INSERT INTO assinatura_pagamentos (locadora_id, data, meses, valor, forma, vencimento_anterior, vencimento_novo, obs, usuario_id)
                                     VALUES (:l, :d, :m, :v, :f, :va, :vn, :o, :u) RETURNING id',
            ['l' => $locadoraId, 'd' => $data, 'm' => $meses, 'v' => $valor, 'f' => $forma, 'va' => $base, 'vn' => $novo,
             'o' => $obs, 'u' => $usuarioId]);
        $reativou = $t['status'] === 'suspensa' && $t['motivo_suspensao'] === 'inadimplencia';
        q("UPDATE tenants SET vencimento = :v,
                 status = CASE WHEN status = 'suspensa' AND motivo_suspensao = 'inadimplencia' THEN 'ativa' ELSE status END,
                 motivo_suspensao = CASE WHEN motivo_suspensao = 'inadimplencia' THEN '' ELSE motivo_suspensao END
           WHERE id = :id", ['v' => $novo, 'id' => $locadoraId]);
        log_app('INFO', "Pagamento da locadora {$t['slug']}: {$meses} mês(es) por $forma, vencimento agora $novo.");
        return ['t' => $t, 'novo' => $novo, 'valor' => $valor, 'reativou' => $reativou, 'pagamento_id' => $pagamentoId];
    });
}

// ---------------------------------------------------------------------
// Cobrança automática (Asaas)
// ---------------------------------------------------------------------

/**
 * Gera no Asaas a cobrança do vencimento atual da locadora. Se já existe (pendente ou paga), devolve a existente.
 * Recusa do Asaas (dados inválidos) libera o vencimento para nova tentativa; falha de rede mantém a reserva,
 * e cobrancas_recuperar() descobre depois se a cobrança chegou a ser criada lá.
 */
function cobranca_gerar(string $locadoraId): array
{
    $t = one('SELECT t.*, p.nome AS plano_nome, p.preco_mensal FROM tenants t LEFT JOIN planos p ON p.id = t.plano_id WHERE t.id = :id',
             ['id' => $locadoraId]);
    if ($t === null) {
        throw new UserError('Locadora não encontrada.');
    }
    if (empty($t['vencimento']) || (float) $t['preco_mensal'] <= 0) {
        throw new UserError("A locadora {$t['nome']} não tem mensalidade a cobrar (sem vencimento ou plano gratuito).");
    }
    if ($t['asaas_cliente_id'] === '' && strlen(so_digitos($t['cnpj'])) !== 14) {
        throw new UserError("A locadora {$t['slug']} está sem CNPJ no cadastro: o Asaas exige o documento para gerar a cobrança.");
    }
    $c = one("INSERT INTO assinatura_cobrancas (locadora_id, vencimento, valor) VALUES (:l, :v, :valor)
              ON CONFLICT (locadora_id, vencimento) WHERE status <> 'cancelada' DO NOTHING RETURNING *",
             ['l' => $t['id'], 'v' => $t['vencimento'], 'valor' => $t['preco_mensal']]);
    if ($c === null) {
        return one("SELECT * FROM assinatura_cobrancas WHERE locadora_id = :l AND vencimento = :v AND status <> 'cancelada'",
                   ['l' => $t['id'], 'v' => $t['vencimento']]);
    }
    try {
        // O Asaas não aceita vencimento no passado: mensalidade já vencida é cobrada para hoje
        $p = asaas_cobranca_criar(asaas_cliente($t), $c['id'], max($t['vencimento'], today()), (float) $t['preco_mensal'],
                                  "LocGestor - plano {$t['plano_nome']} - mensalidade com vencimento em " . dt($t['vencimento']));
    } catch (AsaasErro $e) {
        if ($e->getCode() >= 400 && $e->getCode() < 500) {
            q('DELETE FROM assinatura_cobrancas WHERE id = :id AND gateway_id IS NULL', ['id' => $c['id']]);
        }
        throw $e;
    }
    return cobranca_vincular($c['id'], $p);
}

/** Guarda na cobrança o id e o link do Asaas. */
function cobranca_vincular(string $id, array $p): array
{
    if (!is_string($p['id'] ?? null) || !asaas_id_valido($p['id'])) {
        throw new AsaasErro('Resposta inesperada do Asaas ao criar a cobrança.');
    }
    $link = is_string($p['invoiceUrl'] ?? null) && preg_match('#^https?://#', $p['invoiceUrl']) ? mb_substr($p['invoiceUrl'], 0, 300) : '';
    log_app('INFO', "Cobrança {$p['id']} gerada no Asaas.");
    return one('UPDATE assinatura_cobrancas SET gateway_id = :g, link = :l, atualizado_em = now() WHERE id = :id RETURNING *',
               ['g' => $p['id'], 'l' => $link, 'id' => $id]);
}

/** Reservas sem id do Asaas (a rotina caiu no meio): vincula a cobrança criada lá ou libera o vencimento. */
function cobrancas_recuperar(): int
{
    $n = 0;
    foreach (all("SELECT id FROM assinatura_cobrancas WHERE gateway_id IS NULL AND status = 'pendente'
                   AND criado_em < now() - INTERVAL '2 minutes'") as $c) {
        $p = asaas_cobranca_por_referencia($c['id']);
        if ($p !== null && empty($p['deleted'])) {
            cobranca_vincular($c['id'], $p);
        } else {
            q('DELETE FROM assinatura_cobrancas WHERE id = :id AND gateway_id IS NULL', ['id' => $c['id']]);
        }
        $n++;
    }
    return $n;
}

/**
 * Confere uma cobrança pendente no Asaas e aplica o resultado. Devolve a situação final
 * ('pendente', 'paga' ou 'cancelada'). Pode ser chamada várias vezes (webhook e rotina juntos).
 */
function cobranca_sincronizar(string $cobrancaId): string
{
    $c = one('SELECT id, status, gateway_id FROM assinatura_cobrancas WHERE id = :id', ['id' => $cobrancaId]);
    if ($c === null || $c['status'] !== 'pendente' || $c['gateway_id'] === null) {
        return $c['status'] ?? '';
    }
    $p = asaas_cobranca($c['gateway_id']);
    if (($p['id'] ?? '') !== $c['gateway_id']) {
        throw new AsaasErro('Resposta inesperada do Asaas ao consultar a cobrança.');
    }
    if (in_array($p['status'] ?? '', ASAAS_PAGO, true)) {
        cobranca_quitar($c['id'], $p);
        return 'paga';
    }
    if (!empty($p['deleted']) || ($p['status'] ?? '') === 'REFUNDED') {
        q("UPDATE assinatura_cobrancas SET status = 'cancelada', atualizado_em = now() WHERE id = :id AND status = 'pendente'",
          ['id' => $c['id']]);
        return 'cancelada';
    }
    return 'pendente';
}

/** Baixa da cobrança paga: registra 1 mensalidade (igual ao pagamento manual) e liga o pagamento à cobrança. */
function cobranca_quitar(string $cobrancaId, array $p): void
{
    tx(function () use ($cobrancaId, $p) {
        $c = one('SELECT * FROM assinatura_cobrancas WHERE id = :id FOR UPDATE', ['id' => $cobrancaId]);
        if ($c === null || $c['status'] !== 'pendente') {
            return; // outra chamada já deu baixa
        }
        $data = today();
        foreach (['paymentDate', 'clientPaymentDate', 'confirmedDate'] as $campo) {
            if (is_string($p[$campo] ?? null) && valid_date($p[$campo])) {
                $data = min($p[$campo], today());
                break;
            }
        }
        $forma = ($p['status'] ?? '') === 'RECEIVED_IN_CASH' ? 'Dinheiro' : (ASAAS_FORMAS[$p['billingType'] ?? ''] ?? 'PIX');
        $valor = is_numeric($p['value'] ?? null) ? round((float) $p['value'], 2) : (float) $c['valor'];
        $r = assinatura_registrar_pagamento($c['locadora_id'], 1, $data, $valor, $forma, "Asaas {$c['gateway_id']}", null);
        q("UPDATE assinatura_cobrancas SET status = 'paga', pago_em = :d, pagamento_id = :p, atualizado_em = now() WHERE id = :id",
          ['d' => $data, 'p' => $r['pagamento_id'], 'id' => $c['id']]);
    });
}

/** Cancela a cobrança pendente lá e aqui (ex.: vencimento corrigido à mão). */
function cobranca_cancelar(string $cobrancaId): void
{
    $c = one('SELECT id, status, gateway_id FROM assinatura_cobrancas WHERE id = :id', ['id' => $cobrancaId]);
    if ($c === null || $c['status'] !== 'pendente') {
        throw new UserError('Só cobranças pendentes podem ser canceladas.');
    }
    // Já paga lá? Então dá baixa em vez de cancelar
    if ($c['gateway_id'] !== null && cobranca_sincronizar($c['id']) !== 'pendente') {
        throw new UserError('Esta cobrança já foi paga ou cancelada no Asaas. A lista foi atualizada.');
    }
    if ($c['gateway_id'] !== null) {
        asaas_req('DELETE', '/payments/' . $c['gateway_id']);
    }
    q("UPDATE assinatura_cobrancas SET status = 'cancelada', atualizado_em = now() WHERE id = :id", ['id' => $c['id']]);
    log_app('INFO', 'Cobrança ' . ($c['gateway_id'] ?? 'sem id') . ' cancelada.');
}

/**
 * Rotina diária: confere pendentes, suspende inadimplentes e gera as cobranças que vencem em até
 * 'dias_antes' dias. Devolve as linhas do relatório; erros de uma locadora não param as outras.
 */
function cobrancas_rotina(): array
{
    $rel = [];
    $erros = 0;
    $ativo = asaas_ativo();
    if ($ativo) {
        try {
            $n = cobrancas_recuperar();
            if ($n > 0) {
                $rel[] = "$n cobrança(s) interrompida(s) conferida(s).";
            }
        } catch (Throwable $e) {
            $erros++;
            $rel[] = 'ERRO ao recuperar cobranças: ' . $e->getMessage();
        }
        foreach (all("SELECT c.id, t.slug FROM assinatura_cobrancas c JOIN tenants t ON t.id = c.locadora_id
                       WHERE c.status = 'pendente' AND c.gateway_id IS NOT NULL ORDER BY c.vencimento") as $c) {
            try {
                $s = cobranca_sincronizar($c['id']);
                if ($s !== 'pendente') {
                    $rel[] = "{$c['slug']}: cobrança $s.";
                }
            } catch (Throwable $e) {
                $erros++;
                $rel[] = "ERRO {$c['slug']}: " . $e->getMessage();
            }
        }
    } else {
        $rel[] = 'Asaas não configurado (config.php): cobranças automáticas desligadas.';
    }

    suspender_inadimplentes();

    if ($ativo) {
        $dias = asaas_config()['dias_antes'];
        foreach (all("SELECT t.id, t.slug FROM tenants t JOIN planos p ON p.id = t.plano_id
                       WHERE t.cobranca_automatica AND t.vencimento IS NOT NULL AND p.preco_mensal > 0
                         AND t.vencimento <= CURRENT_DATE + CAST(:d AS integer)
                         AND NOT EXISTS (SELECT 1 FROM assinatura_cobrancas c
                                          WHERE c.locadora_id = t.id AND c.vencimento = t.vencimento AND c.status <> 'cancelada')
                       ORDER BY t.vencimento", ['d' => $dias]) as $t) {
            try {
                $c = cobranca_gerar($t['id']);
                $rel[] = "{$t['slug']}: cobrança de " . money($c['valor']) . ' com vencimento em ' . dt($c['vencimento']) . ' gerada.';
            } catch (Throwable $e) {
                $erros++;
                $rel[] = "ERRO {$t['slug']}: " . $e->getMessage();
            }
        }
    }
    foreach ($rel as $linha) {
        if (str_starts_with($linha, 'ERRO')) {
            log_app('ERRO', "Rotina de cobranças: $linha");
        }
    }
    return ['linhas' => $rel, 'erros' => $erros];
}
