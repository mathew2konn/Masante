<?php

namespace App\Services;

use App\Models\Contribution;
use App\Models\Delegation;
use App\Models\FacturePatient;
use App\Models\MembreFamille;
use App\Models\RendezVous;
use App\Models\ResponsableFamille;
use App\Models\SpecialiteMedicale;
use App\Models\StructureSanitaire;
use App\Models\User;
use App\Models\VersionModeleIa;
use App\Notifications\NotificationMasante;
use App\Support\StatutFacturePatient;
use App\Support\TypeNotification;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Spatie\Permission\Exceptions\RoleDoesNotExist;

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
        $type = $validee ? TypeNotification::CONTRIBUTION_VALIDEE : TypeNotification::CONTRIBUTION_REJETEE;
        $verbe = $validee ? 'validé' : 'refusé';

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
            'membre_id' => $membre->id,
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
                'membre_id' => $membre->id,
                'delegation_id' => $delegation->id,
                // L'écran de revendication (incrément B) doit passer AVANT la complétion du
                // profil : après, un second NIS existe et un NIS ne se libère jamais.
                'revendicable' => (bool) $delegation->est_le_dossier_du_delegue,
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
        $lieu = $etablissement !== null ? ' à '.$etablissement : '';

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
                'voie' => $voie,
                'urgent' => $urgent,
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

    /**
     * P6.8b — des échéances du calendrier vaccinal national sont atteintes pour ce membre.
     *
     * ═══ UNE NOTIFICATION, PAS UNE PAR DOSE ═══
     *
     * À six semaines, le calendrier prévoit quatre injections le même jour. Émettre quatre
     * notifications identiques noierait l'information dans son propre bruit — et la première chose
     * qu'un utilisateur fait d'une liste de lignes identiques, c'est de cesser de la lire. Motif
     * repris de `partageEnMasseRecu` (D1), pour la même raison.
     *
     * ═══ LA RÈGLE INVIOLABLE MORD ICI, ET IL FAUT LE DIRE ═══
     *
     * Le corps NE NOMME AUCUN VACCIN. Un nom de vaccin est une information de santé : il désigne
     * une pathologie visée, parfois une situation. Cette phrase s'affiche sur un écran verrouillé et
     * transite, pour le push, par un tiers. Le détail se lit dans l'application, après
     * authentification — le calendrier y est à un geste.
     *
     * ═══ DESTINATAIRES ═══
     *
     * Le propriétaire du carnet ET les délégués en lecture — mêmes destinataires que
     * {@see carnetEnrichi} et {@see dossierConsulte}. Celui qui emmène l'enfant au centre n'est pas
     * toujours celui qui détient le carnet, et c'est précisément le scénario fondateur de P7.
     *
     * @param  bool  $enRetard  vrai quand le délai de grâce publié est écoulé
     */
    public function echeanceVaccinale(MembreFamille $membre, int $nombre, bool $enRetard): void
    {
        if ($nombre <= 0) {
            return;
        }

        $corps = $enRetard
            ? sprintf(
                '%d vaccination%s prévue%s au calendrier national %s en retard pour %s. '
                .'Ouvrez son carnet pour voir lesquelles.',
                $nombre,
                $nombre > 1 ? 's' : '',
                $nombre > 1 ? 's' : '',
                $nombre > 1 ? 'sont' : 'est',
                $this->nomDuMembre($membre),
            )
            : sprintf(
                '%d vaccination%s du calendrier national %s prévue%s pour %s. '
                .'Ouvrez son carnet pour voir lesquelles.',
                $nombre,
                $nombre > 1 ? 's' : '',
                $nombre > 1 ? 'sont' : 'est',
                $nombre > 1 ? 's' : '',
                $this->nomDuMembre($membre),
            );

        $this->envoyer(
            array_merge([$membre->user_id], Delegation::lecteursDe($membre->id)),
            TypeNotification::ECHEANCE_VACCINALE,
            $corps,
            // `nombre` et `en_retard` sont des compteurs et un drapeau d'affichage, jamais un
            // contenu clinique : ils disent COMBIEN, pas QUOI.
            ['membre_id' => $membre->id, 'nombre' => $nombre, 'en_retard' => $enRetard],
        );
    }

    // ═══════════════════════════════════════════════════════════════════════════════════════
    // Lot 9 (post-facturation) — Notifications de facturation.
    //
    // RÈGLE INVIOLABLE PROPRE À CE DOMAINE (§2.7) : « Notification interdite : tout libellé
    // d'acte, de service, de spécialité ou d'établissement. » Plus stricte que la règle générale
    // de D1 (qui autorise déjà, ailleurs dans ce fichier, à NOMMER un établissement — voir
    // `dossierConsulte()`/`carnetEnrichi()`). Les deux méthodes patient ci-dessous passent donc
    // par un garde-fou de contenu dédié, PAS appliqué aux deux méthodes back-office qui suivent
    // (une alerte interne au back-office DOIT nommer la structure concernée).
    // ═══════════════════════════════════════════════════════════════════════════════════════

    /** Une facture patient vient d'être émise, en attente de règlement. */
    public function facturePatientEmise(FacturePatient $facture): void
    {
        $this->envoyerFacturationPatient($facture, TypeNotification::FACTURE_PATIENT_EMISE);
    }

    /**
     * Relance TOUTES les factures A_REGLER dont l'échéance est dépassée et qui n'ont jamais été
     * relancées (`relance_envoyee_le` encore nul) — UNE SEULE fois par facture (R18) : l'horodatage
     * posé ici EST le garde-fou, pas un compteur qu'un appelant pourrait oublier de lire.
     *
     * Appelée par la commande planifiée `masante:facturation:relancer-patients`.
     */
    public function relancerFacturesEnRetard(): int
    {
        $facturesEnRetard = FacturePatient::query()
            ->where('statut', StatutFacturePatient::A_REGLER->value)
            ->whereNull('relance_envoyee_le')
            ->whereNotNull('date_echeance')
            ->whereDate('date_echeance', '<', now()->toDateString())
            ->get();

        foreach ($facturesEnRetard as $facture) {
            $this->envoyerFacturationPatient($facture, TypeNotification::FACTURE_PATIENT_RELANCE);
            $facture->update(['relance_envoyee_le' => now()]);
        }

        return $facturesEnRetard->count();
    }

    /**
     * B1-d (D15) — le rendez-vous est clos (`honore`).
     *
     * ═══ POURQUOI CE N'EST PAS `facturePatientEmise()` REJOUÉE ═══
     *
     * Depuis B1-c, le règlement précède TOUJOURS le check-in : la facture existe déjà, `PAYEE`,
     * bien avant qu'un RDV puisse être clos. Réutiliser `facturePatientEmise()` ici affirmerait
     * qu'une facture NOUVELLE vient d'apparaître — un mensonge. Cette notification confirme la fin
     * de la consultation et rappelle le montant déjà réglé ; elle ne se déclenche qu'une fois, au
     * moment où `terminer()` l'appelle (aucun autre chemin n'écrit `statut = honore`).
     *
     * Mêmes destinataires que `carnetEnrichi()`/`dossierConsulte()` — titulaire ET délégués en
     * lecture : une consultation menée à son terme est le même type d'événement qu'un dossier
     * ouvert ou enrichi, et un proche qui a emmené le patient doit le savoir sans avoir à demander.
     *
     * `$facture` est nullable : un très ancien RDV réglé par le seul chemin legacy (`Paiement`,
     * avant `factures_patient` — repli documenté dans {@see RecuRdvService::estRegle()}) n'a pas de
     * ligne `FacturePatient` à citer. Le montant disparaît alors du corps plutôt que d'être inventé.
     */
    public function rendezVousTermine(RendezVous $rdv, ?FacturePatient $facture): void
    {
        $membre = $rdv->membre;

        if ($membre === null) {
            return;
        }

        $corps = $facture !== null
            ? sprintf('Votre rendez-vous est terminé · %d FCFA réglés.', $facture->montant_brut)
            : 'Votre rendez-vous est terminé.';

        $this->verifierContenuFacturation($corps);

        $this->envoyer(
            array_merge([$membre->user_id], Delegation::lecteursDe($membre->id)),
            TypeNotification::RENDEZ_VOUS_TERMINE,
            $corps,
            ['membre_id' => $membre->id, 'rendez_vous_id' => $rdv->id, 'facture_patient_id' => $facture?->id],
        );
    }

    /** Contenu partagé par `facturePatientEmise()`/`relancerFacturesEnRetard()` — même libellé, pas de ton différent. */
    private function envoyerFacturationPatient(FacturePatient $facture, TypeNotification $type): void
    {
        $beneficiaire = $facture->beneficiaire_id !== null ? $facture->beneficiaire : null;

        $corps = $beneficiaire !== null
            ? sprintf('Facture pour %s · %d FCFA', $beneficiaire->prenom, $facture->montant_reste_a_charge)
            : sprintf('Vous avez une nouvelle facture · %d FCFA', $facture->montant_reste_a_charge);

        $this->verifierContenuFacturation($corps);

        $this->envoyer(
            [$facture->patient_id],
            $type,
            $corps,
            ['facture_patient_id' => $facture->id],
        );
    }

    /** Une structure vient de basculer au Palier 0 (suspension pour impayé, lot 1) — back-office uniquement. */
    public function structureSuspendue(int $structureSanitaireId, int $montantDu, \DateTimeInterface $dateBascule): void
    {
        $structure = StructureSanitaire::find($structureSanitaireId);
        if ($structure === null) {
            return;
        }

        $corps = sprintf(
            'Structure %s suspendue pour impayé (%d FCFA dû) le %s.',
            $structure->nom,
            $montantDu,
            $dateBascule instanceof \DateTimeInterface ? $dateBascule->format('d/m/Y') : (string) $dateBascule,
        );

        $this->envoyer(
            $this->backOffice(),
            TypeNotification::STRUCTURE_SUSPENDUE_IMPAYE,
            $corps,
            ['structure_sanitaire_id' => $structureSanitaireId, 'montant_du' => $montantDu],
        );
    }

    /**
     * P10c-3-i (F19) — un modèle IA candidat attend une revue de gouvernance (CDC_05 §8/§9).
     *
     * Destinataires : ceux qui PORTENT la permission `ia_triage.valider` — orpheline (attribuée à
     * aucun rôle métier, motif constant de ce projet), donc introuvable par `User::role()`. Le
     * corps NOMME un numéro de version, jamais une métrique (§2.7 transposé à une donnée de
     * gouvernance IA plutôt qu'à un contenu clinique — les chiffres se lisent à l'écran, après
     * authentification et habilitation).
     */
    public function modeleIaCandidat(VersionModeleIa $version): void
    {
        try {
            $destinataires = User::permission('ia_triage.valider')->pluck('id')->all();
        } catch (PermissionDoesNotExist) {
            // Même prudence que `backOffice()` ci-dessous : une permission pas encore seedée ne
            // doit jamais faire échouer l'entraînement qui déclenche cette notification.
            return;
        }

        $this->envoyer(
            $this->sauf($destinataires, [$version->entraine_par]),
            TypeNotification::MODELE_IA_CANDIDAT,
            sprintf(
                'Un modèle IA candidat (version %d, %s) attend votre revue.',
                $version->numero_version,
                $version->pays_code,
            ),
            ['version_id' => $version->id],
        );
    }

    /**
     * P10c-3-ii lot B (F39) — Une dérive constatée sur le modèle en service.
     *
     * ═══ ELLE PRÉVIENT, ELLE NE DÉCIDE PAS ═══
     *
     * Aucun modèle n'est désactivé : retirer un modèle du service sur un indice statistique serait
     * une décision d'exploitation prise par une machine (ligne tenue depuis ADR-017). Le message
     * dit **combien** de dérives et **sur quelle version**, jamais « il faut agir ».
     *
     * ═══ AUCUN CONTENU CLINIQUE, ET C'EST VÉRIFIÉ PAR UN VECTEUR ═══
     *
     * Ni symptôme, ni constante, ni diagnostic — la règle inviolable de P7-D1 vaut ici comme
     * ailleurs : une notification s'affiche sur un écran verrouillé. Le détail chiffré se lit à
     * l'écran de gouvernance, derrière une authentification.
     */
    public function deriveModeleIaDetectee(VersionModeleIa $version, int $nbAlertes): void
    {
        try {
            $destinataires = User::permission('ia_triage.valider')->pluck('id')->all();
        } catch (PermissionDoesNotExist) {
            return;
        }

        $this->envoyer(
            $destinataires,
            TypeNotification::DERIVE_MODELE_IA,
            sprintf(
                '%d dérive(s) constatée(s) sur le modèle en service (version %d, %s). Le modèle '
                .'reste actif : la décision vous appartient.',
                $nbAlertes,
                $version->numero_version,
                $version->pays_code,
            ),
            ['version_id' => $version->id],
        );
    }

    /** Une structure suspendue pour impayé vient d'être réactivée (solde soldé, lot 1) — back-office uniquement. */
    public function structureReactivee(int $structureSanitaireId): void
    {
        $structure = StructureSanitaire::find($structureSanitaireId);
        if ($structure === null) {
            return;
        }

        $this->envoyer(
            $this->backOffice(),
            TypeNotification::STRUCTURE_REACTIVEE,
            sprintf('Structure %s réactivée : solde soldé.', $structure->nom),
            ['structure_sanitaire_id' => $structureSanitaireId],
        );
    }

    /**
     * GARDE-FOU DE CONTENU (Phase 2 du lot 9) — point de code UNIQUE, appelé avant tout envoi
     * d'une notification de facturation vers un PATIENT. Ce n'est pas une confiance aveugle dans
     * les méthodes ci-dessus : c'est un filet de sécurité au dernier point avant l'envoi.
     *
     * Data-driven, pas une liste en dur : interroge les deux référentiels réels de ce projet
     * (spécialités, établissements). Aucun catalogue d'actes n'existe encore dans ce projet
     * (P6.8 l'a explicitement renvoyé à un incrément de paiement séparé, non fait) — ce garde-fou
     * ne peut donc pas le vérifier, faute de source de vérité, et ne prétend pas le faire.
     *
     * @throws RuntimeException un motif interdit a été trouvé — l'envoi n'a PAS lieu
     */
    private function verifierContenuFacturation(string $corps): void
    {
        $corpsNormalise = mb_strtolower($corps);

        $motifsInterdits = SpecialiteMedicale::query()->pluck('libelle')
            ->merge(StructureSanitaire::query()->pluck('nom'))
            ->filter(fn ($motif) => is_string($motif) && trim($motif) !== '');

        foreach ($motifsInterdits as $motif) {
            if (str_contains($corpsNormalise, mb_strtolower($motif))) {
                throw new RuntimeException(
                    'Notification de facturation bloquée : le corps contient un libellé interdit '.
                    "(§2.7 — ni acte, ni service, ni spécialité, ni établissement) : « {$motif} »."
                );
            }
        }
    }

    /**
     * Destinataires des alertes internes de facturation : le back-office MaSanté (lot 8).
     *
     * `User::role()` LÈVE si le rôle n'existe pas encore en base (spatie) — un contexte où il n'a
     * pas été seedé (ex. tests antérieurs au lot 9, environnement non provisionné) ne doit jamais
     * faire échouer la bascule/réactivation qui déclenche cette alerte : une notification qu'on ne
     * peut adresser à personne se tait, elle ne casse pas l'opération financière.
     */
    private function backOffice(): array
    {
        try {
            return User::role('admin_ivoirsante')->pluck('id')->all();
        } catch (RoleDoesNotExist) {
            return [];
        }
    }

    /** Libellé lisible d'une section — présentation, aucune règle. */
    private function libelleSection(string $section): string
    {
        return match ($section) {
            'antecedents' => 'un antécédent',
            'vaccinations' => 'une vaccination',
            'ordonnances' => 'une ordonnance',
            'resultats-analyses' => 'un résultat d\'analyse',
            default => 'un élément',
        };
    }

    /**
     * Envoi effectif — dédoublonné, débarrassé des identifiants nuls.
     *
     * @param  array<int, int|null>  $userIds
     * @param  array<string, mixed>  $donnees
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
