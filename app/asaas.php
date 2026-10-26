<?php
/*
 * LocGestor - cliente da API do Asaas (cobrança das mensalidades das locadoras).
 *
 * Configuração em config.php ('asaas' => [...]). A chave da API nunca vai para log nem para a tela.
 * Variáveis de ambiente LOCGESTOR_ASAAS_URL, LOCGESTOR_ASAAS_CHAVE e LOCGESTOR_ASAAS_WEBHOOK_TOKEN,
 * se existirem, substituem o config (usadas pelos testes com o simulador).
 */

declare(strict_types=1);

const ASAAS_URLS = ['sandbox' => 'https://api-sandbox.asaas.com/v3', 'producao' => 'https://api.asaas.com/v3'];
/** Situações do Asaas em que o dinheiro foi pago (cartão aprovado conta como pago). */
const ASAAS_PAGO = ['RECEIVED', 'CONFIRMED', 'RECEIVED_IN_CASH', 'DUNNING_RECEIVED'];
const ASAAS_FORMAS = ['PIX' => 'PIX', 'BOLETO' => 'Boleto', 'CREDIT_CARD' => 'Cartão de crédito', 'DEBIT_CARD' => 'Cartão de débito'];

/** Falha de comunicação ou recusa do Asaas (mensagem segura para log e tela: sem a chave). */
class AsaasErro extends RuntimeException
{
}

function asaas_config(): array
{
    $c = (array) config('asaas', []);
    $ambiente = ($c['ambiente'] ?? 'sandbox') === 'producao' ? 'producao' : 'sandbox';
    return [
        'ambiente'      => $ambiente,
        'url'           => rtrim((string) (getenv('LOCGESTOR_ASAAS_URL') ?: ASAAS_URLS[$ambiente]), '/'),
        'chave'         => (string) (getenv('LOCGESTOR_ASAAS_CHAVE') ?: ($c['chave'] ?? '')),
        'webhook_token' => (string) (getenv('LOCGESTOR_ASAAS_WEBHOOK_TOKEN') ?: ($c['webhook_token'] ?? '')),
        'dias_antes'    => max(1, min(30, (int) ($c['dias_antes'] ?? 10))),
    ];
}

function asaas_ativo(): bool
{
    return asaas_config()['chave'] !== '';
}

/** Ids do Asaas (cus_..., pay_...) antes de irem para a URL da API. */
function asaas_id_valido(string $id): bool
{
    return (bool) preg_match('/^[a-z]{2,8}_[A-Za-z0-9]{1,40}$/', $id);
}

/** Chamada à API. Devolve o JSON decodificado; recusa ou falha de rede viram AsaasErro. */
function asaas_req(string $metodo, string $caminho, ?array $corpo = null): array
{
    $cfg = asaas_config();
    if ($cfg['chave'] === '') {
        throw new AsaasErro('Asaas não configurado: preencha a chave da API em config.php.');
    }
    $c = curl_init($cfg['url'] . $caminho);
    $opcoes = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $metodo,
        CURLOPT_HTTPHEADER     => ['access_token: ' . $cfg['chave'], 'Content-Type: application/json',
                                   'Accept: application/json', 'User-Agent: LocGestor'],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_FOLLOWLOCATION => false,
    ];
    if ($corpo !== null) {
        $opcoes[CURLOPT_POSTFIELDS] = json_encode($corpo, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
    curl_setopt_array($c, $opcoes);
    $resposta = curl_exec($c);
    $codigo = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    if ($resposta === false) {
        throw new AsaasErro('Sem resposta do Asaas (' . curl_error($c) . ').');
    }
    $json = json_decode((string) $resposta, true);
    if ($codigo < 200 || $codigo > 299 || !is_array($json)) {
        $motivo = is_array($json) && !empty($json['errors'])
            ? implode(' ', array_map(fn($e) => (string) ($e['description'] ?? ''), (array) $json['errors']))
            : "HTTP $codigo";
        throw new AsaasErro("O Asaas recusou a operação ($metodo " . strtok($caminho, '?') . "): $motivo", $codigo);
    }
    return $json;
}

/**
 * Id do cliente da locadora no Asaas; cria na primeira vez (o Asaas exige CPF/CNPJ para cobrar).
 * Procura antes pela referência externa: uma rotina interrompida não cria cliente repetido.
 */
function asaas_cliente(array $t): string
{
    if ($t['asaas_cliente_id'] !== '') {
        return $t['asaas_cliente_id'];
    }
    $doc = so_digitos($t['cnpj']);
    if (!in_array(strlen($doc), [11, 14], true)) {
        throw new AsaasErro("A locadora {$t['slug']} está sem CNPJ no cadastro: o Asaas exige o documento para gerar a cobrança.");
    }
    $id = asaas_req('GET', '/customers?externalReference=' . rawurlencode($t['id']))['data'][0]['id'] ?? null;
    if ($id === null) {
        $dados = ['name' => $t['nome'], 'cpfCnpj' => $doc, 'externalReference' => $t['id']];
        if (filter_var($t['email_contato'], FILTER_VALIDATE_EMAIL)) {
            $dados['email'] = $t['email_contato'];
        }
        $fone = so_digitos($t['telefone']);
        if (strlen($fone) === 11) {
            $dados['mobilePhone'] = $fone;
        } elseif (strlen($fone) === 10) {
            $dados['phone'] = $fone;
        }
        $id = asaas_req('POST', '/customers', $dados)['id'] ?? null;
    }
    if (!is_string($id) || !asaas_id_valido($id)) {
        throw new AsaasErro('Resposta inesperada do Asaas ao cadastrar o cliente.');
    }
    q('UPDATE tenants SET asaas_cliente_id = :c WHERE id = :id', ['c' => $id, 'id' => $t['id']]);
    return $id;
}

/** Cobrança avulsa no Asaas; o cliente escolhe PIX, boleto ou cartão na fatura (billingType UNDEFINED). */
function asaas_cobranca_criar(string $clienteId, string $referencia, string $vencimento, float $valor, string $descricao): array
{
    return asaas_req('POST', '/payments', [
        'customer'          => $clienteId,
        'billingType'       => 'UNDEFINED',
        'value'             => $valor,
        'dueDate'           => $vencimento,
        'description'       => $descricao,
        'externalReference' => $referencia,
    ]);
}

function asaas_cobranca(string $gatewayId): array
{
    if (!asaas_id_valido($gatewayId)) {
        throw new AsaasErro('Id de cobrança inválido.');
    }
    return asaas_req('GET', '/payments/' . $gatewayId);
}

/** Cobrança pela nossa referência (recupera uma cobrança criada lá cuja resposta não chegou aqui). */
function asaas_cobranca_por_referencia(string $referencia): ?array
{
    return asaas_req('GET', '/payments?externalReference=' . rawurlencode($referencia))['data'][0] ?? null;
}
