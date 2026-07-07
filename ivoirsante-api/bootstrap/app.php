<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // §7.3 — En dev, le tunnel Ngrok termine le HTTPS et agit comme proxy.
        // On lui fait confiance pour que Laravel génère des URL https correctes
        // et accepte l'en-tête X-Forwarded-* (sinon rejets de Host / liens en http).
        $middleware->trustProxies(at: '*');

        // §7.1 — En-têtes de sécurité posés sur TOUTES les réponses (web + api).
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

        // API pure : aucune route web `login`. Un invité sur une route protégée ne doit JAMAIS
        // déclencher une redirection vers `login` (RouteNotFoundException → 500) — cas rencontré
        // quand un client omet `Accept: application/json` (ex. <Image> RN chargeant une photo avec
        // un token expiré). On neutralise la redirection : combiné à `shouldRenderJsonWhen(api/*)`,
        // l'invité reçoit un 401 JSON propre, jamais un 500.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
