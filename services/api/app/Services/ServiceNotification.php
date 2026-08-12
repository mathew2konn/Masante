<?php

namespace App\Services;

use App\Models\Contribution;
use App\Models\Delegation;
use App\Models\MembreFamille;
use App\Models\ResponsableFamille;
use App\Models\User;
use App\Notifications\NotificationMasante;
use App\Support\TypeNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Qui est prévenu, de quoi, et en quels termes (incrément D1).
 *
 * FRONTIÈRE : tout le jugement est ici. Le mobile reçoit une liste déjà composée — il n'additionne
 * pas des destinataires, ne devine pas un intitulé, ne décide pas qu'un événement mérite une
 * notification. Test de fin de module : « quelles règles ce module calcule-t-il côté front ? »
 * → aucune.
 *
 * RÈGLE INVIOLABLE (G1) : aucune de ces phrases ne contient de contenu médical. On nomme la
 * personne et l'acte — « Aya a proposé un ajout au carnet de Koffi Eli » — jamais le fait clinique.
 * Ces textes s'affichent sur des écrans verrouillés et, pour le push, transitent par un tiers.
 *
 * NOTE SUR CE QUE LA NOTIFICATION RÉVÈLE QUAND MÊME : nommer un proche et dire que son dossier a
 * été ouvert dans tel établissement reste une divulgation, sur un téléphone posé sur une table.
 * Elle est assumée — c'est exactement le service demandé (« tous les autres le sauront sans même
 * qu'on les appelle ») — mais elle doit être dite, pas découverte.
 */
class ServiceNotification
{
    /** Un ajout attend d'être arbitré : on prévient ceux qui peuvent trancher. */
    public function contributionDeposee(Contribution $contribution): void
    {
        $membre = $contribution->membre;

        if ($membre === null) {
            return;
        }

        // L'auteur sait ce qu'il vient de faire : le notifier serait du bruit. Et il ne peut de
        // toute façon pas valider sa propre contribution (règle de l'incrément C).
        $destinataires = $this->sauf(
            ResponsableFamille::decideursPour($membre->user_id),
            [$contribution->auteur_user_id],
        );

        $this->envoyer(
            $destinataires,
            TypeNotification::CONTRIBUTION_DEPOSEE,
            sprintf(
                '%s a proposé un ajout au carnet de %s.',
                $this->nomDe($contribution->auteur),
                $this->nomDuMembre($membre),
            ),
            ['membre_id' => $membre->id, 'contribution_id' => $contribution->id],
        );
    }

    /**
     * Une contribution a été tranchée.
     *
     * Deux destinataires de nature différente, et c'est le propriétaire qui l'a demandé au G1 :
     * l'AUTEUR, qui attend une réponse, et les AUTRES RESPONSABLES — « Tel responsable a validé
     * l'ajout du carnet de santé par telle personne ». Sans ce second envoi, deux responsables
     * pourraient traiter la même file sans savoir que l'autre a déjà décidé.
     */
    public function contributionDecidee(Contribution $contribution, User $decideur): void
    {
        $membre = $contribution->membre;

        if ($membre === null) {
            return;
        }

        $validee = $contribution->statut === Contribution::VALIDEE;
        $type    = $validee ? TypeNotification::CONTRIBUTION_VALIDEE : TypeNotification::CONTRIBUTION_REJETEE;
        $verbe   = $validee ? 'validé' : 'refusé';

        $corps = sprintf(
            '%s a %s l\'ajout au carnet de %s proposé par %s.',
            $this->nomDe($decideur),
            $verbe,
            $this->nomDuMembre($membre),
            $this->nomDe($contribution->auteur),
        );

        // Le motif de rejet est une justification de gouvernance familiale, saisie par le
        // responsable — pas une donnée clinique. Il est repris tel quel pour que l'auteur
        // comprenne, sans avoir à rappeler.
        if (! $validee && $contribution->motif_rejet !== null) {
            $corps .= ' Motif : '.$contribution->motif_rejet;
        }

        // D2 — DÉCISION DU PROPRIÉTAIRE (2026-08-12) : « lorsque la validation est faite, tous les
        // autres le sauront ». L'annonce s'élargit donc à toute la famille qui a accès au carnet,
        // alors que la DÉCISION, elle, reste aux seuls responsables (`decideursPour`, incrément C).
        // C'est exactement la séparation demandée : voir n'est pas décider.
        //
        // Élargir une audience, c'est élargir une surface de fuite : la règle inviolable de D1
        // s'applique sans exception — le corps ci-dessus dit la section, jamais son contenu.
        $destinataires = $this->sauf(
            array_merge(
                ResponsableFamille::decideursPour($membre->user_id),
                Delegation::lecteursDe($membre->id),
                [$contribution->auteur_user_id],
            ),
            [$decideur->id],   // celui qui vient de décider n'a pas besoin qu'on le lui annonce
        );

        $this->envoyer($destinataires, $type, $corps, [
            'membre_id'       => $membre->id,
            'contribution_id' => $contribution->id,
        ]);
    }

