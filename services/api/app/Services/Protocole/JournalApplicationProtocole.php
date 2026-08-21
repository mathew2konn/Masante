<?php

namespace App\Services\Protocole;

use App\Models\ProtocoleApplication;
use App\Models\ProtocoleConflit;
use App\Services\Audit\ChaineAudit;
use App\Services\Audit\JournalChaine;
use App\Services\Referentiel\EmpreinteReferentiel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * P10b-2 — La chaîne du journal d'exécution (CDC_08 §10 ; CDC_10 « journal immuable »).
 *
 * Même calcul d'empreinte que les trois autres chaînes du projet (`EmpreinteReferentiel`, réutilisé
 * et non réécrit : deux algorithmes de hachage seraient deux endroits où « comment on hache » peut
 * diverger), chaîne SÉPARÉE pour la raison établie en b-1 — mêler deux journaux lierait la validité
 * de l'un à celle de l'autre, et une altération ferait crier les deux sans qu'on puisse dire lequel
 * a bougé.
 *
 * ═══ CE QUI ENTRE DANS L'EMPREINTE, ET POURQUOI LES RECOMMANDATIONS EN FONT PARTIE ═══
 *
 * Le §10 veut pouvoir répondre à « qu'a-t-on recommandé à ce patient, ce jour-là, sur la foi de
 * quelle version ? ». Si les recommandations restaient hors de l'empreinte, on pourrait les
 * réécrire sans rompre la chaîne : la chaîne protégerait la date et l'identité du patient, et
 * laisserait modifiable **le seul élément qu'un litige discute**. C'est la leçon d'`acteur_nom` en
 * P6.3, appliquée à ce que ce journal-ci a de propre.
 *
 * ═══ LE COÛT, DIT PLUTÔT QUE TU ═══
 *
 * Chaque écriture lit le dernier maillon sous verrou : deux évaluations simultanées ne peuvent pas
 * partir de la même empreinte précédente et produire deux branches. Cela SÉRIALISE les évaluations
 * concurrentes. C'est un choix (décision O3) fait en connaissance du §11 (« moins de 100 ms »), qui
 * n'est de toute façon pas déclaré atteint tant que le cache est en `database`.
 */
final class JournalApplicationProtocole implements JournalChaine
{
    public function nomJournal(): string
    {
        return 'protocole_applications';
    }

    public function requete(): Builder
    {
        return ProtocoleApplication::query();
    }

    /**
     * La charge hachée d'une évaluation relue — identique, clé pour clé, à celle de l'écriture.
     *
     * @return array<string, mixed>
     */
    public function charge(object $entree): array
    {
        return [
            'trace_id' => $entree->trace_id,
            'contexte' => $entree->contexte,
            'pays_code' => $entree->pays_code,
            'membre_id' => self::entierOuNull($entree->membre_id),
            'user_id' => self::entierOuNull($entree->user_id),
            'professionnel_id' => self::entierOuNull($entree->professionnel_id),
            'triage_id' => self::entierOuNull($entree->triage_id),
            'protocole_retenu' => $entree->protocole_retenu_code === null ? null : [
                'code' => $entree->protocole_retenu_code,
                'version' => $this->libelleVersion($entree),
                'numero' => (int) $entree->protocole_retenu_version,
            ],
            'protocoles' => $entree->protocoles_json ?? [],
            'recommandations' => $entree->recommandations_json ?? [],
            'conflits' => $this->conflitsPour($entree),
            'decision_finale' => $entree->decision_finale,
            'ecart' => $entree->ecart_justification,
            'cree_le' => $entree->cree_le->toIso8601String(),
        ];
    }

