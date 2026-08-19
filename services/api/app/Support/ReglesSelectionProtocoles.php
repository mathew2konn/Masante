<?php

namespace App\Support;

/**
 * P10b-2 — Quels protocoles sont candidats à l'évaluation (CDC_08 §9.1, §3).
 *
 * CLASSE PURE : aucune base, aucune horloge — la date d'évaluation arrive **par paramètre**, comme
 * dans `ReglesCalendrierVaccinal` (P6.8b), pour que « ce protocole est-il périmé ? » soit une
 * question qu'on peut poser sur n'importe quelle date plutôt qu'une propriété du moment où le test
 * s'exécute.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * LA SÉLECTION EST GROSSIÈRE, ET C'EST DÉLIBÉRÉ
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * Elle ne juge JAMAIS si un protocole convient *à ce patient-là*. Elle retient un protocole sur ce
 * que le registre déclare : le pays, le contexte, l'activité, la non-péremption. C'est ensuite le
 * moteur (`MoteurProtocole`) qui tranche — un protocole sélectionné dont aucune règle ne se
 * déclenche ne recommande **rien**, et ce n'est pas une erreur.
 *
 * *L'alternative — un second langage d'applicabilité à côté du langage de règles — aurait créé deux
 * façons d'écrire « ce protocole s'applique quand… », donc deux endroits où se tromper, et un
 * protocole aurait pu être applicable selon l'un et pas selon l'autre.* Un protocole réservé aux
 * femmes enceintes l'exprime comme une **condition de ses règles**, dans la liste blanche fermée
 * qui existe déjà.
 *
 * ═══ POURQUOI CHAQUE EXCLUSION PORTE SON MOTIF ═══
 *
 * « Ce protocole n'a pas été appliqué » est une information inexploitable ; « il a été écarté parce
 * qu'il ne déclare aucun contexte » se corrige. C'est ce que l'écran d'exploitation lira, et ce que
 * le journal du §10 conserve.
 */
final class ReglesSelectionProtocoles
{
    public const MOTIF_CONTEXTE = 'contexte_non_declare';

    public const MOTIF_HORS_CONTEXTE = 'hors_contexte';

    public const MOTIF_EXPIRE = 'version_expiree';

    public const MOTIF_DESACTIVE = 'protocole_desactive';

    /**
     * Trie les candidats en retenus et écartés.
     *
     * @param  array<int, array<string, mixed>>  $candidats  Descripteurs de protocoles en vigueur :
     *                                                       `code`, `actif`, `contextes`,
     *                                                       `date_expiration`.
     * @param  string  $contexte  Un code de {@see RegistreContextesProtocole}.
     * @param  string  $aujourdhui  Date d'évaluation, format `Y-m-d`.
     * @return array{retenus: array<int, array<string, mixed>>, ecartes: array<int, array{code: string, motif: string}>}
     */
    public static function trier(array $candidats, string $contexte, string $aujourdhui): array
    {
        $retenus = [];
        $ecartes = [];

        foreach ($candidats as $candidat) {
            $motif = self::motifExclusion($candidat, $contexte, $aujourdhui);

            if ($motif === null) {
                $retenus[] = $candidat;

                continue;
            }

            $ecartes[] = ['code' => (string) $candidat['code'], 'motif' => $motif];
        }

        return ['retenus' => $retenus, 'ecartes' => $ecartes];
    }

    /** @param array<string, mixed> $candidat */
    private static function motifExclusion(array $candidat, string $contexte, string $aujourdhui): ?string
    {
        // §6.1 — un protocole retiré du catalogue garde ses versions consultables, mais ne
        // s'applique plus. Le retirer et le voir continuer à décider serait le contraire d'un
        // retrait.
        if (($candidat['actif'] ?? true) === false) {
            return self::MOTIF_DESACTIVE;
        }

        // ═══ AUCUN CONTEXTE DÉCLARÉ ⇒ JAMAIS SÉLECTIONNÉ ═══
        //
        // Le silence n'est pas un « oui à tout ». Un protocole dont personne n'a décidé du champ
        // d'application s'appliquerait partout — en consultation comme en urgence — sans qu'aucun
        // validateur ne l'ait relu sous cet angle. Le contrôle qualité l'exige à la publication ;
        // ceci est la seconde barrière, pour les versions publiées avant ce contrôle.
        $contextes = RegistreContextesProtocole::filtrer($candidat['contextes'] ?? null);

        if ($contextes === []) {
            return self::MOTIF_CONTEXTE;
        }

        if (! in_array($contexte, $contextes, true)) {
            return self::MOTIF_HORS_CONTEXTE;
        }

        // §4.1 — « date d'expiration ». Une version périmée a cessé d'engager qui que ce soit ;
        // continuer à l'appliquer ferait dire au moteur ce que le rédacteur a lui-même déclaré
        // dépassé. Le jour même de l'expiration, le protocole s'applique encore : une date
        // d'expiration se lit « valable jusqu'à », pas « invalide à partir de ».
        $expiration = $candidat['date_expiration'] ?? null;

        if (is_string($expiration) && $expiration !== '' && $expiration < $aujourdhui) {
            return self::MOTIF_EXPIRE;
        }

        return null;
    }
}
