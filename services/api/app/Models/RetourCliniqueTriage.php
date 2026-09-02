<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * P10c-3-ii (F32→F34) — Ce qu'un soignant a RÉELLEMENT constaté, à côté de son verdict §10.
 *
 * ═══ CE QUE CETTE TABLE EST, ET POURQUOI ELLE N'EXISTAIT PAS ═══
 *
 * Le §5.5.4 pt.4 demande d'enregistrer « le triage réalisé, le **diagnostic final posé par le
 * médecin** et le traitement prescrit — constitution progressive d'une base de données africaine ».
 * Ce projet n'avait jusqu'ici **aucune entité consultation/diagnostic** (dette nommée en P10c-2-i) :
 * cette table en est le premier fragment, volontairement minimal — le diagnostic retenu, la
 * spécialité qui a pris en charge, le niveau que le soignant aurait donné.
 *
 * ═══ POURQUOI PAS TROIS COLONNES SUR `protocole_applications` ═══
 *
 * Parce que sa fonction de hachage fige une liste de clés : y ajouter ces faits recalculerait
 * l'empreinte de **toutes** les entrées déjà écrites, et la chaîne crierait à l'altération sans que
 * rien n'ait bougé. P10c-2-i avait refusé une colonne « nature » pour ce motif exact.
 *
 * Et sur le fond, le §10 journalise l'évaluation d'un protocole et la décision du soignant à son
 * sujet ; un diagnostic est un fait clinique d'une autre nature. Les mêler mettrait deux natures de
 * vérité dans la même table — ce que ce projet refuse depuis P6.6a.
 *
 * ═══ APPEND-ONLY À DEUX NIVEAUX, COMME LE JOURNAL QU'ELLE COMPLÈTE ═══
 *
 * Un diagnostic ne se corrige pas en place : un avis révisé est un NOUVEAU retour, donc une
 * nouvelle entrée de journal §10 et une nouvelle précision. Réécrire celle-ci reviendrait à
 * réécrire ce qu'un médecin a consigné un jour donné.
 *
 * ═══ LIBELLÉS FIGÉS (P6.6b/P6.7b/P6.8c) ═══
 *
 * `maladie_libelle` et `specialite_libelle` sont recopiés à l'écriture, jamais relus. Une
 * correction ultérieure du référentiel ne doit pas changer ce qu'un médecin a lu au moment où il a
 * consigné — c'est un fait HISTORIQUE, pas un état courant (à l'inverse du nom d'un organisme
 * d'assurance, ADR-038, où la réponse était l'opposée et pour une raison opposée).
 */
class RetourCliniqueTriage extends Model
{
    protected $table = 'retours_cliniques_triage';

    public $timestamps = false;

    protected $fillable = [
        'chaine',
        'application_id',
        'triage_id',
        'soignant_id',
        'soignant_nom',
        'niveau_reel',
        'maladie_id',
        'maladie_code',
        'maladie_libelle',
        'specialite_id',
        'specialite_code',
        'specialite_libelle',
        'empreinte',
        'empreinte_precedente',
        'cree_le',
    ];

    protected function casts(): array
    {
        return [
            'cree_le' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException(
                'Les précisions cliniques d\'un retour sont append-only : un avis révisé s\'inscrit '
                .'comme un NOUVEAU retour, il ne réécrit pas le précédent.'
            );
        });

        static::deleting(function () {
            throw new RuntimeException(
                'Les précisions cliniques d\'un retour sont append-only : supprimer une entrée '
                .'effacerait un diagnostic posé par un professionnel identifié.'
            );
        });
    }
}
