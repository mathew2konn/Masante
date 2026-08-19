<?php

namespace App\Support;

/**
 * P10a — L'agrégation de l'orientation, en classe PURE (CDC_05 §5).
 *
 * Motif `ReglesReversement` (P5.5a), `ReglesIntervalleReference` (P6.7a),
 * `ReglesCalendrierVaccinal` (P6.8b) : aucun accès base, aucune horloge, tout par paramètre. C'est
 * ce qui rend le jugement vérifiable sans monter une base ni toucher à l'horloge du système.
 *
 * ═══ CE QU'ELLE FAIT, ET SURTOUT CE QU'ELLE NE FAIT PAS ═══
 *
 * Elle **ordonne des codes**. Elle ne conclut rien de médical, ne nomme aucune maladie, ne produit
 * aucun diagnostic — CDC_05 §1 : « *le triage n'est jamais un diagnostic : il oriente vers le
 * niveau de soins approprié* ».
 *
 * **L'orientation n'est pas une déduction, c'est une agrégation d'annotations.** Chaque symptôme
 * porte, en base, les spécialités vers lesquelles il oriente, rangées. Cette classe fusionne les
 * listes de plusieurs symptômes. Elle n'infère jamais une spécialité que la donnée ne porte pas.
 *
 * ═══ LES TROIS RÈGLES EN DUR QU'ELLE REMPLACE ═══
 *
 * `TriageService::deduireSpecialite()` portait :
 *
 *   1. `str_contains($h, 'urgenc') || str_contains($h, 'cardio')`  → priorité
 *   2. `str_contains($h, 'gyn') && $sexe !== 'F'`                  → restriction
 *   3. `if ($age < 15) return 'Pédiatrie';`                        → repli
 *
 * Trois comparaisons de sous-chaînes sur du **texte libre modifiable au portail**. Écrire
 * « Urgence » au singulier supprimait la priorité, *sans que rien ne le signale*. Ici : (1) devient
 * le `rang` de la liaison, (2) devient `sexe_requis`, (3) devient un repli **déclaré et passé en
 * paramètre**, jamais un littéral enfoui.
 */
final class ReglesOrientation
{
    /**
     * Fusionne les orientations de plusieurs symptômes en une liste ordonnée de codes.
     *
     * @param  array<int, array{code: string, rang: int, sexe_requis: ?string}>  $orientations
     *         Les liaisons des symptômes retenus, telles que la base les porte — dans n'importe
     *         quel ordre : c'est cette méthode qui ordonne.
     * @param  string|null  $sexe        'M', 'F', ou `null` si inconnu.
     * @param  int|null     $age         En années, ou `null`.
     * @param  string|null  $codePediatrie  Le code du repli pédiatrique, **fourni** et non deviné.
     * @param  int          $agePediatrie   Le seuil, **donnée** et non littéral (§5.1.3).
     *
     * @return array<int, string> les codes, du plus pertinent au moins pertinent, sans doublon
     */
    public static function agreger(
        array $orientations,
        ?string $sexe = null,
        ?int $age = null,
        ?string $codePediatrie = null,
        int $agePediatrie = 15,
    ): array {
        $retenues = [];

        foreach ($orientations as $o) {
            $code = (string) ($o['code'] ?? '');

            if ($code === '') {
                continue;
            }

            // ═══ LA RESTRICTION VIENT DE LA DONNÉE, PLUS D'UN `str_contains` ═══
            //
            // Et le SEXE INCONNU n'écarte rien : un triage anonyme ne renseigne pas toujours le
            // sexe, et retirer une orientation faute d'information reviendrait à décider à la
            // place du patient. On écarte seulement quand on SAIT que la restriction n'est pas
            // remplie — motif des trois silences de P7-D2 : on n'agit que sur ce qu'on sait.
            $requis = $o['sexe_requis'] ?? null;

            if ($requis !== null && $sexe !== null && $requis !== $sexe) {
                continue;
            }

            $rang = max(1, (int) ($o['rang'] ?? 1));

            // Un même code peut venir de plusieurs symptômes : on garde le MEILLEUR rang. Deux
            // symptômes qui orientent tous deux vers les urgences ne doivent pas la reléguer.
            if (! isset($retenues[$code]) || $rang < $retenues[$code]) {
                $retenues[$code] = $rang;
            }
        }

        if ($retenues === []) {
            // ═══ LE REPLI PÉDIATRIQUE (§5.1.3) ═══
            //
            // Il ne s'applique QUE si rien n'a été retenu : un enfant qui présente un symptôme
            // dentaire va chez le dentiste, pas en pédiatrie parce qu'il est enfant. Le code est
            // FOURNI par l'appelant — s'il n'existe pas au vocabulaire publié, on ne renvoie rien
            // plutôt que d'inventer un terme (précédent P6.4a).
            if ($codePediatrie !== null && $age !== null && $age < $agePediatrie) {
                return [$codePediatrie];
            }

            return [];
        }

        // Tri par rang, puis par code — ordre TOTAL. Sans le second critère, deux orientations au
        // même rang sortiraient dans l'ordre du moteur, et la même entrée répondrait différemment
        // d'une base à l'autre (leçon `NumeroUrgence::scopeOrdonne`, P6.8e).
        uksort($retenues, static function (string $a, string $b) use ($retenues): int {
            return [$retenues[$a], $a] <=> [$retenues[$b], $b];
        });

        return array_keys($retenues);
    }
}
