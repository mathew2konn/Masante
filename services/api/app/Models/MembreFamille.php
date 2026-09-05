<?php

namespace App\Models;

use Database\Factories\MembreFamilleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Membre de la famille rattaché à un compte (CdC §5.2 / §8.1, F2.1).
 *
 * Sécurité :
 *  - `matricule_ivs` et `user_id` sont cachés des sérialisations JSON : le matricule interne
 *    ne doit jamais fuiter (§1, §2.1 Sécurité) ; l'isolation est garantie par la Policy.
 *  - `cmu_numero` est chiffré au repos (cast `encrypted`, AES-256 via APP_KEY — §6.1 Sécurité).
 *  - `$fillable` explicite (anti mass-assignment) ; `matricule_ivs` n'y figure pas : il est
 *    attribué par le serveur via MatriculeService, jamais par l'utilisateur.
 *
 * La règle « max 5 membres par compte » (F2.2) est appliquée à la validation (StoreMembreRequest).
 */
class MembreFamille extends Model
{
    /** @use HasFactory<MembreFamilleFactory> */
    use HasFactory;

    protected $table = 'membres_famille';

    protected $fillable = [
        'nom',
        'prenom',
        'date_naissance',
        'sexe',
        'groupe_sanguin',
        // P6.8d — `cmu_numero`, `cmu_statut` et `cmu_validite` ONT DISPARU DE CETTE LISTE.
        //
        // La couverture santé n'est plus un attribut de la personne : c'est un contrat, porté par
        // `couvertures_membre` ({@see CouvertureMembre}). Les colonnes subsistent (ADR-024 : une
        // migration destructive perdrait de l'information réelle pour un gain nul) mais **plus
        // personne ne les écrit** — motif exact de `vaccinations.statut` en P6.8b.
        //
        // Les trois valeurs restent EXPOSÉES à l'identique, pour que le contrat de `GET /membres` et
        // son cache hors-ligne chiffré (module P2, validé G5) ne bougent pas : elles sont
        // désormais DÉRIVÉES de la couverture CNAM ({@see getCmuStatutAttribute()}).
    ];

    protected $hidden = [
        'matricule_ivs',
        'user_id',
        // P6.1 — colonne générée servant uniquement à l'unicité déclarative du dossier
        // titulaire (index UNIQUE). Détail de persistance : jamais exposé.
        // NB : `nis` n'est PAS caché — contrairement au matricule interne, l'Identifiant
        // National de Santé est fait pour être communiqué (CDC_09 §3.5 : consultations,
        // ordonnances, assurances, CNAM, urgences).
        'titulaire_du_compte',
        // F2.3 — le numéro CMU complet ne quitte JAMAIS le serveur (chiffré au repos ET caché) :
        // seul `cmu_numero_masque` (accessor) est exposé. §5.2 Sécurité (exposition minimale).
        'cmu_numero',
        // Profil — chemin de stockage interne de la photo : jamais exposé (comme `matricule_ivs`).
        // Le client utilise `a_photo` puis l'endpoint dédié. `photo_url` est renseigné côté serveur.
        'photo_url',
    ];

    /**
     * Champs dérivés ajoutés aux sérialisations JSON.
     *
     * P6.8d — `cmu_statut` et `cmu_validite` y entrent alors que ce sont des COLONNES. Ce n'est pas
     * une redondance : un accesseur ne s'applique qu'aux clés présentes dans les attributs, et une
     * ligne fraîchement créée n'en porte aucune des deux (rien ne les écrit plus). Sans `$appends`,
     * la réponse d'une CRÉATION de membre omettrait purement et simplement les deux clés, alors que
     * la même fiche relue les porterait — le contrat de P2 varierait selon le chemin.
     *
     * Le vecteur qui l'a trouvé : `MembreFamilleTest::test_les_champs_cmu_envoyes_sont_ignores`.
     * C'est la deuxième fois que ce piège se présente, après le statut vaccinal de P6.8b.
     */
    protected $appends = ['cmu_numero_masque', 'cmu_statut', 'cmu_validite', 'a_photo'];

    /** Profil — indique si le membre a une photo (le client charge alors l'endpoint dédié). */
    public function getAPhotoAttribute(): bool
    {
        return $this->photo_url !== null;
    }

