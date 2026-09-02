<?php

namespace App\Services\Triage;

use App\Models\AlerteDerive;
use App\Models\ExportJeuEntrainement;
use App\Models\VersionModeleIa;
use App\Services\ServiceNotification;
use App\Support\StatutVersionModeleIa;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * P10c-3-ii lot B (F37→F39) — La surveillance de dérive (CDC_05 §8).
 *
 * ═══ D'OÙ VIENNENT LES DEUX DISTRIBUTIONS, ET POURQUOI PAS D'AILLEURS ═══
 *
 * **Référence** : l'export sur lequel le modèle actif a été entraîné. C'est la seule qui décrive
 * vraiment ce que le modèle a vu — prendre « les triages du mois dernier » comparerait deux
 * populations dont aucune n'est celle de l'apprentissage.
 *
 * **Production** : re-dérivée des tables du triage à la demande ({@see TraitsDepuisTriage}), jamais
 * recopiée à côté des prédictions. Le §9.2 l'exige en toutes lettres : les données d'entrée sont
 * « référencées, non dupliquées en clair ». La tentation était forte — recopier le vecteur eût été
 * plus rapide — et elle aurait créé une seconde copie de données cliniques.
 *
 * ═══ CE QUI EST MESURÉ, ET CE QUI NE L'EST PAS ═══
 *
 * PSI par feature (la population a-t-elle changé ?) et chute du rappel sur `sous_triage` (le modèle
 * rate-t-il davantage le cas dangereux ?). **Pas de score global** : une moyenne des deux dirait
 * « ça va » d'un modèle dont la population est stable et la performance effondrée.
 *
 * ═══ DÉTECTION SEULE (F39) ═══
 *
 * Aucune version n'est désactivée, aucun statut n'est touché. L'alerte est journalisée et notifiée
 * au contrôleur plateforme ; un humain décide, avec le rollback de F24. Retirer un modèle sur un
 * indice statistique serait une décision d'exploitation prise par une machine — la ligne tenue
 * depuis ADR-017 pour la fraude, et pour la même raison.
 *
 * ═══ LE SILENCE EST UNE RÉPONSE ═══
 *
 * Une journée sans dérive n'écrit RIEN. Remplir la table de « stable » la rendrait illisible, et
 * un rapport qu'on ne lit plus ne prévient plus. L'absence de ligne se lit comme telle.
 */
final class ServiceDeriveModeleIa
{
    public function __construct(
        private readonly TraitsDepuisTriage $traits,
        private readonly ServiceComparaisonModeleIa $comparaison,
        private readonly ServiceNotification $notifications,
    ) {}

    /**
     * Le rapport d'un jour pour le modèle actif d'un pays.
     *
     * @return array<string, mixed>
     */
    public function analyser(string $paysCode = 'CI', ?CarbonImmutable $jour = null): array
    {
        $jour ??= CarbonImmutable::now();

        $version = VersionModeleIa::query()
            ->where('pays_code', $paysCode)
            ->where('statut', StatutVersionModeleIa::ACTIF)
            ->first();

        if ($version === null) {
            // Aucun modèle en service : il n'y a rien à surveiller. On le DIT plutôt que de rendre
            // un rapport vide qui ressemblerait à « tout va bien ».
            return ['statut' => 'aucun_modele_actif', 'alertes' => 0];
        }

        $reference = $this->lignesDeReference($version);
        $observees = $this->traits->fenetre($jour->subDays($this->fenetreJours()))->all();

        if ($reference === [] || $observees === []) {
            return [
                'statut' => 'echantillon_insuffisant',
                'nb_reference' => count($reference),
                'nb_observees' => count($observees),
                'alertes' => 0,
            ];
        }

        $alertes = DB::transaction(fn (): array => array_merge(
            $this->deriveEntree($version, $jour, $reference, $observees),
            $this->derivePerformance($version, $jour, $reference, $observees),
        ));

        // ═══ ON NE PRÉVIENT QUE DES DÉRIVES NOUVELLES (défaut trouvé au G2 live) ═══
        //
        // Les LIGNES sont idempotentes — rejouer le rapport d'un jour les met à jour. Les
        // NOTIFICATIONS ne l'étaient pas : le G2 a produit trois messages identiques pour la même
        // journée, simplement parce que le rapport avait tourné trois fois. Un contrôleur
        // plateforme qui reçoit le même avertissement à chaque passage cesse de les lire — et
        // c'est ainsi qu'une alerte devient invisible.
        //
        // Précédent exact : le drapeau `notifiee` du routage de fraude (B1) et le rejeu muet du
        // partage en masse (P7-D1). Une relance manuelle après le passage du cron doit être
        // silencieuse.
        $nouvelles = array_filter($alertes, static fn (array $a): bool => $a['nouvelle']);

        if ($nouvelles !== []) {
            // Hors transaction serait plus prudent, mais la notification passe par l'Outbox
            // (P5.4c) : elle s'écrit DANS la transaction et se livre après, par le relais. Le
            // motif est celui de B1 — un tiers ne met jamais en péril l'écriture d'un rapport.
            $this->notifications->deriveModeleIaDetectee($version, count($alertes));
        }

        return [
            'statut' => 'analyse',
            'version' => $version->numero_version,
            'nb_reference' => count($reference),
            'nb_observees' => count($observees),
            'alertes' => count($alertes),
            'detail' => $alertes,
        ];
    }

