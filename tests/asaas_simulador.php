<?php
/*
 * Simulador da API do Asaas para os testes (php -S 127.0.0.1:8091 tests/asaas_simulador.php).
 * Imita as rotas usadas pelo LocGestor (/v3/customers e /v3/payments), com a mesma forma de
 * resposta e de erro. Rotas de controle do teste (sem chave): /_teste/pagar/{id}, /_teste/estado,
 * /_teste/modo (faz a próxima criação de cobrança falhar).
 */

declare(strict_types=1);

date_default_timezone_set('America/Sao_Paulo');   // o Asaas trabalha no horário de Brasília
const CHAVE = 'chave-teste';
$arquivo = sys_get_temp_dir() . '/locgestor_asaas_simulador_' . ($_SERVER['SERVER_PORT'] ?? '0') . '.json';

function ler(string $arquivo): array
{
    $d = is_file($arquivo) ? json_decode((string) file_get_contents($arquivo), true) : null;
    return is_array($d) ? $d : ['customers' => [], 'payments' => [], 'modo' => '', 'chamadas' => []];
}

function gravar(string $arquivo, array $d): void
{
    file_put_contents($arquivo, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function sair(int $codigo, array $corpo): never
{
    http_response_code($codigo);
    header('Content-Type: application/json');
    echo json_encode($corpo, JSON_UNESCAPED_UNICODE);
    exit;
}

function erro(int $codigo, string $code, string $descricao): never
{
    sair($codigo, ['errors' => [['code' => $code, 'description' => $descricao]]]);
}

$metodo = $_SERVER['REQUEST_METHOD'];
$caminho = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
parse_str((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $qs);
$corpo = json_decode((string) file_get_contents('php://input'), true) ?? [];
$db = ler($arquivo);

// --- controle do teste ---
if (str_starts_with($caminho, '/_teste/')) {
    if ($caminho === '/_teste/estado') {
        sair(200, $db);
    }
    if ($caminho === '/_teste/zerar') {
        gravar($arquivo, ['customers' => [], 'payments' => [], 'modo' => '', 'chamadas' => []]);
        sair(200, ['ok' => true]);
    }
    if ($caminho === '/_teste/modo') {
        $db['modo'] = (string) ($qs['payments'] ?? '');
        gravar($arquivo, $db);
        sair(200, ['ok' => true]);
    }
    if (preg_match('#^/_teste/pagar/(pay_\w+)$#', $caminho, $m) && isset($db['payments'][$m[1]])) {
        $p = &$db['payments'][$m[1]];
        $p['billingType'] = (string) ($qs['forma'] ?? 'PIX');
        $p['status'] = $p['billingType'] === 'CREDIT_CARD' ? 'CONFIRMED' : 'RECEIVED';
        $p['paymentDate'] = $p['clientPaymentDate'] = date('Y-m-d');
        unset($p);
        gravar($arquivo, $db);
        sair(200, ['ok' => true]);
    }
    erro(404, 'not_found', 'Rota de teste inexistente.');
}

// --- API ---
$db['chamadas'][] = "$metodo $caminho";
gravar($arquivo, $db);
if (($_SERVER['HTTP_ACCESS_TOKEN'] ?? '') !== CHAVE) {
    erro(401, 'invalid_access_token', 'A chave de API fornecida é inválida');
}
if (trim($_SERVER['HTTP_USER_AGENT'] ?? '') === '') {
    erro(400, 'invalid_user_agent', 'O header User-Agent é obrigatório.');
}

if ($caminho === '/v3/customers' && $metodo === 'GET') {
    $lista = array_values(array_filter($db['customers'], fn($c) => !isset($qs['externalReference'])
                                       || ($c['externalReference'] ?? null) === $qs['externalReference']));
    sair(200, ['object' => 'list', 'hasMore' => false, 'totalCount' => count($lista), 'limit' => 10, 'offset' => 0, 'data' => $lista]);
}
if ($caminho === '/v3/customers' && $metodo === 'POST') {
    if (trim((string) ($corpo['name'] ?? '')) === '') {
        erro(400, 'invalid_name', 'O campo name deve ser informado.');
    }
    if (!preg_match('/^(\d{11}|\d{14})$/', (string) ($corpo['cpfCnpj'] ?? ''))) {
        erro(400, 'invalid_cpfCnpj', 'O CPF/CNPJ informado é inválido.');
    }
    $id = 'cus_' . bin2hex(random_bytes(6));
    $db['customers'][$id] = ['object' => 'customer', 'id' => $id, 'dateCreated' => date('Y-m-d'), 'deleted' => false] + $corpo;
    gravar($arquivo, $db);
    sair(200, $db['customers'][$id]);
}
if ($caminho === '/v3/payments' && $metodo === 'GET') {
    $lista = array_values(array_filter($db['payments'], fn($p) => !isset($qs['externalReference'])
                                       || ($p['externalReference'] ?? null) === $qs['externalReference']));
    sair(200, ['object' => 'list', 'hasMore' => false, 'totalCount' => count($lista), 'limit' => 10, 'offset' => 0, 'data' => $lista]);
}
if ($caminho === '/v3/payments' && $metodo === 'POST') {
    if ($db['modo'] === 'recusar') {
        $db['modo'] = '';
        gravar($arquivo, $db);
        erro(400, 'invalid_value', 'O valor da cobrança é inválido.');
    }
    if (!isset($db['customers'][$corpo['customer'] ?? ''])) {
        erro(400, 'invalid_customer', 'Cliente inexistente.');
    }
    if (!in_array($corpo['billingType'] ?? '', ['UNDEFINED', 'BOLETO', 'PIX', 'CREDIT_CARD'], true)) {
        erro(400, 'invalid_billingType', 'Forma de pagamento inválida.');
    }
    if (!is_numeric($corpo['value'] ?? null) || $corpo['value'] <= 0) {
        erro(400, 'invalid_value', 'O valor da cobrança é inválido.');
    }
    $venc = (string) ($corpo['dueDate'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $venc) || $venc < date('Y-m-d')) {
        erro(400, 'invalid_dueDate', 'A data de vencimento não pode ser anterior à data atual.');
    }
    $id = 'pay_' . bin2hex(random_bytes(8));
    $db['payments'][$id] = [
        'object' => 'payment', 'id' => $id, 'dateCreated' => date('Y-m-d'), 'customer' => $corpo['customer'],
        'value' => (float) $corpo['value'], 'netValue' => round($corpo['value'] * 0.98, 2), 'billingType' => $corpo['billingType'],
        'status' => 'PENDING', 'dueDate' => $venc, 'description' => $corpo['description'] ?? '',
        'externalReference' => $corpo['externalReference'] ?? null, 'paymentDate' => null, 'clientPaymentDate' => null,
        'invoiceUrl' => 'https://sandbox.asaas.com/i/' . substr($id, 4), 'deleted' => false,
    ];
    $falhar = $db['modo'] === 'cair';   // cria lá, mas a resposta "não chega"
    $db['modo'] = $falhar ? '' : $db['modo'];
    gravar($arquivo, $db);
    if ($falhar) {
        erro(502, 'bad_gateway', 'Bad Gateway');
    }
    sair(200, $db['payments'][$id]);
}
if (preg_match('#^/v3/payments/(pay_\w+)$#', $caminho, $m)) {
    if (!isset($db['payments'][$m[1]])) {
        erro(404, 'not_found', 'Cobrança não encontrada.');
    }
    if ($metodo === 'DELETE') {
        $db['payments'][$m[1]]['deleted'] = true;
        gravar($arquivo, $db);
        sair(200, ['deleted' => true, 'id' => $m[1]]);
    }
    sair(200, $db['payments'][$m[1]]);
}
erro(404, 'not_found', "Rota inexistente: $metodo $caminho");
