<?php
/*
 * LocGestor - endereço do webhook do Asaas (avisos das mensalidades das locadoras).
 *
 * No Asaas (Integrações › Webhooks): URL https://SEU-DOMINIO/webhook_asaas.php, eventos de cobrança e o "token de
 * autenticação" igual a LOCGESTOR_ASAAS_WEBHOOK_TOKEN no .env do laravel/. O atendimento é do Laravel (rota POST
 * /webhook/asaas): este arquivo só entrega a requisição, com o corpo e os cabeçalhos originais, para ela.
 */

declare(strict_types=1);

require __DIR__ . '/laravel/vendor/autoload.php';

$app = require __DIR__ . '/laravel/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

// O caminho é o da rota do Laravel (não o deste arquivo): funciona com ou sem o prefixo do servidor
$requisicao = Illuminate\Http\Request::create('/webhook/asaas', 'POST', [], [], [], $_SERVER, (string) file_get_contents('php://input'));
$resposta = $kernel->handle($requisicao);
$resposta->send();
$kernel->terminate($requisicao, $resposta);