    /**
     * Un carnet vient d'être partagé — remplace le `Log::info` posé au chap. 4.2.
     *
     * La délégation n'est pas encore acceptée : c'est précisément l'objet de la notification, la
     * personne doit savoir qu'on l'attend.
     */
    public function delegationRecue(Delegation $delegation): void
    {
        $membre = $delegation->membre;

        if ($membre === null) {
            return;
        }

        $this->envoyer(
            [$delegation->delegue_user_id],
            TypeNotification::DELEGATION_RECUE,
            sprintf(
                '%s partage avec vous le carnet de %s.',
                $this->nomDe($delegation->titulaire),
                $this->nomDuMembre($membre),
            ),
            [
                'membre_id'     => $membre->id,
                'delegation_id' => $delegation->id,
                // L'écran de revendication (incrément B) doit passer AVANT la complétion du
                // profil : après, un second NIS existe et un NIS ne se libère jamais.
                'revendicable'  => (bool) $delegation->est_le_dossier_du_delegue,
            ],
        );
    }

    /**
     * Plusieurs carnets partagés en un geste → UNE notification, pas quinze.
     *
     * Le partage en masse est le geste normal du scénario : un responsable accueille un nouveau
     * membre et lui donne accès à toute la famille. Émettre une notification par carnet noierait
     * l'information dans son propre bruit — et la première chose qu'un utilisateur fait d'une
     * liste de quinze lignes identiques, c'est de cesser de la lire.
     */
    public function partageEnMasseRecu(User $titulaire, User $delegue, int $nombre, bool $revendicable): void
    {
        if ($nombre <= 0) {
            return;
        }

        $corps = sprintf(
            '%s partage avec vous %d carnet%s de santé.',
            $this->nomDe($titulaire),
            $nombre,
            $nombre > 1 ? 's' : '',
        );

        if ($revendicable) {
            $corps .= ' L\'un d\'eux serait le vôtre.';
        }

        $this->envoyer(
            [$delegue->id],
            TypeNotification::DELEGATION_RECUE,
            $corps,
            ['nombre' => $nombre, 'revendicable' => $revendicable],
        );
    }

    /** Quelqu'un vient de recevoir le pouvoir de valider les ajouts d'une famille. */
    public function responsableDesigne(ResponsableFamille $ligne): void
    {
        $this->envoyer(
            [$ligne->responsable_user_id],
            TypeNotification::RESPONSABLE_DESIGNE,
            sprintf(
                '%s vous a désigné responsable : vous pouvez valider les ajouts à ses carnets.',
                $this->nomDe($ligne->titulaire),
            ),
            ['responsable_id' => $ligne->id],
        );
    }

