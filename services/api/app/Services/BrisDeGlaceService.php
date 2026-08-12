<?php

namespace App\Services;

use App\Models\AccesDossier;
use App\Models\MembreFamille;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Module 5 / 5.3 — Voie 4 « bris de glace » (Note_Continuite §5).
 *
 * Quand le patient est inconscient et que ni le titulaire ni un délégué n'est joignable, un service
 * d'urgences vérifié peut ouvrir le SOUS-ENSEMBLE VITAL MINIMAL — jamais le dossier complet.
 * Inspiré du mode bris de glace du DMP français. Le principe directeur de la note : le système ne
 * doit jamais bloquer les soins, tout en gardant le patient au contrôle et en traçant chaque accès.
 *
 * IDENTIFICATION DU PATIENT — le point que la note laisse ouvert. Sans QR, l'agent doit désigner le
 * membre. Une recherche par nom exposerait un annuaire national des malades : un agent curieux
 * pourrait parcourir les dossiers d'une personnalité. On exige donc une CORRESPONDANCE EXACTE sur
 * trois critères (nom, prénom, date de naissance). On ne peut pas explorer, seulement CONFIRMER une
 * identité déjà connue — par les papiers du patient, ou par un proche présent. Toute tentative,
 * fructueuse ou non, est tracée.
 *
 * Le périmètre ouvert est celui de {@see FicheVitaleService} : identité, groupe sanguin, allergies,
 * maladies chroniques et traitements, vaccinations essentielles, contacts d'urgence. Sont exclus
 * l'historique des consultations, les documents importés, les notes libres et les autres membres.
 */
class BrisDeGlaceService
{
    public function __construct(
        private readonly FicheVitaleService $fiches,
        private readonly ServiceNotification $notifications,
    ) {
    }

    /**
     * Retrouve un membre par correspondance EXACTE des trois critères, ou null.
     *
     * Volontairement sans recherche floue, sans `LIKE`, sans liste de résultats : la réponse est
     * un membre ou rien. La casse et les espaces superflus sont normalisés — refuser « KONE » pour
     * « Koné » relèverait de l'obstruction, pas de la sécurité.
     */
    public function identifier(string $nom, string $prenom, string $dateNaissance): ?MembreFamille
    {
        return MembreFamille::query()
            ->whereRaw('LOWER(TRIM(nom)) = ?', [$this->normaliser($nom)])
            ->whereRaw('LOWER(TRIM(prenom)) = ?', [$this->normaliser($prenom)])
            ->whereDate('date_naissance', $dateNaissance)
            ->first();
    }

    /**
     * Ouvre l'accès d'exception : écrit la ligne d'audit d'ouverture, notifie le titulaire et les
     * contacts d'urgence, et renvoie cette ligne (le contrôleur en fait une session de 15 minutes).
     *
     * L'écriture d'audit précède tout affichage : si la notification échoue, l'accès reste tracé.
     */
    public function ouvrir(MembreFamille $membre, User $agent, string $motif, ?string $ip): AccesDossier
    {
        $acces = AccesDossier::create([
            'membre_id'     => $membre->id,
            'agent_id'      => $agent->id,
            'type_acces'    => 'bris_de_glace',
            'motif_urgence' => $motif,
            // D2 — établissement de l'agent AU MOMENT du bris de glace. C'est sur cette voie qu'il
            // compte le plus : la famille, prévenue d'un accès non consenti, demande d'abord
            // « quel hôpital ? ».
            'etablissement' => $agent->structure?->nom,
            'ip_address'    => $ip,
        ]);

        $this->notifier($membre, $agent, $acces);

        return $acces;
    }

    /** Le contenu vital exposé à l'agent — strictement celui de la carte vitale d'urgence. */
    public function ficheVitale(MembreFamille $membre): array
    {
        return $this->fiches->pour($membre);
    }

    /**
     * Notification IMMÉDIATE au titulaire et à la famille (§5.3) :
     * « Accès d'urgence au dossier de [membre] par [établissement] à [heure] ».
     *
     * Stub levé par l'incrément D1 : la notification part réellement, en application, vers le
     * propriétaire du carnet ET tous les délégués en lecture — c'est le cœur du scénario que le
     * propriétaire décrivait au G1 du carnet familial partagé.
     *
     * CE QUI RESTE UNE DETTE, ET QU'IL FAUT DIRE : les CONTACTS D'URGENCE ne sont pas notifiés.
     * Ce sont des numéros de téléphone, pas des comptes MaSanté — les joindre suppose une
     * passerelle SMS, qui n'existe pas dans le projet. Le journal `warning` est donc conservé, avec
     * les numéros réels, pour qu'on puisse toujours vérifier qui AURAIT dû être joint.
     */
    private function notifier(MembreFamille $membre, User $agent, AccesDossier $acces): void
    {
        $this->notifications->dossierConsulte($membre, $agent, 'bris_de_glace', $acces->motif_urgence);

        $horsApplication = $membre->contactsUrgence()
            ->pluck('telephone')
            ->filter()
            ->values()
            ->all();

        Log::warning('Bris de glace — accès d\'urgence au dossier', [
            'acces_id'              => $acces->id,
            'membre_id'             => $membre->id,
            'agent_id'              => $agent->id,
            'etablissement'         => $agent->structure?->nom,
            'motif'                 => $acces->motif_urgence,
            // Notifiés en application par ServiceNotification ; ceux-ci restent injoignables
            // faute de passerelle SMS (dette D1).
            'contacts_non_joignables' => $horsApplication,
        ]);
    }

    /** Normalise pour la comparaison : minuscules, espaces de bord retirés. */
    private function normaliser(string $valeur): string
    {
        return mb_strtolower(trim($valeur));
    }
}
