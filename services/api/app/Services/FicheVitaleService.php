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
     * ═══ P6.8c — LE CODE NATIONAL EST JOINT, IL NE REMPLACE RIEN ═══
     *
     * `libelle` reste ce que le patient a écrit, mot pour mot. Le rattachement au référentiel des
     * maladies s'ajoute À CÔTÉ : « diabète », « Diabète type 2 » et « DT2 » sont trois chaînes, un
     * code national n'en est qu'un.
     *
     * *Un champ de plus sur un écran lu sans authentification se justifie ici parce qu'il ne révèle
     * rien de neuf* : c'est la normalisation de ce qui s'affiche déjà juste au-dessus. Et il vaut
     * `null` tant que personne n'a déclaré de lien — le serveur ne devine aucune maladie (CDC_00 §4).
     *
     * @return array<array{libelle: string, traitement: string|null, code_national: ?string, libelle_reference: ?string}>
     */
    private function maladiesChroniques(MembreFamille $membre): array
    {
        return $membre->antecedents
            ->where('type', 'maladie_chronique')
            ->map(fn (Antecedent $a) => [
                'libelle'           => $a->description,
                'traitement'        => $a->traitement_actuel,
                'code_national'     => $a->maladie_code,
                'libelle_reference' => $a->maladie_libelle,
            ])
            ->values()
            ->all();
    }

    /**
     * Vaccinations EFFECTUÉES, avec ce qui les atteste (P6.8b — décision propriétaire W2-bis).
     *
     * ═══ CE QUE CE FILTRE DISAIT AVANT, ET POURQUOI C'ÉTAIT FAUX ═══
     *
     * Il retenait `obligatoire = true` ET `statut = 'fait'` — c'est-à-dire **les deux seules
     * colonnes de la table que le client déclarait librement**, et elles n'étaient lues nulle part
     * ailleurs dans le projet. Cet écran est pourtant montré à un secouriste SANS authentification,
     * sous un bloc « Vaccinations essentielles » et une icône de bouclier coché.
     *
     * Conséquence en deux sens : n'importe qui cochant les deux cases faisait apparaître une
     * vaccination **présentée comme attestée** ; et un BCG réellement administré, saisi sans cocher
     * « obligatoire », en était **absent**. *Le bouclier coché couvrait une case cochée par
     * l'intéressé lui-même.*
     *
     * ═══ CE SUR QUOI IL S'APPUIE MAINTENANT ═══
     *
     * Sur un signal que **le serveur garantit et que le client ne peut pas falsifier** : `source`,
     * écrite depuis P7-D0 par les trois chemins d'écriture (contribution → `patient`, écriture
     * soignant → `medecin`). Il existait déjà sur cette table, et cet écran ne s'en servait pas.
     *
     * Le critère de sélection devient donc le seul fait qui compte en urgence — **la dose a-t-elle
     * été administrée ?** —, et l'attestation devient une information JOINTE plutôt qu'un filtre :
     * rien ne disparaît de l'écran, et ce qui s'y affiche cesse de prétendre plus qu'il ne sait.
     *
     * `statut` reste consulté, mais il est désormais CALCULÉ ({@see App\Models\Vaccination::statut}) :
     * il ne peut plus être déclaré. `date_administration` suffirait, et la garder rend le critère
     * lisible ; les deux disent la même chose et ne peuvent pas diverger.
     *
     * @return array<array{vaccin: string, date: string|null, atteste: bool, code_national: ?string}>
     */
    private function vaccinationsEssentielles(MembreFamille $membre): array
    {
        return $membre->vaccinations
            ->where('statut', 'fait')
            ->map(fn ($v) => [
                'vaccin' => $v->vaccin_nom,
                'date'   => $v->date_administration?->toDateString(),
                // Vrai UNIQUEMENT si un soignant ou une structure l'a consigné. Le secouriste doit
                // pouvoir distinguer ce qu'un professionnel a écrit de ce qu'une famille a recopié
                // — les deux sont utiles, ils n'ont pas le même poids.
                'atteste' => in_array($v->source, ['medecin', 'structure'], true),
                // Rattaché au référentiel national ou non : « BCG », « bcg » et « B.C.G. » sont
                // trois chaînes, un code national n'en est qu'un (P6.8b).
                'code_national' => $v->vaccin_code,
            ])
            ->sortByDesc('atteste')
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
