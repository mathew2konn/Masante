<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P6.8d — La couverture santé déclarée d'un membre (CDC_09 §8, CDC_06 §8).
 *
 * ═══ CE N'EST PAS UNE DONNÉE DE RÉFÉRENCE ═══
 *
 * Cette table n'entre PAS sous gouvernance §10, pour la même raison qu'`alertes_epidemiques` en
 * P6.8c : c'est un **fait individuel**. Un quatre-yeux sur la déclaration de sa propre mutuelle
 * n'aurait aucun sens. Ce qui est gouverné, c'est le registre qu'elle désigne.
 *
 * ═══ LE STATUT EST UN CALCUL, ET `non_inscrit` N'EXISTE PLUS ═══
 *
 * `membres_famille.cmu_statut` était déclaré par le client (`in:actif,expire,non_inscrit`) — famille
 * de T2, refermée en P6.8b pour les vaccinations. Ici le statut est DÉRIVÉ des dates de la ligne.
 *
 * Et `non_inscrit` disparaît : c'était **un statut qui affirmait qu'il n'y a pas de couverture**,
 * porté par une ligne qui existait toujours. L'absence de couverture se dit maintenant par l'ABSENCE
 * DE LIGNE.
 *
 * ═══ L'ACCESSEUR NE LIT QUE SA PROPRE LIGNE (leçon P6.8b) ═══
 *
 * S'il consultait le référentiel ou la date du jour d'ailleurs, la même ligne pourrait répondre
 * `active` dans un endpoint et `expiree` dans un autre selon ce qui a été chargé — *une valeur qui
 * change avec la façon dont on la demande n'est pas un calcul, c'est un hasard*.
 *
 * ═══ CE QUE LE CLIENT NE DÉCLARE PAS ═══
 *
 * `provenance` et `verifiee_le` sont HORS `$fillable` : la première ne peut valoir que `declare`
 * tant qu'aucune vérification n'existe (décision F2), et laisser le client écrire `verifie`
 * transformerait une auto-déclaration en attestation d'un simple envoi HTTP. La garde vit AUSSI dans
 * {@see App\Services\Assurance\ServiceCouvertures} : `$fillable` protège l'assignation de masse,
 * pas un appel direct au modèle (leçon des mutations de P6.6b).
 */
class CouvertureMembre extends Model
{
    /**
     * La phrase que TOUT écran affichant une couverture doit reprendre — SOURCE UNIQUE.
     *
     * Elle vit ici plutôt que dans un écran parce que trois surfaces l'affichent (la carte F2.3, la
     * liste des couvertures, le portail) : recopiée, elle divergerait le jour où une vérification
     * auprès d'un organisme existera, et l'une des trois continuerait de dire « non vérifié » quand
     * les deux autres auraient changé.
     */
    public const MENTION_PROVENANCE = 'Statut déclaré par l\'assuré, non vérifié auprès de '
        .'l\'organisme.';

    protected $table = 'couvertures_membre';

    protected $fillable = [
        'organisme_assurance_id',
        'organisme_libelle',
        'numero_assure',
        'date_debut',
        'date_fin',
        'resiliee_le',
    ];

    /**
     * Le numéro d'assuré complet ne quitte JAMAIS le serveur : chiffré au repos ET caché, comme
     * `cmu_numero` depuis F2.3. Seul `numero_masque` est exposé.
     */
    protected $hidden = ['numero_assure'];

    /** Sans `$appends`, une ligne fraîchement créée serait renvoyée SANS son statut (leçon P6.8b). */
    protected $appends = ['statut', 'numero_masque'];

    protected function casts(): array
    {
        return [
            'numero_assure' => 'encrypted',
            'date_debut'    => 'date',
            'date_fin'      => 'date',
            'resiliee_le'   => 'date',
            'verifiee_le'   => 'datetime',
        ];
    }

    public function membre(): BelongsTo
    {
        return $this->belongsTo(MembreFamille::class, 'membre_id');
    }

    public function organisme(): BelongsTo
    {
        return $this->belongsTo(OrganismeAssurance::class, 'organisme_assurance_id');
    }

    /**
     * `resiliee` | `expiree` | `active` — dérivé des seules colonnes de cette ligne.
     *
     * ORDRE DÉLIBÉRÉ : une résiliation l'emporte sur une date de fin. Un contrat résilié le 3 mars
     * dont l'échéance était en décembre n'est pas « encore actif jusqu'en décembre » — et répondre
     * `expiree` à un contrat résilié dirait la bonne conclusion pour la mauvaise raison, ce qu'un
     * assuré ne pourrait pas contester utilement au guichet (même principe que l'ordre des contrôles
     * du §5.4 en P6.5b).
     */
    public function getStatutAttribute(): string
    {
        if ($this->resiliee_le !== null) {
            return 'resiliee';
        }

        if ($this->date_fin !== null && $this->date_fin->isBefore(now()->startOfDay())) {
            return 'expiree';
        }

        return 'active';
    }

    /** `•••• •••• 1234` — mêmes règles d'exposition que le numéro CMU depuis F2.3. */
    public function getNumeroMasqueAttribute(): ?string
    {
        $numero = $this->numero_assure;

        if (! $numero) {
            return null;
        }

        return '•••• •••• '.substr($numero, -4);
    }

    /** Le témoin de l'écart (motif E4) : cette couverture nomme un organisme hors du registre. */
    public function estHorsReferentiel(): bool
    {
        return $this->organisme_assurance_id === null;
    }

    /**
     * Les couvertures qui valent aujourd'hui. Utilisé pour dériver la vue CMU historique — et non
     * pour décider quoi que ce soit d'un remboursement, qui n'est pas du ressort de ce module.
     */
    public function scopeVivante(Builder $query): Builder
    {
        return $query->whereNull('resiliee_le')
            ->where(function (Builder $q): void {
                $q->whereNull('date_fin')->orWhereDate('date_fin', '>=', now()->toDateString());
            });
    }
}
