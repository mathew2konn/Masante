<?php

namespace App\Support;

/**
 * P10b-1 — Liste blanche FERMÉE des opérateurs de condition (CDC_08 §4.3a, §12
 * « tests unitaires du moteur d'inférence : conditions, opérateurs, priorités »).
 *
 * Même raison de fermeture que {@see RegistreFaitsProtocole} : l'opérateur arrive par la donnée.
 * Sans liste blanche, le rédacteur choisirait librement une comparaison que le moteur devrait
 * ensuite interpréter — c'est-à-dire évaluer une expression écrite en base. On ne construit pas
 * ça dans un système qui décide du niveau d'urgence d'un citoyen.
 *
 * CHAQUE OPÉRATEUR DÉCLARE LES TYPES DE FAIT QU'IL ACCEPTE, et le contrôle qualité s'en sert : la
 * confrontation attrape au moment de la publication l'erreur la plus banale et la plus muette de
 * ce modèle — `symptome_id >= 5`, une comparaison numérique sur une liste, qui ne veut rien dire
 * et qui, sans ce contrôle, se contenterait de ne jamais se déclencher.
 *
 * L'ARITÉ EST DÉCLARÉE ELLE AUSSI : `entre` attend deux bornes, `existe` n'attend rien. Une valeur
 * manquante ou surnuméraire est une donnée fausse, pas un cas limite à deviner à l'exécution.
 */
final class RegistreOperateursProtocole
{
    /** Aucune valeur attendue (`existe`, `absent`). */
    public const ARITE_AUCUNE = 0;

    /** Une valeur scalaire. */
    public const ARITE_SIMPLE = 1;

    /** Deux bornes, inclusives. */
    public const ARITE_INTERVALLE = 2;

    /**
     * opérateur => [arité, types de fait acceptés, libellé lisible]
     *
     * @var array<string, array{arite: int, types: array<int, string>, libelle: string}>
     */
    public const OPERATEURS = [
        '=' => [
            'arite'   => self::ARITE_SIMPLE,
            'types'   => [
                RegistreFaitsProtocole::TYPE_NOMBRE,
                RegistreFaitsProtocole::TYPE_TEXTE,
                RegistreFaitsProtocole::TYPE_BOOLEEN,
            ],
            'libelle' => 'est égal à',
        ],
        '!=' => [
            'arite'   => self::ARITE_SIMPLE,
            'types'   => [
                RegistreFaitsProtocole::TYPE_NOMBRE,
                RegistreFaitsProtocole::TYPE_TEXTE,
                RegistreFaitsProtocole::TYPE_BOOLEEN,
            ],
            'libelle' => 'est différent de',
        ],
        '<' => [
            'arite'   => self::ARITE_SIMPLE,
            'types'   => [RegistreFaitsProtocole::TYPE_NOMBRE],
            'libelle' => 'est inférieur à',
        ],
        '<=' => [
            'arite'   => self::ARITE_SIMPLE,
            'types'   => [RegistreFaitsProtocole::TYPE_NOMBRE],
            'libelle' => 'est inférieur ou égal à',
        ],
        '>' => [
            'arite'   => self::ARITE_SIMPLE,
            'types'   => [RegistreFaitsProtocole::TYPE_NOMBRE],
            'libelle' => 'est supérieur à',
        ],
        '>=' => [
            'arite'   => self::ARITE_SIMPLE,
            'types'   => [RegistreFaitsProtocole::TYPE_NOMBRE],
            'libelle' => 'est supérieur ou égal à',
        ],

        // Bornes INCLUSIVES des deux côtés. Dit ici parce que c'est cet opérateur qui porte les
        // bandes de niveau du triage : « entre 0 et 25 » puis « entre 26 et 50 » ne doivent ni se
        // recouvrir ni laisser de trou, et le contrôle qualité le vérifie sur cette convention.
        'entre' => [
            'arite'   => self::ARITE_INTERVALLE,
            'types'   => [RegistreFaitsProtocole::TYPE_NOMBRE],
            'libelle' => 'est compris entre',
        ],

        'contient' => [
            'arite'   => self::ARITE_SIMPLE,
            'types'   => [RegistreFaitsProtocole::TYPE_LISTE],
            'libelle' => 'contient',
        ],
        'ne_contient_pas' => [
            'arite'   => self::ARITE_SIMPLE,
            'types'   => [RegistreFaitsProtocole::TYPE_LISTE],
            'libelle' => 'ne contient pas',
        ],

        // ═══ `existe` / `absent` NE SONT PAS DES SYNONYMES DE VRAI / FAUX ═══
        //
        // Ils portent sur la CONNAISSANCE du fait, pas sur sa valeur. Un triage anonyme ne
        // renseigne pas toujours le sexe ni l'âge : « le sexe est absent » et « le sexe vaut M »
        // sont deux affirmations différentes, et les confondre ferait décider à la place du
        // patient — le raisonnement de `ReglesOrientation` (un sexe inconnu n'écarte rien) et des
        // trois silences de P7-D2.
        'existe' => [
            'arite'   => self::ARITE_AUCUNE,
            'types'   => [
                RegistreFaitsProtocole::TYPE_NOMBRE,
                RegistreFaitsProtocole::TYPE_TEXTE,
                RegistreFaitsProtocole::TYPE_BOOLEEN,
                RegistreFaitsProtocole::TYPE_LISTE,
            ],
            'libelle' => 'est renseigné',
        ],
        'absent' => [
            'arite'   => self::ARITE_AUCUNE,
            'types'   => [
                RegistreFaitsProtocole::TYPE_NOMBRE,
                RegistreFaitsProtocole::TYPE_TEXTE,
                RegistreFaitsProtocole::TYPE_BOOLEEN,
                RegistreFaitsProtocole::TYPE_LISTE,
            ],
            'libelle' => 'n\'est pas renseigné',
        ],
    ];

    public static function existe(string $operateur): bool
    {
        return isset(self::OPERATEURS[$operateur]);
    }

    public static function arite(string $operateur): ?int
    {
        return self::OPERATEURS[$operateur]['arite'] ?? null;
    }

    public static function libelle(string $operateur): string
    {
        return self::OPERATEURS[$operateur]['libelle'] ?? $operateur;
    }

    /** L'opérateur accepte-t-il un fait de ce type ? */
    public static function accepteType(string $operateur, string $typeFait): bool
    {
        return in_array($typeFait, self::OPERATEURS[$operateur]['types'] ?? [], true);
    }

    /** @return array<int, string> */
    public static function codes(): array
    {
        return array_keys(self::OPERATEURS);
    }
}
