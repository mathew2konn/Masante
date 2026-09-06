<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Résultat d'analyse d'un membre (CdC §8.3, F2.6). `resultats_json` chiffré AES-256
 * au repos (§6 Sécurité).
 *
 * `origine` (B5-c, L15) : décidée par le SERVEUR, jamais déclarée — `null` pour un résultat saisi
 * directement (patient, délégué, soignant), `saisie`/`automate` pour un résultat publié par le
 * circuit du laboratoire ({@see App\Services\Analyse\ServiceValidationBiologique::publier()}).
 */
class ResultatAnalyse extends Model
{
    protected $table = 'resultats_analyses';

    protected $fillable = [
        'type_analyse',
        'intitule',
        'date_analyse',
        'laboratoire',
        'medecin_prescripteur',
        // P6.7b — le client PROPOSE les identifiants ; les noms figés, lui, ne viennent jamais de
        // lui. Ils figurent pourtant ici, et il faut dire pourquoi : le chemin d'écriture des
        // sections du carnet est une **assignation de masse** (`create($valide)`), donc une colonne
        // absente de `$fillable` serait silencieusement perdue — le service les aurait posées pour
        // rien, et les trois vecteurs de gel l'ont montré.
        //
        // La garantie ne repose donc pas sur `$fillable` mais sur DEUX couches, chacune éprouvée :
        // les règles de validation ne déclarent pas ces clés (donc `validate()` les écarte), et
        // {@see App\Services\Analyse\ServiceLienResultat} les efface avant de reposer ce qu'il a
        // relu au référentiel — y compris quand on l'appelle sans passer par la validation.
        'medecin_prescripteur_id',
        'medecin_prescripteur_nom',
        'laboratoire_id',
        'laboratoire_nom',
        'laboratoire_code',
        'resultats_json',
        'fichier_url',
        'added_by',
        'source',
        // B5-c — posée UNIQUEMENT par `ServiceValidationBiologique::publier()`, jamais déclarée par
        // un client (absente de `ResultatAnalyseController::regles()`, même garde à deux couches).
        'origine',
    ];

    /** F2.13 — défaut aligné sur la colonne BDD, pour que la réponse de création porte déjà la provenance. */
    protected $attributes = ['source' => 'patient'];

    protected function casts(): array
    {
        return [
            'date_analyse'   => 'date',
            'resultats_json' => 'encrypted:array',
        ];
    }

    public function membre(): BelongsTo
    {
        return $this->belongsTo(MembreFamille::class, 'membre_id');
    }

    /**
     * Le prélèvement qui a produit ce résultat, quand il vient du circuit du laboratoire (B5-c).
     * `null` pour un résultat saisi directement — la relation est un ENRICHISSEMENT de lecture,
     * jamais une exigence : `Prelevement::resultat_analyse_id` porte le lien, ici seulement inversé.
     */
    public function prelevement(): HasOne
    {
        return $this->hasOne(Prelevement::class, 'resultat_analyse_id');
    }
}
