<?php

namespace App\Models;

use App\Services\Medicament\ProjecteurLignesOrdonnance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Ordonnance médicale d'un membre (CdC §8.3, F2.5). `medicaments_json` chiffré AES-256
 * au repos (§6 Sécurité). Peut être rattachée au triage à l'origine (`triage_id`).
 */
class Ordonnance extends Model
{
    /**
     * B3-a — le jeton de partage vers une officine (patron P10a, non réinventé).
     *
     * HORS `$fillable` : un client qui choisirait son propre jeton pourrait le deviner. `$hidden` :
     * il ne sort jamais d'une lecture ordinaire du carnet — seule la génération du QR l'expose, à
     * son propriétaire.
     */
    protected $hidden = ['jeton_partage'];

    protected static function booted(): void
    {
        static::creating(static function (self $ordonnance): void {
            $ordonnance->jeton_partage ??= Str::random(48);
        });

        // B3-a — les lignes sont DÉRIVÉES de `medicaments_json`, par un seul crochet. `saved`
        // et non `created` : le `PUT` doit être couvert au même titre que la création, sinon la
        // garantie ne vaudrait que sur l'un des chemins (leçon P6.8b, où `update()` avait été
        // oublié). La projection se refuse d'elle-même sur une ordonnance déjà servie.
        static::saved(static function (self $ordonnance): void {
            app(ProjecteurLignesOrdonnance::class)->projeter($ordonnance);
        });
    }

    protected $fillable = [
        'triage_id',
        'medecin_nom',
        'structure_sanitaire',
        'date_prescription',
        'medicaments_json',
        'photo_url',
        'pdf_url',
        'added_by',
        'source',
        // B2-c — POSÉS PAR LE SERVEUR, JAMAIS PAR LE CLIENT, et pourtant `$fillable` : le chemin
        // d'écriture est une ASSIGNATION DE MASSE (`$membre->ordonnances()->create($valide)`), donc
        // une clé absente d'ici serait écartée EN SILENCE — piège relevé par P6.7b sur les noms
        // figés.
        //
        // CE QUI PROTÈGE RÉELLEMENT, DIT SANS EMBELLIR (constat d'une mutation survivante) : c'est
        // LA VALIDATION, et elle seule. `EcritureSoignantService` valide les données avec les
        // règles de la section AVANT de poser quoi que ce soit, et ces clés n'y figurent pas —
        // elles n'atteignent donc jamais le service, sur AUCUN des trois chemins d'écriture. Le
        // service ne fait que POSER ; il n'a rien à écraser. Écrire ici qu'« une seconde couche
        // repose les valeurs » aurait décrit une garde qui n'est jamais sollicitée.
        'medecin_id',
        'structure_id',
        'consultation_id',
    ];

    /** F2.13 — défaut aligné sur la colonne BDD, pour que la réponse de création porte déjà la provenance. */
    protected $attributes = ['source' => 'patient'];

    protected function casts(): array
    {
        return [
            'date_prescription' => 'date',
            'medicaments_json'  => 'encrypted:array',
        ];
    }

    public function membre(): BelongsTo
    {
        return $this->belongsTo(MembreFamille::class, 'membre_id');
    }

    /**
     * B2-c — le prescripteur, enfin désigné et plus seulement nommé (constat Y2).
     *
     * `medecin_nom` reste la valeur SIGNÉE : c'est elle que porte le contenu canonique de la
     * signature (P6.5b), et elle survit à la disparition de la fiche. Ce lien répond aux questions
     * que la chaîne ne pouvait pas : « toutes les ordonnances du Dr X », « ce prescripteur
     * exerce-t-il encore ? ».
     */
    public function medecin(): BelongsTo
    {
        return $this->belongsTo(Medecin::class, 'medecin_id');
    }

    public function structure(): BelongsTo
    {
        return $this->belongsTo(StructureSanitaire::class, 'structure_id');
    }

    /** Les lignes de la prescription (B3-a). Vides sur les ordonnances antérieures à ce lot. */
    public function lignes(): HasMany
    {
        return $this->hasMany(OrdonnanceLigne::class, 'ordonnance_id')->orderBy('rang');
    }

    /** Les délivrances déjà faites en officine (B3-a). */
    public function delivrances(): HasMany
    {
        return $this->hasMany(Delivrance::class, 'ordonnance_id')->orderBy('delivree_le');
    }

    /**
     * Cette ordonnance peut-elle être servie électroniquement ?
     *
     * NON pour toutes celles écrites avant B3-a : elles n'ont pas de lignes, et on n'en fabrique
     * pas rétroactivement depuis un JSON saisi librement — ce seraient des lignes que personne n'a
     * vérifiées, sur un document parfois signé.
     */
    public function estDelivrable(): bool
    {
        return $this->lignes()->exists();
    }

    /** L'acte de soin qui l'a produite, quand elle a été écrite pendant une consultation (B2-a). */
    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class, 'consultation_id');
    }
}
