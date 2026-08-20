<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une réponse POSSIBLE d'une question (CDC_08 §4.4 `protocole_reponses`).
 *
 * ═══ POURQUOI « POSSIBLE » ET NON « DONNÉE » ═══
 *
 * Le §4.4 nomme la table sans dire ce qu'elle contient. Les réponses DONNÉES par un patient vivent
 * dans `triage_reponses`, que CDC_04 §115 exige par ailleurs : y mettre aussi celles-ci ferait deux
 * tables pour le même fait, la « deux vérités » refusée depuis P6.6a.
 *
 * ═══ CE QUE CETTE TABLE REFERME (constat X5 du G0) ═══
 *
 * Le référentiel portait deux listes parallèles du même fait :
 *
 *     'options' => ['seche', 'grasse'],
 *     'impact'  => ['points_par_option' => ['seche' => 3, 'grasse' => 5]]
 *
 * Elles coïncidaient ; rien ne l'imposait. Une option présente dans l'une et absente de l'autre
 * marquait **0 point sans bruit** ; une entrée d'impact sans option était inatteignable. Ici une
 * ligne EST une réponse possible, et `UNIQUE(question_id, valeur)` rend la divergence
 * **inexprimable** plutôt qu'interdite — le geste de P6.8c, où l'absence de colonne `type` empêche
 * d'écrire une seconde vérité.
 *
 * ═══ ET POURQUOI AUCUNE COLONNE `points` ICI ═══
 *
 * Ce serait la même règle médicale hors du §7 qu'on vient de déménager (X3). L'impact d'une réponse
 * est une RÈGLE — `SI reponse.type_toux = grasse ALORS AJOUTER_SCORE 5` — relue et signée par
 * quatre validateurs. Une colonne `points` ici rouvrirait la porte de derrière, en plus discret.
 */
class ProtocoleReponse extends Model
{
    protected $table = 'protocole_reponses';

    protected $fillable = ['question_id', 'valeur', 'libelle', 'ordre'];

    protected function casts(): array
    {
        return ['ordre' => 'integer'];
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(ProtocoleQuestion::class, 'question_id');
    }
}
