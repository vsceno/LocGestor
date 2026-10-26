<?php
/*
 * LocGestor - rotina diária (Agendador de Tarefas do Windows ou cron):
 *   php tarefas\diaria.php
 *
 * 1. confere no Asaas as cobranças pendentes e dá baixa nas pagas (funciona mesmo sem webhook);
 * 2. marca como suspensas as locadoras além da carência;
 * 3. gera no Asaas as cobranças que vencem nos próximos dias (locadoras com cobrança automática).
 * Sai com código 1 se algo falhou (o detalhe vai para logs/app.log).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const AREA = 'plataforma';
const SEM_SESSAO = true;

require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/app/asaas.php';
require dirname(__DIR__) . '/app/assinatura.php';

$r = cobrancas_rotina();
echo '[' . date('Y-m-d H:i:s') . '] Rotina diária do LocGestor' . PHP_EOL;
foreach ($r['linhas'] ?: ['Nada a fazer.'] as $linha) {
    echo "  $linha" . PHP_EOL;
}
exit($r['erros'] > 0 ? 1 : 0);
