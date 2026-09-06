<?php

namespace App\Services\Analyse;

use App\Models\Automate;
use App\Models\DemandeAnalyse;
use App\Models\JournalLaboratoire;
use App\Models\Prelevement;
use App\Models\ResultatAnalyse;
use App\Models\User;
use App\Models\ValidationBiologique;
use App\Services\Integration\AuthentificationClientApi;
use App\Services\ServiceNotification;
use App\Support\RegistreSectionsCarnet;
use App\Support\StatutDemandeAnalyse;
use App\Support\StatutPrelevement;
use App\Support\VerdictValidationBiologique;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * B5-c — Résultats (saisie/import), validation biologique et son verrou, publication au carnet
 * (CDC_09 §7.4 étapes 6-8, CDC_04 §109, L7/L10/L13-L15 du plan G1, M1→M12 de son complément).
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * LE BROUILLON N'EST JAMAIS DANS LE CARNET (M1)
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * `CarnetSectionController::index()` liste sans filtre de statut : le carnet ne connaît aucune
 * notion de brouillon. Les valeurs mesurées vivent donc, avant publication, sur
 * `prelevements.resultats_bruts_json` (chiffré) — jamais sur une ligne `resultats_analyses` qui
 * serait visible au patient avant que L7 ne l'autorise.
 *
 * ═══ SAISIE ET IMPORT : UN SEUL SERVICE (L15/M2) ═══
 *
 * `saisir()` (laborantin, portail) et `importer()` (automate, API) écrivent le MÊME couple de
 * colonnes par la MÊME méthode privée : seule diffère l'`origine`, décidée ICI, jamais déclarée par
 * l'appelant. La garde d'état (`en_analyse` seulement) est APPLICATIVE, dite comme telle — jamais
 * déguisée en garantie du moteur (précédent B3-b : une garde qui dépend d'un état textuel, pas
 * d'une somme, mais l'honnêteté est la même).
 *
 * ═══ LA VALIDATION EST LE VERROU (L7) ═══
 *
 * `publier()` exige `statut === VALIDE` : un résultat non validé ne part JAMAIS au dossier du
 * patient. Un rejet EFFACE le brouillon et journalise (`validations_biologiques`, append-only) —
 * on ne supprime pas une validation, on en écrit une autre.
 *
 * ═══ LA PUBLICATION : QUATRIÈME CHEMIN D'ÉCRITURE, MAIS PAS UN CINQUIÈME POINT DE RÉSOLUTION ═══
 *
 * `publier()` réutilise {@see ServiceLienResultat} DIRECTEMENT — pas
 * `ResultatAnalyseController::preparerDonnees()` — et c'est une décision, pas un oubli : ce
 * point d'accroche composite re-résoudrait AUSSI `resultats_json` via {@see ServiceLienAnalyse},
 * donc re-interrogerait le catalogue sur des valeurs déjà FIGÉES à la saisie. Si le catalogue a
 * changé entre-temps (une unité corrigée sous quatre-yeux), le résultat publié changerait
 * silencieusement sans qu'aucun biologiste ne l'ait revu — exactement le risque que
 * {@see ProjecteurLignesDemande} existe pour fermer sur les lignes de la demande. `resultats_json`
 * est donc copié VERBATIM depuis le brouillon ; seuls `medecin_prescripteur_id`/`laboratoire_id`
 * (des déclarations sur des TIERS, jamais chiffrées, jamais figées avant cet instant) passent par
 * `ServiceLienResultat`.
 *
 * ═══ `source='structure'` : LA VALEUR QUI FERME K5/K11, JAMAIS UN CHOIX DU CLIENT ═══
 *
 * Posée ICI, après toute résolution — c'est le fait qui distingue un résultat publié par ce circuit
 * d'un compte rendu recopié par le patient.
 */
final class ServiceValidationBiologique
{
    public const PERMISSION_EXECUTION = ServiceCircuitPrelevement::PERMISSION;

