<?php

namespace App\Services;

use App\Models\StructureSanitaire;

/**
 * Résout un `etablissementRef` du canal paiement (B4, ADR-056, S1) — `{pays_code}-{identifiant}`,
 * par exemple `CI-ETS000001` — vers l'identifiant LOCAL de `structures_sanitaires`.
 *
 * Jamais l'inverse : le microservice de paiement ne connaît QUE l'identifiant national préfixé du
 * pays, jamais l'id technique Laravel — « un identifiant technique ne veut rien dire hors de cette
 * base » (argument déjà opposé à `symptome_id`, P10b-3-i). Le préfixe pays est OBLIGATOIRE :
 * l'unicité de `identifiant_national` est `(pays_code, identifiant)` (P6.4a), donc deux
 * établissements de deux pays peuvent partager le même `identifiant_national` — sans le pays, la
 * résolution serait ambiguë.
 *
 * Ne devine jamais : une référence malformée ou introuvable rend `null`, jamais une structure au
 * hasard.
 */
class ResolveurEtablissementRef
{
    public function resoudre(?string $etablissementRef): ?int
    {
        if ($etablissementRef === null || $etablissementRef === '') {
            return null;
        }

        [$paysCode, $identifiant] = array_pad(explode('-', $etablissementRef, 2), 2, null);
        if ($paysCode === null || $identifiant === null || $identifiant === '') {
            return null;
        }

        return StructureSanitaire::query()
            ->where('pays_code', $paysCode)
            ->where('identifiant_national', $identifiant)
            ->value('id');
    }

    /**
     * Le sens INVERSE (B4-b) : construit `{pays_code}-{identifiant}` depuis une structure, pour
     * appeler le canal paiement en tant qu'ÉMETTEUR. Paire naturelle de {@see resoudre()} — pas un
     * second endroit où le format serait écrit.
     *
     * `null` si `identifiant_national` est vide : jamais un format à moitié rempli envoyé au
     * microservice (le backfill de P6.4a est un prérequis dur, S1 d'ADR-056).
     */
    public function formater(StructureSanitaire $structure): ?string
    {
        if ($structure->identifiant_national === null || $structure->identifiant_national === ''
            || $structure->pays_code === null || $structure->pays_code === '') {
            return null;
        }

        return $structure->pays_code.'-'.$structure->identifiant_national;
    }
}
