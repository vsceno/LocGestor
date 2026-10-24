<?php
/*
 * Painel. Provisório: os indicadores e gráficos chegam na Fase 7.
 */

declare(strict_types=1);

function dashboard_index(): void
{
    $abertas = (int) val("SELECT count(*) FROM locacoes WHERE status = 'aberta'");
    $clientes = (int) val('SELECT count(*) FROM clientes WHERE ativo');
    $produtos = (int) val('SELECT count(*) FROM produtos WHERE ativo');

    layout_start('Painel', 'dashboard');
    ?>
    <div class="kpis">
        <div class="kpi"><span>Locações em aberto</span><strong><?= num($abertas) ?></strong></div>
        <div class="kpi"><span>Clientes ativos</span><strong><?= num($clientes) ?></strong></div>
        <div class="kpi"><span>Produtos ativos</span><strong><?= num($produtos) ?></strong></div>
    </div>
    <div class="card">
        <h2>Bem-vindo(a), <?= e(user()['nome']) ?></h2>
        <p>Você está na locadora <strong><?= e(tenant()['nome']) ?></strong>
           (<code><?= e(tenant()['slug']) ?></code>), plano <?= e(tenant()['plano']) ?>.</p>
        <p class="muted">O painel completo, com indicadores e gráficos, será construído numa próxima fase.</p>
    </div>
    <?php
    layout_end();
}
