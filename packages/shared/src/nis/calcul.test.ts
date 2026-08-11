/**
 * P6.1 — GARDE ANTI-DIVERGENCE TS ↔ PHP, côté TypeScript (ADR-021 §5).
 *
 * Jumeau de `services/api/tests/Unit/NisVecteursPartagesTest.php`. Les deux suites lisent le
 * MÊME fichier `vecteurs.json` : si l'une des implémentations dérive, l'une des deux casse.
 *
 * Exécution — runner NATIF de Node, aucune dépendance ajoutée (discipline §2.6) :
 *     node --test src/nis/calcul.test.ts
 *
 * Les vecteurs sont lus par `fs` et non par `import ... with { type: 'json' }` : le module
 * testé (`calcul.ts`) doit rester sans aucun import pour être exécutable tel quel.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';

// Extension explicite : requise par l'ESM natif de Node. Elle reste confinée à ce fichier de
// test — `index.ts` importe `./calcul` sans extension, pour rester compatible Metro et Next.
import { calculerCleNis, composerNis, estNisValide, verifierNis } from './calcul.ts';

interface CasValide {
  nis: string;
  prefixe: string;
  annee: string;
  compteur: string;
  cle: string;
}
interface CasInvalide {
  nis: string;
  motif: string;
}

const ici = dirname(fileURLToPath(import.meta.url));
const vecteurs = JSON.parse(readFileSync(join(ici, 'vecteurs.json'), 'utf8')) as {
  valides: CasValide[];
  invalides: CasInvalide[];
};

test('le fichier de vecteurs partagés est présent et non vide', () => {
  assert.ok(vecteurs.valides.length > 0, 'aucun vecteur valide : la garde ne protège rien');
  assert.ok(vecteurs.invalides.length > 0, 'aucun vecteur invalide : les rejets ne sont pas couverts');
});

test('chaque NIS de référence est accepté et se recompose à l’identique', () => {
  for (const cas of vecteurs.valides) {
    const r = verifierNis(cas.nis);
    assert.equal(r.valide, true, `NIS de référence rejeté : ${cas.nis}`);

    assert.equal(
      calculerCleNis(cas.prefixe, cas.annee, cas.compteur),
      cas.cle,
      `clé recalculée divergente pour ${cas.nis}`,
    );

    assert.equal(
      composerNis(cas.prefixe, Number(cas.annee), Number(cas.compteur)),
      cas.nis,
      `la recomposition de ${cas.nis} ne redonne pas la même valeur`,
    );
  }
});

test('chaque NIS invalide est rejeté avec le bon motif', () => {
  for (const cas of vecteurs.invalides) {
    const r = verifierNis(cas.nis);
    assert.equal(r.valide, false, `NIS invalide accepté : « ${cas.nis} »`);
    assert.equal(
      r.valide === false ? r.motif : null,
      cas.motif,
      `motif de rejet divergent pour « ${cas.nis} »`,
    );
  }
});

test('toute erreur portant sur un seul chiffre est détectée', () => {
  const nis = composerNis('CIS', 24, 12_000_125);

  for (let i = 3; i < 13; i++) {
    for (const d of '0123456789') {
      if (d === nis[i]) continue;
      const altere = nis.slice(0, i) + d + nis.slice(i + 1);
      assert.equal(estNisValide(altere), false, `erreur d’un chiffre non détectée : ${altere}`);
    }
  }
});

test('toute inversion de deux chiffres voisins est détectée', () => {
  const nis = composerNis('CIS', 26, 48_150_723);

  for (let i = 3; i < 14; i++) {
    if (nis[i] === nis[i + 1]) continue;
    const altere = nis.slice(0, i) + nis[i + 1] + nis[i] + nis.slice(i + 2);
    assert.equal(estNisValide(altere), false, `inversion non détectée : ${altere}`);
  }
});

test('deux pays ne produisent pas la même clé pour les mêmes chiffres', () => {
  const ci = composerNis('CIS', 24, 12_000_125);
  const sn = composerNis('SNS', 24, 12_000_125);

  assert.equal(ci.slice(3, 13), sn.slice(3, 13), 'les chiffres doivent être identiques');
  assert.notEqual(ci.slice(13), sn.slice(13), 'les clés doivent différer');
});

test('la saisie est normalisée (minuscules, espaces)', () => {
  const nis = composerNis('CIS', 24, 12_000_125);

  assert.equal(estNisValide(nis.toLowerCase()), true);
  assert.equal(estNisValide(`  ${nis}  `), true);
});
