<?php

namespace App\Services;

use App\Models\MembreFamille;
use App\Models\MesureSante;
use App\Models\ReferentielMesure;
use App\Services\Referentiel\DiffusionReferentiel;
use App\Services\Referentiel\ReferentielException;
use App\Services\Referentiel\SourceSeuilsMesure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Module 5 / 5.6 — Journal de bord des maladies chroniques (CdC FN5).
 *
 * SOURCE UNIQUE de la qualification d'une mesure. Trois responsabilités, qu'on refuse de laisser
 * au client :
 *
 *  1. LE STATUT EST CALCULÉ ICI, à partir du référentiel national PUBLIÉ — jamais reçu du mobile.
 *     Même principe que le score de triage (Module 1) : la règle médicale appartient au serveur, et
 *     un client compromis ne doit pas pouvoir déclarer « normale » une glycémie à 4 g/L. Depuis L1
 *     (ADR-025 §5), les seuils viennent de la version en vigueur du référentiel, plus de la table :
 *     un seuil corrigé par un `UPDATE` n'a aucun effet tant qu'il n'a pas été publié.
 *  2. LA TENSION EST UNE SAISIE, DEUX LIGNES. Le patient saisit « 12/8 » ; le CdC veut deux entrées
 *     (`tension_systolique`, `tension_diastolique`). On écrit donc les deux, dans une transaction et
 *     sous le même `groupe_uuid` : jamais une systolique orpheline.
 *  3. LE PARTAGE AVEC LE RÉFÉRENT (FN5 « partage automatique avec le médecin référent ») : une
 *     valeur critique déclenche la notification du référent actif du membre. Le référent, lui, lit
 *     le journal à la demande via la voie 2 — le « partage » n'est pas un envoi de données en clair
 *     par-dessus le réseau, c'est un accès tracé au dossier (§4.4).
 */
class MesureSanteService
{
    /** Saisie composite exposée au client : deux lignes CdC sous un seul geste. */
    public const TYPE_TENSION = 'tension';

    /** Sept lignes lues plusieurs fois par requête (validation, écriture, résumé) : on les garde. */
    private ?Collection $seuils = null;

    /** Numéro de la version qui a fourni ces seuils — l'estampille posée sur chaque mesure. */
    private ?int $version = null;

    public function __construct(
        private readonly ReferentService $referents,
        private readonly DiffusionReferentiel $diffusion,
    ) {
    }

    /** Référentiel complet des seuils, dans l'ordre d'affichage (le mobile s'en sert pour saisir). */
    public function referentiels(): Collection
    {
        $this->charger();

        return $this->seuils;
    }

    /** Seuils d'un type, ou null si le type n'existe pas au référentiel. */
    public function referentiel(string $type): ?ReferentielMesure
    {
        return $this->referentiels()->firstWhere('type_mesure', $type);
    }

    /**
     * La version du référentiel qui gouverne cette requête — apposée sur toute mesure écrite (§10
     * « toute décision conserve la version du référentiel utilisée »).
     */
    public function versionReferentiel(): int
    {
        $this->charger();

        return $this->version;
    }

    /**
     * Les types de mesure de la version EN VIGUEUR — la seule liste qui ait le droit d'ouvrir une
     * saisie. Le contrôleur bâtissait ses règles sur `ReferentielMesure::pluck()`, c'est-à-dire sur
     * la table : la validation aurait accepté un type absent de la version publiée, et le
     * référentiel diffusé aurait dit autre chose que les règles gouvernant l'écriture.
     *
     * @return array<int, string>
     */
    public function typesConnus(): array
    {
        return $this->referentiels()->pluck('type_mesure')->all();
    }

    /**
     * Charge les seuils DEPUIS LE RÉFÉRENTIEL PUBLIÉ, pas depuis la table.
     *
     * C'est la bascule décidée en L1 (ADR-025 §5). Avant elle, un `UPDATE` direct sur
     * `referentiels_mesure` changeait immédiatement la qualification de toutes les mesures à venir,
     * sans trace, sans version et sans relecture : c'est le défaut que le G0 de P6.3 avait nommé —
     * corriger un seuil rendait inexplicable toute mesure jugée « critique » avant la correction.
     * Désormais un seuil ne prend effet qu'une fois PUBLIÉ, donc proposé par un agent et validé par
     * un second (§10, quatre-yeux).
     *
     * LES MODÈLES SONT HYDRATÉS SANS EXISTER EN BASE. C'est ce qui rend la bascule chirurgicale :
     * `statutPour()`, les casts et l'unité vivent sur le modèle, donc les quatre consommateurs
     * gardent leur contrat exact. Ces instances ne doivent jamais être sauvegardées — elles
     * n'ont pas d'`id` et un `save()` créerait un doublon dans la table.
     *
     * L'ordre change de sens entre les deux mondes : l'instantané est trié par `type_mesure` (ordre
     * total et stable, au service de l'empreinte), l'écran veut `ordre` (au service de la lecture).
     */
    private function charger(): void
    {
        if ($this->seuils !== null) {
            return;
        }

        try {
            $diffuse = $this->diffusion->lire(SourceSeuilsMesure::CODE);
        } catch (ReferentielException $e) {
            // Échec BRUYANT, jamais un repli sur la table : un repli laisserait un oubli de
            // publication passer inaperçu, et la garantie serait inactive sans que personne ne le
            // sache. 503 et non 404 — le membre existe, c'est la mise en vigueur qui manque.
            abort(503, 'Les seuils nationaux de mesure n\'ont aucune version en vigueur : le journal '
                .'de bord ne peut pas qualifier une valeur tant qu\'une version n\'a pas été publiée '
                .'(CDC_09 §10).');
        }

        $this->version = $diffuse['version'];

        $this->seuils = collect($diffuse['contenu'])
            ->map(fn (array $ligne): ReferentielMesure => new ReferentielMesure($ligne))
            ->sortBy('ordre')
            ->values();
    }

