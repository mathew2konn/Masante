<?php

namespace App\Services;

use App\Models\Antecedent;
use App\Models\MembreFamille;

/**
 * Module 5 / 5.1 — Fiche vitale d'urgence (CdC FN2 ; Note_Continuite §5.2).
 *
 * SOURCE UNIQUE du « sous-ensemble vital minimal » d'un membre. Trois usages s'appuient dessus,
 * et doivent voir exactement le même périmètre :
 *   - FN2 carte vitale affichée au secouriste (5.1) ;
 *   - FN1 bouton SOS, corps du SMS envoyé au contact d'urgence (5.2) ;
 *   - voie 4 « bris de glace » consultée par un service d'urgences (5.3).
 *
 * PÉRIMÈTRE STRICT (Note_Continuite §5.2). Inclus : identité, âge, sexe, groupe sanguin, allergies,
 * maladies chroniques et traitements en cours, vaccinations essentielles, contacts d'urgence.
 * Exclus : historique des consultations, documents importés (F2.10), notes médicales libres (F2.12),
 * suivi de grossesse détaillé, données des autres membres. Le matricule interne et le numéro CMU
 * ne sortent jamais (`$hidden` sur le modèle) : une fiche vitale sert à soigner, pas à identifier
 * administrativement.
 *
 * Ces données sont destinées à être lues SANS authentification, par un secouriste qui tient le
 * téléphone : d'où la sévérité du périmètre. Tout champ ajouté ici est un champ exposé au premier
 * venu qui ramasse un téléphone.
 */
class FicheVitaleService
{
    /**
     * Construit la fiche vitale d'un membre.
     *
     * @return array<string, mixed>
     */
    public function pour(MembreFamille $membre): array
    {
        $membre->loadMissing(['antecedents', 'vaccinations', 'contactsUrgence']);

        return [
            'membre_id'      => $membre->id,
            'nom'            => $membre->nom,
            'prenom'         => $membre->prenom,
            'age'            => $this->age($membre),
            'sexe'           => $membre->sexe,
            'groupe_sanguin' => $membre->groupe_sanguin,
            'allergies'      => $this->allergies($membre),
            'maladies_chroniques' => $this->maladiesChroniques($membre),
            'vaccinations_essentielles' => $this->vaccinationsEssentielles($membre),
            'contacts_urgence' => $this->contactsUrgence($membre),
            'genere_le'      => now()->toIso8601String(),
        ];
    }

    /**
     * Résumé d'une ligne, destiné au corps d'un SMS d'urgence (FN1) : le message doit tenir
     * dans un SMS et rester lisible d'un coup d'œil par un secouriste.
     */
    public function resume(MembreFamille $membre): string
    {
        $fiche = $this->pour($membre);

        $parties = [
            trim($fiche['prenom'].' '.$fiche['nom']),
            $fiche['age'] !== null ? $fiche['age'].' ans' : null,
            $fiche['groupe_sanguin'],
            $fiche['allergies'] !== [] ? 'Allergies: '.implode(', ', $fiche['allergies']) : null,
            $fiche['maladies_chroniques'] !== []
                ? 'Chroniques: '.implode(', ', array_column($fiche['maladies_chroniques'], 'libelle'))
                : null,
        ];

        return implode(' | ', array_filter($parties));
    }

    /** Âge révolu, ou null si la date de naissance n'est pas renseignée. */
    private function age(MembreFamille $membre): ?int
    {
        return $membre->date_naissance?->age;
    }

    /** @return array<string> */
    private function allergies(MembreFamille $membre): array
    {
        return $membre->antecedents
            ->where('type', 'allergie')
            ->pluck('description')
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Maladies chroniques ET traitement en cours : un secouriste doit savoir ce que le patient
     * prend déjà (interactions médicamenteuses).
     *
     * @return array<array{libelle: string, traitement: string|null}>
     */
    private function maladiesChroniques(MembreFamille $membre): array
    {
        return $membre->antecedents
            ->where('type', 'maladie_chronique')
            ->map(fn (Antecedent $a) => [
                'libelle'    => $a->description,
                'traitement' => $a->traitement_actuel,
            ])
            ->values()
            ->all();
    }

    /**
     * Vaccinations ESSENTIELLES uniquement : celles marquées obligatoires et effectivement faites.
     * Le carnet vaccinal complet ne relève pas de l'urgence.
     *
     * @return array<array{vaccin: string, date: string|null}>
     */
    private function vaccinationsEssentielles(MembreFamille $membre): array
    {
        return $membre->vaccinations
            ->where('obligatoire', true)
            ->where('statut', 'fait')
            ->map(fn ($v) => [
                'vaccin' => $v->vaccin_nom,
                'date'   => $v->date_administration?->toDateString(),
            ])
            ->values()
            ->all();
    }

    /** @return array<array{nom: string, lien: string, telephone: string, principal: bool}> */
    private function contactsUrgence(MembreFamille $membre): array
    {
        return $membre->contactsUrgence
            ->sortByDesc('est_principal')
            ->map(fn ($c) => [
                'nom'       => $c->nom,
                'lien'      => $c->lien_parente,
                'telephone' => $c->telephone,
                'principal' => (bool) $c->est_principal,
            ])
            ->values()
            ->all();
    }
}
