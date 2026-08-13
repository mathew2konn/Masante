<?php

namespace App\Services\Referentiel;

/**
 * Empreintes SHA-256 du socle référentiel — un seul endroit où l'on décide « comment on hache ».
 *
 * LE PROBLÈME QU'IL RÉSOUT : `json_encode` ne garantit pas un résultat stable. L'ordre des clés
 * dépend de l'ordre d'insertion dans le tableau, les accents peuvent être échappés ou non, les
 * flottants formatés différemment. Deux exécutions sur des données IDENTIQUES produiraient alors
 * deux empreintes différentes, et « le contenu a-t-il changé depuis la dernière publication ? »
 * deviendrait une question sans réponse fiable.
 *
 * On canonise donc avant de hacher : clés triées récursivement, unicode non échappé, séparateurs
 * fixes. La règle vaut aussi pour la chaîne d'audit, dont l'intégrité repose entièrement sur la
 * reproductibilité du calcul — une empreinte non reproductible signalerait des altérations
 * imaginaires et masquerait les vraies.
 */
final class EmpreinteReferentiel
{
    /** JSON canonique : clés triées en profondeur, sans échappement unicode ni barre oblique. */
    public static function canoniser(mixed $valeur): string
    {
        return json_encode(
            self::trier($valeur),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        );
    }

    /** L'empreinte d'un contenu de référentiel (l'instantané d'une version). */
    public static function duContenu(array $contenu): string
    {
        return hash('sha256', self::canoniser($contenu));
    }

    /**
     * Le maillon suivant de la chaîne d'audit.
     *
     * La séparation par `\x1F` (séparateur d'unité ASCII) n'est pas décorative : avec un séparateur
     * qui peut apparaître dans les données, deux entrées différentes pourraient produire la même
     * chaîne à hacher — et donc la même empreinte. `\x1F` n'apparaît dans aucun des champs concaténés.
     */
    public static function duMaillon(?string $precedente, array $charge): string
    {
        return hash('sha256', implode("\x1F", [
            $precedente ?? 'GENESE',
            self::canoniser($charge),
        ]));
    }

    /** Tri récursif des clés des tableaux associatifs ; les listes gardent leur ordre. */
    private static function trier(mixed $valeur): mixed
    {
        if (! is_array($valeur)) {
            return $valeur;
        }

        // Une liste est ordonnée par nature : la réordonner changerait le sens de la donnée
        // (l'ordre des questions d'un symptôme, par exemple).
        if (array_is_list($valeur)) {
            return array_map(static fn (mixed $v): mixed => self::trier($v), $valeur);
        }

        ksort($valeur);

        return array_map(static fn (mixed $v): mixed => self::trier($v), $valeur);
    }
}
