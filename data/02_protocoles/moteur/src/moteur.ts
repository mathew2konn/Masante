/**
 * Moteur d'évaluation des protocoles MaSanté.
 *
 * Principes tenus :
 *  - chaînage avant jusqu'au point fixe : une règle qui produit un fait (une classification)
 *    permet aux règles suivantes de s'appuyer dessus, quel que soit l'ordre des priorités ;
 *  - les priorités départagent les règles d'un même tour, elles ne séquencent pas le raisonnement ;
 *  - toute évaluation produit une trace complète, opposable (CDC_08 §10) ;
 *  - aucune règle ne peut exécuter de code (voir conditions.ts).
 */

import { evaluerCondition, validerCondition } from './conditions.js';
import { resoudrePosologie, validerTables, type AnomalieTable } from './posologie.js';
import type {
  Action, ContextePatient, Protocole, Recommandation, RegleDeclenchee, ResultatEvaluation,
} from './types.js';

const MAX_TOURS = 8;

let compteurTrace = 0;
function nouveauTraceId(): string {
  compteurTrace += 1;
  return `eval-${String(compteurTrace).padStart(5, '0')}`;
}

export class MoteurProtocoles {
  constructor(private readonly protocole: Protocole) {}

  /** Contrôles à la publication d'une version (CDC_08 §7.4 « validation technique »). */
  validerProtocole(): { erreurs: string[]; avertissements: string[]; anomalies_posologie: AnomalieTable[] } {
    const erreurs: string[] = [];
    const avertissements: string[] = [];
    const declarees = new Set(this.protocole.variables_entree.map((v) => v.cle));
    const produites = new Set<string>(['classification']);

    for (const r of this.protocole.regles) {
      for (const a of r.actions) {
        if (a.type === 'CLASSIFICATION') produites.add('classification');
      }
    }

    const ids = new Set<string>();
    for (const r of this.protocole.regles) {
      if (ids.has(r.id)) erreurs.push(`Identifiant de règle dupliqué : ${r.id}`);
      ids.add(r.id);

      const v = validerCondition(r.condition);
      if (!v.valide) {
        erreurs.push(`${r.id} — condition invalide : ${v.erreur}`);
        continue;
      }
      for (const variable of v.variables) {
        if (!declarees.has(variable) && !produites.has(variable)) {
          avertissements.push(`${r.id} — la variable « ${variable} » n'est déclarée nulle part dans variables_entree`);
        }
      }
      for (const cible of r.remplace ?? []) {
        if (!this.protocole.regles.some((x) => x.id === cible)) {
          erreurs.push(`${r.id} — remplace une règle inexistante : ${cible}`);
        }
      }
    }

    return { erreurs, avertissements, anomalies_posologie: validerTables(this.protocole.traitements) };
  }

  /**
   * Parcourt un arbre de décision nommé et renvoie l'action de la première branche vraie.
   * Les branches utilisent le même évaluateur de conditions que les règles — pas d'exception.
   */
  private resoudreArbre(nom: string, faits: ContextePatient): string | null {
    const arbre = this.protocole.arbres_decision?.[nom];
    if (!arbre) return null;
    const groupes: any[][] = Array.isArray(arbre) ? [arbre] : Object.values(arbre);
    for (const branches of groupes) {
      if (!Array.isArray(branches)) continue;
      for (const b of branches) {
        if (!b?.condition) continue;
        try {
          if (evaluerCondition(b.condition, faits).vrai) return b.action;
        } catch { /* branche mal formée : ignorée, signalée à la validation */ }
      }
    }
    return null;
  }

