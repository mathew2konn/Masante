<?php

namespace App\Http\Middleware;

use App\Services\SessionDossierService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Module 4 / 4.5 — Garde de la fenêtre de consultation (CdC §4.3 étape 7).
 *
 * Refuse l'accès aux écrans du dossier si aucune session n'est ouverte, ou si les 30 minutes
 * sont écoulées. Dans ce dernier cas, {@see SessionDossierService::estActive()} a déjà écrit la
 * ligne d'audit de clôture : l'agent est renvoyé vers le scanner, un nouveau QR est nécessaire.
 */
class SessionDossierActive
{
    public function __construct(private readonly SessionDossierService $session)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->session->estActive()) {
            return redirect()
                ->route('portail.scan.index')
                ->with('statut', 'Session du dossier expirée ou fermée. Scannez un nouveau QR Code.');
        }

        return $next($request);
    }
}