    public const PERMISSION_VALIDATION = 'analyse.valider';

    public function __construct(
        private readonly ServiceLienAnalyse $lienAnalyse,
        private readonly ServiceLienResultat $lienResultat,
        private readonly ServiceNotification $notifications,
    ) {}

    /**
     * Saisie manuelle, au portail, par le laborantin qui exécute (étape 6 du §7.4).
     *
     * @param  array<int, array<string, mixed>>  $valeurs
     */
    public function saisir(User $laborantin, Prelevement $prelevement, array $valeurs): Prelevement
    {
        $this->assertHabiliteExecution($laborantin);
        $this->assertAppartientAuLaboratoire($prelevement, $laborantin->structure_id);

        return $this->enregistrerBrouillon(
            $prelevement, $valeurs, 'saisie', 'resultat_saisi',
            $laborantin->id, $laborantin->nomLisible(),
        );
    }

    /**
     * Import automate (M9, L10 réécrit) — authentifié en amont par clé+HMAC
     * ({@see AuthentificationClientApi}), jamais par une session
     * portail. `$automate` doit appartenir au MÊME laboratoire que le prélèvement : anti-usurpation
     * d'un automate déclaré ailleurs.
     *
     * @param  array<int, array<string, mixed>>  $valeurs
     */
    public function importer(Automate $automate, Prelevement $prelevement, array $valeurs): Prelevement
    {
        if (! $automate->appartientA($prelevement->laboratoire_structure_id)) {
            throw ValidationException::withMessages([
                'automate' => 'Cet automate n\'est pas déclaré pour ce laboratoire.',
            ]);
        }

        $prelevement = $this->enregistrerBrouillon(
            $prelevement, $valeurs, 'automate', 'resultat_importe',
            null, 'Automate '.$automate->libelle,
        );

        $automate->forceFill(['dernier_message_le' => now()])->save();

        return $prelevement;
    }

    /** Le verdict qui valide (étape 7 du §7.4) — le verrou de L7. */
    public function valider(User $biologiste, Prelevement $prelevement): Prelevement
    {
        $this->assertHabiliteValidation($biologiste);
        $this->assertAppartientAuLaboratoire($prelevement, $biologiste->structure_id);

        return DB::transaction(function () use ($biologiste, $prelevement): Prelevement {
            /** @var Prelevement $verrouille */
            $verrouille = Prelevement::whereKey($prelevement->id)->lockForUpdate()->firstOrFail();

            $this->assertPeutJugerLeBrouillon($verrouille, 'validé');

            $verrouille->statut = StatutPrelevement::VALIDE;
            $verrouille->valide_le = now();
            $verrouille->valide_par_user_id = $biologiste->id;
            $verrouille->valide_par_nom = $biologiste->nomLisible();
            $verrouille->save();

            ValidationBiologique::create([
                'prelevement_id' => $verrouille->id,
                'user_id' => $biologiste->id,
                'nom' => $biologiste->nomLisible(),
                'verdict' => VerdictValidationBiologique::VALIDE,
            ]);

            JournalLaboratoire::consigner(
                'validation', $verrouille->demande_id, $verrouille->id,
                $biologiste->id, $biologiste->nomLisible(), $verrouille->laboratoire_structure_id,
            );

            return $verrouille;
        });
    }

