import { readFileSync } from 'node:fs';
import { MoteurProtocoles } from '../src/moteur.js';
import { CAS } from './cas-cliniques.js';
import type { Protocole } from '../src/types.js';

const protocole: Protocole = JSON.parse(readFileSync(new URL('../protocoles/PROT-CI-PALU-2022.json', import.meta.url), 'utf8'));
const moteur = new MoteurProtocoles(protocole);

const V = '\x1b[32m', R = '\x1b[31m', J = '\x1b[33m', G = '\x1b[1m', N = '\x1b[0m', D = '\x1b[2m';

console.log(`\n${G}Protocole${N} ${protocole.id} v${protocole.version} — état ${protocole.cycle_de_vie.etat}`);
console.log(`${D}${protocole.titre}${N}\n`);

// ── 1. Validation technique du protocole ────────────────────────────────────────────────────
console.log(`${G}1. Validation technique (CDC_08 §7.4)${N}`);
const val = moteur.validerProtocole();
if (!val.erreurs.length) console.log(`   ${V}✓${N} aucune erreur bloquante`);
val.erreurs.forEach((e) => console.log(`   ${R}✗ ${e}${N}`));
val.avertissements.forEach((a) => console.log(`   ${J}⚠ ${a}${N}`));
if (val.anomalies_posologie.length) {
  console.log(`\n   ${G}Contrôle des tables de posologie${N}`);
  for (const a of val.anomalies_posologie) {
    console.log(`   ${J}⚠ ${a.traitement} / ${a.option} — ${a.type} : ${a.detail}${N}`);
  }
} else {
  console.log(`   ${V}✓${N} tables de posologie cohérentes`);
}

// ── 2. Batterie de cas cliniques ────────────────────────────────────────────────────────────
console.log(`\n${G}2. Cas cliniques de référence (CDC_08 §12)${N}\n`);

let reussis = 0;
const echecs: string[] = [];
const durees: number[] = [];

for (const cas of CAS) {
  const r = moteur.evaluer(cas.contexte);
  durees.push(r.duree_ms);
  const ecarts: string[] = [];
  const declenchees = r.regles_declenchees.filter((d) => !d.annulee_par).map((d) => d.regle_id);

  if (cas.attendu.classification !== undefined && r.classification !== cas.attendu.classification) {
    ecarts.push(`classification : attendu ${cas.attendu.classification}, obtenu ${r.classification}`);
  }
  for (const id of cas.attendu.regles ?? []) {
    if (!r.regles_declenchees.some((d) => d.regle_id === id)) ecarts.push(`règle ${id} non déclenchée`);
  }
  for (const id of cas.attendu.regles_absentes ?? []) {
    if (declenchees.includes(id)) ecarts.push(`règle ${id} déclenchée alors qu'elle ne devrait pas`);
  }
  if (cas.attendu.option_posologie && r.posologie?.option_code !== cas.attendu.option_posologie) {
    ecarts.push(`option : attendu ${cas.attendu.option_posologie}, obtenu ${r.posologie?.option_code ?? 'aucune'}`);
  }
  if (cas.attendu.dose && r.posologie?.dose !== cas.attendu.dose) {
    ecarts.push(`dose : attendu « ${cas.attendu.dose} », obtenu « ${r.posologie?.dose ?? 'aucune'} »`);
  }
  for (const ci of cas.attendu.contre_indications ?? []) {
    if (!r.contre_indications.some((c) => c.medicament === ci)) ecarts.push(`contre-indication ${ci} absente`);
  }

  const ok = ecarts.length === 0;
  if (ok) reussis++; else echecs.push(cas.id);

  console.log(`${ok ? `${V}✓` : `${R}✗`} ${cas.id}${N} ${cas.titre}`);
  console.log(`   ${D}→ ${r.classification ?? 'aucune classification'}${r.posologie ? ` | ${r.posologie.option_libelle} — ${r.posologie.dose} (${r.posologie.tranche})` : ''}${N}`);
  if (declenchees.length) console.log(`   ${D}  règles : ${declenchees.join(', ')}${r.conflits.length ? ` | conflits : ${r.conflits.join(' ; ')}` : ''}${N}`);
  r.alertes.forEach((a) => console.log(`   ${J}  ⚠ ${a}${N}`));
  r.contre_indications.forEach((c) => console.log(`   ${J}  ⊘ ${c.medicament}${c.motif ? ` — ${c.motif}` : ''}${N}`));
  r.recommandations.filter((x) => x.action === 'ARBRE_DECISION_RESOLU').forEach((x) => console.log(`   ${D}  → arbre : ${x.detail}${N}`));
  if (r.variables_manquantes.length) console.log(`   ${R}  ? variables OBLIGATOIRES absentes : ${r.variables_manquantes.join(', ')}${N}`);
  ecarts.forEach((e) => console.log(`   ${R}  ✗ ${e}${N}`));
  console.log();
}

// ── 3. Bilan ────────────────────────────────────────────────────────────────────────────────
const p95 = [...durees].sort((a, b) => a - b)[Math.floor(durees.length * 0.95)];
console.log(`${G}3. Bilan${N}`);
console.log(`   ${reussis === CAS.length ? V : R}${reussis}/${CAS.length} cas conformes${N}${echecs.length ? ` — échecs : ${echecs.join(', ')}` : ''}`);
console.log(`   Performance : P95 = ${p95} ms (exigence CDC_08 §11 : < 100 ms) ${p95 < 100 ? `${V}✓${N}` : `${R}✗${N}`}`);
console.log(`   Trace : chaque évaluation porte un trace_id, la version du protocole et la liste des règles déclenchées.\n`);

process.exit(echecs.length ? 1 : 0);
