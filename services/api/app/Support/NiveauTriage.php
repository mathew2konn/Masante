<?php

namespace App\Support;

/**
 * P10b-1 — Les niveaux de priorité du triage (CDC_05 §5.3), miroir PHP de `TriageNiveauPatient`
 * de `@masante/shared`.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * CE QUE LE G0 A TROUVÉ (constat W3) : LE VOCABULAIRE AVAIT DIVERGÉ, ET LA SOURCE UNIQUE DORMAIT
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 *   `@masante/shared` → `TriageNiveauPatient`      les 4 de CDC_05 §5.3   consommé par PERSONNE
 *   `@masante/shared` → `TriageNiveauHospitalier`  les 5 Manchester/ESI   consommé par PERSONNE
 *   `palette.json`    → `triage`                   les 9 couleurs, depuis P0   consommé par PERSONNE
 *   `apps/mobile/src/types/triage.ts`              3 valeurs, REDÉFINIES localement   tout le mobile
 *   `triages.niveau` (MySQL)                       3 valeurs, dans l'ENUM   tout le backend
 *
 * Trois faits en découlaient, et aucun n'était cosmétique : la règle « source unique » de CLAUDE.md
 * était enfreinte (famille du constat G-a de P6.4b, les communes d'Abidjan en dur, et de U5 de
 * P6.8b, les libellés de statut vaccinal) ; le projet rendait **trois** niveaux là où le corpus en
 * exige **quatre** ; et le design system avait peint neuf couleurs que rien n'important.
 *
 * **C'est la première clé dormante du projet dont la version endormie était la JUSTE.** Les
 * précédentes — `loinc`, les codes CIM, `numero_agrement`, `specialites_json`,
 * `maladies_probables_json`, `urgence.sos` — étaient vides ou fausses. Ici, la bonne réponse
 * attendait dans la source unique pendant que le code en appliquait une autre.
 *
 * ═══ LES VALEURS SONT EN MINUSCULES, ET C'EST UN CHOIX ═══
 *
 * `@masante/shared` porte des clés en majuscules et des valeurs en minuscules — exactement comme
 * `Role` (« valeurs = noms spatie côté backend »). La colonne `triages.niveau` porte déjà `leger`,
 * `modere`, `urgent` en minuscules depuis le Module 1 : y ajouter `FAIBLE` en majuscules ferait
 * cohabiter deux conventions dans **la même colonne**, et rendrait toute comparaison dépendante de
 * la collation — le défaut exact trouvé au G2 de P6.8c.
 *
 * ═══ LES TROIS VALEURS HÉRITÉES NE SONT PAS CONVERTIES ═══
 *
 * Un patient a lu « MODÉRÉ » sur son écran. Réécrire sa fiche en « RECOMMANDÉE » changerait ce
 * qu'il a réellement lu — un mensonge d'archive. Elles restent dans l'ENUM, elles restent
 * affichables, et **plus rien ne les produit**. Même énoncé honnête que `vaccinations.statut`
 * (P6.8b), les colonnes `cmu_*` (P6.8d) et `specialite_hint` (P10a).
 */
final class NiveauTriage
{
    /** 🟢 Surveillance à domicile, conseils généraux, orientation possible vers un pharmacien. */
    public const FAIBLE = 'faible';

    /** 🟡 Rendez-vous avec un généraliste ou un spécialiste. */
    public const RECOMMANDEE = 'recommandee';

    /** 🟠 Consultation dans les 24 heures. */
    public const RAPIDE = 'rapide';

    /** 🔴 Se rendre immédiatement aux urgences ou appeler les secours. */
    public const URGENCE = 'urgence';

    /**
     * Les quatre niveaux patient de CDC_05 §5.3, du moins urgent au plus urgent.
     *
     * L'ORDRE EST SIGNIFIANT : il sert au contrôle qualité pour dire qu'une bande de score en
     * recouvre une autre, et à l'écran pour classer. Il n'est jamais utilisé pour DÉDUIRE un
     * niveau — c'est le protocole qui décide, jamais un rang.
     *
     * @var array<int, string>
     */
    public const PATIENT = [
        self::FAIBLE,
        self::RECOMMANDEE,
        self::RAPIDE,
        self::URGENCE,
    ];

    /**
     * Les trois valeurs du Module 1, conservées pour l'historique. Voir l'en-tête.
     *
     * @var array<int, string>
     */
    public const HERITES = ['leger', 'modere', 'urgent'];

    /**
     * Libellés citoyens, tels que CDC_05 §5.3 les formule.
     *
     * Ils vivent ICI et non dans un écran : trois surfaces les afficheront (mobile, fiche de
     * triage, texte de partage), et *une phrase recopiée trois fois finit par diverger deux fois* —
     * précédent `MENTION_PROVENANCE` de P6.8d et de la mention obligatoire du §5.4 en P10a.
     *
     * @var array<string, string>
     */
    public const LIBELLES = [
        self::FAIBLE      => 'Faible priorité',
        self::RECOMMANDEE => 'Consultation recommandée',
        self::RAPIDE      => 'Consultation rapide',
        self::URGENCE     => 'Urgence',

        // Historique — plus rien ne les produit, tout doit encore savoir les lire.
        'leger'           => 'Léger',
        'modere'          => 'Modéré',
        'urgent'          => 'Urgent',
    ];

    public static function estValide(string $niveau): bool
    {
        return in_array($niveau, self::PATIENT, true);
    }

    public static function estHerite(string $niveau): bool
    {
        return in_array($niveau, self::HERITES, true);
    }

    public static function libelle(string $niveau): string
    {
        return self::LIBELLES[$niveau] ?? $niveau;
    }

    /** Toutes les valeurs acceptées par la colonne `triages.niveau`. */
    public static function toutes(): array
    {
        return array_merge(self::HERITES, self::PATIENT);
    }
}
