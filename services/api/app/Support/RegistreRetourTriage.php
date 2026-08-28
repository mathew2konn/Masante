<?php

namespace App\Support;

/**
 * P10c-2-i — Ce qu'un soignant peut dire de l'orientation rendue par un triage (CDC_05 §5.5.4,
 * §9.1 ; CDC_08 §10 ; ADR-044).
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * POURQUOI CE VOCABULAIRE-LÀ, ET PAS UN DIAGNOSTIC
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * Le §5.5.4 veut « le diagnostic final posé par le médecin et le traitement prescrit ». Ce projet
 * ne peut pas encore porter le premier : il n'a **aucune entité consultation ni diagnostic
 * d'épisode** (constat Y7 du G0). Le seul endroit où une maladie codée se pose aujourd'hui est un
 * ANTÉCÉDENT — or `antecedents` porte aussi `impact_triage`, qui alimente le score des triages
 * suivants : y consigner chaque grippe la transformerait en antécédent permanent pesant sur toutes
 * les orientations futures. On dégraderait l'orientation qu'on cherche à améliorer.
 *
 * Le diagnostic codé a un porteur nommé : le **Module 8 (Espace Médecin)** de CDC_01 §17, adossé à
 * l'étape 7 de CDC_04 §12 (« Dossier médical : consultations, diagnostics, observations »).
 * *Nommer un manque ne le comble pas, mais un manque nommé ne s'oublie pas.*
 *
 * Ce que le médecin PEUT dire dès aujourd'hui, sans consultation structurée et sans suivi à 48 h,
 * c'est ce qui intéresse précisément le triage : **l'orientation était-elle juste ?** C'est un
 * jugement porté sur une décision de machine — la supervision humaine du §9.1 — et il a la
 * propriété qui manquait à tout ce module : il n'est **pas dérivable des bandes du protocole**,
 * donc il échappe à la tautologie du constat A3.
 *
 * ═══ LES TROIS VALEURS NE SONT PAS SYMÉTRIQUES, ET LE MODÈLE DEVRA LE SAVOIR ═══
 *
 * Un sur-triage coûte une place aux urgences. Un **sous-triage** peut coûter la vie. Ce sont deux
 * erreurs de sens opposé et de gravité incomparable ; les traiter comme deux faces d'une même pièce
 * conduirait un futur modèle à les compenser l'une par l'autre. La distinction est portée ici, en
 * donnée, pour qu'aucun apprentissage ne puisse l'ignorer.
 *
 * ═══ CE QUE CETTE CLASSE N'EST PAS ═══
 *
 * Ni une échelle clinique, ni une évaluation du médecin, ni une note de performance du service.
 * C'est une appréciation déclarée, et elle vaut ce que vaut celui qui la pose — ce qui est écrit
 * dans les limites du G5 plutôt que déguisé en mesure.
 *
 * ═══ LISTE BLANCHE FERMÉE ═══
 *
 * Même motif que `RegistreActionsProtocole` et `RegistreFaitsProtocole` : la valeur arrive par un
 * formulaire, donc par le client. Sans liste fermée, `decision_finale` — qui est une colonne
 * `string(200)` **entrant dans l'empreinte** de la chaîne d'audit du §10 — accepterait n'importe
 * quel texte, et le jeu d'apprentissage se peuplerait d'étiquettes libres incomparables entre elles
 * (le défaut exact que P6.8a a refermé pour les spécialités).
 */
final class RegistreRetourTriage
{
    /** L'orientation correspondait à l'état réel du patient. */
    public const ADAPTEE = 'adaptee';

    /** Le triage a orienté TROP HAUT (urgence pour ce qui relevait d'une consultation). */
    public const SUR_TRIAGE = 'sur_triage';

    /** Le triage a orienté TROP BAS. La seule des trois qui expose le patient. */
    public const SOUS_TRIAGE = 'sous_triage';

    /**
     * Les valeurs admises et leur libellé, tel qu'un soignant le lit à l'écran.
     *
     * @var array<string, string>
     */
    public const RETOURS = [
        self::ADAPTEE => 'Orientation adaptée',
        self::SUR_TRIAGE => 'Orientation trop élevée (sur-triage)',
        self::SOUS_TRIAGE => 'Orientation trop basse (sous-triage)',
    ];

    /**
     * Les retours qui signalent un ÉCART, et pour lesquels la justification est exigée.
     *
     * Le §10 nomme `ecart_justification` : une divergence sans motif serait un signal qu'on ne peut
     * ni comprendre ni corriger. Exiger le motif sur « adaptée » n'aurait en revanche aucun sens —
     * on ne justifie pas un accord.
     *
     * Précédent : le motif de scellement d'ADR-042 et la commission de P5.5a, **obligatoires et
     * sans valeur par défaut** — un champ à remplir qu'on peut laisser vide finit toujours vide.
     *
     * @var array<int, string>
     */
    public const ECARTS = [self::SUR_TRIAGE, self::SOUS_TRIAGE];

    public static function existe(string $retour): bool
    {
        return array_key_exists($retour, self::RETOURS);
    }

    public static function libelle(string $retour): ?string
    {
        return self::RETOURS[$retour] ?? null;
    }

    /** Ce retour signale-t-il un écart entre l'orientation rendue et l'état réel ? */
    public static function estUnEcart(string $retour): bool
    {
        return in_array($retour, self::ECARTS, true);
    }

    /** @return array<int, string> */
    public static function valeurs(): array
    {
        return array_keys(self::RETOURS);
    }
}
