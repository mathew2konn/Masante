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
    /** Durée d'une session ouverte par scan QR, en minutes (CdC §4.3 étape 5). */
    public const DUREE_MINUTES = 30;

    /** Durée d'une session de bris de glace (Note_Continuite §5.3) : plus courte, car non consentie. */
    public const DUREE_BRIS_DE_GLACE = 15;

    /** Clé de la session web portant le dossier ouvert. */
    private const CLE = 'dossier_ouvert';

    /**
     * Ouvre la fenêtre de consultation. La durée dépend de la voie d'accès : 30 minutes après un
     * scan QR (le patient a consenti en présentant son QR), 15 minutes en bris de glace (il n'a
     * rien consenti — la fenêtre doit être la plus étroite possible).
     */
    public function ouvrir(AccesDossier $ouverture, int $dureeMinutes = self::DUREE_MINUTES): void
    {
        Session::put(self::CLE, [
            'acces_id'  => $ouverture->id,
            'membre_id' => $ouverture->membre_id,
            'type'      => $ouverture->type_acces,
            'duree'     => $dureeMinutes,
            'ouvert_a'  => now()->toIso8601String(),
            'sections'  => [],
            // D0 — ce que le soignant écrit pendant la fenêtre, inscrit au journal à la clôture.
            'ecritures' => [],
            // P10c-2-i — le triage auquel le soignant déclare que cette consultation répond.
            // NULL tant qu'il ne l'a pas désigné : on ne devine jamais ce lien (décision F1).
            'triage_id' => null,
            // B1-c — le rendez-vous qui a rendu CET accès possible (voie `rdv_partage` seule ;
            // NULL sur les cinq autres voies). Repris de l'ouverture, jamais deviné : c'est ce
            // qui permet au contrôleur d'écriture de savoir sur quel canal Reverb diffuser
            // l'événement de présence, sans coupler {@see EcritureSoignantService} à la voie.
            'rdv_id' => $ouverture->rendez_vous_id,
        ]);
    }

    /** Voie par laquelle le dossier courant a été ouvert (`qr_scan`, `bris_de_glace`, …). */
    public function typeAcces(): ?string
    {
        return Session::get(self::CLE.'.type');
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
     * Note qu'un élément vient d'être ÉCRIT au dossier (incrément D0).
     *
     * Alimente `acces_dossier.donnees_ajoutees` — colonne déclarée au Module 2 et restée vide
     * jusqu'ici, faute de chemin d'écriture soignant.
     *
     * ON N'Y MET AUCUN CONTENU CLINIQUE : section, identifiant, horodatage. Minimisation (loi
     * 2013-450) — dupliquer une ordonnance dans le journal d'audit la ferait exister deux fois, et
     * le journal est immuable. La fiche de parcours (D2) relira l'entrée réelle par son identifiant.
     */
    public function noterEcriture(string $section, int|string $entreeId): void
    {
        if (! $this->estActive()) {
            return;
        }

        $etat = Session::get(self::CLE);

        $etat['ecritures'][] = [
            'section' => $section,
            'id'      => $entreeId,
            'a'       => now()->toIso8601String(),
        ];

        Session::put(self::CLE, $etat);
    }

    /**
     * P10c-2-i — Note que cette consultation répond à un triage donné (CDC_05 §5.5.4).
     *
     * ═══ LE LIEN EST DÉCLARÉ, JAMAIS DÉDUIT (décision F1) ═══
     *
     * La tentation était de rattacher automatiquement au triage le plus récent du membre. Un
     * patient peut avoir fait trois triages dans la semaine et consulter pour le premier :
     * affirmer le lien par proximité temporelle produirait une base d'apprentissage **fausse**,
     * c'est-à-dire le pire résultat possible — un modèle qui apprend d'un lien inventé.
     *
     * Même refus qu'en P6.8c, où le serveur ne rapproche pas une maladie d'un texte libre *même
     * identique au libellé officiel*, et qu'en P6.7b, où une réécriture « évidente » inscrivait le
     * nom du mauvais médecin.
     *
     * L'appartenance du triage au membre ouvert est vérifiée par l'appelant avant cet appel : ce
     * service porte la fenêtre, il ne juge pas les droits.
     */
    public function noterTriage(int $triageId): void
    {
        if (! $this->estActive()) {
            return;
        }

        $etat = Session::get(self::CLE);
        $etat['triage_id'] = $triageId;

        Session::put(self::CLE, $etat);
    }

    /** Le triage désigné pour cette consultation, ou `null` si le soignant n'en a désigné aucun. */
    public function triageDeclare(): ?int
    {
        $triageId = Session::get(self::CLE.'.triage_id');

        return $triageId === null ? null : (int) $triageId;
    }

    /** B1-c — le rendez-vous de la session active, ou `null` hors voie `rdv_partage`. */
    public function rdvDeclare(): ?int
    {
        $rdvId = Session::get(self::CLE.'.rdv_id');

        return $rdvId === null ? null : (int) $rdvId;
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

        // Durée en minutes, arrondie au plus proche, avec un plancher de 1 : une consultation
        // éclair reste une consultation. (Un `ceil` transformerait les quelques secondes de
        // traitement d'une session de 5 min en 6 min.)
        $secondes = (int) Carbon::parse($etat['ouvert_a'])->diffInSeconds(now());
        $duree = max(1, (int) round($secondes / 60));

        AccesDossier::create([
            'membre_id'           => $ouverture->membre_id,
            'agent_id'            => $ouverture->agent_id,
            'token_qr_id'         => $ouverture->token_qr_id,
            'type_acces'          => $ouverture->type_acces,
            // La justification du bris de glace est reportée sur la ligne de clôture : les deux
            // lignes d'un même accès portent ainsi le même motif.
            'motif_urgence'       => $ouverture->motif_urgence,
            // D2 — l'établissement est repris de l'ouverture, jamais relu depuis le compte de
            // l'agent : les deux lignes d'un même accès doivent dire le même hôpital, même si
            // l'agent est muté entre-temps.
            'etablissement'       => $ouverture->etablissement,
            // D2 — le lien qui manquait. `token_qr_id` ne relie les deux lignes que sur la voie du
            // scan ; en accès référent ou d'urgence vitale il est NULL, et la fiche de parcours
            // aurait dû rapprocher les lignes par proximité horaire — c'est-à-dire deviner.
            'acces_ouverture_id'  => $ouverture->id,
            // P10c-2-i — le triage auquel le soignant a déclaré que cette consultation répond
            // (§5.5.4). Porté par la ligne de CLÔTURE, comme les sections consultées et les
            // écritures : il n'est connu qu'une fois la consultation faite, pas au scan.
            //
            // CE QU'IL FAUT SAVOIR DE SA FRAGILITÉ, ET ELLE EST ASSUMÉE : si l'agent ferme son
            // navigateur sans clôturer, cette ligne n'est jamais écrite (constat F5 de P7-D2, qui
            // vaut déjà pour la durée et les sections). Le lien serait alors perdu — mais **le
            // retour clinique, lui, ne l'est pas** : il est écrit immédiatement dans le journal du
            // §10 au moment où le médecin le donne, précisément pour ne pas dépendre d'une clôture
            // qui peut ne jamais venir.
            'triage_id'           => $etat['triage_id'] ?? null,
            // B1-c — même raisonnement que `etablissement` juste au-dessus : les deux lignes
            // d'un accès partagé doivent désigner le même rendez-vous.
            'rendez_vous_id'      => $ouverture->rendez_vous_id,
            'sections_consultees' => $etat['sections'],
            // D0 — `null` plutôt qu'un tableau vide : une session sans écriture n'a rien ajouté,
            // et le journal doit le dire sans ambiguïté.
            'donnees_ajoutees'    => ($etat['ecritures'] ?? []) !== [] ? $etat['ecritures'] : null,
            'ip_address'          => $ouverture->ip_address,
            // Bornée à la durée ACCORDÉE pour cette voie : une session expirée n'a pas duré plus.
            'duree_minutes'       => min($duree, $etat['duree'] ?? self::DUREE_MINUTES),
        ]);

        Log::info('Session dossier fermée', [
            'acces_ouverture' => $ouverture->id,
            'membre_id'       => $ouverture->membre_id,
            'motif'           => $motif,
        ]);
    }

    /** Instant d'expiration de la fenêtre, selon la durée accordée à la voie d'accès. */
    private function expireA(array $etat): Carbon
    {
        return Carbon::parse($etat['ouvert_a'])->addMinutes($etat['duree'] ?? self::DUREE_MINUTES);
    }
}
