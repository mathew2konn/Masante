<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * Structure sanitaire géolocalisée (CdC §8.4, F3.1/F3.5). Annuaire public (données non
 * sensibles) : aucun chiffrement. Les coordonnées GPS servent la carte et le calcul de
 * proximité. `note_moyenne`/`nb_avis` sont dénormalisés (mis à jour par les avis, 3A.2).
 */
class StructureSanitaire extends Model
{
    /**
     * Cette structure est-elle une officine ?
     *
     * La question se posait déjà dans `PrixMedicamentService::exigerPharmacie()`, en privé. B3-a en
     * a besoin pour la délivrance : plutôt que de recopier la comparaison — deux endroits qui
     * pourraient diverger le jour où le type change de nom —, elle vit ici, sur l'objet qui la sait.
     */
    public function estPharmacie(): bool
    {
        return $this->type === 'pharmacie';
    }

    /**
     * Cette structure est-elle un laboratoire ? Même motif qu'`estPharmacie()` — la comparaison
     * vivait déjà en privé dans `ServiceLienResultat::resoudreLeLaboratoire()` (P6.7b) ; B5-b en a
     * besoin une seconde fois pour le circuit du prélèvement, d'où son passage ici.
     */
    public function estLaboratoire(): bool
    {
        return $this->type === 'laboratoire';
    }

    protected $table = 'structures_sanitaires';

    protected $fillable = [
        'nom',
        'type',
        'adresse',
        'commune',
        'latitude',
        'longitude',
        'telephone',
        'whatsapp',
        'horaires_json',
        'specialites_json',
        'tarif_min_cfa',
        'tarif_max_cfa',
        'note_moyenne',
        'nb_avis',
        'partenaire_ivoirsante',
        'actif',
        // ── P6.4 — identité administrative (CDC_09 §4.2, CDC_11 §3.1) ──────────────────
        // `identifiant_national` et `pays_code` sont ABSENTS de cette liste À DESSEIN :
        // l'identifiant est attribué par `AttributeurIdentifiantEtablissement` sous verrou et
        // ne doit jamais arriver d'un formulaire. Le laisser assignable en masse permettrait à
        // un client de choisir son propre numéro national.
        'nom_officiel',
        'statut_juridique',
        'forme_juridique',
        'niveau_soins',
        // P6.7b — §7.1/§7.2, propres au laboratoire. `type_laboratoire` est un SECOND AXE de la
        // categorie (`type = 'laboratoire'` dit ce que c'est, celui-ci dit lequel).
        'type_laboratoire',
        'responsable_scientifique',
        'responsable_scientifique_titre',
        'equipements',
        'delai_rendu_moyen_heures',
        'connecte_si_national',
        'region_id',
        'district_id',
        'ville_id',
        'quartier',
        'email',
        'site_web',
        'directeur',
        'capacite_accueil',
        'nombre_lits',
        'numero_autorisation',
        'numero_fiscal',
        'registre_commerce',
        'date_creation',
        'licence_exploitation',
        'autorite_tutelle',
        'agrements_json',
        'certifications_json',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'horaires_json' => 'array',
            'specialites_json' => 'array',
            'note_moyenne' => 'float',
            'partenaire_ivoirsante' => 'boolean',
            'actif' => 'boolean',
            'agrements_json' => 'array',
            'certifications_json' => 'array',
            'date_creation' => 'date',
            'capacite_accueil' => 'integer',
            'nombre_lits' => 'integer',
        ];
    }

    /** Ville couverte de rattachement (P6.4b) — nullable : une structure hors zone reste valide. */
    public function ville(): BelongsTo
    {
        return $this->belongsTo(Ville::class, 'ville_id');
    }

    /** Région sanitaire de rattachement (P6.4, CDC_09 §4.2). */
    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class, 'region_id');
    }

    /** District sanitaire — l'échelon que le CDC exige nommément (P6.4). */
    public function district(): BelongsTo
    {
        return $this->belongsTo(DistrictSanitaire::class, 'district_id');
    }

    /**
     * Images publiques de l'établissement (P6.4c) — logo et photos.
     *
     * Ordonnées par catégorie puis par rang de dépôt : le logo d'abord (ordre 1 dans la table de
     * référence), puis l'accueil, la salle d'attente… L'ordre d'affichage est donc une DONNÉE, et
     * la fiche mobile n'a rien à trier.
     */
    public function images(): HasMany
    {
        return $this->hasMany(EtablissementImage::class, 'structure_id')
            ->join('categories_image_etablissement as c', 'c.code', '=', 'etablissement_images.categorie_code')
            ->orderBy('c.ordre')
            ->orderBy('etablissement_images.ordre')
            ->select('etablissement_images.*');
    }

    /** Services médicaux de l'établissement. */
    public function services(): HasMany
    {
        return $this->hasMany(ServiceEtablissement::class, 'structure_id');
    }

    /** Comptes staff (gestionnaire + agents) rattachés à cet établissement (4.2/4.3). */
    public function staff(): HasMany
    {
        return $this->hasMany(User::class, 'structure_id');
    }

    /** Disponibilités quotidiennes, à travers les services. */
    public function disponibilites(): HasManyThrough
    {
        return $this->hasManyThrough(
            DisponibiliteJour::class,
            ServiceEtablissement::class,
            'structure_id',
            'service_id',
        );
    }

    /** Inscriptions de garde (pharmacies). */
    public function gardes(): HasMany
    {
        return $this->hasMany(PharmacieGarde::class, 'structure_id');
    }

    /** Avis patients (3A.2). */
    public function avis(): HasMany
    {
        return $this->hasMany(Avis::class, 'structure_id');
    }

    /** Signalements citoyens (3A.2). */
    public function signalements(): HasMany
    {
        return $this->hasMany(Signalement::class, 'structure_id');
    }
}