    /**
     * Les lignes de l'export sur lequel CE modèle a été entraîné.
     *
     * @return array<int, array<string, mixed>>
     */
    private function lignesDeReference(VersionModeleIa $version): array
    {
        $export = ExportJeuEntrainement::find($version->export_id);

        return $export?->instantane_json ?? [];
    }

    /**
     * PSI par feature. Une alerte par feature qui dérive — jamais un indice agrégé.
     *
     * @param  array<int, array<string, mixed>>  $reference
     * @param  array<int, array<string, mixed>>  $observees
     * @return array<int, array<string, mixed>>
     */
    private function deriveEntree(
        VersionModeleIa $version, CarbonImmutable $jour, array $reference, array $observees): array
    {
        $seuils = $this->seuils();
        $profilRef = $this->traits->profil($reference);
        $profilObs = $this->traits->profil($observees);

        $alertes = [];

        foreach ($profilRef as $feature => $categoriesRef) {
            $psi = ReglesDerive::psi($categoriesRef, $profilObs[$feature] ?? []);
            $niveau = ReglesDerive::niveau($psi, $seuils);

            // `stable` n'écrit rien — voir l'en-tête sur le silence.
            if ($niveau === null || $niveau === 'stable') {
                continue;
            }

            $alertes[] = $this->inscrire($version, $jour, 'entree', $niveau, $feature, $psi,
                $seuils[$niveau === 'fort' ? 'fort' : 'leger'],
                ['reference' => $categoriesRef, 'observee' => $profilObs[$feature] ?? []],
                count($reference), count($observees));
        }

        return $alertes;
    }

    /**
     * La chute du rappel sur `sous_triage` — la seule métrique dont l'écart se paie en vies.
     *
     * @param  array<int, array<string, mixed>>  $reference
     * @param  array<int, array<string, mixed>>  $observees
     * @return array<int, array<string, mixed>>
     */
    private function derivePerformance(
        VersionModeleIa $version, CarbonImmutable $jour, array $reference, array $observees): array
    {
        $comparaison = $this->comparaison->pour($version);

        $chute = ReglesDerive::chuteDeRappel(
            $comparaison['rappel_sous_triage_test'],
            $comparaison['rappel_sous_triage_production'],
        );

        $seuil = (float) config('masante.triage_ia.seuil_chute_rappel', 0.15);

        if ($chute === null || $chute < $seuil) {
            return [];
        }

        return [$this->inscrire($version, $jour, 'performance', 'chute', 'rappel_sous_triage',
            $chute, $seuil, [
                'au_test' => $comparaison['rappel_sous_triage_test'],
                'en_production' => $comparaison['rappel_sous_triage_production'],
                'nb_couples' => $comparaison['nb_couples'],
            ], count($reference), count($observees))];
    }

    /**
     * Écrit une alerte, ou met à jour celle du jour — idempotent (motif P5.5c / B1).
     *
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    private function inscrire(
        VersionModeleIa $version, CarbonImmutable $jour, string $nature, string $niveau,
        string $indicateur, float $valeur, float $seuil, array $detail,
        int $nbReference, int $nbObservees): array
    {
        // ═══ POURQUOI PAS `updateOrCreate` : UNE CLÉ QUI COMPARE UNE DATE À UN DATETIME N'EN EST PAS UNE ═══
        //
        // `date_rapport` est castée en `date` : à l'écriture Eloquent range `2026-08-29 00:00:00`,
        // tandis qu'une clause `where('date_rapport', '2026-08-29')` compare la chaîne brute. Les
        // deux ne se rencontrent jamais — `updateOrCreate` créait donc une SECONDE ligne, que la
        // contrainte d'unicité refusait ensuite. Troisième occurrence de la même famille dans cet
        // incrément : *la valeur n'est pas stockée sous la forme où on l'interroge*.
        //
        // `whereDate` compare une DATE à une date, quel que soit le moteur.
        $existante = AlerteDerive::query()
            ->where('version_id', $version->id)
            ->whereDate('date_rapport', $jour->toDateString())
            ->where('nature', $nature)
            ->where('indicateur', $indicateur)
            ->first();

        $valeurs = [
            'niveau' => $niveau,
            'valeur' => $valeur,
            'seuil' => $seuil,
            'detail_json' => $detail,
            'nb_lignes_reference' => $nbReference,
            'nb_lignes_observees' => $nbObservees,
            'cree_le' => now(),
        ];

        if ($existante !== null) {
            $existante->update($valeurs);
        } else {
            AlerteDerive::create($valeurs + [
                'version_id' => $version->id,
                'date_rapport' => $jour->toDateString(),
                'nature' => $nature,
                'indicateur' => $indicateur,
            ]);
        }

        return [
            'nature' => $nature,
            'indicateur' => $indicateur,
            'niveau' => $niveau,
            'valeur' => $valeur,
            // `nouvelle` porte l'idempotence des NOTIFICATIONS, pas celle des lignes : rejouer un
            // rapport met les lignes a jour sans reprevenir personne (voir `analyser()`).
            'nouvelle' => $existante === null,
        ];
    }

    /** @return array{leger: float, fort: float} */
    private function seuils(): array
    {
        return [
            'leger' => (float) config('masante.triage_ia.seuil_psi_leger', 0.1),
            'fort' => (float) config('masante.triage_ia.seuil_psi_fort', 0.25),
        ];
    }

    private function fenetreJours(): int
    {
        return (int) config('masante.triage_ia.fenetre_derive_jours', 30);
    }
}
