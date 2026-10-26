<?php
/*
 * LocGestor - avisos do Asaas (webhook de cobranças das mensalidades).
 *
 * No Asaas (Integrações › Webhooks): URL https://SEU-DOMINIO/webhook_asaas.php, eventos de cobrança
 * e "token de autenticação" igual ao 'webhook_token' do config.php.
 * O aviso só diz QUAL cobrança mudou: a situação é sempre conferida na API antes da baixa,
 * então um aviso forjado não registra pagamento. Avisos repetidos são ignorados.
 */

declare(strict_types=1);

const AREA = 'plataforma';
const SEM_SESSAO = true;

require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/asaas.php';
require __DIR__ . '/app/assinatura.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function responder(int $codigo, string $msg): never
{
    http_response_code($codigo);
    echo json_encode(['ok' => $codigo === 200, 'msg' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    responder(405, 'Use POST.');
}
$token = asaas_config()['webhook_token'];
$recebido = $_SERVER['HTTP_ASAAS_ACCESS_TOKEN'] ?? '';
if ($token === '' || !is_string($recebido) || !hash_equals($token, $recebido)) {
    log_app('ALERTA DE SEGURANÇA', 'Aviso do Asaas recusado: token ausente ou inválido.');
    responder(401, 'Token inválido.');
}

$aviso = json_decode((string) file_get_contents('php://input', false, null, 0, 100000), true);
if (!is_array($aviso) || !is_string($aviso['event'] ?? null)) {
    responder(400, 'Aviso inválido.');
}
$eventoId = is_string($aviso['id'] ?? null) && strlen($aviso['id']) <= 80 ? $aviso['id'] : '';
$pagamentoId = $aviso['payment']['id'] ?? '';
if (!str_starts_with($aviso['event'], 'PAYMENT_') || !is_string($pagamentoId) || !asaas_id_valido($pagamentoId)) {
    responder(200, 'Ignorado.');
}

try {
    if ($eventoId !== '' && val('SELECT 1 FROM gateway_eventos WHERE id = :id', ['id' => $eventoId]) !== null) {
        responder(200, 'Aviso já processado.');
    }
    $c = one('SELECT id FROM assinatura_cobrancas WHERE gateway_id = :g', ['g' => $pagamentoId]);
    // Cobrança que não é mensalidade do LocGestor (a conta do Asaas pode ter outras)
    $situacao = $c === null ? 'desconhecida' : cobranca_sincronizar($c['id']);
    if ($eventoId !== '') {
        q('INSERT INTO gateway_eventos (id, tipo) VALUES (:id, :t) ON CONFLICT (id) DO NOTHING',
          ['id' => $eventoId, 't' => mb_substr($aviso['event'], 0, 60)]);
    }
    responder(200, "Cobrança $situacao.");
} catch (Throwable $e) {
    // 500: o Asaas tenta de novo mais tarde (e a rotina diária também confere)
    log_app('ERRO', 'Aviso do Asaas ' . $aviso['event'] . ': ' . $e->getMessage());
    responder(500, 'Erro ao processar o aviso.');
}
