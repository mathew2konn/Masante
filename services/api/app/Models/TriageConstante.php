<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P10c-1 — Une constante clinique relevée lors d'un triage (CDC_05 §5.2).
 *
 * Elle décrit **cet épisode** de triage, pas l'état durable du patient : le carnet
 * (`mesures_sante`) n'est jamais écrit depuis ici — voir l'en-tête de la migration.
 *
 * ═══ CE QUI EST FIGÉ, ET POURQUOI ═══
 *
 * `unite` est copiée à l'écriture. La résoudre à la lecture ferait qu'une correction du référentiel
 * changerait rétroactivement le sens d'une valeur enregistrée. Motif établi : la DCI figée dans une
 * ordonnance (P6.6b), l'établissement copié dans le journal d'accès (P7-D2), le libellé d'une
 * question dans `triage_reponses` (P10b-3-i).
 *
 * ═══ ELLE NE PORTE PAS DE STATUT, ET C'EST LE POINT DE CONCEPTION DE L'INCRÉMENT ═══
 *
 * `mesures_sante` porte `statut_norme` (`normal|bas|eleve|critique`), calculé par
 * {@see ReferentielMesure::statutPour()}. **Cette table n'en porte pas, et le triage n'appelle
 * jamais cette méthode.**
 *
 * `critique_haut = 39.5` vit dans un référentiel gouverné par les **deux signatures
 * administratives** du §10. Faire dépendre le niveau d'urgence d'un citoyen de cette valeur la
 * soumettrait à deux signatures là où **le §7 en exige quatre** — c'est exactement l'asymétrie que
 * P10b-3-i a passé un incrément entier à refermer pour l'impact des réponses. On ne la rouvre pas
 * un cran plus bas.
 *
 * Partage retenu : le référentiel fournit l'unité, les décimales et les bornes de **plausibilité**
 * (une question de qualité de donnée : *300 °C n'est pas un patient*) ; le seuil qui **change un
 * triage** est une règle de protocole, comparant la valeur brute et signée par quatre validateurs.
 */
class TriageConstante extends Model
{
    protected $table = 'triage_constantes';

    /** Valeur saisie par le patient au moment du triage. */
    public const ORIGINE_SAISIE = 'saisie';

    /** Valeur proposée depuis le carnet — donc affichée avec sa date — puis validée par le patient. */
    public const ORIGINE_CARNET = 'reprise_du_carnet';

    protected $fillable = [
        'triage_id', 'type_mesure', 'valeur', 'unite',
        'origine', 'mesure_id', 'referentiel_version',
    ];

    protected function casts(): array
    {
        return [
            'valeur' => 'float',
            'referentiel_version' => 'integer',
            'mesure_id' => 'integer',
        ];
    }

    public function triage(): BelongsTo
    {
        return $this->belongsTo(Triage::class, 'triage_id');
    }
}
