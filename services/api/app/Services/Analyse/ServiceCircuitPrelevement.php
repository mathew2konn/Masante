<?php

namespace App\Services\Analyse;

use App\Models\DemandeAnalyse;
use App\Models\JournalLaboratoire;
use App\Models\Prelevement;
use App\Models\StructureSanitaire;
use App\Models\User;
use App\Support\StatutPrelevement;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * B5-b — Le circuit du prélèvement, SANS SESSION DE DOSSIER (L3, décision centrale du lot).
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * LE LABORATOIRE N'OUVRE JAMAIS DE FENÊTRE SUR LE DOSSIER
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * Trois voies étaient possibles (plan G1, L3), deux écartées avec leur raison : une SEPTIÈME voie
 * d'accès au dossier — l'élargissement durable que B3-a a déjà refusé pour le pharmacien — et le
 * scan du QR patient — le plus court, le plus disproportionné. Retenu : le laboratoire lit la
 * DEMANDE par son JETON ({@see DemandeAnalyse::$hidden}), et son écriture est bornée
 * PAR CONSTRUCTION à un prélèvement rattaché à CETTE demande. *La porte n'est pas gardée, elle
 * n'existe pas.*
 *
 * **VECTEUR CENTRAL DU LOT, comme en B3-a** : consulter une demande ou enregistrer un prélèvement
 * ne crée AUCUNE ligne dans `acces_dossier` — vérifié en SQL direct au G2 live.
 *
 * ═══ LES QUATRE TRANSITIONS DE B5-b, ET CE QUI RESTE HORS DE PORTÉE ═══
 *
 * `enregistrer()` (préleve → preleve), `expedier()` (preleve → expedie, FACULTATIF), `recevoir()`
 * (preleve|expedie → recu), `mettreEnAnalyse()` (recu → en_analyse). Les transitions vers `valide`
 * et `publie` n'existent pas ici : elles supposent un résultat, que B5-c seul construit
 * ({@see ServiceValidationBiologique}, à venir).
 *
 * ═══ ANTI-IDOR : UN LABORATOIRE D'UNE AUTRE STRUCTURE REÇOIT 404 ═══
 *
 * Une fois enregistré, un prélèvement appartient à SON laboratoire (`laboratoire_structure_id`,
 * figé à l'enregistrement — patron `delivrances.structure_id`). Toute transition ultérieure
 * revérifie cette appartenance, `abort_if(..., 404)` — jamais 403, qui confirmerait qu'un
 * prélèvement existe là (patron `RendezVousValidationService::assertPerimetre()`, B1-a).
 *
 * ═══ CE QUE `journal_laboratoire` REÇOIT, ET CE QUE `prelevements` NE PORTE PAS TOUJOURS ═══
 *
 * Chaque appel écrit une ligne du journal (L13), y compris la simple consultation par jeton. Le
 * journal porte le détail complet de CHAQUE acte ; `prelevements` ne porte l'acteur que pour deux
 * étapes (`preleve_par_nom`, `valide_par_*`) — le reste (expédition, réception, mise en analyse)
 * vit dans le journal seul, pour ne pas dupliquer une identité que ce journal garde déjà.
 */
final class ServiceCircuitPrelevement
{
    public const PERMISSION = 'analyse.executer';

    public function __construct(private readonly GenerateurIdentifiantPrelevement $generateur) {}

    /**
     * La demande désignée par ce jeton, ou `null`. Comparaison en TEMPS CONSTANT (patron
     * P10a/B3-a) : la seule protection contre un balayage de l'espace des jetons ne doit pas
     * dépendre du temps de réponse.
     */
    public function demandePourJeton(?string $jeton): ?DemandeAnalyse
    {
        $jeton = trim((string) $jeton);

        if ($jeton === '') {
            return null;
        }

        $demande = DemandeAnalyse::with(['lignes', 'membre:id,nom,prenom,date_naissance'])
            ->where('jeton_partage', $jeton)
            ->first();

        if ($demande === null || ! hash_equals((string) $demande->jeton_partage, $jeton)) {
            return null;
        }

        return $demande;
    }

    /**
     * Journalise la simple consultation d'une demande par jeton — L13 trace TOUS les actes, y
     * compris ceux qui ne créent rien. Appelé séparément de {@see demandePourJeton()} : un jeton
     * FAUX ne doit journaliser rien (rien à journaliser sur ce qui n'existe pas).
     */
    public function journaliserConsultation(User $laborantin, DemandeAnalyse $demande): void
    {
        JournalLaboratoire::consigner(
            'jeton_consulte',
            $demande->id,
            null,
            $laborantin->id,
            $laborantin->nomLisible(),
            $laborantin->structure_id,
        );
    }

    /** Enregistre un nouveau prélèvement contre cette demande. Étapes 2-3 du §7.4, un seul acte. */
    public function enregistrer(User $laborantin, DemandeAnalyse $demande): Prelevement
    {
        $this->assertHabilite($laborantin);
        $laboratoire = $this->assertLaboratoire($laborantin);
        $this->assertOuverte($demande);

        return DB::transaction(function () use ($laborantin, $demande, $laboratoire): Prelevement {
            $prelevement = new Prelevement;
            $prelevement->demande_id = $demande->id;
            $prelevement->identifiant = $this->generateur->generer();
            $prelevement->laboratoire_structure_id = $laboratoire->id;
            $prelevement->statut = StatutPrelevement::PRELEVE;
            $prelevement->preleve_le = now();
            $prelevement->preleve_par_nom = $laborantin->nomLisible();
            $prelevement->save();

            JournalLaboratoire::consigner(
                'prelevement_enregistre', $demande->id, $prelevement->id,
                $laborantin->id, $laborantin->nomLisible(), $laboratoire->id,
            );

            return $prelevement;
        });
    }

    /** Étape 4 — le transport, FACULTATIF (L6) : sauté quand le prélèvement est fait sur place. */
    public function expedier(User $laborantin, Prelevement $prelevement): Prelevement
    {
        return $this->transition(
            $laborantin, $prelevement, [StatutPrelevement::PRELEVE], StatutPrelevement::EXPEDIE,
            'expedie', 'expedie_le', 'Ce prélèvement ne peut pas être expédié depuis son état actuel.',
        );
    }

    /** Étape 5 — réception/accession au laboratoire. N'EST PAS facultative, contrairement au transport. */
    public function recevoir(User $laborantin, Prelevement $prelevement): Prelevement
    {
        return $this->transition(
            $laborantin, $prelevement,
            [StatutPrelevement::PRELEVE, StatutPrelevement::EXPEDIE], StatutPrelevement::RECU,
            'recu', 'recu_le', 'Ce prélèvement ne peut pas être reçu depuis son état actuel.',
        );
    }

    /** Étape 6 — mise en analyse. */
    public function mettreEnAnalyse(User $laborantin, Prelevement $prelevement): Prelevement
    {
        $prelevement = $this->transition(
            $laborantin, $prelevement, [StatutPrelevement::RECU], StatutPrelevement::EN_ANALYSE,
            'mis_en_analyse', 'analyse_le', 'Ce prélèvement ne peut pas être mis en analyse depuis son état actuel.',
        );

        $prelevement->forceFill(['execute_par_user_id' => $laborantin->id])->save();

        return $prelevement;
    }

    /**
     * Le travail en cours de ce laboratoire — tout prélèvement pas encore publié.
     *
     * DÉFAUT RÉEL CORRIGÉ EN B5-c, invisible en B5-b faute d'état `valide` atteignable : la liste
     * s'arrêtait à `en_analyse`, donc un prélèvement VALIDÉ — en attente de publication — aurait
     * disparu de « mon travail en cours » sans qu'aucun biologiste ne puisse le retrouver pour le
     * publier.
     */
    public function travailPour(User $laborantin): Collection
    {
        if ($laborantin->structure_id === null) {
            return collect();
        }

        return Prelevement::with('demande.membre:id,nom,prenom')
            ->where('laboratoire_structure_id', $laborantin->structure_id)
            ->whereIn('statut', ['preleve', 'expedie', 'recu', 'en_analyse', 'valide'])
            ->orderBy('preleve_le')
            ->get();
    }

    /**
     * @param  array<int, StatutPrelevement>  $depuis
     */
    private function transition(
        User $laborantin,
        Prelevement $prelevement,
        array $depuis,
        StatutPrelevement $vers,
        string $action,
        string $colonneDate,
        string $messageRefus,
    ): Prelevement {
        $this->assertHabilite($laborantin);
        $this->assertAppartientAuLaboratoire($prelevement, $laborantin);

        return DB::transaction(function () use (
            $laborantin, $prelevement, $depuis, $vers, $action, $colonneDate, $messageRefus,
        ): Prelevement {
            /** @var Prelevement $verrouille */
            $verrouille = Prelevement::whereKey($prelevement->id)->lockForUpdate()->firstOrFail();

            if (! in_array($verrouille->statut, $depuis, true)) {
                throw ValidationException::withMessages(['prelevement' => $messageRefus]);
            }

            $verrouille->statut = $vers;
            $verrouille->{$colonneDate} = now();
            $verrouille->save();

            JournalLaboratoire::consigner(
                $action, $verrouille->demande_id, $verrouille->id,
                $laborantin->id, $laborantin->nomLisible(), $verrouille->laboratoire_structure_id,
            );

            return $verrouille;
        });
    }

    private function assertHabilite(User $laborantin): void
    {
        // Vérifiée ICI et pas seulement par le middleware : les routes du portail sont sur le
        // guard `web`, et un `permission:` au mauvais guard laisse passer (piège P4).
        if (! $laborantin->can(self::PERMISSION)) {
            throw ValidationException::withMessages([
                'laboratoire' => 'Vous n\'êtes pas habilité à exécuter un prélèvement.',
            ]);
        }
    }

    /**
     * B5-c (M6) — une demande DÉJÀ SERVIE ou ANNULÉE ne reçoit plus de nouveau prélèvement.
     *
     * DÉFAUT RÉEL LAISSÉ OUVERT PAR B5-b, invisible tant que `servie` était inatteignable :
     * `enregistrer()` ne vérifiait rien sur l'état de la demande, et rien ne limitait le nombre de
     * prélèvements qu'on pouvait y enregistrer. Maintenant que B5-c peut publier (donc atteindre
     * `servie`), la garde devient réelle. `DemandeAnalyse::estOuverte()` existe depuis B5-a
     * (jamais câblée) : c'est elle qui tranche, pas une nouvelle règle.
     *
     * UNE DEMANDE = UN CYCLE : si un examen exige un second prélèvement APRÈS publication, c'est
     * une nouvelle prescription — miroir du monde réel où une ordonnance honorée ne se réutilise
     * pas. Plusieurs prélèvements AVANT publication restent possibles (échantillon insuffisant,
     * reprise) : rien ici ne les interdit, seule la publication ferme la porte.
     */
    private function assertOuverte(DemandeAnalyse $demande): void
    {
        if (! $demande->estOuverte()) {
            throw ValidationException::withMessages([
                'demande' => 'Cette demande est '.$demande->statut->libelle()
                    .' : elle ne peut plus recevoir de nouveau prélèvement.',
            ]);
        }
    }

    private function assertLaboratoire(User $laborantin): StructureSanitaire
    {
        $structure = $laborantin->structure;

        if ($structure === null || ! $structure->estLaboratoire()) {
            throw ValidationException::withMessages([
                'laboratoire' => 'Votre compte n\'est rattaché à aucun laboratoire.',
            ]);
        }

        return $structure;
    }

    /** 404, jamais 403 : un laboratoire d'une autre structure ne doit pas savoir qu'un prélèvement existe là. */
    private function assertAppartientAuLaboratoire(Prelevement $prelevement, User $laborantin): void
    {
        abort_if(! $prelevement->appartientA($laborantin->structure_id), Response::HTTP_NOT_FOUND);
    }
}