    /**
     * Un soignant a ouvert le dossier — LE SCÉNARIO DE L'ACCIDENT.
     *
     * « Si un membre fait un accident et qu'on consulte sa carte vitale, tous les autres le sauront
     * sans même qu'on les appelle » (propriétaire, G1). D'où l'envoi au propriétaire ET à tous les
     * délégués en lecture, et non au seul titulaire comme le faisaient les trois stubs remplacés
     * (scan QR §4.3 étape 6, médecin référent §5.6, bris de glace §5.3).
     *
     * @param  string  $voie  `qr_scan` | `referent` | `bris_de_glace` (colonne `type_acces`)
     */
    public function dossierConsulte(
        MembreFamille $membre,
        ?User $agent,
        string $voie,
        ?string $motifUrgence = null,
    ): void {
        $urgent = $voie === 'bris_de_glace';

        $etablissement = $agent?->structure?->nom;
        $lieu          = $etablissement !== null ? ' à '.$etablissement : '';

        $corps = match ($voie) {
            'bris_de_glace' => sprintf(
                'Accès d\'urgence au dossier de %s%s.',
                $this->nomDuMembre($membre),
                $lieu,
            ),
            'referent' => sprintf(
                'Le médecin référent a consulté le dossier de %s%s.',
                $this->nomDuMembre($membre),
                $lieu,
            ),
            default => sprintf(
                'Le dossier de %s a été consulté%s.',
                $this->nomDuMembre($membre),
                $lieu,
            ),
        };

        // Le motif d'un bris de glace est saisi par l'agent AVANT l'ouverture (§5.3) et n'est pas
        // un fait clinique : c'est la justification de l'exception. La cacher priverait la famille
        // du seul élément qui rend l'alerte compréhensible dans l'instant.
        if ($urgent && $motifUrgence !== null) {
            $corps .= ' Motif déclaré : '.$motifUrgence;
        }

        $destinataires = array_merge([$membre->user_id], Delegation::lecteursDe($membre->id));

        // Un soignant qui serait par ailleurs délégué de ce carnet n'a pas à recevoir l'alerte de
        // son propre accès.
        $this->envoyer(
            $this->sauf($destinataires, [$agent?->id]),
            TypeNotification::DOSSIER_CONSULTE,
            $corps,
            [
                'membre_id' => $membre->id,
                'voie'      => $voie,
                'urgent'    => $urgent,
            ],
        );
    }

    /**
     * Un soignant vient de consigner un acte dans le carnet (incrément D0).
     *
     * Mêmes destinataires que {@see dossierConsulte} — le propriétaire et les délégués en lecture.
     * Un parent en voyage doit apprendre qu'une ordonnance a été ajoutée au carnet de son enfant
     * sans avoir à ouvrir l'application par hasard.
     *
     * On nomme la SECTION, jamais son contenu : « une ordonnance », pas le médicament prescrit.
     * La règle inviolable de D1 s'applique ici sans changement.
     */
    public function carnetEnrichi(MembreFamille $membre, User $soignant, string $section): void
    {
        $lieu = $soignant->structure?->nom;

        $corps = sprintf(
            '%s a ajouté %s au carnet de %s%s.',
            $this->nomDe($soignant),
            $this->libelleSection($section),
            $this->nomDuMembre($membre),
            $lieu !== null ? ' à '.$lieu : '',
        );

        $destinataires = array_merge([$membre->user_id], Delegation::lecteursDe($membre->id));

        $this->envoyer(
            $this->sauf($destinataires, [$soignant->id]),
            TypeNotification::CARNET_ENRICHI,
            $corps,
            ['membre_id' => $membre->id, 'section' => $section],
        );
    }

    /** Libellé lisible d'une section — présentation, aucune règle. */
    private function libelleSection(string $section): string
    {
        return match ($section) {
            'antecedents'        => 'un antécédent',
            'vaccinations'       => 'une vaccination',
            'ordonnances'        => 'une ordonnance',
            'resultats-analyses' => 'un résultat d\'analyse',
            default              => 'un élément',
        };
    }

    /**
     * Envoi effectif — dédoublonné, débarrassé des identifiants nuls.
     *
     * @param  array<int, int|null>   $userIds
     * @param  array<string, mixed>   $donnees
     */
    private function envoyer(array $userIds, TypeNotification $type, string $corps, array $donnees = []): void
    {
        $ids = array_values(array_unique(array_filter($userIds, static fn ($id) => $id !== null)));

        if ($ids === []) {
            return;
        }

        $destinataires = User::whereIn('id', $ids)->get();

        if ($destinataires->isEmpty()) {
            return;
        }

        Notification::send($destinataires, new NotificationMasante($type, $corps, $donnees));
    }

    /**
     * @param  array<int, int|null>  $ids
     * @param  array<int, int|null>  $exclus
     * @return array<int, int|null>
     */
    private function sauf(array $ids, array $exclus): array
    {
        $exclus = array_filter($exclus, static fn ($id) => $id !== null);

        return array_values(array_diff($ids, $exclus));
    }

    private function nomDe(?User $user): string
    {
        if ($user === null) {
            return 'Un membre de la famille';
        }

        return trim($user->prenom.' '.$user->nom) ?: 'Un membre de la famille';
    }

    private function nomDuMembre(MembreFamille $membre): string
    {
        return trim($membre->prenom.' '.$membre->nom);
    }
}
