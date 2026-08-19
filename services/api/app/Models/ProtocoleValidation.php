<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Une validation d'une version de protocole (CDC_08 §7 — « aucun protocole utilisable sans
 * validation »).
 *
 * ═══ QUATRE COUCHES, QUATRE MÉTIERS ═══
 *
 *   clinique      — médecins spécialistes, experts hospitaliers, sociétés savantes ;
 *   reglementaire — Ministère de la Santé, programmes nationaux, autorités sanitaires ;
 *   scientifique  — publications revues par les pairs, essais cliniques, méta-analyses ;
 *   technique     — cohérence des règles, absence de conflits, tests automatiques.
 *
 * Le §7 les veut « enregistrée[s] (validateur, rôle, date, avis, commentaires) et opposable[s] ».
 *
 * ═══ APPEND-ONLY : UNE SIGNATURE NE SE RÉÉCRIT PAS ═══
 *
 * « Opposable » perdrait tout sens si une validation pouvait être modifiée après coup : ce qu'on
 * produirait devant un tribunal serait la dernière rédaction, pas ce qui a été signé. Une nouvelle
 * relecture pose donc une NOUVELLE ligne ; la plus récente de chaque type fait foi, les
 * précédentes racontent l'histoire. Motif de la chaîne d'audit de P6.3 et du grand livre
 * append-only du paiement (P5.1).
 *
 * ═══ `empreinte_contenu` PORTE L'ANTI-SUBSTITUTION ═══
 *
 * L'empreinte du contenu **au moment de la signature**. À la publication, le contenu est ré-extrait
 * et comparé : s'il a changé depuis, la validation est caduque et la publication refusée **en la
 * nommant**. Sans cela on publierait des règles cliniques que personne n'a relues — transposition
 * du contrôle central de P6.3 et du « destination révoquée depuis le figeage » de P5.5b-2, où il
 * s'agissait d'argent ; ici il s'agit de conduites à tenir.
 */
class ProtocoleValidation extends Model
{
    public const CLINIQUE = 'clinique';

    public const REGLEMENTAIRE = 'reglementaire';

    public const SCIENTIFIQUE = 'scientifique';

    public const TECHNIQUE = 'technique';

    public const FAVORABLE = 'favorable';

    public const RESERVE = 'reserve';

    public const DEFAVORABLE = 'defavorable';

    protected $table = 'protocole_validations';

    protected $fillable = [
        'version_id', 'type', 'validateur_id', 'validateur_nom', 'validateur_role',
        'avis', 'commentaires', 'empreinte_contenu', 'valide_le',
    ];

    protected function casts(): array
    {
        return ['valide_le' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException(
                'Une validation de protocole ne se modifie pas : elle est opposable (CDC_08 §7). '
                .'Une nouvelle relecture pose une nouvelle validation.'
            );
        });

        static::deleting(function () {
            throw new RuntimeException(
                'Une validation de protocole ne se supprime pas : elle est opposable (CDC_08 §7).'
            );
        });
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(ProtocoleVersion::class, 'version_id');
    }

    public function validateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validateur_id');
    }

    /**
     * Cette validation autorise-t-elle la publication du contenu courant ?
     *
     * DEUX CONDITIONS, ET LA SECONDE EST CELLE QU'ON OUBLIE : l'avis doit être favorable, **et**
     * le contenu ne doit pas avoir bougé depuis la signature. Un avis favorable sur un contenu
     * qui a changé n'est pas un avis favorable — c'est un avis sur autre chose.
     *
     * `hash_equals` plutôt que `===` : comparaison en temps constant, par cohérence avec le reste
     * du projet (P5.5b-1, P6.5b). Ce n'est pas un secret, mais l'habitude évite d'avoir à décider
     * au cas par cas ce qui en est un.
     */
    public function autorisePublication(string $empreinteCourante): bool
    {
        return $this->avis === self::FAVORABLE
            && hash_equals($this->empreinte_contenu, $empreinteCourante);
    }
}