    /**
     * Le verdict qui rejette : EFFACE le brouillon (M4), journalise, ne change PAS le statut — le
     * prélèvement est déjà `en_analyse` au moment où rejeter a un sens (des valeurs existent, le
     * biologiste les juge mauvaises). « On ne supprime pas une validation, on en écrit une autre. »
     */
    public function rejeter(User $biologiste, Prelevement $prelevement, string $motif): Prelevement
    {
        $this->assertHabiliteValidation($biologiste);
        $this->assertAppartientAuLaboratoire($prelevement, $biologiste->structure_id);

        $motif = trim($motif);

        // Refus bruyant AVANT la transaction : le déclencheur du moteur porte la même garde,
        // mais le service doit nommer le champ, pas se reposer sur un `SQLSTATE 45000` brut.
        if ($motif === '') {
            throw ValidationException::withMessages(['motif' => 'Un rejet doit porter son motif.']);
        }

        return DB::transaction(function () use ($biologiste, $prelevement, $motif): Prelevement {
            /** @var Prelevement $verrouille */
            $verrouille = Prelevement::whereKey($prelevement->id)->lockForUpdate()->firstOrFail();

            $this->assertPeutJugerLeBrouillon($verrouille, 'rejeté');

            $verrouille->resultats_bruts_json = null;
            $verrouille->resultats_bruts_origine = null;
            $verrouille->save();

            ValidationBiologique::create([
                'prelevement_id' => $verrouille->id,
                'user_id' => $biologiste->id,
                'nom' => $biologiste->nomLisible(),
                'verdict' => VerdictValidationBiologique::REJETE,
                'motif' => $motif,
            ]);

            JournalLaboratoire::consigner(
                'rejet', $verrouille->demande_id, $verrouille->id,
                $biologiste->id, $biologiste->nomLisible(), $verrouille->laboratoire_structure_id,
            );

            return $verrouille;
        });
    }

