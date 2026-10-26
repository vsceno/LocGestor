<?php
/*
 * Auditoria do painel da plataforma: quem criou, alterou ou excluiu locadoras, planos, mensalidades,
 * recados e usuários do painel, e quando. Só leitura.
 */

declare(strict_types=1);

const TABELAS_AUDITORIA_PLATAFORMA = [
    'tenants'               => 'Locadora',
    'planos'                => 'Plano',
    'assinatura_pagamentos' => 'Pagamento de mensalidade',
    'recados'               => 'Recado',
    'plataforma_usuarios'   => 'Usuário do painel',
];
const ACOES_AUDITORIA_PLATAFORMA = ['I' => 'incluiu', 'U' => 'alterou', 'D' => 'excluiu'];

/** Valor de um campo para a tela (texto curto). */
function auditoria_plataforma_valor($v): string
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

function auditoria_index(): void
{
    $de = valid_date(get('de')) ? get('de') : date('Y-m-d', strtotime('-30 days'));
    $ate = valid_date(get('ate')) ? get('ate') : today();
    $tabela = array_key_exists(get('tabela'), TABELAS_AUDITORIA_PLATAFORMA) ? get('tabela') : '';
    $acao = array_key_exists(get('acao'), ACOES_AUDITORIA_PLATAFORMA) ? get('acao') : '';
    $usuario = mb_substr(get('usuario'), 0, 80);

    $where = ['quando >= CAST(:de AS date)', "quando < CAST(:ate AS date) + INTERVAL '1 day'"];
    $params = ['de' => $de, 'ate' => $ate];
    foreach (['tabela' => $tabela, 'acao' => $acao, 'usuario' => $usuario] as $col => $v) {
        if ($v !== '') {
            $where[] = $col === 'usuario' ? 'usuario = :usuario' : "$col = :$col";
            $params[$col] = $v;
        }
    }
    $sqlWhere = implode(' AND ', $where);
    $pg = paginate("SELECT count(*) FROM auditoria_plataforma WHERE $sqlWhere", $params, 50);
    $linhas = all("SELECT * FROM auditoria_plataforma WHERE $sqlWhere ORDER BY quando DESC, id DESC
                   LIMIT {$pg['por']} OFFSET {$pg['offset']}", $params);
    $usuarios = array_column(all('SELECT DISTINCT usuario FROM auditoria_plataforma ORDER BY usuario'), 'usuario');
    $cores = ['I' => 'verde', 'U' => 'azul', 'D' => 'vermelho'];

    layout_start('Auditoria', 'auditoria');
    ?>
    <div class="card">
        <div class="card-titulo"><h2>Auditoria do painel</h2><span class="muted"><?= num($pg['total']) ?> registro(s)</span></div>
        <p class="ajuda">Quem criou, alterou ou excluiu locadoras, planos, pagamentos, recados e usuários do painel, e quando.
            "Rotina diária" e "webhook asaas" são as ações automáticas. Dados pessoais e senhas aparecem como <em>(oculto)</em>.
            O histórico não pode ser alterado nem apagado.</p>
        <form method="get" class="filtros">
            <input type="hidden" name="p" value="auditoria">
            <input type="date" name="de" value="<?= e($de) ?>" aria-label="De">
            <input type="date" name="ate" value="<?= e($ate) ?>" aria-label="Até">
            <select name="tabela" aria-label="O quê"><?= options(TABELAS_AUDITORIA_PLATAFORMA, $tabela, 'Tudo') ?></select>
            <select name="acao" aria-label="Ação"><?= options(ACOES_AUDITORIA_PLATAFORMA, $acao, 'Todas as ações') ?></select>
            <select name="usuario" aria-label="Quem"><?= options($usuarios, $usuario, 'Todos') ?></select>
            <button class="btn btn-sec">Filtrar</button>
        </form>
        <div class="tabela-wrap">
            <table class="tabela">
                <thead><tr><th>Quando</th><th>Quem</th><th>Ação</th><th>O quê</th><th>Detalhes</th></tr></thead>
                <tbody>
                <?php foreach ($linhas as $a): $dados = json_decode($a['dados'], true) ?: []; ?>
                    <tr>
                        <td class="nowrap"><?= e(dt($a['quando'], true)) ?></td>
                        <td><?= e($a['usuario']) ?></td>
                        <td><span class="selo <?= $cores[$a['acao']] ?>"><?= e(ACOES_AUDITORIA_PLATAFORMA[$a['acao']]) ?></span></td>
                        <td><?= e((TABELAS_AUDITORIA_PLATAFORMA[$a['tabela']] ?? $a['tabela']) . ($a['rotulo'] !== '' ? ' · ' . $a['rotulo'] : '')) ?></td>
                        <td class="auditoria-dados">
                            <?php if ($a['acao'] === 'U'): ?>
                                <?php foreach ($dados as $campo => [$antes, $depois]): ?>
                                    <div><strong><?= e(str_replace('_', ' ', $campo)) ?>:</strong>
                                        <?= $antes === '***' ? '(oculto, alterado)' : e(auditoria_plataforma_valor($antes)) . ' → ' . e(auditoria_plataforma_valor($depois)) ?></div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <?php $preenchidos = array_filter($dados, fn($v, $k) => $k !== 'id' && $v !== null && $v !== '', ARRAY_FILTER_USE_BOTH); ?>
                                <?= e(implode(' · ', array_map(fn($k, $v) => str_replace('_', ' ', $k) . ': ' . auditoria_plataforma_valor($v),
                                    array_keys($preenchidos), array_values($preenchidos)))) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$linhas): ?><tr><td colspan="5" class="vazio">Nada registrado no período.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <?= pager($pg, 'auditoria', array_filter(['de' => $de, 'ate' => $ate, 'tabela' => $tabela, 'acao' => $acao, 'usuario' => $usuario])) ?>
    </div>
    <?php
    layout_end();
}
