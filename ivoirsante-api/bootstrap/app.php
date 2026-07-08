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

        // Middlewares RBAC du portail (Sécurité §4.2) — spatie/laravel-permission.
        $middleware->alias([
            'role'               => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission'         => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);

        // Invités : sur l'API (`api/*`) on NE redirige PAS (null) → combiné à `shouldRenderJsonWhen`,
        // l'invité reçoit un 401 JSON propre (jamais un 500 `Route [login] not defined`). Sur le
        // PORTAIL web, on redirige vers l'écran de connexion du portail (Module 4).
        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('api/*') ? null : route('portail.login'),
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
