<?php
/*
 * Uso por locadora (painel da plataforma): o que cada uma faz no sistema, sem dados pessoais.
 * Cada locadora é contada dentro do próprio contexto (RLS), e o contexto é limpo no fim.
 */

declare(strict_types=1);

/** Recursos que contam como "usados" (cada um com o rótulo da tela). */
const RECURSOS_USO = [
    'vistorias'  => 'Vistorias',
    'roteiro'    => 'Roteiro do motorista',
    'patrimonio' => 'Patrimônio',
    'manutencoes' => 'Manutenção',
    'kits'       => 'Kits',
    'contas'     => 'Contas a pagar',
];

/** Plano mais barato que comporta mais usuários do que o limite atual (sugestão de upgrade). */
function uso_plano_acima(int $limite): ?string
{
    return one('SELECT nome FROM planos WHERE ativo AND limite_usuarios > :l ORDER BY preco_mensal, nome LIMIT 1',
               ['l' => $limite])['nome'] ?? null;
}

/** Números de uso de cada locadora (uma consulta por locadora, no contexto dela). */
function uso_locadoras(): array
{
    $tenants = all('SELECT id, slug, nome, status, limite_usuarios FROM tenants ORDER BY nome');
    try {
        foreach ($tenants as &$t) {
            tenant_definir($t['id']);
            $t += one("SELECT (SELECT count(*) FROM locacoes WHERE status <> 'cancelada'
                                  AND data_saida >= date_trunc('month', CURRENT_DATE)::date)                 AS locacoes_mes,
                              (SELECT count(*) FROM usuarios WHERE ativo)                                    AS usuarios,
                              (SELECT count(*) FROM usuarios WHERE ativo AND ultimo_acesso >= now() - INTERVAL '30 days') AS usuarios_30d,
                              (SELECT COALESCE(sum(tamanho), 0) FROM vistoria_fotos)                         AS fotos_bytes,
                              (SELECT count(*) FROM vistorias)                                               AS vistorias,
                              (SELECT count(*) FROM entregas)                                                AS roteiro,
                              (SELECT count(*) FROM unidades)                                                AS patrimonio,
                              (SELECT count(*) FROM manutencoes)                                             AS manutencoes,
                              (SELECT count(*) FROM kits)                                                    AS kits,
                              (SELECT count(*) FROM contas_pagar)                                            AS contas");
            $t['recursos'] = array_keys(array_filter(RECURSOS_USO, fn($rot, $k) => (int) $t[$k] > 0, ARRAY_FILTER_USE_BOTH));
            $t['perto_do_limite'] = $t['usuarios'] >= max(1, (int) ceil($t['limite_usuarios'] * 0.8));
            $t['sugestao'] = $t['perto_do_limite'] ? uso_plano_acima((int) $t['limite_usuarios']) : null;
        }
        unset($t);
    } finally {
        tenant_limpar();
    }
    return $tenants;
}

function uso_index(): void
{
    $locadoras = uso_locadoras();
    $inativas = array_filter($locadoras, fn($t) => $t['status'] === 'ativa' && $t['usuarios_30d'] === 0);
    usort($locadoras, fn($a, $b) => [(int) $b['locacoes_mes'], $a['nome']] <=> [(int) $a['locacoes_mes'], $b['nome']]);

    layout_start('Uso por locadora', 'uso');
    ?>
    <div class="kpis">
        <div class="kpi"><span>Locações no mês (todas)</span><strong><?= num(array_sum(array_column($locadoras, 'locacoes_mes'))) ?></strong></div>
        <div class="kpi amarelo"><span>Sem acesso há 30 dias</span><strong><?= num(count($inativas)) ?></strong><small>locadoras ativas</small></div>
        <div class="kpi"><span>Espaço das fotos de vistoria</span><strong><?= num(array_sum(array_column($locadoras, 'fotos_bytes')) / 1048576, 1) ?> MB</strong></div>
    </div>
    <div class="card">
        <h2>Uso por locadora</h2>
        <p class="ajuda">Contagens do que cada locadora faz no sistema. Nenhum dado de cliente aparece aqui.
            "Recursos" são as funções usadas alguma vez (de <?= num(count(RECURSOS_USO)) ?>).</p>
        <div class="tabela-wrap">
            <table class="tabela">
                <thead><tr><th>Locadora</th><th>Status</th><th class="num">Locações no mês</th><th class="num">Usuários (30 dias)</th>
                    <th class="num">Fotos</th><th>Recursos usados</th><th>Sugestão</th></tr></thead>
                <tbody>
                <?php foreach ($locadoras as $t): ?>
                    <tr>
                        <td><a href="<?= e(url('tenants', ['a' => 'editar', 'id' => $t['id']])) ?>"><strong><?= e($t['nome']) ?></strong></a><br><code><?= e($t['slug']) ?></code></td>
                        <td><span class="selo <?= $t['status'] === 'ativa' ? 'verde' : 'vermelho' ?>"><?= e($t['status']) ?></span></td>
                        <td class="num"><?= num($t['locacoes_mes']) ?></td>
                        <td class="num"><?= num($t['usuarios_30d']) ?> de <?= num($t['usuarios']) ?> <span class="muted">(limite <?= num($t['limite_usuarios']) ?>)</span></td>
                        <td class="num"><?= $t['fotos_bytes'] > 0 ? num($t['fotos_bytes'] / 1048576, 1) . ' MB' : '—' ?></td>
                        <td><?= $t['recursos'] ? e(implode(' · ', array_map(fn($k) => RECURSOS_USO[$k], $t['recursos']))) : '<span class="muted">nenhum</span>' ?>
                            <span class="muted">(<?= num(count($t['recursos'])) ?> de <?= num(count(RECURSOS_USO)) ?>)</span></td>
                        <td><?php if ($t['perto_do_limite']): ?>
                                <span class="selo amarelo">perto do limite de usuários</span>
                                <?= $t['sugestao'] ? '<br><small class="muted">sugerir o plano ' . e($t['sugestao']) . '</small>' : '' ?>
                            <?php elseif ($t['status'] === 'ativa' && $t['usuarios_30d'] === 0): ?>
                                <span class="selo">sem acesso recente</span>
                            <?php else: ?><span class="muted">—</span><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$locadoras): ?><tr><td colspan="7" class="vazio">Nenhuma locadora cadastrada.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
    layout_end();
}
