<?php

namespace App\Services\Triage;

use App\Models\Symptome;
use Illuminate\Support\Collection;

/**
 * P10b-3-ii — La SOURCE UNIQUE des faits remis au moteur de protocoles (CDC_08 §2).
 *
 * ═══ LE DÉFAUT QUE CETTE CLASSE FERME (constat Z1 du G0) ═══
 *
 * L'assemblage existait en **trois exemplaires** : `TriageController::questions()` et
 * `TriageService::analyser()` (deux fois, une par phase) composaient chacun à la main le même
 * tableau, en recopiant les mêmes clés.
 *
 * Ce n'était pas une redite sans conséquence. `score_antecedents` était déjà un fait déclaré,
 * passé par les deux sites du service et **pas** par celui du contrôleur ; or, depuis P10b-1, **un
 * fait inconnu lève**. Une règle de questionnaire conditionnée sur les antécédents aurait donc
 * fonctionné dans `POST /triage/analyser` et **rendu `POST /triage/questions` inopérant** — défaut
 * actif, simplement non déclenché faute de règle qui l'emprunte. Même famille que le
 * `centre_dialyse` en dur de P6.4b et le `specialite_requise` jamais branché de P10a.
 *
 * Ajouter deux faits à trois endroits aurait reproduit la faute au lieu de la fermer.
 *
 * ═══ CE QUE CETTE CLASSE NE FAIT PAS ═══
 *
 * Elle n'ajoute aucun fait dérivé, ne juge rien, ne décide rien : elle **rassemble**. Les faits
 * propres à une phase (`score`, `score_reponses`, `score_antecedents`) sont ajoutés par l'appelant
 * au moment où ils existent — les inscrire ici obligerait à leur donner une valeur avant qu'ils
 * n'aient un sens, et un fait qui ment est pire qu'un fait absent.
 */
final class FaitsTriage
{
    /**
     * Les faits connus dès la sélection des symptômes.
     *
     * ═══ P10c-1 — LES CONSTANTES SONT DANS LA BASE, ET C'EST UNE DIFFÉRENCE AVEC LES ANTÉCÉDENTS ═══
     *
     * Les antécédents sont ajoutés à part parce que `POST /triage/questions` **ne peut pas** les
     * connaître : il ignore le membre. Les constantes, elles, sont envoyées par le client sur
     * **les deux** endpoints — elles sont donc connues au même moment que les symptômes.
     *
     * Les mettre ici est ce qui rend légitime une règle de questionnaire conditionnée sur la
     * fièvre (« 39,5 — depuis quand ? »), qui est exactement l'adaptativité du §4.3b. Les passer à
     * un seul des deux endpoints rejouerait le constat Z1 : un fait inconnu lève, donc une telle
     * règle ferait tomber un endpoint sur deux.
     *
     * Elles arrivent **déjà nommées** (`constante.temperature`) et déjà validées contre la version
     * publiée : cette classe rassemble, elle ne juge rien — voir l'en-tête.
     *
     * @param  Collection<int, Symptome>  $symptomes
     * @param  array<string, float>  $constantes  Faits produits par {@see ServiceConstantesTriage::faits()}.
     * @return array<string, mixed>
     */
    public static function base(
        Collection $symptomes,
        ?int $age = null,
        ?string $sexe = null,
        array $constantes = [],
    ): array {
        return $constantes + [
            'age' => $age,
            'sexe' => $sexe,
            'score_symptomes' => (int) $symptomes->sum('poids_severite'),
            'drapeau_rouge' => $symptomes->contains(fn (Symptome $s) => $s->drapeau_rouge === true),
            'nb_symptomes' => $symptomes->count(),
            'symptome_id' => $symptomes->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            'symptome_categorie' => $symptomes->pluck('categorie')->unique()->values()->all(),

        ];
    }

    /**
     * Les faits des antécédents, AJOUTÉS SÉPARÉMENT — et ce n'est pas un détail de forme.
     *
     * ═══ POURQUOI ILS NE SONT PAS DANS LA BASE ═══
     *
     * `POST /triage/questions` ne connaît pas le membre, donc pas ses antécédents ; seul
     * `POST /triage/analyser` les connaît. Les mettre dans la base les rendrait présents dans un
     * cas et absents dans l'autre — et, comme un fait inconnu lève depuis P10b-1, une règle qui
     * s'en servirait ferait tomber un endpoint sur deux. Les séparer rend la frontière lisible,
     * et le contrôle qualité la rend **vérifiable** : un protocole de questionnaire ne peut pas
     * conditionner sur ces deux faits.
     *
     * ═══ LA SOMME EST BRUTE, ET C'EST TOUT CE QU'ELLE PEUT ÊTRE ICI ═══
     *
     * La borne est une décision clinique portée par un protocole publié
     * ({@see ServicePlafondAntecedents}). L'appliquer ici la remettrait dans le code, d'où cet
     * incrément la sort.
     *
     * @param  array<string, mixed>  $base
     * @param  array<int, array{libelle: string, impact_triage: int}>  $antecedents
     * @return array<string, mixed>
     */
    public static function avecAntecedents(array $base, array $antecedents): array
    {
        return $base + [
            'score_antecedents_brut' => (int) collect($antecedents)->sum('impact_triage'),
            'nb_antecedents' => count($antecedents),
        ];
    }
}