    /**
     * Étape 8 du §7.4 — transmission sécurisée dans le dossier patient. LE SEUL ENDROIT où
     * `source='structure'` est posé sur un résultat (M7, referme K5/K11 pour de bon).
     */
    public function publier(User $biologiste, Prelevement $prelevement): ResultatAnalyse
    {
        $this->assertHabiliteValidation($biologiste);
        $this->assertAppartientAuLaboratoire($prelevement, $biologiste->structure_id);

        return DB::transaction(function () use ($biologiste, $prelevement): ResultatAnalyse {
            /** @var Prelevement $verrouille */
            $verrouille = Prelevement::whereKey($prelevement->id)->lockForUpdate()->firstOrFail();

            if ($verrouille->statut !== StatutPrelevement::VALIDE) {
                throw ValidationException::withMessages([
                    'prelevement' => 'Ce prélèvement doit d\'abord être validé par un biologiste.',
                ]);
            }

            /** @var DemandeAnalyse $demande */
            $demande = $verrouille->demande()->with(['lignes', 'membre'])->firstOrFail();

            $valide = [
                'type_analyse' => 'biologique',
                'intitule' => $this->intitulePour($demande),
                'date_analyse' => ($verrouille->preleve_le ?? now())->toDateString(),
                'medecin_prescripteur' => $demande->medecin_nom,
                'medecin_prescripteur_id' => $demande->medecin_id,
                'laboratoire_id' => $verrouille->laboratoire_structure_id,
                // VERBATIM depuis le brouillon FIGÉ à la saisie/import — voir le docblock de
                // classe sur la raison de ne PAS repasser par `ServiceLienAnalyse` ici.
                'resultats_json' => $verrouille->resultats_bruts_json,
                'added_by' => 'medecin',
            ];

            $valide = $this->lienResultat->resoudre($valide);

            // K5/K11 — jamais un choix du client, posé ici seulement.
            $valide['source'] = 'structure';
            $valide['origine'] = $verrouille->resultats_bruts_origine;

            $controleur = RegistreSectionsCarnet::controleur('resultats-analyses');

            /** @var ResultatAnalyse $resultat */
            $resultat = $demande->membre->{$controleur->nomRelation()}()->create($valide);

            $verrouille->resultat_analyse_id = $resultat->id;
            $verrouille->publie_le = now();
            $verrouille->statut = StatutPrelevement::PUBLIE;
            $verrouille->save();

            $demande->forceFill(['statut' => StatutDemandeAnalyse::SERVIE])->save();

            JournalLaboratoire::consigner(
                'publication', $demande->id, $verrouille->id,
                $biologiste->id, $biologiste->nomLisible(), $verrouille->laboratoire_structure_id,
            );

            $this->notifications->resultatAnalysePublie($demande->membre, $verrouille->laboratoire?->nom);

            return $resultat;
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $valeurs
     */
    private function enregistrerBrouillon(
        Prelevement $prelevement,
        array $valeurs,
        string $origine,
        string $action,
        ?int $acteurUserId,
        string $acteurNom,
    ): Prelevement {
        return DB::transaction(function () use (
            $prelevement, $valeurs, $origine, $action, $acteurUserId, $acteurNom,
        ): Prelevement {
            /** @var Prelevement $verrouille */
            $verrouille = Prelevement::whereKey($prelevement->id)->lockForUpdate()->firstOrFail();

            // Garde APPLICATIVE (M2), dite comme telle : le moteur ne la garantit pas, le service
            // si — précédent B3-b (le stock n'a pas de somme calculable par un déclencheur non
            // plus, et l'honnêteté sur la nature de la garde est la même).
            if ($verrouille->statut !== StatutPrelevement::EN_ANALYSE) {
                throw ValidationException::withMessages([
                    'prelevement' => 'Un résultat ne peut être saisi ou importé que pendant '
                        .'l\'analyse (état actuel : '.$verrouille->statut->libelle().').',
                ]);
            }

            $verrouille->resultats_bruts_json = $this->lienAnalyse->resoudre($valeurs, 'valeurs');
            $verrouille->resultats_bruts_origine = $origine;
            $verrouille->save();

            JournalLaboratoire::consigner(
                $action, $verrouille->demande_id, $verrouille->id,
                $acteurUserId, $acteurNom, $verrouille->laboratoire_structure_id,
            );

            return $verrouille;
        });
    }

    private function assertPeutJugerLeBrouillon(Prelevement $prelevement, string $participePasse): void
    {
        if ($prelevement->statut !== StatutPrelevement::EN_ANALYSE) {
            throw ValidationException::withMessages([
                'prelevement' => 'Ce prélèvement ne peut pas être '.$participePasse
                    .' depuis son état actuel.',
            ]);
        }

        if (! $prelevement->aUnBrouillon()) {
            throw ValidationException::withMessages([
                'prelevement' => 'Aucun résultat n\'a été saisi ou importé : rien à '.$participePasse.'.',
            ]);
        }
    }

    private function intitulePour(DemandeAnalyse $demande): string
    {
        $libelles = $demande->lignes->pluck('libelle')->filter()->implode(', ');

        return $libelles !== '' ? $libelles : 'Analyses de laboratoire';
    }

    private function assertHabiliteExecution(User $laborantin): void
    {
        if (! $laborantin->can(self::PERMISSION_EXECUTION)) {
            throw ValidationException::withMessages([
                'laboratoire' => 'Vous n\'êtes pas habilité à saisir un résultat.',
            ]);
        }
    }

    private function assertHabiliteValidation(User $biologiste): void
    {
        // Vérifiée ICI et pas seulement par le middleware — le groupe de routes s'ouvre aux DEUX
        // permissions (`analyse.executer|analyse.valider`, M10) pour qu'un biologiste puisse
        // ouvrir la fiche ; la garde qui compte reste celle-ci (piège P4).
        if (! $biologiste->can(self::PERMISSION_VALIDATION)) {
            throw ValidationException::withMessages([
                'laboratoire' => 'Vous n\'êtes pas habilité à valider un résultat biologique.',
            ]);
        }
    }

    /** 404, jamais 403 : un laboratoire d'une autre structure ne doit pas savoir qu'un prélèvement existe là. */
    private function assertAppartientAuLaboratoire(Prelevement $prelevement, ?int $structureId): void
    {
        abort_if(! $prelevement->appartientA($structureId), Response::HTTP_NOT_FOUND);
    }
}
