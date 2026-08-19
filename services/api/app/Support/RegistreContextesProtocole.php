<?php

namespace App\Support;

/**
 * P10b-2 — Liste blanche FERMÉE des contextes d'évaluation (CDC_08 §9.1).
 *
 * Le contrat du §9.1 porte `"contexte": "triage|consultation|urgence"`. C'est la situation de
 * l'APPELANT — pas une propriété du patient, pas un domaine médical.
 *
 * ═══ POURQUOI UNE LISTE FERMÉE, ET POURQUOI LE PROTOCOLE LA DÉCLARE ═══
 *
 * Le contexte arrive par la requête, donc par l'extérieur. Sans liste fermée, `contexte=<n'importe
 * quoi>` traverserait le sélecteur jusqu'à une comparaison SQL — la même raison qui a fermé les
 * faits, les opérateurs et les actions en P10b-1.
 *
 * Et c'est le PROTOCOLE qui déclare les contextes où il s'applique (`protocoles.contextes_json`),
 * jamais une table de correspondance `contexte → domaines` écrite en PHP : celle-ci serait une
 * règle en dur de plus, à corriger par un déploiement, alors que le §1.2 veut ces décisions en
 * base. Ajouter un contexte à un protocole doit être une **publication**, pas une livraison.
 */
final class RegistreContextesProtocole
{
    /** Auto-évaluation du citoyen (CDC_05 §5) — le seul consommateur réel aujourd'hui. */
    public const TRIAGE = 'triage';

    /** Consultation menée par un professionnel. Aucun écran ne l'émet encore (limite N6). */
    public const CONSULTATION = 'consultation';

    /** Prise en charge en urgence. Aucun écran ne l'émet encore. */
    public const URGENCE = 'urgence';

    /** @var array<string, string> code => libellé lisible */
    public const CONTEXTES = [
        self::TRIAGE       => 'Triage / auto-évaluation',
        self::CONSULTATION => 'Consultation',
        self::URGENCE      => 'Urgence',
    ];

    public static function existe(string $contexte): bool
    {
        return isset(self::CONTEXTES[$contexte]);
    }

    public static function libelle(string $contexte): string
    {
        return self::CONTEXTES[$contexte] ?? $contexte;
    }

    /** @return array<int, string> */
    public static function codes(): array
    {
        return array_keys(self::CONTEXTES);
    }

    /**
     * Ne garde que les contextes connus d'une liste venue de la base.
     *
     * Un contexte inconnu est IGNORÉ plutôt que fatal : il vient d'un instantané publié, donc d'un
     * contenu déjà relu et signé. Le refuser rendrait un protocole en vigueur inapplicable après
     * un simple renommage de constante — le contrôle qualité, lui, refuse à la PUBLICATION.
     *
     * @param  mixed  $valeurs
     * @return array<int, string>
     */
    public static function filtrer(mixed $valeurs): array
    {
        if (! is_array($valeurs)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $v): string => is_string($v) ? $v : '', $valeurs),
            static fn (string $v): bool => self::existe($v),
        ));
    }
}
