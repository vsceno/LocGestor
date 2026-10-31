<?php

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Cliente da API do Asaas (cobrança das mensalidades das locadoras). A chave da API nunca vai para log nem para a tela.
 * Mesmas regras de app/asaas.php do sistema antigo. Nos testes, a API é simulada com Http::fake().
 */
final class Asaas
{
    public const URLS = ['sandbox' => 'https://api-sandbox.asaas.com/v3', 'producao' => 'https://api.asaas.com/v3'];

    /** Situações do Asaas em que o dinheiro foi pago (cartão aprovado conta como pago). */
    public const PAGO = ['RECEIVED', 'CONFIRMED', 'RECEIVED_IN_CASH', 'DUNNING_RECEIVED'];

    public const FORMAS = ['PIX' => 'PIX', 'BOLETO' => 'Boleto', 'CREDIT_CARD' => 'Cartão de crédito', 'DEBIT_CARD' => 'Cartão de débito'];

    public static function config(): array
    {
        $c = (array) config('locgestor.asaas', []);
        $ambiente = ($c['ambiente'] ?? 'sandbox') === 'producao' ? 'producao' : 'sandbox';
        return [
            'ambiente' => $ambiente,
            'url' => rtrim((string) (($c['url'] ?? '') ?: self::URLS[$ambiente]), '/'),
            'chave' => (string) ($c['chave'] ?? ''),
            'webhook_token' => (string) ($c['webhook_token'] ?? ''),
            'dias_antes' => max(1, min(30, (int) ($c['dias_antes'] ?? 10))),
        ];
    }

    public static function ativo(): bool
    {
        return self::config()['chave'] !== '';
    }

    /** Ids do Asaas (cus_..., pay_...) antes de irem para a URL da API. */
    public static function idValido(string $id): bool
    {
        return (bool) preg_match('/^[a-z]{2,8}_[A-Za-z0-9]{1,40}$/', $id);
    }

    /** Chamada à API. Devolve o JSON; recusa ou falha de rede viram AsaasErro (a mensagem não traz a chave). */
    public static function req(string $metodo, string $caminho, ?array $corpo = null): array
    {
        $cfg = self::config();
        if ($cfg['chave'] === '') {
            throw new AsaasErro('Asaas não configurado: preencha LOCGESTOR_ASAAS_CHAVE no .env.');
        }
        try {
            $resposta = Http::withHeaders(['access_token' => $cfg['chave'], 'User-Agent' => 'LocGestor'])
                ->acceptJson()
                ->connectTimeout(10)
                ->timeout(30)
                ->send($metodo, $cfg['url'] . $caminho, $corpo === null ? [] : ['json' => $corpo]);
        } catch (ConnectionException $e) {
            throw new AsaasErro('Sem resposta do Asaas (' . $e->getMessage() . ').');
        }

        $json = $resposta->json();
        if (!$resposta->successful() || !is_array($json)) {
            $motivo = is_array($json) && !empty($json['errors'])
                ? implode(' ', array_map(fn ($e) => (string) ($e['description'] ?? ''), (array) $json['errors']))
                : 'HTTP ' . $resposta->status();
            throw new AsaasErro("O Asaas recusou a operação ($metodo " . Str::before($caminho, '?') . "): $motivo", $resposta->status());
        }
        return $json;
    }

    /**
     * Id do cliente da locadora no Asaas; cria na primeira vez (o Asaas exige CPF/CNPJ para cobrar). Procura antes pela
     * referência externa: uma rotina interrompida não cria cliente repetido.
     */
    public static function cliente(object $t): string
    {
        if ($t->asaas_cliente_id !== '') {
            return $t->asaas_cliente_id;
        }
        $doc = so_digitos($t->cnpj);
        if (!in_array(strlen($doc), [11, 14], true)) {
            throw new AsaasErro("A locadora {$t->slug} está sem CNPJ no cadastro: o Asaas exige o documento para gerar a cobrança.");
        }
        $id = self::req('GET', '/customers?externalReference=' . rawurlencode($t->id))['data'][0]['id'] ?? null;
        if ($id === null) {
            $dados = ['name' => $t->nome, 'cpfCnpj' => $doc, 'externalReference' => $t->id];
            if (filter_var($t->email_contato, FILTER_VALIDATE_EMAIL)) {
                $dados['email'] = $t->email_contato;
            }
            $fone = so_digitos($t->telefone);
            if (strlen($fone) === 11) {
                $dados['mobilePhone'] = $fone;
            } elseif (strlen($fone) === 10) {
                $dados['phone'] = $fone;
            }
            $id = self::req('POST', '/customers', $dados)['id'] ?? null;
        }
        if (!is_string($id) || !self::idValido($id)) {
            throw new AsaasErro('Resposta inesperada do Asaas ao cadastrar o cliente.');
        }
        DB::connection('plataforma')->update('UPDATE tenants SET asaas_cliente_id = ? WHERE id = ?', [$id, $t->id]);
        return $id;
    }

    /** Cobrança avulsa no Asaas; o cliente escolhe PIX, boleto ou cartão na fatura (billingType UNDEFINED). */
    public static function cobrancaCriar(string $clienteId, string $referencia, string $vencimento, float $valor, string $descricao): array
    {
        return self::req('POST', '/payments', [
            'customer' => $clienteId,
            'billingType' => 'UNDEFINED',
            'value' => $valor,
            'dueDate' => $vencimento,
            'description' => $descricao,
            'externalReference' => $referencia,
        ]);
    }

    public static function cobranca(string $gatewayId): array
    {
        if (!self::idValido($gatewayId)) {
            throw new AsaasErro('Id de cobrança inválido.');
        }
        return self::req('GET', '/payments/' . $gatewayId);
    }

    /** Cobrança pela nossa referência (recupera uma cobrança criada lá cuja resposta não chegou aqui). */
    public static function cobrancaPorReferencia(string $referencia): ?array
    {
        return self::req('GET', '/payments?externalReference=' . rawurlencode($referencia))['data'][0] ?? null;
    }

    public static function cancelarCobranca(string $gatewayId): void
    {
        self::req('DELETE', '/payments/' . $gatewayId);
    }
}
