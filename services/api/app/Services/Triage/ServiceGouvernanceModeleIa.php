<?php

namespace App\Services\Triage;

use App\Models\ExportJeuEntrainement;
use App\Models\MetriqueModeleIa;
use App\Models\User;
use App\Models\VersionModeleIa;
use App\Services\ServiceNotification;
use App\Support\StatutVersionModeleIa;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * P10c-3-i (F17/F18/F19) — Le cycle candidat → validé (CDC_05 §7.2/§8/§9).
 *
 * ═══ `entrainer()` : REFUS EN DOUBLE GARDE (F15), PUIS UN FAIT MÉCANIQUE, PAS UNE DÉCISION ═══
 *
 * Le seuil minimal est vérifié ICI, avant tout appel réseau (Laravel refuse le premier) — puis à
 * nouveau, indépendamment, par `triage-service` (F15 : « dédoublé, une couche un vecteur »). Une
 * fois l'entraînement réussi, `candidat` est posé SANS jugement humain : c'est `TriageService::
 * analyser()` qui décide d'un niveau, ici c'est XGBoost qui produit un modèle — aucun des deux
 * n'exige un humain pour EXISTER, seulement pour être MIS EN SERVICE.
 *
 * ═══ `valider()` : QUATRE-YEUX, MOTIF `ServiceGouvernanceProtocole::publier()` ═══
 *
 * Même poids que la publication d'un protocole ou d'un référentiel (§9 : « validation clinique...
 * avant toute mise en production d'un modèle influençant une décision de soins ») — donc même
 * garde : celui qui a déclenché l'entraînement ne peut pas valider son propre candidat.
 *
 * ═══ FRONTIÈRE ═══
 *
 * Aucune règle médicale ici : habilitation, seuil, quatre-yeux, et une décision humaine enregistrée
 * telle quelle. « Quelles règles métier ce module calcule-t-il ? » → aucune.
 */
class ServiceGouvernanceModeleIa
{
    public const PERMISSION = 'ia_triage.valider';

    public function __construct(
        private readonly ClientEntrainementIa $client,
        private readonly ServiceNotification $notifications,
    ) {}

    public function entrainer(User $auteur, ExportJeuEntrainement $export): VersionModeleIa
    {
        $this->exigerHabilitation($auteur);

        $seuil = (int) config('masante.triage_ia.seuil_min_entrainement', 30);

        if ($export->nb_lignes < $seuil) {
            throw new \RuntimeException(
                "Cet export porte {$export->nb_lignes} ligne(s) validée(s), {$seuil} requises au "
                .'minimum : refus honnête plutôt qu\'un modèle entraîné sur trop peu de données réelles.'
            );
        }

        // `niveau_protocole`/`annee_mois` restent dans l'instantané pour l'audit humain (F17) mais
        // JAMAIS dans ce qui est envoyé comme feature — précédent D3 (P10c-2-i) étendu ici : le
        // vecteur d'entraînement doit être identique à celui du service, sous peine de recréer le
        // décalage train/serve que Y4 signale.
        $lignes = collect($export->instantane_json)
            ->map(fn (array $ligne): array => Arr::except($ligne, ['niveau_protocole', 'annee_mois']))
            ->values()
            ->all();

        $reponse = $this->client->entrainer($export->pays_code, $export->numero_export, $lignes);

        return DB::transaction(function () use ($export, $auteur, $reponse): VersionModeleIa {
            $version = VersionModeleIa::create([
                'pays_code' => $export->pays_code,
                'numero_version' => $this->prochainNumeroVersion($export->pays_code),
                'export_id' => $export->id,
                'statut' => StatutVersionModeleIa::CANDIDAT,
                'mlflow_run_id' => $reponse['mlflow_run_id'],
                'entraine_par' => $auteur->id,
                'cree_le' => now(),
            ]);

            foreach ($reponse['metriques'] as $cle => $valeur) {
                MetriqueModeleIa::create([
                    'version_id' => $version->id,
                    'cle' => $cle,
                    'valeur' => $valeur,
                    'mesure_le' => now(),
                ]);
            }

            $this->notifications->modeleIaCandidat($version);

            return $version;
        });
    }

    public function valider(User $validateur, VersionModeleIa $version): VersionModeleIa
    {
        $this->exigerHabilitation($validateur);

        return DB::transaction(function () use ($validateur, $version): VersionModeleIa {
            $version = VersionModeleIa::query()->whereKey($version->id)->lockForUpdate()->firstOrFail();

            if ($version->statut !== StatutVersionModeleIa::CANDIDAT) {
                throw new \RuntimeException(
                    "Seul un candidat se valide : cette version est déjà « {$version->statut} »."
                );
            }

            // ═══ QUATRE-YEUX (§9) ═══
            //
            // Celui qui a déclenché l'entraînement A le droit de valider un AUTRE candidat — c'est
            // CETTE version-là qu'il ne peut pas valider lui-même (motif exact de
            // `ServiceGouvernanceProtocole::publier()`).
            if ($version->entraine_par !== null && (int) $version->entraine_par === (int) $validateur->id) {
                throw new \RuntimeException(
                    "Celui qui a déclenché l'entraînement ne peut pas valider ce candidat lui-même : "
                    .'le §9 exige une double décision. Un autre agent habilité doit trancher.'
                );
            }

            $version->statut = StatutVersionModeleIa::VALIDE;
            $version->valide_par = $validateur->id;
            $version->date_validation_clinique = now();
            $version->save();

            return $version;
        });
    }

    /**
     * P10c-3-ii (F24) — Met un modèle EN SERVICE : `valide` (ou `archive`) → `actif`.
     *
     * ═══ AU PLUS UN ACTIF PAR PAYS, ET C'EST LA CONDITION POUR QUE LA QUESTION AIT UNE RÉPONSE ═══
     *
     * « Quel modèle a produit cette prédiction ? » doit avoir une réponse unique. Deux actifs la
     * rendraient insoluble — même résolution que l'ambiguïté du §6.1 tranchée en P10b-1, où deux
     * versions « Active » du même protocole ont été refusées pour la même raison. Activer archive
     * donc le précédent, dans la même transaction.
     *
     * ═══ LE ROLLBACK DU §8 EST CE MÊME GESTE, DANS L'AUTRE SENS ═══
     *
     * « Possibilité de rollback immédiat » : réactiver une version archivée. `archive` n'est donc
     * PAS terminal, et c'est dit — un état dont on sort doit être annoncé comme tel, sinon le
     * premier rollback ressemblera à une entorse.
     *
     * ═══ AUCUN QUATRE-YEUX SUPPLÉMENTAIRE, ET C'EST DÉLIBÉRÉ ═══
     *
     * La validation clinique du §9.6 a déjà eu lieu au passage `candidat → valide`, par quelqu'un
     * qui n'est pas l'entraîneur. Exiger un troisième acteur ici serait un garde-fou **plus strict
     * que sa propre règle** — le réflexe que ce projet a refusé en P6.8c, où un déclencheur
     * comparait plus sévèrement que la règle qu'il gardait.
     */
    public function activer(User $operateur, VersionModeleIa $version): VersionModeleIa
    {
        $this->exigerHabilitation($operateur);

        return DB::transaction(function () use ($operateur, $version): VersionModeleIa {
            $version = VersionModeleIa::query()->whereKey($version->id)->lockForUpdate()->firstOrFail();

            if (! in_array($version->statut, [StatutVersionModeleIa::VALIDE, StatutVersionModeleIa::ARCHIVE], true)) {
                throw new \RuntimeException(
                    'Seule une version validée cliniquement peut être mise en service : celle-ci est '
                    ."« {$version->statut} ». La validation du §9.6 n'est pas une formalité que "
                    .'l\'activation pourrait contourner.'
                );
            }

            // Le précédent actif est archivé DANS la même transaction : à aucun instant deux
            // versions ne répondent, pas même le temps d'un commit.
            VersionModeleIa::query()
                ->where('pays_code', $version->pays_code)
                ->where('statut', StatutVersionModeleIa::ACTIF)
                ->lockForUpdate()
                ->get()
                ->each(function (VersionModeleIa $precedent): void {
                    $precedent->statut = StatutVersionModeleIa::ARCHIVE;
                    $precedent->save();
                });

            $version->statut = StatutVersionModeleIa::ACTIF;
            $version->activee_par = $operateur->id;
            $version->activee_le = now();
            $version->save();

            return $version;
        });
    }

    /**
     * La version qui répond aujourd'hui pour ce pays, s'il y en a une.
     *
     * ═══ C'EST LA SEULE SOURCE DE « QUEL MODÈLE RÉPOND » (F23) ═══
     *
     * Le service Python ne choisit pas : il charge le run qu'on lui nomme et refuse s'il ne l'a
     * pas. Laisser le service décider (« le plus récent sur le disque ») ferait dire à la base
     * « l'actif est X » pendant que la réponse viendrait de Y.
     */
    public function actif(string $paysCode): ?VersionModeleIa
    {
        return VersionModeleIa::query()
            ->where('pays_code', $paysCode)
            ->where('statut', StatutVersionModeleIa::ACTIF)
            ->first();
    }

    /**
     * L'habilitation, vérifiée ICI — pas seulement par le middleware `permission:` de spatie : les
     * routes du portail sont authentifiées par session `web`, où les permissions vivent déjà, mais
     * le service reste la garde qui fait autorité quel que soit l'appelant (piège constant de ce
     * projet depuis P4 sur `rdv.validate`).
     */
    private function exigerHabilitation(User $utilisateur): void
    {
        if (! $utilisateur->can(self::PERMISSION)) {
            throw new \RuntimeException(
                'Cette action exige l\'habilitation « '.self::PERMISSION.' », accordée nominativement '
                .'(CDC_05 §9 : aucun modèle influençant une décision de soins sans validation humaine).'
            );
        }
    }

    private function prochainNumeroVersion(string $paysCode): int
    {
        return (int) VersionModeleIa::query()->where('pays_code', $paysCode)->max('numero_version') + 1;
    }
}
