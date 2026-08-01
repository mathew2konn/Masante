<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * En-têtes HTTP de sécurité — §7.1 du document Sécurité (couche 1 « Transport »).
 *
 * Posés sur toutes les réponses pour durcir l'application contre le sniffing de type MIME,
 * le clickjacking et les fuites de référent. HSTS n'est activé qu'en production : en
 * développement, l'URL Ngrok change à chaque session, donc forcer HTTPS sur 1 an serait
 * gênant (cf. §7.3 : « HSTS / pinning désactivés en test »).
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        // Empêche le navigateur de « deviner » un type MIME différent (anti-sniffing).
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // Interdit l'affichage du site dans une iframe (anti-clickjacking).
        $response->headers->set('X-Frame-Options', 'DENY');

        // Ne transmet jamais l'URL de provenance (anti-fuite d'information).
        $response->headers->set('Referrer-Policy', 'no-referrer');

        // HSTS : uniquement en production (URL stable + HTTPS garanti).
        if (app()->environment('production')) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains; preload'
            );
        }

        return $response;
    }
}