    /**
     * P6.1 — Maintient `titulaire_du_compte`, support de l'unicité « un seul dossier titulaire
     * par compte » sur MySQL (ADR-021 §2.2).
     *
     * Pourquoi ici plutôt qu'en colonne générée : MySQL refuse une colonne générée STORED
     * dérivée de `user_id`, qui porte ON DELETE CASCADE (erreur 1215). La valeur est donc
     * posée applicativement — mais la contrainte CHECK en base interdit qu'elle diverge de
     * `est_titulaire` / `user_id`, et l'index UNIQUE interdit le second titulaire. Le moteur
     * reste juge : ce hook est une commodité, pas la garantie.
     *
     * Sur SQLite (tests), l'index unique partiel suffit et la colonne n'existe pas.
     */
    /** Mémoïsé : `hasColumn` interroge le schéma, on ne le fait pas à chaque sauvegarde. */
    private static ?bool $colonneTitulaireDisponible = null;

    protected static function booted(): void
    {
        static::saving(function (self $membre): void {
            self::$colonneTitulaireDisponible ??= Schema::hasColumn(
                $membre->getTable(),
                'titulaire_du_compte'
            );

            if (self::$colonneTitulaireDisponible !== true) {
                return;
            }

            $membre->setAttribute(
                'titulaire_du_compte',
                $membre->est_titulaire ? $membre->user_id : null
            );
        });
    }

    protected function casts(): array
    {
        return [
            'date_naissance'  => 'date',
            'cmu_validite'    => 'date',
            'cmu_numero'      => 'encrypted',
            // P6.1 — NIS (CDC_09 §3).
            'nis_attribue_le' => 'datetime',
            'est_titulaire'   => 'boolean',
        ];
    }

    /**
     * F2.3 — Numéro CMU masqué (`•••• •••• 1234` : seuls les 4 derniers chiffres visibles).
     *
     * P6.8d — LIT LA COUVERTURE, PLUS LA COLONNE. Voir {@see getCmuStatutAttribute()} pour la raison
     * (une seule vérité) et pour la conséquence de déploiement.
     */
    public function getCmuNumeroMasqueAttribute(): ?string
    {
        return $this->couvertureCmu()?->numero_masque;
    }

    /**
     * ═══ P6.8d — LES TROIS VALEURS CMU SONT DÉRIVÉES, PAS RECOPIÉES ═══
     *
     * `GET /membres` (module P2, validé G5, avec son cache hors-ligne chiffré) expose `cmu_statut`,
     * `cmu_validite` et `cmu_numero_masque`. Le contrat ne bouge pas — ni les clés, ni le vocabulaire
     * (`actif` / `expire` / `non_inscrit`), ni le format.
     *
     * POURQUOI DÉRIVER PLUTÔT QUE LAISSER LES COLONNES : elles seraient figées au jour du backfill
     * pendant que le citoyen modifie sa couverture → **deux vérités dans la même réponse**. La
     * dérivation en laisse une seule. C'est le même raisonnement qui a fait de `vaccinations.statut`
     * un calcul en P6.8b, appliqué ici à la compatibilité d'un contrat existant.
     *
     * CONSÉQUENCE DE DÉPLOIEMENT, DITE PLUTÔT QUE MASQUÉE : tant que
     * `masante:couvertures:backfill` n'a pas tourné, un membre dont la colonne dit « actif » répond
     * `non_inscrit`. **Aucun repli sur la colonne** — il ressusciterait une valeur périmée le jour où
     * un citoyen supprime sa couverture, et rétablirait exactement les deux vérités qu'on supprime.
     * La mise en vigueur est donc une étape de déploiement, comme la publication de la v1 en L1+L2.
     *
     * TRADUCTION DU VOCABULAIRE : `resiliee` et `expiree` répondent toutes deux `expire`. La
     * distinction existe sur la couverture (elle est visible dans le nouvel écran) ; l'inventer dans
     * un contrat qui ne l'a jamais portée casserait un client validé G5 pour un gain nul.
     */
    public function getCmuStatutAttribute(): string
    {
        $couverture = $this->couvertureCmu();

        if ($couverture === null) {
            // L'absence de couverture se dit par l'absence de ligne (P6.8d) ; le contrat P2, lui,
            // attend une valeur — c'est ici, et ici seulement, que `non_inscrit` subsiste.
            return 'non_inscrit';
        }

        return $couverture->statut === 'active' ? 'actif' : 'expire';
    }

