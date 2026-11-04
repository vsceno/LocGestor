<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Com LOCGESTOR_FORCAR_HTTPS=true (use em produção, com certificado): todo acesso por http:// vai para https:// (301) e
 * as respostas mandam o navegador usar só HTTPS por um ano (HSTS). Desligado por padrão, para o ambiente local.
 * Atrás de Cloudflare ou balanceador, o HTTPS vale quando o proxy está em LOCGESTOR_PROXIES (X-Forwarded-Proto).
 */
class ForcarHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('locgestor.forcar_https')) {
            return $next($request);
        }
        if (! $request->secure()) {
            return redirect()->secure($request->getRequestUri(), 301);
        }

        $resposta = $next($request);
        $resposta->headers->set('Strict-Transport-Security', 'max-age=31536000');

        return $resposta;
    }
}
