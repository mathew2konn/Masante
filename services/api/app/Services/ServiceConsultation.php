<?php

namespace App\Services;

use App\Models\Antecedent;
use App\Models\Consultation;
use App\Models\Diagnostic;
use App\Models\Medecin;
use App\Models\MembreFamille;
use App\Models\NoteObservation;
use App\Models\User;
use App\Services\Maladie\ServiceLienMaladie;
use App\Support\StatutConsultation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * B2-a — mener une consultation (CDC_11 §5.2).
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * TOUT LE JUGEMENT EST ICI ; LE CONTRÔLEUR TRADUIT EN HTTP
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * Même partage que `EcritureSoignantService` (P7-D0) et `RendezVousValidationService` (B1-a) : les
 * refus sont décidés dans le service, pour que les deux surfaces éventuelles (portail Blade
 * aujourd'hui, API Next demain) ne puissent pas diverger sur QUI a le droit de faire QUOI.
 *
 * ═══ LES GARDES, ET AUCUNE NE RATTRAPE LES AUTRES ═══
 *
 *  1. HABILITATION — `dossier.ecrire`. AUCUNE PERMISSION NEUVE N'EST CRÉÉE : mener une
 *     consultation, c'est consigner un acte dans le carnet, ce que cette permission dit déjà.
 *     En inventer une seconde (`consultation.mener`) donnerait DEUX CLÉS POUR UNE SEULE PORTE et
 *     laisserait « qui peut poser un acte de soin ? » avoir deux réponses — refus déjà opposé par
 *     P11.1-D5 à `demande.traiter`. Vérifiée ICI et pas seulement par le middleware : les routes
 *     du portail sont sur le guard `web`, le piège de P4 (`rdv.validate`) est connu.
 *  2. VOIE CONSENTIE — la liste vient de `EcritureSoignantService::VOIES_ECRITURE`, jamais
 *     recopiée. Le bris de glace en est exclu depuis P7-D0 : cette voie ouvre le vital minimal
 *     SANS consentement ; y mener une consultation ferait d'un accès d'exception un droit de
 *     soigner. Une seconde liste ici aurait pu diverger de la première sans que rien ne le dise.
 *  3. UN SEUL ACTE PAR ACCÈS — garantie déclarative en base (`uq_consultation_acces`) ; le service
 *     la vérifie d'abord pour rendre un message utile plutôt qu'une violation d'index.
 *  4. L'AUTEUR SEUL POURSUIT SON ACTE — un autre soignant, fût-il habilité et dans le même
 *     établissement, ne complète ni ne clôture la consultation d'un confrère.
 *  5. UNE CONSULTATION CLÔTURÉE EST TERMINALE.
 *
 * ═══ CE QUE LE CLIENT NE DÉCLARE JAMAIS ═══
 *
 * Ni le patient (il vient de la SESSION, jamais d'un identifiant d'URL — règle du Module 4, qui
 * rend l'anti-IDOR structurel), ni l'auteur, ni l'établissement, ni le rattachement au
 * rendez-vous ou au triage : tout cela, le serveur le SAIT. Cinquième application du principe qui
 * a refermé `source`/`added_by` (P7-C), `obligatoire` (P6.8b), `provenance` (P6.8d) et
 * `medecin_nom` (P6.5a). Le client n'apporte que le motif — ce que le patient a dit.
 */
class ServiceConsultation
{
    public function __construct(
        private readonly SessionDossierService $session,
        // B2-b — le lien au référentiel des maladies passe par le service de P6.8c, jamais par une
        // seconde mécanique ; et l'inscription aux antécédents par le chemin d'écriture soignant de
        // P7-D0, avec ses trois gardes, plutôt que par un accès direct au modèle.
        private readonly ServiceLienMaladie $liens,
        private readonly EcritureSoignantService $ecritures,
    ) {}

    /**
     * Ouvre la consultation de la session de dossier en cours.
     *
     * @throws ValidationException
     */
    public function ouvrir(User $soignant, ?string $motif = null): Consultation
    {
        $membre = $this->session->membre();
        $accesId = $this->session->accesId();

        if ($membre === null || $accesId === null) {
            $this->refus('Aucun dossier n\'est ouvert : une consultation se mène dans un dossier.');
        }

        $this->assertHabilite($soignant);
        $this->assertVoieConsentie();

        // Verrou pessimiste : deux onglets ouverts sur la même session de dossier ne doivent pas
        // créer deux consultations pour un seul accès (l'index unique refuserait la seconde, mais
        // avec une violation d'index au lieu d'un message utile).
        return DB::transaction(function () use ($soignant, $membre, $accesId, $motif): Consultation {
            $existante = Consultation::where('acces_dossier_id', $accesId)->lockForUpdate()->first();

            if ($existante !== null) {
                $this->refus('Une consultation est déjà ouverte pour cet accès au dossier.');
            }

            $fiche = Medecin::with('structure:id,nom')->where('user_id', $soignant->id)->first();

            $consultation = new Consultation;
            $consultation->membre_id = $membre->id;

            // FIGÉS PAR LE SERVEUR. Le nom vient de la fiche professionnelle quand elle existe
            // (elle est reliée au compte par un gestionnaire — acte humain, P6.5a), sinon du nom
            // lisible du compte. `User::nomLisible()` est la source unique depuis P10b-1, qui a
            // trouvé que `$user->name` n'existe pas sur ce modèle et écrivait « Système » pour
            // tout acteur humain dans trois journaux d'audit.
            $consultation->soignant_user_id = $soignant->id;
            $consultation->soignant_nom = $fiche?->nom_complet ?? $soignant->nomLisible();
            $consultation->medecin_id = $fiche?->id;
            $consultation->structure_id = $fiche?->structure?->id;
            $consultation->structure_nom = $fiche?->structure?->nom;

            $consultation->acces_dossier_id = $accesId;
            $consultation->rendez_vous_id = $this->session->rdvDeclare();
            $consultation->triage_id = $this->session->triageDeclare();

            $consultation->statut = StatutConsultation::EN_COURS;
            $consultation->motif = $this->texteOuNull($motif);
            $consultation->debutee_le = now();
            $consultation->save();

            return $consultation;
        });
    }

    /**
     * Consigne une observation dans la consultation (Z-a — la table `notes_observations` existe
     * depuis le 2026-07-02 et EST celle du CDC_04 §103 ; on ne la double pas).
     *
     * POURQUOI CE CHEMIN, ET PAS L'OUVERTURE DE `notes-observations` AUX SOIGNANTS. Le registre
     * des sections l'a délibérément laissée « réservée au propriétaire », en notant que l'ouvrir
     * serait additif. L'ouvrir par le registre générique laisserait un soignant écrire une note
     * FLOTTANTE, rattachée à aucun acte — alors que le §5.2 place l'observation DANS la
     * consultation. Ici, une observation de soignant appartient toujours à un acte identifié.
     *
     * @throws ValidationException
     */
    public function observer(User $soignant, Consultation $consultation, string $contenu): NoteObservation
    {
        $this->assertHabilite($soignant);
        $this->assertVoieConsentie();
        $this->assertAuteur($soignant, $consultation);
        $this->assertEnCours($consultation);

        $texte = $this->texteOuNull($contenu);

        if ($texte === null) {
            $this->refus('Une observation ne peut pas être vide.', 'contenu');
        }

        $note = new NoteObservation;
        $note->membre_id = $consultation->membre_id;
        $note->contenu = $texte;
        // Réécrits par le serveur, jamais déclarés : un soignant ne peut pas faire passer son
        // observation pour une note du patient, ni l'inverse (miroir exact de P7-C et P7-D0).
        $note->auteur_type = 'medecin';
        $note->auteur_user_id = $soignant->id;
        $note->triage_id = $consultation->triage_id;
        $note->consultation_id = $consultation->id;
        $note->save();

        return $note;
    }

    /**
     * Clôture la consultation. Terminal.
     *
     * @throws ValidationException
     */
    public function cloturer(User $soignant, Consultation $consultation): Consultation
    {
        $this->assertHabilite($soignant);
        $this->assertAuteur($soignant, $consultation);
        $this->assertEnCours($consultation);

        // La clôture N'EXIGE PAS la voie consentie, à la différence de l'ouverture et de
        // l'observation : refermer un acte n'ajoute rien au dossier. Si le consentement a expiré
        // pendant la consultation, laisser l'acte ouvert indéfiniment serait pire — il resterait
        // « en cours » dans le dossier du patient sans que personne ne puisse le refermer.
        $consultation->statut = StatutConsultation::CLOTUREE;
        $consultation->cloturee_le = now();
        $consultation->save();

        return $consultation;
    }

    /**
     * B2-b — pose un diagnostic dans la consultation.
     *
     * LE LIEN AU RÉFÉRENTIEL EST FACULTATIF, ET LE SERVEUR NE DEVINE JAMAIS. Aucun rapprochement
     * entre les mots du médecin et une entrée du référentiel : ce serait un diagnostic posé par une
     * machine (CDC_00 §4, décision P6.8c). Quand le lien EST fourni, code et libellé sont relus à la
     * version publiée et FIGÉS — et `libelle`, les mots du médecin, n'est jamais réécrit : le lien
     * s'ajoute À CÔTÉ (leçon P6.7a).
     *
     * @throws ValidationException
     */
    public function diagnostiquer(
        User $soignant,
        Consultation $consultation,
        string $libelle,
        ?int $maladieId = null,
    ): Diagnostic {
        $this->assertHabilite($soignant);
        $this->assertVoieConsentie();
        $this->assertAuteur($soignant, $consultation);
        $this->assertEnCours($consultation);

        $texte = $this->texteOuNull($libelle);

        if ($texte === null) {
            $this->refus('Un diagnostic ne peut pas être vide.', 'libelle');
        }

        // Le lien passe par le service de P6.8c, jamais par une seconde mécanique : `maladie_code`
        // et `maladie_libelle` y sont effacés puis reposés depuis la version PUBLIÉE, de sorte
        // qu'un client ne puisse pas les déclarer lui-même.
        $resolu = $this->liens->resoudreDiagnostic(['maladie_id' => $maladieId]);

        $diagnostic = new Diagnostic;
        $diagnostic->consultation_id = $consultation->id;
        $diagnostic->libelle = $texte;
        $diagnostic->maladie_id = $resolu['maladie_id'] ?? null;
        $diagnostic->maladie_code = $resolu['maladie_code'] ?? null;
        $diagnostic->maladie_libelle = $resolu['maladie_libelle'] ?? null;
        $diagnostic->save();

        return $diagnostic;
    }

    /**
     * B2-b — inscrit un diagnostic aux ANTÉCÉDENTS du patient.
     *
     * ═══ POURQUOI CE N'EST PAS AUTOMATIQUE, ET NE DOIT JAMAIS L'ÊTRE ═══
     *
     * `antecedents.impact_triage` alimente le score des triages suivants. Y verser chaque
     * diagnostic ferait d'une grippe un antécédent permanent pesant sur toutes les orientations
     * futures du patient : *on dégraderait l'orientation qu'on cherche à améliorer*
     * (`RegistreRetourTriage`, P10c-2-i). Ce qui suit le patient à vie relève d'un jugement
     * clinique, pas d'une conséquence de saisie.
     *
     * LE TYPE EST CHOISI PAR LE MÉDECIN, jamais déduit : décider qu'un diagnostic est « chronique »
     * est une affirmation clinique, et ce projet ne les fabrique pas.
     *
     * L'ÉCRITURE PASSE PAR LE CHEMIN EXISTANT (`EcritureSoignantService`, P7-D0) : les trois gardes
     * de l'écriture soignant s'appliquent sans être réécrites, `source`/`added_by` sont réécrits
     * par le serveur, et la notification part comme pour toute autre écriture au carnet.
     *
     * @throws ValidationException
     */
    public function promouvoirEnAntecedent(
        User $soignant,
        Consultation $consultation,
        Diagnostic $diagnostic,
        string $type,
    ): Antecedent {
        $this->assertHabilite($soignant);
        $this->assertVoieConsentie();
        $this->assertAuteur($soignant, $consultation);

        if ($diagnostic->consultation_id !== $consultation->id) {
            $this->refus('Ce diagnostic appartient à une autre consultation.');
        }

        if ($diagnostic->estPromu()) {
            $this->refus('Ce diagnostic est déjà inscrit aux antécédents.');
        }

        $membre = $consultation->membre;

        if ($membre === null) {
            $this->refus('Le dossier de ce patient est introuvable.');
        }

        /** @var Antecedent $antecedent */
        $antecedent = $this->ecritures->ecrire(
            $soignant,
            $membre,
            (string) $this->session->typeAcces(),
            'antecedents',
            [
                'type' => $type,
                // Les mots du médecin, repris tels quels. Le lien au référentiel est repris aussi :
                // il a déjà été vérifié à la pose du diagnostic, le re-résoudre ici n'apporterait
                // rien et pourrait donner un libellé différent si le référentiel a changé entre-temps.
                'description' => $diagnostic->libelle,
                'maladie_id' => $diagnostic->maladie_id,
                'date_diagnostic' => ($consultation->debutee_le ?? now())->toDateString(),
            ],
        );

        $diagnostic->antecedent_id = $antecedent->id;
        $diagnostic->save();

        return $antecedent;
    }

    /** La consultation ouverte pour la session de dossier en cours, s'il y en a une. */
    public function enCoursPourLaSession(): ?Consultation
    {
        $accesId = $this->session->accesId();

        if ($accesId === null) {
            return null;
        }

        return Consultation::with(['observations', 'diagnostics'])
            ->where('acces_dossier_id', $accesId)
            ->first();
    }

    /** Les consultations d'un membre, la plus récente d'abord. */
    public function historique(MembreFamille $membre, int $limite = 20): Collection
    {
        return Consultation::where('membre_id', $membre->id)
            ->orderByDesc('debutee_le')
            ->limit($limite)
            ->get();
    }

    private function assertHabilite(User $soignant): void
    {
        if (! $soignant->can('dossier.ecrire')) {
            $this->refus('Vous n\'êtes pas habilité à mener une consultation.');
        }
    }

    private function assertVoieConsentie(): void
    {
        $voie = $this->session->typeAcces();

        if (! in_array($voie, EcritureSoignantService::VOIES_ECRITURE, true)) {
            $this->refus('Cet accès est en lecture seule : le patient n\'a pas consenti à une écriture.');
        }
    }

    private function assertAuteur(User $soignant, Consultation $consultation): void
    {
        if ($consultation->soignant_user_id !== $soignant->id) {
            $this->refus('Cette consultation est menée par un autre soignant.');
        }
    }

    private function assertEnCours(Consultation $consultation): void
    {
        if (! $consultation->estEnCours()) {
            $this->refus('Cette consultation est clôturée.');
        }
    }

    /** @return never */
    private function refus(string $message, string $champ = 'consultation'): void
    {
        throw ValidationException::withMessages([$champ => $message]);
    }

    private function texteOuNull(?string $valeur): ?string
    {
        $texte = trim((string) $valeur);

        return $texte === '' ? null : $texte;
    }
}
