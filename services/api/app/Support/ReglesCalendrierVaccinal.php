<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * P6.8b — Ce que le calendrier vaccinal national dit, pour une personne, à une date (CDC_09 §8).
 *
 * ═══ CLASSE PURE ═══
 *
 * Aucune base, aucune requête, et surtout **aucun appel à `now()`** : la date du jour est un
 * paramètre. C'est ce qui rend vérifiable le vecteur central du module — « une ligne saisie il y a
 * un an répond `en_retard` aujourd'hui sans qu'aucune écriture ait eu lieu » — sans avoir à
 * manipuler l'horloge du système. Même motif que `ReglesReversement` (P5.5a) et
 * `ReglesIntervalleReference` (P6.7a).
 *
 * ═══ CE QUE CETTE CLASSE NE FAIT PAS, ET C'EST LA FRONTIÈRE ═══
 *
 * Elle dit ce que le CALENDRIER prévoit à un âge donné. Elle ne dit **jamais** si une personne doit
 * être vaccinée : une contre-indication, une immunodépression, une allergie connue, une vaccination
 * faite à l'étranger et non reportée sont des éléments cliniques qu'elle ignore et qu'elle n'a pas à
 * arbitrer. Un calendrier est un plan, pas une prescription (CDC_00 §4 — aucune règle médicale en
 * dur, aucune machine qui décide).
 *
 * C'est aussi pourquoi les seuils qu'elle applique n'existent pas dans ce fichier : l'âge dû, le
 * délai de grâce et la borne de rattrapage sont des **colonnes de `calendrier_vaccinal`**, donc des
 * données publiées sous gouvernance §10. Écrire « en retard après trente jours » ici ferait d'un
 * développeur l'auteur d'une règle de santé publique.
 *
 * ═══ POURQUOI DEUX VOCABULAIRES ═══
 *
 * Une LIGNE DU CARNET et une ÉCHÉANCE DU CALENDRIER ne sont pas la même chose, et les confondre
 * produirait des réponses fausses dans les deux sens.
 *
 *  - Une **ligne** est un fait inscrit : elle vaut `fait`, `a_faire` ou `en_retard` — les trois
 *    valeurs de l'ENUM `vaccinations.statut`, inchangé (ADR-024, additif).
 *  - Une **échéance** est une prévision : elle peut en plus être `a_venir` (l'enfant est trop jeune,
 *    ce n'est pas un retard) ou `hors_delai` (la fenêtre de rattrapage publiée est passée).
 *
 * Ranger `a_venir` parmi les statuts d'une ligne aurait fait apparaître comme « en attente » des
 * vaccinations qui ne concernent pas encore l'enfant ; l'omettre du calendrier aurait affiché
 * « en retard » à un nourrisson de cinq semaines pour une échéance à six.
 */
final class ReglesCalendrierVaccinal
{
    /** Statuts d'une LIGNE du carnet — miroir exact de l'ENUM `vaccinations.statut`. */
    public const FAIT      = 'fait';
    public const A_FAIRE   = 'a_faire';
    public const EN_RETARD = 'en_retard';

    /** Statuts SUPPLÉMENTAIRES d'une échéance du calendrier. Jamais écrits en base. */
    public const A_VENIR    = 'a_venir';
    public const HORS_DELAI = 'hors_delai';

    /**
     * Le statut d'une LIGNE du carnet de vaccination.
     *
     * Ordre délibéré : **l'administration l'emporte sur tout le reste**. Une dose administrée est un
     * fait ; sa date d'échéance, dépassée ou non, ne le remet pas en cause. Tester l'échéance
     * d'abord ferait afficher « en retard » sur une vaccination faite avec deux semaines de décalage
     * — ce qui accuserait un parent d'un manquement inexistant.
     *
     * Sans échéance connue (ni rappel saisi, ni lien au calendrier), on répond `a_faire` : c'est une
     * intention non datée, et la présenter comme un retard serait affirmer un fait qu'on ignore.
     *
     * @param  CarbonInterface|null  $echeance  voir {@see echeanceDeLaLigne}
     */
    public static function statutLigne(
        ?CarbonInterface $dateAdministration,
        ?CarbonInterface $echeance,
        CarbonInterface $aujourdhui,
        int $toleranceJours = 0,
    ): string {
        if ($dateAdministration !== null) {
            return self::FAIT;
        }

        if ($echeance === null) {
            return self::A_FAIRE;
        }

        $limite = $echeance->addDays(max(0, $toleranceJours));

        return $aujourdhui->startOfDay()->greaterThan($limite->startOfDay())
            ? self::EN_RETARD
            : self::A_FAIRE;
    }

    /**
     * La date à laquelle une ligne du carnet devient exigible.
     *
     * DEUX SOURCES, ET L'ORDRE COMPTE. La date de rappel saisie sur la ligne prime sur le calendrier
     * national : elle vient d'un carnet papier ou d'un soignant qui a vu le patient, et elle tient
     * compte de ce que le calendrier ignore (une dose reçue en retard décale les suivantes). Faire
     * primer le calendrier écraserait une information plus précise par une information plus générale.
     *
     * À défaut, l'échéance se déduit de la naissance et de l'âge dû publié — ce qui n'est possible
     * que si la ligne est **rattachée** au référentiel. Sinon : aucune échéance, et on le dit en
     * renvoyant `null` plutôt qu'en inventant une date.
     */
    public static function echeanceDeLaLigne(
        ?CarbonInterface $dateRappel,
        ?CarbonInterface $dateNaissance,
        ?int $ageJoursDu,
    ): ?CarbonImmutable {
        if ($dateRappel !== null) {
            return CarbonImmutable::parse($dateRappel)->startOfDay();
        }

        if ($dateNaissance === null || $ageJoursDu === null) {
            return null;
        }

        return CarbonImmutable::parse($dateNaissance)->startOfDay()->addDays($ageJoursDu);
    }

    /**
     * Le statut d'une ÉCHÉANCE du calendrier pour une personne d'un âge donné.
     *
     * Ordre délibéré, et chaque marche répond à une question différente :
     *   1. **est-elle faite ?** — un fait inscrit clôt la question, quel que soit l'âge ;
     *   2. **est-elle encore à venir ?** — un enfant trop jeune n'est en retard de rien ;
     *   3. **la fenêtre de rattrapage publiée est-elle passée ?** — vérifiée AVANT le retard, sans
     *      quoi une échéance définitivement dépassée serait présentée comme rattrapable, et un
     *      parent courrait après un rendez-vous que le calendrier ne prévoit plus ;
     *   4. **le délai de grâce est-il écoulé ?**
     *
     * @param  int|null  $ageJoursLimite  borne de rattrapage publiée ; `null` = borne ouverte
     */
    public static function statutEcheance(
        bool $faite,
        int $ageJoursMembre,
        int $ageJoursDu,
        int $toleranceJours = 0,
        ?int $ageJoursLimite = null,
    ): string {
        if ($faite) {
            return self::FAIT;
        }

        if ($ageJoursMembre < $ageJoursDu) {
            return self::A_VENIR;
        }

        if ($ageJoursLimite !== null && $ageJoursMembre > $ageJoursLimite) {
            return self::HORS_DELAI;
        }

        return $ageJoursMembre > $ageJoursDu + max(0, $toleranceJours)
            ? self::EN_RETARD
            : self::A_FAIRE;
    }

    /**
     * L'âge en jours à une date donnée. `null` si la date de naissance est inconnue.
     *
     * EN JOURS, jamais en mois : le calendrier exprime « six semaines », que les mois ne savent pas
     * dire. Et l'absence de date de naissance renvoie `null` — un âge supposé produirait un
     * calendrier entier de fausses échéances pour une personne dont on ne sait rien.
     */
    public static function ageEnJours(?CarbonInterface $naissance, CarbonInterface $aujourdhui): ?int
    {
        if ($naissance === null) {
            return null;
        }

        $jours = CarbonImmutable::parse($naissance)->startOfDay()
            ->diffInDays(CarbonImmutable::parse($aujourdhui)->startOfDay(), false);

        // Une date de naissance future est une saisie fautive, pas un âge négatif : on la traite
        // comme « le premier jour », ce qui rend toutes les échéances `a_venir` — la réponse la
        // moins fausse possible, et sûrement pas un retard.
        return (int) max(0, $jours);
    }
}
