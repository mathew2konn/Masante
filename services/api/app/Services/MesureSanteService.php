<?php

namespace App\Services;

use App\Models\MembreFamille;
use App\Models\MesureSante;
use App\Models\ReferentielMesure;
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
 *  1. LE STATUT EST CALCULÉ ICI, à partir du référentiel de seuils en base — jamais reçu du mobile.
 *     Même principe que le score de triage (Module 1) : la règle médicale appartient au serveur, et
 *     un client compromis ne doit pas pouvoir déclarer « normale » une glycémie à 4 g/L.
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

    public function __construct(private readonly ReferentService $referents)
    {
    }

    /** Référentiel complet des seuils, dans l'ordre d'affichage (le mobile s'en sert pour saisir). */
    public function referentiels(): Collection
    {
        return $this->seuils ??= ReferentielMesure::orderBy('ordre')->get();
    }

    /** Seuils d'un type, ou null si le type n'existe pas au référentiel. */
    public function referentiel(string $type): ?ReferentielMesure
    {
        return $this->referentiels()->firstWhere('type_mesure', $type);
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
