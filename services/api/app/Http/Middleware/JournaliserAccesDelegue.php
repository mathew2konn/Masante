<?php

namespace App\Http\Middleware;

use App\Models\AccesDossier;
use App\Models\Delegation;
use App\Models\MembreFamille;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Journalise toute lecture d'un dossier effectuée par un DÉLÉGUÉ (carnet familial partagé, A).
 *
 * POURQUOI UN MIDDLEWARE ET NON UN APPEL DANS CHAQUE CONTRÔLEUR : la lecture déléguée passe par
 * une douzaine de contrôleurs (sections du carnet, photo, carte CMU, fiche vitale, NIS, référent),
 * tous validés G5. Les instrumenter un par un les réécrirait, et surtout : une route ajoutée
 * demain serait oubliée. Posé sur le groupe authentifié, ce middleware couvre par construction
 * toute route portant `{membre}` — celles d'aujourd'hui et celles de demain.
 *
 * POURQUOI PAS DANS LA POLICY : une Policy répond à une question, elle n'a pas d'effet de bord.
 * Elle est d'ailleurs appelée plusieurs fois par requête et aussi quand l'accès est REFUSÉ — on
 * journaliserait des accès qui n'ont pas eu lieu.
 *
 * CE QUI EST JOURNALISÉ : une ligne par lecture aboutie, nominative (`agent_id` = le délégué),
 * avec la section touchée. Volume assumé : un journal d'accès à un dossier médical qui aurait des
 * trous ne vaudrait rien (loi 2013-450, Sécurité §10.2).
 */
class JournaliserAccesDelegue
{
    public function handle(Request $request, Closure $next): Response
    {
        $reponse = $next($request);

        try {
            $this->journaliser($request, $reponse);
        } catch (\Throwable $e) {
            // Le journal ne doit jamais faire échouer la consultation d'un dossier — mais son
            // échec ne doit pas non plus passer inaperçu.
            Log::error('Journalisation d\'un accès délégué impossible', [
                'erreur' => $e->getMessage(),
                'route'  => $request->path(),
            ]);
        }

        return $reponse;
    }

    private function journaliser(Request $request, Response $reponse): void
    {
        // Une lecture refusée n'est pas un accès.
        if ($reponse->getStatusCode() >= 400) {
            return;
        }

        // A n'ouvre que la LECTURE. Les écritures d'un délégué sont refusées par la Policy, et la
        // génération de QR (POST) écrit déjà sa propre ligne via QrTokenService — ne pas doubler.
        if (! $request->isMethod('GET')) {
            return;
        }

        $membre = $request->route('membre');

        if (! $membre instanceof MembreFamille) {
            return;
        }

        $utilisateur = $request->user();

        // Le propriétaire qui lit son propre dossier n'est pas un accès de tiers.
        if ($utilisateur === null || $membre->user_id === $utilisateur->id) {
            return;
        }

        if (! Delegation::lecturePour($utilisateur->id, $membre->id)) {
            return;
        }

        AccesDossier::create([
            'membre_id'           => $membre->id,
            'agent_id'            => $utilisateur->id,
            'type_acces'          => 'delegation',
            'sections_consultees' => [$this->section($request)],
            'ip_address'          => $request->ip(),
        ]);
    }

    /**
     * Nomme la section lue à partir du gabarit de route :
     *   `api/v1/membres/{membre}/antecedents` → « antecedents »
     *   `api/v1/membres/{membre}`             → « dossier »
     */
    private function section(Request $request): string
    {
        $segments = explode('/', $request->route()?->uri() ?? '');
        $position = array_search('{membre}', $segments, true);

        if ($position === false) {
            return 'dossier';
        }

        return $segments[$position + 1] ?? 'dossier';
    }
}