    /**
     * Enregistre une saisie et renvoie la ou les mesures créées (deux pour une tension).
     *
     * @param  array{type_mesure: string, valeur?: float, systolique?: float, diastolique?: float, date_mesure: string, note?: string|null}  $donnees
     * @return Collection<int, MesureSante>
     */
    public function enregistrer(MembreFamille $membre, array $donnees, string $source = 'patient', ?string $addedBy = null): Collection
    {
        $lignes = $donnees['type_mesure'] === self::TYPE_TENSION
            ? [
                ['type' => 'tension_systolique',  'valeur' => (float) $donnees['systolique']],
                ['type' => 'tension_diastolique', 'valeur' => (float) $donnees['diastolique']],
            ]
            : [
                ['type' => $donnees['type_mesure'], 'valeur' => (float) $donnees['valeur']],
            ];

        // Une tension est UNE prise : ses deux lignes partagent un groupe, et naissent ou échouent
        // ensemble. Une systolique sans sa diastolique ne veut rien dire cliniquement.
        $groupe = count($lignes) > 1 ? (string) Str::uuid() : null;

        $mesures = DB::transaction(function () use ($membre, $lignes, $donnees, $groupe, $source, $addedBy) {
            return collect($lignes)->map(function (array $ligne) use ($membre, $donnees, $groupe, $source, $addedBy) {
                $seuils = $this->referentiel($ligne['type']);

                $mesure = new MesureSante([
                    'type_mesure' => $ligne['type'],
                    'valeur'      => $ligne['valeur'],
                    'date_mesure' => $donnees['date_mesure'],
                    'note'        => $donnees['note'] ?? null,
                    'source'      => $source,
                    'added_by'    => $addedBy,
                ]);

                // Dérivés du serveur : hors $fillable, posés ici (comme le score du triage).
                $mesure->unite = $seuils->unite;
                $mesure->statut_norme = $seuils->statutPour($ligne['valeur']);
                $mesure->groupe_uuid = $groupe;

                // L'ESTAMPILLE (L2, §10). Elle dit avec quels seuils cette valeur a été jugée, et
                // c'est ce qui rend une mesure « critique » d'hier encore explicable après une
                // correction du référentiel. Les deux lignes d'une tension la partagent : elles
                // naissent dans la même transaction, donc sous la même version.
                $mesure->referentiel_version = $this->versionReferentiel();

                $membre->mesuresSante()->save($mesure);

                return $mesure;
            });
        });

        $this->notifierReferentSiCritique($membre, $mesures);

        return $mesures;
    }

    /**
     * Supprime une saisie erronée. Une tension part avec son groupe : effacer la systolique et
     * laisser la diastolique produirait une courbe mensongère.
     *
     * @return int nombre de lignes supprimées
     */
    public function supprimer(MembreFamille $membre, MesureSante $mesure): int
    {
        if ($mesure->groupe_uuid === null) {
            $mesure->delete();

            return 1;
        }

        return $membre->mesuresSante()->where('groupe_uuid', $mesure->groupe_uuid)->delete();
    }

    /**
     * Historique d'un type de mesure, du plus ancien au plus récent — l'ordre d'une COURBE, pas
     * celui d'une liste (le mobile trace l'évolution : FN5 « graphiques d'évolution »).
     *
     * @return Collection<int, MesureSante>
     */
    public function serie(MembreFamille $membre, string $type, int $jours = 90): Collection
    {
        return $membre->mesuresSante()
            ->where('type_mesure', $type)
            ->where('date_mesure', '>=', now()->subDays($jours))
            ->orderBy('date_mesure')
            ->get();
    }

    /**
     * FN5 — « Partage automatique avec le médecin référent ».
     *
     * Une valeur critique n'attend pas la prochaine consultation : le référent actif est notifié.
     * Faute de Firebase et de passerelle SMS (contrainte du projet), la notification est une trace
     * applicative de niveau `warning`, journalisée avec son destinataire réel — même stub que le
     * bris de glace (5.3). Aucune valeur médicale n'est poussée hors du serveur : la notification
     * dit QU'IL FAUT REGARDER, le référent lit ensuite le dossier par la voie 2, et cet accès est
     * tracé. Sans référent désigné, il n'y a personne à prévenir : le patient garde son alerte à
     * l'écran, et c'est tout ce que le système peut promettre.
     *
     * @param  Collection<int, MesureSante>  $mesures
     */
    private function notifierReferentSiCritique(MembreFamille $membre, Collection $mesures): void
    {
        $critiques = $mesures->where('statut_norme', 'critique');

        if ($critiques->isEmpty()) {
            return;
        }

        $referent = $this->referents->actif($membre);

        Log::warning('Mesure critique enregistrée', [
            'membre_id' => $membre->id,
            'types'     => $critiques->pluck('type_mesure')->all(),
            'referent'  => $referent?->medecin?->nom_complet,
            'notifie'   => $referent?->medecin?->user_id,   // null = aucun référent joignable
        ]);
    }
}