    public function getCmuValiditeAttribute(): ?Carbon
    {
        return $this->couvertureCmu()?->date_fin;
    }

    /**
     * La couverture CNAM/CMU du membre, celle que la carte F2.3 présente.
     *
     * Le TYPE fait foi, pas le nom : « CMU » est le régime, la CNAM est l'organisme qui le gère
     * (CDC_06 §8.1). Chercher « CMU » dans un libellé rendrait la carte dépendante d'une chaîne de
     * caractères — exactement ce que ce module supprime.
     *
     * PRÉFÉRENCE À LA COUVERTURE VIVANTE : un assuré qui renouvelle a deux lignes, et la carte doit
     * montrer celle qui vaut. À défaut, la plus récente — pour que l'écran dise « expirée » plutôt
     * que « non inscrit », qui serait faux.
     *
     * `loadMissing` : la valeur ne dépend donc pas de ce que l'appelant a pensé à charger (leçon
     * P6.8b). Les listes chargent la relation par `with()` pour ne pas payer un N+1.
     */
    public function couvertureCmu(): ?CouvertureMembre
    {
        $this->loadMissing('couvertures.organisme');

        return $this->couvertures
            ->filter(fn (CouvertureMembre $c): bool => $c->organisme?->type === 'cnam')
            ->sortByDesc(fn (CouvertureMembre $c): array => [
                $c->statut === 'active' ? 1 : 0,
                $c->date_debut?->timestamp ?? 0,
                $c->id,
            ])
            ->first();
    }

    /** Compte propriétaire du membre. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Tokens QR dynamiques générés pour ce membre (2A.3). */
    public function tokensQr(): HasMany
    {
        return $this->hasMany(TokenQr::class, 'membre_id');
    }

    /** Journal d'audit des accès au dossier de ce membre (§10, loi 2013-450). */
    public function accesDossier(): HasMany
    {
        return $this->hasMany(AccesDossier::class, 'membre_id');
    }

    // --- Sections du carnet (2A.4) ---

    public function antecedents(): HasMany
    {
        return $this->hasMany(Antecedent::class, 'membre_id');
    }

    public function vaccinations(): HasMany
    {
        return $this->hasMany(Vaccination::class, 'membre_id');
    }

    public function ordonnances(): HasMany
    {
        return $this->hasMany(Ordonnance::class, 'membre_id');
    }

    /** B3-d — les commandes de médicaments passées pour ce membre. */
    public function commandes(): HasMany
    {
        return $this->hasMany(Commande::class, 'membre_id');
    }

    public function resultatsAnalyses(): HasMany
    {
        return $this->hasMany(ResultatAnalyse::class, 'membre_id');
    }

    public function rappels(): HasMany
    {
        return $this->hasMany(Rappel::class, 'membre_id');
    }

    // --- Nouvelles sections du carnet (F2.10 → F2.12) ---

    public function documentsMedicaux(): HasMany
    {
        return $this->hasMany(DocumentMedical::class, 'membre_id');
    }

    public function contactsUrgence(): HasMany
    {
        return $this->hasMany(ContactUrgence::class, 'membre_id');
    }

    public function notesObservations(): HasMany
    {
        return $this->hasMany(NoteObservation::class, 'membre_id');
    }

    /** Module 5 / FN4 — Suivis de grossesse (en cours + historique clos). */
    public function suivisGrossesse(): HasMany
    {
        return $this->hasMany(SuiviGrossesse::class, 'membre_id');
    }

    /** Module 5 / FN5 — Journal de bord des mesures (glycémie, tension, poids…). */
    public function mesuresSante(): HasMany
    {
        return $this->hasMany(MesureSante::class, 'membre_id');
    }

    /** Module 5 / 5.6 — Désignations de médecin référent (active + historique révoqué, voie 2). */
    public function referents(): HasMany
    {
        return $this->hasMany(Referent::class, 'membre_id');
    }

    /**
     * P6.8d — Ses couvertures santé déclarées (CNAM, mutuelle, assurance d'entreprise…).
     *
     * PLUSIEURS, et c'est tout le point de l'incrément : le §8 du CDC_06 enchaîne « CNAM, **puis**
     * assurances privées » sur la même facture, ce que trois colonnes `cmu_*` ne pouvaient pas dire.
     */
    public function couvertures(): HasMany
    {
        return $this->hasMany(CouvertureMembre::class, 'membre_id');
    }
}