    /**
     * Inscrit une évaluation et ses divergences. À appeler DANS une transaction déjà ouverte.
     *
     * @param  array<string, mixed>  $resultat  La sortie de {@see SelecteurProtocoles::evaluer()}.
     * @param  array<string, mixed>  $contexteAppel  `contexte`, `pays_code`, `membre_id`,
     *                                               `user_id`, `professionnel_id`, `triage_id`,
     *                                               `decision_finale`, `ecart_justification`.
     */
    public function inscrire(array $resultat, array $contexteAppel): ProtocoleApplication
    {
        // ORDRE DES VERROUS : ce journal est toujours pris EN DERNIER, après les tables métier de
        // l'appelant. Une inversion entre deux transactions concurrentes est la définition d'un
        // interblocage — celui qui avait mordu en P6.1.
        $chaine = ChaineAudit::numeroCourant($this->nomJournal(), $this->requete());

        $precedent = ProtocoleApplication::query()
            ->where('chaine', $chaine)
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        $horodatage = Carbon::now();
        $traceId = (string) Str::uuid();

        $retenu = $resultat['protocole_retenu'] ?? null;

        $charge = [
            'trace_id' => $traceId,
            'contexte' => $contexteAppel['contexte'],
            'pays_code' => $contexteAppel['pays_code'] ?? 'CI',
            'membre_id' => self::entierOuNull($contexteAppel['membre_id'] ?? null),
            'user_id' => self::entierOuNull($contexteAppel['user_id'] ?? null),
            'professionnel_id' => self::entierOuNull($contexteAppel['professionnel_id'] ?? null),
            'triage_id' => self::entierOuNull($contexteAppel['triage_id'] ?? null),
            'protocole_retenu' => $retenu,
            'protocoles' => $resultat['protocoles'] ?? [],
            // Ce que le patient a vu. Hors de l'empreinte, ce serait la seule chose réécrivable.
            'recommandations' => $resultat['actions'] ?? [],
            'conflits' => $resultat['conflits'] ?? [],
            'decision_finale' => $contexteAppel['decision_finale'] ?? null,
            'ecart' => $contexteAppel['ecart_justification'] ?? null,
            'cree_le' => $horodatage->toIso8601String(),
        ];

        $application = ProtocoleApplication::create([
            'chaine' => $chaine,
            'trace_id' => $traceId,
            'contexte' => $contexteAppel['contexte'],
            'pays_code' => $contexteAppel['pays_code'] ?? 'CI',
            'membre_id' => $contexteAppel['membre_id'] ?? null,
            'user_id' => $contexteAppel['user_id'] ?? null,
            'professionnel_id' => $contexteAppel['professionnel_id'] ?? null,
            'triage_id' => $contexteAppel['triage_id'] ?? null,
            'protocole_retenu_code' => $retenu['code'] ?? null,
            'protocole_retenu_version' => $retenu['numero'] ?? null,
            'protocoles_json' => $resultat['protocoles'] ?? [],
            'recommandations_json' => $resultat['actions'] ?? [],
            'decision_finale' => $contexteAppel['decision_finale'] ?? null,
            'ecart_justification' => $contexteAppel['ecart_justification'] ?? null,
            'empreinte_precedente' => $precedent?->empreinte,
            'empreinte' => EmpreinteReferentiel::duMaillon($precedent?->empreinte, $charge),
            'cree_le' => $horodatage,
        ]);

        // L'ancrage de tête : la première évaluation d'une chaîne fixe son point de départ
        // ({@see ChaineAudit::ancrer()}).
        if ($precedent === null) {
            ChaineAudit::ancrer($this->nomJournal(), $chaine, $application->empreinte);
        }

        foreach ($resultat['conflits'] ?? [] as $conflit) {
            ProtocoleConflit::create($conflit + [
                'application_id' => $application->id,
                'cree_le' => $horodatage,
            ]);
        }

        return $application;
    }

    /**
     * Recalcule toute la chaîne et signale la première rupture.
     *
     * Deux ruptures distinguées, parce qu'elles ne disent pas la même chose : CHAINAGE (une entrée
     * a été supprimée ou insérée hors du moteur) et CONTENU (une entrée a été modifiée après son
     * écriture). Motif `JournalProtocole` / `JournalReferentiel`.
     *
     * @return array{intacte: bool, entrees: int, rupture: ?array<string, mixed>}
     */
    public function verifierChaine(): array
    {
        return ChaineAudit::verifier($this);
    }

    /**
     * Un identifiant entre dans l'empreinte sous UNE seule forme.
     *
     * À l'écriture il vient d'un tableau PHP, à la vérification d'une colonne de base : selon le
     * pilote, `12` peut revenir en `int` ou en `"12"`. Sans normalisation, la même ligne
     * produirait deux empreintes différentes et la chaîne crierait à l'altération alors que rien
     * n'a bougé — une fausse alerte sur un journal médico-légal est pire qu'un journal muet.
     */
    private static function entierOuNull(mixed $valeur): ?int
    {
        return $valeur === null || $valeur === '' ? null : (int) $valeur;
    }

    /**
     * Le libellé de version du protocole retenu, tel qu'il a été inscrit dans `protocoles_json`.
     *
     * On ne le relit PAS en base : la version en vigueur aura changé, et l'empreinte recalculée ne
     * correspondrait plus — la vérification signalerait une altération là où seule la base a
     * évolué. Le journal doit se vérifier avec ce qu'il porte, jamais avec l'état du jour.
     */
    private function libelleVersion(ProtocoleApplication $entree): ?string
    {
        foreach ($entree->protocoles_json ?? [] as $protocole) {
            if (($protocole['code'] ?? null) === $entree->protocole_retenu_code) {
                return $protocole['version'] ?? null;
            }
        }

        return null;
    }

    /** @return array<int, array<string, mixed>> */
    private function conflitsPour(ProtocoleApplication $entree): array
    {
        return DB::table('protocole_conflits')
            ->where('application_id', $entree->id)
            ->orderBy('id')
            ->get()
            ->map(static fn ($c): array => [
                'action_type' => $c->action_type,
                'valeur_retenue' => $c->valeur_retenue,
                'protocole_retenu_code' => $c->protocole_retenu_code,
                'protocole_retenu_version' => (int) $c->protocole_retenu_version,
                'source_retenue' => $c->source_retenue,
                'valeur_ecartee' => $c->valeur_ecartee,
                'protocole_ecarte_code' => $c->protocole_ecarte_code,
                'protocole_ecarte_version' => (int) $c->protocole_ecarte_version,
                'source_ecartee' => $c->source_ecartee,
                'critere' => $c->critere,
            ])
            ->all();
    }
}
