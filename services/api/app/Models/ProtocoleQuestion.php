<?php

namespace App\Models;

use App\Support\RegistreFaitsProtocole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Une question du questionnaire adaptatif (CDC_08 §4.3b, §4.4 `protocole_questions`).
 *
 * Elle appartient à une VERSION de protocole, pas au protocole : corriger un énoncé produit une
 * nouvelle version relue et signée (§6.1, §7). C'est la granularité que W5 du G0 de P10b a établie
 * face au socle P6.3.
 *
 * ═══ ELLE NE PORTE AUCUNE CONDITION, ET C'EST LA DÉCISION R1 ═══
 *
 * « Pose cette question si… » est une RÈGLE, pas une colonne : `POSER_QUESTION` est une action de
 * `protocole_actions`. La question ne sait donc pas quand elle est posée — c'est le protocole qui
 * le sait, dans le même langage que tout le reste, avec les mêmes listes blanches et le même
 * contrôle qualité.
 */
class ProtocoleQuestion extends Model
{
    protected $table = 'protocole_questions';

    protected $fillable = [
        'version_id', 'cle', 'libelle', 'type', 'unite',
        'valeur_min', 'valeur_max', 'ordre',
    ];

    protected function casts(): array
    {
        return [
            'valeur_min' => 'integer',
            'valeur_max' => 'integer',
            'ordre' => 'integer',
        ];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(ProtocoleVersion::class, 'version_id');
    }

    public function reponses(): HasMany
    {
        return $this->hasMany(ProtocoleReponse::class, 'question_id');
    }

    /**
     * Le nom du fait par lequel une condition désigne la réponse à cette question.
     *
     * Il vit ici et nulle part ailleurs : le préfixe est écrit à UN seul endroit, sinon le
     * compilateur, le contrôle qualité et le service le recopieraient chacun de leur côté —
     * *une chaîne recopiée trois fois finit par diverger deux fois* (précédent `MENTION_PROVENANCE`
     * de P6.8d).
     */
    public function fait(): string
    {
        return RegistreFaitsProtocole::PREFIXE_REPONSE.$this->cle;
    }

    /**
     * Le type de FAIT correspondant au type de QUESTION.
     *
     * Les deux vocabulaires sont distincts et ne doivent pas être confondus : `echelle` et
     * `nombre` sont deux contrôles d'écran différents qui produisent le même type de fait. C'est
     * ce type-là que le contrôle de compatibilité fait/opérateur de P10b-1 utilise pour refuser
     * `>=` sur une question booléenne.
     */
    public function typeDeFait(): string
    {
        return match ($this->type) {
            'nombre', 'echelle' => RegistreFaitsProtocole::TYPE_NOMBRE,
            'booleen' => RegistreFaitsProtocole::TYPE_BOOLEEN,
            default => RegistreFaitsProtocole::TYPE_TEXTE,
        };
    }
}
