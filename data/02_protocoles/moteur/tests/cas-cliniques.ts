/**
 * Batterie de cas cliniques de référence — CDC_08 §12.
 *
 * Chaque cas décrit un patient et ce que le protocole national doit produire.
 * Ces attentes viennent du Guide national PNLP Côte d'Ivoire, mars 2022.
 * Elles doivent être relues par un médecin avant que le protocole passe à l'état ACTIF.
 */

import type { ContextePatient } from '../src/types.js';

export interface CasClinique {
  id: string;
  titre: string;
  contexte: ContextePatient;
  attendu: {
    classification?: string | null;
    regles?: string[];              // règles qui doivent se déclencher
    regles_absentes?: string[];     // règles qui ne doivent PAS se déclencher
    option_posologie?: string;      // code de l'option de traitement retenue
    dose?: string;                  // dose attendue pour ce poids
    contre_indications?: string[];
  };
}

const AUCUNE_GRAVITE: string[] = [];

export const CAS: CasClinique[] = [
  {
    id: 'CAS-01',
    titre: 'Enfant 3 ans, 14 kg, fièvre, TDR positif, sans signe de gravité',
    contexte: {
      age_mois: 36, poids_kg: 14, temperature_c: 38.2, fievre_ou_antecedent_fievre: true,
      resultat_tdr: 'POSITIF', resultat_goutte_epaisse: 'NON_REALISE', signes_gravite: AUCUNE_GRAVITE,
      grossesse: false, niveau_structure: 'ESPC', peut_avaler: true,
    },
    attendu: {
      classification: 'PALUDISME_SIMPLE_CONFIRME',
      regles: ['R03'], regles_absentes: ['R01'],
      option_posologie: 'AS_AQ', dose: '1 comprimé/jour',
    },
  },
  {
    id: 'CAS-02',
    titre: 'Enfant 2 ans, 11 kg, convulsions répétées et prostration',
    contexte: {
      age_mois: 24, poids_kg: 11, temperature_c: 39.5, fievre_ou_antecedent_fievre: true,
      resultat_tdr: 'POSITIF', signes_gravite: ['GRAV_CONVULSIONS', 'GRAV_PROSTRATION'],
      grossesse: false, niveau_structure: 'ESPC', peut_avaler: false,
    },
    attendu: {
      classification: 'PALUDISME_GRAVE_SUSPECT',
      regles: ['R01'], regles_absentes: ['R03'],
    },
  },
  {
    id: 'CAS-03',
    titre: 'Adulte 70 kg, TDR positif, paludisme simple',
    contexte: {
      age_mois: 384, poids_kg: 70, temperature_c: 38.8, fievre_ou_antecedent_fievre: true,
      resultat_tdr: 'POSITIF', signes_gravite: AUCUNE_GRAVITE,
      grossesse: false, niveau_structure: 'ESPC', peut_avaler: true,
    },
    attendu: {
      classification: 'PALUDISME_SIMPLE_CONFIRME',
      regles: ['R03', 'R09'], option_posologie: 'AS_AQ', dose: '2 comprimés/jour',
    },
  },
  {
    id: 'CAS-04',
    titre: 'Femme enceinte 1er trimestre, 58 kg, TDR positif — CTA contre-indiquées',
    contexte: {
      age_mois: 336, poids_kg: 58, temperature_c: 38.4, fievre_ou_antecedent_fievre: true,
      resultat_tdr: 'POSITIF', signes_gravite: AUCUNE_GRAVITE,
      grossesse: true, trimestre_grossesse: 'T1', semaines_amenorrhee: 9,
      niveau_structure: 'ESPC', peut_avaler: true, sous_cotrimoxazole: false,
    },
    attendu: {
      classification: 'PALUDISME_SIMPLE_CONFIRME',
      regles: ['R03', 'R05'],
      option_posologie: 'TRT_QUININE_ORALE_5J', dose: '2 comprimés × 3/jour',
    },
  },
  {
    id: 'CAS-05',
    titre: 'Femme enceinte 20 SA sous cotrimoxazole — SP contre-indiquée',
    contexte: {
      age_mois: 300, poids_kg: 62, fievre_ou_antecedent_fievre: false,
      resultat_tdr: 'NEGATIF', signes_gravite: AUCUNE_GRAVITE,
      grossesse: true, trimestre_grossesse: 'T2', semaines_amenorrhee: 20,
      niveau_structure: 'ESPC', peut_avaler: true, sous_cotrimoxazole: true,
    },
    attendu: {
      regles: ['R07'], regles_absentes: ['R06'],
      contre_indications: ['SULFADOXINE_PYRIMETHAMINE'],
    },
  },
  {
    id: 'CAS-06',
    titre: 'Femme enceinte 18 SA, sans cotrimoxazole — TPIg indiqué',
    contexte: {
      age_mois: 288, poids_kg: 60, fievre_ou_antecedent_fievre: false,
      resultat_tdr: 'NEGATIF', signes_gravite: AUCUNE_GRAVITE,
      grossesse: true, trimestre_grossesse: 'T2', semaines_amenorrhee: 18,
      niveau_structure: 'ESPC', peut_avaler: true, sous_cotrimoxazole: false,
    },
    attendu: { regles: ['R06'], regles_absentes: ['R07'] },
  },
  {
    id: 'CAS-07',
    titre: 'Patient VIH sous éfavirenz, TDR positif — éviter AS+AQ',
    contexte: {
      age_mois: 420, poids_kg: 65, temperature_c: 38.1, fievre_ou_antecedent_fievre: true,
      resultat_tdr: 'POSITIF', signes_gravite: AUCUNE_GRAVITE,
      grossesse: false, niveau_structure: 'ESPC', peut_avaler: true,
      traitement_arv: ['TENOFOVIR', 'LAMIVUDINE', 'EFAVIRENZ'],
    },
    attendu: {
      classification: 'PALUDISME_SIMPLE_CONFIRME',
      regles: ['R03', 'R08'],
      contre_indications: ['ARTESUNATE_AMODIAQUINE'],
      option_posologie: 'AL', dose: '4 comprimés par prise',
    },
  },
  {
    id: 'CAS-08',
    titre: 'Fièvre sans test réalisé — confirmation parasitologique obligatoire',
    contexte: {
      age_mois: 60, poids_kg: 18, temperature_c: 38.6, fievre_ou_antecedent_fievre: true,
      resultat_tdr: 'NON_REALISE', resultat_goutte_epaisse: 'NON_REALISE',
      signes_gravite: AUCUNE_GRAVITE, grossesse: false, niveau_structure: 'ESPC', peut_avaler: true,
    },
    attendu: { classification: null, regles: ['R02'], regles_absentes: ['R03'] },
  },
  {
    id: 'CAS-09',
    titre: 'TDR négatif sans gravité — chercher une autre cause de fièvre',
    contexte: {
      age_mois: 48, poids_kg: 16, temperature_c: 38.9, fievre_ou_antecedent_fievre: true,
      resultat_tdr: 'NEGATIF', signes_gravite: AUCUNE_GRAVITE,
      grossesse: false, niveau_structure: 'ESPC', peut_avaler: true,
    },
    attendu: { classification: 'PALUDISME_ECARTE', regles: ['R04', 'R09'], regles_absentes: ['R03'] },
  },
  {
    id: 'CAS-10',
    titre: 'Échec thérapeutique à J3, bonne observance, microscopie positive',
    contexte: {
      age_mois: 240, poids_kg: 55, temperature_c: 38.7, fievre_ou_antecedent_fievre: true,
      resultat_tdr: 'POSITIF', resultat_goutte_epaisse: 'POSITIF', signes_gravite: AUCUNE_GRAVITE,
      grossesse: false, niveau_structure: 'STRUCTURE_REFERENCE', peut_avaler: true,
      parasitemie_persistante_j3: true, observance: 'BONNE', jours_depuis_debut: 5,
    },
    attendu: { regles: ['R10'], regles_absentes: ['R03'] },
  },
  {
    id: 'CAS-11',
    titre: 'Nourrisson 4 kg, TDR positif — sous le plancher des tables de posologie',
    contexte: {
      age_mois: 2, poids_kg: 4, temperature_c: 38.3, fievre_ou_antecedent_fievre: true,
      resultat_tdr: 'POSITIF', signes_gravite: AUCUNE_GRAVITE,
      grossesse: false, niveau_structure: 'ESPC', peut_avaler: true,
    },
    attendu: { classification: 'PALUDISME_SIMPLE_CONFIRME', regles: ['R03', 'R11'] },
  },
  {
    id: 'CAS-12',
    titre: 'Agent de santé communautaire, signes de gravité — référence immédiate',
    contexte: {
      age_mois: 30, poids_kg: 12, temperature_c: 39.8, fievre_ou_antecedent_fievre: true,
      resultat_tdr: 'NON_REALISE', signes_gravite: ['GRAV_CONSCIENCE'],
      grossesse: false, niveau_structure: 'COMMUNAUTE_ASC', peut_avaler: false,
    },
    attendu: {
      classification: 'PALUDISME_GRAVE_SUSPECT',
      regles: ['R01'], regles_absentes: ['R02', 'R03'],
    },
  },
];
