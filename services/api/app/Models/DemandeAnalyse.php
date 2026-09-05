<?php

namespace App\Models;

use App\Services\Analyse\ProjecteurLignesDemande;
use App\Support\StatutDemandeAnalyse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Demande d'examen d'un membre (B5-a, CDC_11 §8.1, CDC_09 §7.4, CDC_04 §109).
 *
 * Analogue exact d'{@see Ordonnance} (décision L1 du plan G1) : un praticien produit cette pièce
 * pendant une consultation ou un patient y recopie une demande papier, et un laboratoire la lit
 * PAR SON JETON, sans jamais ouvrir le dossier (B5-b).
 */
class DemandeAnalyse extends Model
{
    protected $table = 'demandes_analyses';

    /**
     * L5 — le jeton de partage vers un laboratoire (patron B3-a/P10a, non réinventé).
     *
     * HORS `$fillable` : un client qui choisirait son propre jeton pourrait le deviner. `$hidden` :
     * il ne sort jamais d'une lecture ordinaire du carnet — seule la génération du QR/lien
     * l'expose, à son propriétaire.
     */
    protected $hidden = ['jeton_partage'];

    protected static function booted(): void
    {
        static::creating(static function (self $demande): void {
            $demande->jeton_partage ??= Str::random(48);
        });

        // L2 — les lignes sont DÉRIVÉES de `analyses_json`, par un seul crochet. `saved` et non
        // `created` : le `PUT` doit être couvert au même titre que la création (leçon P6.8b).
        static::saved(static function (self $demande): void {
            app(ProjecteurLignesDemande::class)->projeter($demande);
        });
    }

    protected $fillable = [
        'date_demande',
        'analyses_json',
        'renseignements_cliniques',
        'added_by',
        'source',
        // B2-c/B5-a — POSÉS PAR LE SERVEUR, JAMAIS PAR LE CLIENT, et pourtant `$fillable` : le
        // chemin d'écriture est une ASSIGNATION DE MASSE, donc une clé absente d'ici serait
        // écartée EN SILENCE (piège relevé par P6.7b). La garantie ne repose pas sur cette liste
        // mais sur les règles de validation (qui ne les déclarent pas) et sur `EcritureSoignantService`
        // (qui les pose après validation) — chacune avec son vecteur.
        'medecin_id',
        'structure_id',
        'consultation_id',
        'medecin_nom',
        // NOMMÉE `structure_sanitaire`, PAS `structure_nom` : c'est le nom de colonne littéral
        // qu'`EcritureSoignantService::ecrire()` vérifie par `array_key_exists('structure_sanitaire', …)`
        // pour la réécrire depuis la fiche du soignant — même colonne que sur `antecedents` et
        // `ordonnances`. Un nom différent aurait laissé le mécanisme générique muet (défaut réel
        // trouvé par `test_une_demande_du_soignant_designe_sa_fiche_et_son_etablissement`).
        'structure_sanitaire',
    ];

    /**
     * F2.13 — défaut aligné sur la colonne BDD, pour que la réponse de création porte déjà la
     * provenance. `statut` DOIT être ici et pas seulement en base : sans valeur EN MÉMOIRE avant le
     * premier `save()`, le crochet `saved()` lirait un `statut` NUL au moment même où il décide de
     * projeter — Eloquent ne relit jamais les défauts SQL après un INSERT (défaut réel trouvé par
     * `test_deux_examens_produisent_deux_lignes`, qui plantait aucune ligne projetée).
     */
    protected $attributes = ['source' => 'patient', 'statut' => 'emise'];

    protected function casts(): array
    {
        return [
            'date_demande' => 'date',
            'analyses_json' => 'encrypted:array',
            'renseignements_cliniques' => 'encrypted',
            'statut' => StatutDemandeAnalyse::class,
        ];
    }

    public function membre(): BelongsTo
    {
        return $this->belongsTo(MembreFamille::class, 'membre_id');
    }

    /** Le prescripteur, désigné comme sur les ordonnances depuis B2-c. */
    public function medecin(): BelongsTo
    {
        return $this->belongsTo(Medecin::class, 'medecin_id');
    }

    public function structure(): BelongsTo
    {
        return $this->belongsTo(StructureSanitaire::class, 'structure_id');
    }

    /** Les lignes de la demande (L2). Vides sur une demande sans `analyses_json` exploitable. */
    public function lignes(): HasMany
    {
        return $this->hasMany(DemandeAnalyseLigne::class, 'demande_id')->orderBy('rang');
    }

    /** L'acte de soin qui l'a produite, quand elle a été écrite pendant une consultation (B2-a). */
    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class, 'consultation_id');
    }

    /** Les prélèvements enregistrés contre cette demande (B5-b). */
    public function prelevements(): HasMany
    {
        return $this->hasMany(Prelevement::class, 'demande_id');
    }

    public function estOuverte(): bool
    {
        return $this->statut instanceof StatutDemandeAnalyse && $this->statut->estOuverte();
    }
}
