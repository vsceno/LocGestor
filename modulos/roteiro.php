<?php
/*
 * Roteiro do motorista (feito para o celular): as entregas e coletas de um dia.
 *   entrega = locação com saída no dia;
 *   coleta  = locação aberta com devolução prevista no dia (hoje: também as atrasadas).
 * Cada parada tem endereço (local de uso ou o do cliente), mapa, ligação, WhatsApp e "feito".
 */

declare(strict_types=1);

const TIPOS_PARADA = ['entrega' => 'Entrega', 'coleta' => 'Coleta'];

/** Paradas do dia, as pendentes primeiro. */
function roteiro_paradas(string $data): array
{
    $hoje = $data === today();
    $paradas = all("SELECT 'entrega' AS tipo, l.id, l.numero, l.local_uso, l.data_saida, l.data_prev_devolucao, false AS atrasada,
                           c.nome, c.tipo AS cliente_tipo, c.celular, c.telefone, c.endereco, c.numero AS end_numero, c.bairro, c.cidade, c.uf
                      FROM locacoes l JOIN clientes c ON c.id = l.cliente_id
                     WHERE l.status <> 'cancelada' AND l.data_saida = CAST(:d1 AS date)
                    UNION ALL
                    SELECT 'coleta', l.id, l.numero, l.local_uso, l.data_saida, l.data_prev_devolucao, l.data_prev_devolucao < CAST(:d2 AS date),
                           c.nome, c.tipo, c.celular, c.telefone, c.endereco, c.numero, c.bairro, c.cidade, c.uf
                      FROM locacoes l JOIN clientes c ON c.id = l.cliente_id
                     WHERE l.status = 'aberta'
                       AND (l.data_prev_devolucao = CAST(:d3 AS date) OR (CAST(:hoje AS boolean) AND l.data_prev_devolucao < CAST(:d4 AS date)))
                     ORDER BY 1 DESC, 3",
        ['d1' => $data, 'd2' => $data, 'd3' => $data, 'd4' => $data, 'hoje' => $hoje ? 'true' : 'false']);
    $feitas = [];
    foreach (all('SELECT e.locacao_id, e.tipo, e.feita_em, e.obs, u.nome AS usuario FROM entregas e LEFT JOIN usuarios u ON u.id = e.usuario_id
                   WHERE e.data = :d', ['d' => $data]) as $f) {
        $feitas[$f['locacao_id'] . $f['tipo']] = $f;
    }
    foreach ($paradas as &$p) {
        $p['feita'] = $feitas[$p['id'] . $p['tipo']] ?? null;
        $p['itens'] = whatsapp_itens($p['id'], $p['tipo'] === 'coleta');
        $doCliente = trim(implode(', ', array_filter([$p['endereco'], $p['end_numero'], $p['bairro']])) . ' - '
                          . $p['cidade'] . ($p['uf'] !== '' ? '/' . $p['uf'] : ''), ' -/');
        $p['endereco_parada'] = $p['local_uso'] !== '' ? $p['local_uso'] : $doCliente;
    }
    unset($p);
    usort($paradas, fn($a, $b) => [$a['feita'] !== null, $a['tipo'] !== 'entrega', $a['numero']] <=> [$b['feita'] !== null, $b['tipo'] !== 'entrega', $b['numero']]);
    return $paradas;
}

function roteiro_data(): string
{
    $d = get('data');
    return valid_date($d) ? $d : today();
}

function roteiro_index(): void
{
    $data = roteiro_data();
    $paradas = roteiro_paradas($data);
    $feitas = count(array_filter($paradas, fn($p) => $p['feita'] !== null));
    $loja = empresa()['nome_fantasia'] ?: empresa()['razao_social'];

    layout_start('Roteiro', 'roteiro');
    ?>
    <div class="card">
        <form method="get" class="filtros">
            <input type="hidden" name="p" value="roteiro">
            <a class="btn btn-sec btn-peq" href="<?= e(url('roteiro', ['data' => date('Y-m-d', strtotime("$data -1 day"))])) ?>" aria-label="Dia anterior">‹</a>
            <input type="date" name="data" value="<?= e($data) ?>" aria-label="Dia">
            <a class="btn btn-sec btn-peq" href="<?= e(url('roteiro', ['data' => date('Y-m-d', strtotime("$data +1 day"))])) ?>" aria-label="Dia seguinte">›</a>
            <button class="btn btn-sec btn-peq">Ver</button>
        </form>
        <p class="muted" style="margin:0"><?= $data === today() ? 'Hoje' : e(dt($data)) ?>: <?= num(count($paradas)) ?> parada(s), <?= num($feitas) ?> feita(s).</p>
    </div>
    <?php foreach ($paradas as $p):
        $tel = whatsapp_numero(['celular' => $p['celular'], 'telefone' => $p['telefone']]);
        $msg = whatsapp_saudacao(['nome' => $p['nome'], 'tipo' => $p['cliente_tipo']]) . ' '
             . ($p['tipo'] === 'entrega' ? "Estamos a caminho para entregar a locação nº {$p['numero']}: {$p['itens']}."
                                         : "Vamos passar para buscar a locação nº {$p['numero']}: {$p['itens']}.");
        $mapa = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($p['endereco_parada']); ?>
        <div class="card parada<?= $p['feita'] ? ' feita' : '' ?>">
            <div class="card-titulo">
                <h2><?= e(TIPOS_PARADA[$p['tipo']]) ?> · <a href="<?= e(url('locacoes', ['a' => 'ver', 'n' => $p['numero']])) ?>">nº <?= num($p['numero']) ?></a></h2>
                <?= $p['feita'] ? '<span class="selo verde">feita ' . e(dt($p['feita']['feita_em'], true)) . '</span>'
                                : ($p['atrasada'] ? '<span class="selo vermelho">atrasada desde ' . e(dt($p['data_prev_devolucao'])) . '</span>'
                                                  : '<span class="selo amarelo">pendente</span>') ?>
            </div>
            <p class="parada-cliente"><strong><?= e($p['nome']) ?></strong></p>
            <p class="parada-endereco"><?= $p['endereco_parada'] !== '' ? e($p['endereco_parada']) : '<span class="alerta">Sem endereço: confira com o cliente.</span>' ?></p>
            <p class="muted"><?= e($p['tipo'] === 'entrega' ? 'Levar: ' : 'Buscar: ') ?><?= e($p['itens']) ?></p>
            <?php if ($p['feita'] && $p['feita']['obs'] !== ''): ?><p class="muted">Obs.: <?= e($p['feita']['obs']) ?> (<?= e($p['feita']['usuario'] ?? '') ?>)</p><?php endif; ?>
            <div class="parada-acoes">
                <?php if ($p['endereco_parada'] !== ''): ?>
                    <a class="btn btn-sec" href="<?= e($mapa) ?>" target="_blank" rel="noopener noreferrer">Mapa</a>
                <?php endif; ?>
                <?php if ($tel !== null): ?>
                    <a class="btn btn-sec" href="tel:+<?= e($tel) ?>">Ligar</a>
                <?php endif; ?>
                <?= botao_whatsapp(whatsapp_url($tel, $msg), 'WhatsApp') ?>
                <?php if (!$p['feita'] && $data <= today()): ?>
                    <form method="post" action="<?= e(url('roteiro', ['a' => 'marcar'])) ?>" class="parada-feito">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= e($p['id']) ?>"><input type="hidden" name="tipo" value="<?= e($p['tipo']) ?>">
                        <input type="hidden" name="data" value="<?= e($data) ?>">
                        <input type="text" name="obs" maxlength="200" placeholder="Observação (opcional)" aria-label="Observação">
                        <button class="btn"><?= $p['tipo'] === 'entrega' ? 'Entregue' : 'Coletado' ?></button>
                    </form>
                <?php elseif ($p['feita']): ?>
                    <form method="post" action="<?= e(url('roteiro', ['a' => 'desmarcar'])) ?>" data-confirmar="Desfazer o registro desta parada?">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= e($p['id']) ?>"><input type="hidden" name="tipo" value="<?= e($p['tipo']) ?>">
                        <input type="hidden" name="data" value="<?= e($data) ?>">
                        <button class="btn btn-sec btn-peq">Desfazer</button>
                    </form>
                    <?php if ($p['tipo'] === 'coleta'): ?>
                        <a class="btn btn-peq" href="<?= e(url('locacoes', ['a' => 'devolucao', 'n' => $p['numero']])) ?>">Registrar devolução</a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
    <?php if (!$paradas): ?>
        <div class="card"><p class="vazio">Nenhuma entrega ou coleta em <?= e(dt($data)) ?>.</p></div>
    <?php endif; ?>
    <?php
    layout_end();
}

/** Locação, tipo e data do POST, conferidos contra o roteiro daquele dia. */
function roteiro_parada_do_post(): array
{
    $id = post('id');
    $tipo = post('tipo');
    $data = post('data');
    if (!is_uuid($id) || !isset(TIPOS_PARADA[$tipo]) || !valid_date($data)) {
        throw new UserError('Parada inválida.');
    }
    foreach (roteiro_paradas($data) as $p) {
        if ($p['id'] === $id && $p['tipo'] === $tipo) {
            return [$p, $data];
        }
    }
    throw new UserError('Esta parada não está no roteiro do dia.');
}

function roteiro_marcar(): void
{
    [$p, $data] = roteiro_parada_do_post();
    if ($data > today()) {
        throw new UserError('Só é possível marcar paradas de hoje ou de dias anteriores.');
    }
    q('INSERT INTO entregas (locacao_id, tipo, data, obs, usuario_id) VALUES (:l, :t, :d, :o, :u)
       ON CONFLICT (tenant_id, locacao_id, tipo, data) DO NOTHING',
      ['l' => $p['id'], 't' => $p['tipo'], 'd' => $data, 'o' => mb_substr(post('obs'), 0, 200), 'u' => uid()]);
    flash(($p['tipo'] === 'entrega' ? 'Entrega' : 'Coleta') . " da locação nº {$p['numero']} registrada."
          . ($p['tipo'] === 'coleta' ? ' Na loja, registre a devolução para conferir os itens.' : ''));
    redirect(url('roteiro', ['data' => $data]));
}

function roteiro_desmarcar(): void
{
    [$p, $data] = roteiro_parada_do_post();
    q('DELETE FROM entregas WHERE locacao_id = :l AND tipo = :t AND data = :d', ['l' => $p['id'], 't' => $p['tipo'], 'd' => $data]);
    flash('Registro desfeito.');
    redirect(url('roteiro', ['data' => $data]));
}
