/**
 * Sélection de la posologie par tranche de poids, et validation des tables.
 *
 * Convention retenue : une tranche [poids_min, poids_max[ est fermée à gauche, ouverte à droite.
 * C'est la convention des guides nationaux (« 9–18 kg » puis « 18–36 kg » : 18 kg appartient à la
 * seconde tranche). poids_max = null signifie « et au-delà ».
 */

import type { PosologieResolue, TranchePosologie } from './types.js';

export function trancheApplicable(tranches: TranchePosologie[], poidsKg: number): TranchePosologie | null {
  for (const t of tranches) {
    const min = t.poids_min ?? 0;
    const max = t.poids_max ?? Infinity;
    if (poidsKg >= min && poidsKg < max) return t;
  }
  return null;
}

export function libelleTranche(t: TranchePosologie): string {
  const min = t.poids_min ?? 0;
  const max = t.poids_max;
  const poids = max == null ? `≥ ${min} kg` : `${min}–${max} kg`;
  return t.age ? `${poids} (${t.age})` : poids;
}

export function resoudrePosologie(
  traitement: any,
  nomTraitement: string,
  poidsKg: number,
  optionsExclues: string[] = [],
): PosologieResolue | null {
  const options: any[] = traitement.options ?? [traitement];
  for (const opt of options) {
    if (opt.code && optionsExclues.includes(opt.code)) continue;
    const tranches: TranchePosologie[] = opt.posologie_par_poids ?? [];
    if (!tranches.length) continue;
    const t = trancheApplicable(tranches, poidsKg);
    if (!t) continue;
    return {
      traitement: nomTraitement,
      option_code: opt.code ?? nomTraitement,
      option_libelle: opt.libelle ?? nomTraitement,
      presentation: t.presentation ?? opt.presentation,
      dose: t.dose,
      prises_par_jour: t.prises_par_jour ?? opt.prises_par_jour,
      duree_jours: t.duree_jours ?? opt.duree_jours,
      tranche: libelleTranche(t),
    };
  }
  return null;
}

export interface AnomalieTable {
  traitement: string;
  option: string;
  type: 'TROU' | 'CHEVAUCHEMENT' | 'POIDS_MIN_NON_COUVERT';
  detail: string;
}

/**
 * Contrôle qualité des tables de posologie (CDC_08 §12 « tests des cas limites »).
 * Détecte les trous entre tranches, les chevauchements, et le poids plancher non couvert.
 */
export function validerTables(traitements: Record<string, any>): AnomalieTable[] {
  const anomalies: AnomalieTable[] = [];

  for (const [nom, trt] of Object.entries(traitements)) {
    const options: any[] = trt.options ?? [trt];
    for (const opt of options) {
      const tranches: TranchePosologie[] = opt.posologie_par_poids ?? [];
      if (tranches.length < 1) continue;
      const nomOpt = opt.code ?? opt.libelle ?? nom;

      const triees = [...tranches].sort((a, b) => (a.poids_min ?? 0) - (b.poids_min ?? 0));

      const plancher = triees[0].poids_min ?? 0;
      if (plancher > 0) {
        anomalies.push({
          traitement: nom, option: nomOpt, type: 'POIDS_MIN_NON_COUVERT',
          detail: `aucune posologie en dessous de ${plancher} kg`,
        });
      }

      for (let i = 0; i < triees.length - 1; i++) {
        const finCourante = triees[i].poids_max;
        const debutSuivante = triees[i + 1].poids_min ?? 0;
        if (finCourante == null) continue;
        if (finCourante < debutSuivante) {
          anomalies.push({
            traitement: nom, option: nomOpt, type: 'TROU',
            detail: `aucune posologie entre ${finCourante} kg et ${debutSuivante} kg`,
          });
        } else if (finCourante > debutSuivante) {
          anomalies.push({
            traitement: nom, option: nomOpt, type: 'CHEVAUCHEMENT',
            detail: `les tranches se recouvrent entre ${debutSuivante} kg et ${finCourante} kg`,
          });
        }
      }
    }
  }
  return anomalies;
}