  evaluer(contexte: ContextePatient): ResultatEvaluation {
    const debut = performance.now();
    const faits: ContextePatient = { ...contexte, classification: contexte.classification ?? null };

    const declenchees = new Map<string, RegleDeclenchee>();
    const manquantes = new Set<string>();

    // --- Chaînage avant jusqu'au point fixe -------------------------------------------------
    let tour = 0;
    let nouveau = true;
    while (nouveau && tour < MAX_TOURS) {
      nouveau = false;
      tour += 1;
      const candidates = [...this.protocole.regles].sort((a, b) => a.priorite - b.priorite);

      for (const regle of candidates) {
        if (declenchees.has(regle.id)) continue;
        const res = evaluerCondition(regle.condition, faits);
        res.variables_manquantes.forEach((v) => manquantes.add(v));
        if (!res.vrai) continue;

        declenchees.set(regle.id, {
          regle_id: regle.id,
          libelle: regle.libelle,
          priorite: regle.priorite,
          niveau_preuve: regle.niveau_preuve,
          actions: regle.actions,
        });
        nouveau = true;

        for (const a of regle.actions) {
          if (a.type === 'CLASSIFICATION' && a.valeur) faits.classification = a.valeur;
        }
      }
    }

    // --- Résolution des conflits : une règle qui en remplace une autre l'annule --------------
    const conflits: string[] = [];
    for (const [id, d] of declenchees) {
      const regle = this.protocole.regles.find((r) => r.id === id)!;
      for (const cible of regle.remplace ?? []) {
        const annulee = declenchees.get(cible);
        if (annulee && !annulee.annulee_par) {
          annulee.annulee_par = id;
          conflits.push(`${cible} annulée par ${id} — ${regle.libelle}`);
        }
      }
    }

    const actives = [...declenchees.values()]
      .filter((d) => !d.annulee_par)
      .sort((a, b) => a.priorite - b.priorite);

    // --- Construction de la sortie -----------------------------------------------------------
    const recommandations: Recommandation[] = [];
    const alertes: string[] = [];
    const contreIndications: { medicament: string; motif?: string }[] = [];
    const exclusions: string[] = [];
    let refTraitement: string | null = null;

    for (const d of actives) {
      for (const a of d.actions) {
        if (a.type === 'ALERTE' && a.message) alertes.push(a.message);
        if (a.type === 'BLOCAGE_PRESCRIPTION' && a.message) alertes.push(a.message);
        if (a.type === 'PRECAUTION' && a.message) alertes.push(a.message);
        if ((a.type === 'CONTRE_INDICATION' || a.type === 'CONTRE_INDICATION_RELATIVE') && a.medicament) {
          contreIndications.push({ medicament: a.medicament, motif: a.motif });
          exclusions.push(...codesExclus(a.medicament));
        }
        if (a.type === 'ALTERNATIVE') exclusions.push(...(a.exclure ?? []));
        if ((a.type === 'TRAITEMENT' || a.type === 'TRAITEMENT_PRE_TRANSFERT') && a.ref) refTraitement = a.ref;

        recommandations.push({
          action: a.type,
          detail: a.valeur ?? a.ref ?? a.medicament ?? a.message,
          justification: d.libelle,
          niveau_preuve: d.niveau_preuve,
          protocole: { id: this.protocole.id, version: this.protocole.version },
          regle_id: d.regle_id,
        });
      }
    }

    // --- Arbres de décision référencés par les règles ---------------------------------------
    for (const d of actives) {
      for (const a of d.actions) {
        if (a.type !== 'ARBRE_DECISION' || !a.ref) continue;
        const branche = this.resoudreArbre(a.ref, faits);
        if (branche) {
          recommandations.push({
            action: 'ARBRE_DECISION_RESOLU',
            detail: branche,
            justification: `${d.libelle} — ${a.ref}`,
            niveau_preuve: d.niveau_preuve,
            protocole: { id: this.protocole.id, version: this.protocole.version },
            regle_id: d.regle_id,
          });
        }
      }
    }

    // --- Posologie ---------------------------------------------------------------------------
    let posologie = null;
    const poids = typeof faits.poids_kg === 'number' ? faits.poids_kg : null;
    const bloque = actives.some((d) => d.actions.some((a) => a.type === 'BLOCAGE_PRESCRIPTION'));

    if (refTraitement && poids != null && !bloque) {
      const trt = this.protocole.traitements[refTraitement];
      // Un traitement structuré par population (et non par poids) n'a pas de table à résoudre :
      // ses options sont retournées telles quelles, sans alerte.
      const aDesTables = trt && (trt.options ?? [trt]).some((o: any) => o.posologie_par_poids?.length);
      if (aDesTables) {
        posologie = resoudrePosologie(trt, refTraitement, poids, exclusions);
        if (!posologie) {
          alertes.push(`Aucune posologie applicable à ${poids} kg dans ${refTraitement} — avis médical requis.`);
        }
      }
    }

    // Variables déclarées obligatoires mais absentes du contexte
    const manquantesObligatoires: string[] = [];
    const declarees = new Map(this.protocole.variables_entree.map((v) => [v.cle, v]));
    for (const v of this.protocole.variables_entree) {
      if (v.obligatoire && !(v.cle in contexte)) manquantesObligatoires.push(v.cle);
    }
    for (const m of manquantes) {
      if (declarees.get(m)?.obligatoire && !manquantesObligatoires.includes(m)) manquantesObligatoires.push(m);
    }

    return {
      trace_id: nouveauTraceId(),
      protocole: { id: this.protocole.id, version: this.protocole.version, etat: this.protocole.cycle_de_vie.etat },
      classification: (faits.classification as string) ?? null,
      recommandations,
      posologie,
      alertes,
      contre_indications: contreIndications,
      variables_manquantes: manquantesObligatoires.sort(),
      variables_optionnelles_absentes: [...manquantes].filter((m) => !declarees.get(m)?.obligatoire).sort(),
      regles_declenchees: [...declenchees.values()],
      conflits,
      duree_ms: Math.round((performance.now() - debut) * 1000) / 1000,
    };
  }
}

export interface ArbreResolu { branche: string }

/** Traduit un nom de médicament de contre-indication en codes d'options de traitement à exclure. */
function codesExclus(medicament: string): string[] {
  const table: Record<string, string[]> = {
    ARTESUNATE_AMODIAQUINE: ['AS_AQ'],
    SULFADOXINE_PYRIMETHAMINE: ['SP'],
  };
  return table[medicament] ?? [];
}
