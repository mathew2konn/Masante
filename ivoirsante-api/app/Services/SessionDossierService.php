<?php

namespace App\Services;

use App\Models\AccesDossier;
use App\Models\MembreFamille;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;

/**
 * Module 4 / 4.5 — Session « dossier ouvert » de 30 minutes (CdC §4.3 étape 5-7 ; Sécurité §5.4).
 *
 * Un scan de QR valide n'accorde pas un accès permanent : il ouvre une fenêtre de 30 minutes,
 * après quoi un nouveau QR est nécessaire. La fenêtre vit dans la SESSION WEB de l'agent —
 * elle meurt donc avec sa déconnexion, et l'identifiant du membre n'apparaît jamais dans l'URL
 * (l'agent ne peut pas atteindre un autre dossier en changeant l'adresse : anti-IDOR par
 * construction, OWASP A01).
 *
 * Journal d'audit (§10.1). Le journal est immuable (§10.2) : impossible de compléter après coup
 * la ligne écrite au scan avec la durée réelle et les sections consultées, qui ne sont connues
 * qu'à la fermeture. On écrit donc DEUX lignes en ajout seul, liées par le même `token_qr_id` :
 *
 *   1. OUVERTURE — écrite par {@see QrTokenService::consommer()} au scan (durée et sections nulles) ;
 *   2. CLÔTURE   — écrite ici à la fermeture (manuelle, expiration ou déconnexion), portant
 *      `duree_minutes` et `sections_consultees`.
 *
 * L'immuabilité du journal reste ainsi entière : rien n'est jamais réécrit.
 */
class SessionDossierService
{
    /** Durée d'une session dossier, en minutes (CdC §4.3 étape 5). */
    public const DUREE_MINUTES = 30;

    /** Clé de la session web portant le dossier ouvert. */
    private const CLE = 'dossier_ouvert';

    /** Ouvre la fenêtre de consultation à la suite d'un scan valide. */
    public function ouvrir(AccesDossier $ouverture): void
    {
        Session::put(self::CLE, [
            'acces_id'  => $ouverture->id,
            'membre_id' => $ouverture->membre_id,
            'ouvert_a'  => now()->toIso8601String(),
            'sections'  => [],
        ]);
    }

    /** Y a-t-il une session dossier encore valide ? (une session expirée est close et purgée) */
    public function estActive(): bool
    {
        $etat = Session::get(self::CLE);

        if ($etat === null) {
            return false;
        }

        if ($this->expireA($etat)->isPast()) {
            $this->fermer('expiration');

            return false;
        }

        return true;
    }

    /** Le membre dont le dossier est ouvert, ou null si aucune session valide. */
    public function membre(): ?MembreFamille
    {
        if (! $this->estActive()) {
            return null;
        }

        return MembreFamille::find(Session::get(self::CLE.'.membre_id'));
    }

    /** Secondes restantes avant expiration (0 si aucune session). */
    public function secondesRestantes(): int
    {
        $etat = Session::get(self::CLE);

        return $etat === null ? 0 : (int) max(0, now()->diffInSeconds($this->expireA($etat), false));
    }

    /**
     * Note qu'une section du dossier vient d'être consultée (§10.1 « sections consultées »).
     * Accumulée en session, sans doublon, puis inscrite au journal à la clôture.
     */
    public function noterSection(string $section): void
    {
        if (! $this->estActive()) {
            return;
        }

        $etat = Session::get(self::CLE);

        if (! in_array($section, $etat['sections'], true)) {
            $etat['sections'][] = $section;
            Session::put(self::CLE, $etat);
        }
    }

    /**
     * Ferme la session et écrit la ligne d'audit de CLÔTURE. Idempotent : sans session ouverte,
     * ne fait rien. `$motif` n'est pas journalisé en base (le schéma d'audit est figé) mais tracé
     * dans les logs applicatifs, utile au diagnostic.
     */
    public function fermer(string $motif = 'manuelle'): void
    {
        $etat = Session::pull(self::CLE);

        if ($etat === null) {
            return;
        }

        $ouverture = AccesDossier::find($etat['acces_id']);

        if ($ouverture === null) {
            return;
        }

        // Minutes entamées : une consultation de 30 secondes compte pour 1 minute. Les secondes
        // sont tronquées AVANT l'arrondi, sinon une fraction de seconde ajouterait une minute.
        $secondes = (int) Carbon::parse($etat['ouvert_a'])->diffInSeconds(now());
        $duree = (int) ceil($secondes / 60);

        AccesDossier::create([
            'membre_id'           => $ouverture->membre_id,
            'agent_id'            => $ouverture->agent_id,
            'token_qr_id'         => $ouverture->token_qr_id,
            'type_acces'          => $ouverture->type_acces,
            'sections_consultees' => $etat['sections'],
            'ip_address'          => $ouverture->ip_address,
            // Bornée à la durée accordée : une session expirée n'a pas duré plus de 30 min.
            'duree_minutes'       => min($duree, self::DUREE_MINUTES),
        ]);

        Log::info('Session dossier fermée', [
            'acces_ouverture' => $ouverture->id,
            'membre_id'       => $ouverture->membre_id,
            'motif'           => $motif,
        ]);
    }

    /** Instant d'expiration de la fenêtre de 30 minutes. */
    private function expireA(array $etat): Carbon
    {
        return Carbon::parse($etat['ouvert_a'])->addMinutes(self::DUREE_MINUTES);
    }
}
