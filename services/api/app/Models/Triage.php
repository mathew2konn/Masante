<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Session de triage enregistrée (Module 1, F1.6 historique / F1.8 fiche).
 * $fillable explicite (§8.2 Sécurité). Données médicales non sensibles ici
 * (symptômes/score) ; les antécédents chiffrés viendront du carnet (Module 2).
 */
class Triage extends Model
{
    protected $table = 'triages';

    protected $fillable = [
        'user_id',
        'membre_id',
        'patient_nom',
        'patient_age',
        'patient_sexe',
        'symptomes_json',
        'reponses_json',
        'score_severite',
        'niveau',
        'specialite_requise',
        // P10a — Posées par le SERVEUR seul. Elles sont `$fillable` parce que le contrôleur écrit
        // par assignation de masse (motif P6.7b), mais aucune valeur du client ne peut les
        // atteindre : `AnalyserTriageRequest::validated()` ne les déclare pas, et le contrôleur les
        // reprend du résultat calculé. Un vecteur dédié envoie `referentiel_version: 999` et vérifie
        // que la base porte la vraie version.
        'referentiel_version',
        'specialites_json',
        // P10b-1 — L'estampille du protocole (§6.1, exigence médico-légale). Même statut que
        // `referentiel_version` juste au-dessus : `$fillable` parce que le contrôleur écrit par
        // assignation de masse, mais aucune valeur du client ne peut les atteindre —
        // `AnalyserTriageRequest` ne les déclare pas et le contrôleur les reprend du résultat.
        'protocole_code',
        'protocole_version',
        'recommandation_texte',
        'fiche_generee',
        'structure_visitee_id',
    ];

    /**
     * P10a — LA MENTION OBLIGATOIRE DU §5.4, AU MOT PRÈS.
     *
     * Le CDC_05 §5.4 ne la décrit pas, il la **cite** : c'est un texte imposé, pas une formulation
     * libre. Elle vit sur le modèle et non dans un gabarit — précédent `MENTION_PROVENANCE`
     * (P6.8d) : plusieurs surfaces l'affichent (API, texte de partage, écran mobile, futur PDF), et
     * une phrase recopiée à quatre endroits finit par diverger en trois.
     *
     * Elle est aussi la traduction opérationnelle de l'interdit de CDC_00 §4 — *un triage présenté
     * comme un diagnostic* — et de CDC_05 §1 : « le triage n'est jamais un diagnostic ».
     */
    public const MENTION_OBLIGATOIRE = 'Ce document constitue une aide à l\'orientation et ne '
        .'remplace pas un diagnostic médical.';

    /**
     * Le jeton n'est jamais sérialisé par défaut : c'est la CLÉ de la fiche. Il ne sort que là où
     * on le construit délibérément (la réponse d'analyse, et la charge utile du QR).
     *
     * @var array<int, string>
     */
    protected $hidden = ['jeton_partage'];

    /**
     * ═══ P10a — LE JETON EST POSÉ ICI, PAS DANS LE CONTRÔLEUR ═══
     *
     * Il est **hors `$fillable`** : un client qui choisirait son propre jeton pourrait le deviner
     * pour un autre. Et il est posé par un crochet du modèle plutôt que par le contrôleur, pour que
     * la garantie vaille sur **tout chemin d'écriture** — motif de `preparerDonnees()` en P6.6b,
     * où une garantie qui n'aurait valu que sur l'un des trois chemins n'en aurait pas été une.
     *
     * `??=` et non `=` : une valeur déjà posée (rattrapage, import) n'est pas écrasée en silence.
     */
    protected static function booted(): void
    {
        static::creating(function (self $triage): void {
            $triage->jeton_partage ??= Str::random(48);
        });
    }

    protected $casts = [
        'symptomes_json' => 'array',
        'reponses_json' => 'array',
        'specialites_json' => 'array',
        'score_severite' => 'integer',
        'patient_age' => 'integer',
        'referentiel_version' => 'integer',
        'fiche_generee' => 'boolean',
    ];

    /**
     * P10b-3-i — Les réponses au questionnaire (CDC_04 §115).
     *
     * VIDE pour les triages antérieurs à la bascule : leurs réponses vivent dans `reponses_json`,
     * et leur en fabriquer ici serait un mensonge d'archive (précédent L2). La fiche §5.4 lit
     * l'une ou l'autre selon ce qui existe.
     */
    public function reponses(): HasMany
    {
        return $this->hasMany(TriageReponse::class, 'triage_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
