<?php

use App\Http\Middleware\ForcarHttps;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Avisos do Asaas: quem chama é o Asaas, sem formulário; a autorização é o token do cabeçalho asaas-access-token
        $middleware->preventRequestForgery(except: ['webhook/asaas']);

        // HTTPS obrigatório em produção (LOCGESTOR_FORCAR_HTTPS=true); no ambiente local, desligado
        $middleware->append(ForcarHttps::class);

        // Atrás de um proxy (Cloudflare, balanceador), o IP do cliente vem no X-Forwarded-For. Só os IPs de
        // LOCGESTOR_PROXIES podem informar esse cabeçalho. Sem a variável, nenhum proxy é confiável (o padrão seguro):
        // assim ninguém burla o limite de cadastros trocando o IP pelo cabeçalho.
        $proxies = array_values(array_filter(array_map('trim', explode(',', (string) env('LOCGESTOR_PROXIES', '')))));
        if ($proxies !== []) {
            $middleware->trustProxies(at: $proxies);
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
