/**
 * Évaluateur de conditions de protocole.
 *
 * Contrainte de sécurité (CDC_10) : AUCUN eval(), AUCUN new Function().
 * Une condition venant d'un protocole est une donnée, jamais du code.
 * On tokenise, on parse en arbre, on évalue l'arbre. Rien d'autre ne s'exécute.
 *
 * Grammaire supportée :
 *   expr      := ou
 *   ou        := et ( 'OU' et )*
 *   et        := cmp ( 'ET' cmp )*
 *   cmp       := primaire ( ('=='|'!='|'>'|'>='|'<'|'<='|'contient') primaire )?
 *   primaire  := '(' expr ')' | litteral | chemin
 *   chemin    := identifiant ( '.' identifiant )*
 */

import type { ContextePatient, Valeur } from './types.js';

type Token =
  | { t: 'nombre'; v: number }
  | { t: 'texte'; v: string }
  | { t: 'bool'; v: boolean }
  | { t: 'ident'; v: string }
  | { t: 'op'; v: string }
  | { t: 'parG' }
  | { t: 'parD' };

const OPERATEURS_COMPARAISON = ['==', '!=', '>=', '<=', '>', '<'];

export class ErreurCondition extends Error {}

function tokeniser(src: string): Token[] {
  const tokens: Token[] = [];
  let i = 0;
  while (i < src.length) {
    const c = src[i];

    if (/\s/.test(c)) { i++; continue; }
    if (c === '(') { tokens.push({ t: 'parG' }); i++; continue; }
    if (c === ')') { tokens.push({ t: 'parD' }); i++; continue; }

    if (c === "'" || c === '"') {
      const fin = src.indexOf(c, i + 1);
      if (fin === -1) throw new ErreurCondition(`Chaîne non fermée à la position ${i}`);
      tokens.push({ t: 'texte', v: src.slice(i + 1, fin) });
      i = fin + 1;
      continue;
    }

    const op = OPERATEURS_COMPARAISON.find((o) => src.startsWith(o, i));
    if (op) { tokens.push({ t: 'op', v: op }); i += op.length; continue; }

    if (/[0-9]/.test(c)) {
      const m = /^[0-9]+(\.[0-9]+)?/.exec(src.slice(i))!;
      tokens.push({ t: 'nombre', v: parseFloat(m[0]) });
      i += m[0].length;
      continue;
    }

    if (/[A-Za-zÀ-ÿ_]/.test(c)) {
      const m = /^[A-Za-zÀ-ÿ0-9_.]+/.exec(src.slice(i))!;
      const mot = m[0];
      i += mot.length;
      if (mot === 'ET' || mot === 'OU' || mot === 'contient') tokens.push({ t: 'op', v: mot });
      else if (mot === 'true') tokens.push({ t: 'bool', v: true });
      else if (mot === 'false') tokens.push({ t: 'bool', v: false });
      else tokens.push({ t: 'ident', v: mot });
      continue;
    }

    throw new ErreurCondition(`Caractère inattendu « ${c} » à la position ${i}`);
  }
  return tokens;
}

type Noeud =
  | { n: 'litteral'; v: Valeur }
  | { n: 'chemin'; chemin: string }
  | { n: 'binaire'; op: string; g: Noeud; d: Noeud };

function parser(tokens: Token[]): Noeud {
  let pos = 0;
  const fini = () => pos >= tokens.length;
  const voir = () => tokens[pos];

  function expr(): Noeud { return ou(); }

  function ou(): Noeud {
    let g = et();
    while (!fini() && voir().t === 'op' && (voir() as any).v === 'OU') {
      pos++;
      g = { n: 'binaire', op: 'OU', g, d: et() };
    }
    return g;
  }

  function et(): Noeud {
    let g = cmp();
    while (!fini() && voir().t === 'op' && (voir() as any).v === 'ET') {
      pos++;
      g = { n: 'binaire', op: 'ET', g, d: cmp() };
    }
    return g;
  }

  function cmp(): Noeud {
    const g = primaire();
    if (!fini() && voir().t === 'op') {
      const op = (voir() as any).v as string;
      if (OPERATEURS_COMPARAISON.includes(op) || op === 'contient') {
        pos++;
        return { n: 'binaire', op, g, d: primaire() };
      }
    }
    return g;
  }

  function primaire(): Noeud {
    if (fini()) throw new ErreurCondition('Expression incomplète');
    const tk = tokens[pos];
    if (tk.t === 'parG') {
      pos++;
      const e = expr();
      if (fini() || tokens[pos].t !== 'parD') throw new ErreurCondition('Parenthèse fermante manquante');
      pos++;
      return e;
    }
    if (tk.t === 'nombre' || tk.t === 'texte' || tk.t === 'bool') { pos++; return { n: 'litteral', v: tk.v }; }
    if (tk.t === 'ident') { pos++; return { n: 'chemin', chemin: tk.v }; }
    throw new ErreurCondition(`Jeton inattendu : ${JSON.stringify(tk)}`);
  }

  const racine = expr();
  if (!fini()) throw new ErreurCondition(`Texte résiduel après l'expression (jeton ${pos})`);
  return racine;
}

/** Résout un chemin comme `signes_gravite.length` dans le contexte. */
function resoudre(chemin: string, ctx: ContextePatient, manquantes: Set<string>): Valeur {
  const parties = chemin.split('.');
  const racine = parties[0];
  if (!(racine in ctx)) {
    manquantes.add(racine);
    return undefined;
  }
  let val: any = ctx[racine];
  for (const p of parties.slice(1)) {
    if (val == null) return undefined;
    if (p === 'length') { val = Array.isArray(val) || typeof val === 'string' ? val.length : undefined; continue; }
    val = val[p];
  }
  return val;
}

function comparer(op: string, g: any, d: any): boolean {
  switch (op) {
    case '==': return g === d;
    case '!=': return g !== d;
    case '>': return typeof g === 'number' && typeof d === 'number' && g > d;
    case '>=': return typeof g === 'number' && typeof d === 'number' && g >= d;
    case '<': return typeof g === 'number' && typeof d === 'number' && g < d;
    case '<=': return typeof g === 'number' && typeof d === 'number' && g <= d;
    case 'contient':
      if (Array.isArray(g)) return g.includes(d);
      if (typeof g === 'string') return g.includes(String(d));
      return false;
    default: throw new ErreurCondition(`Opérateur inconnu : ${op}`);
  }
}

function evaluerNoeud(n: Noeud, ctx: ContextePatient, manquantes: Set<string>): any {
  switch (n.n) {
    case 'litteral': return n.v;
    case 'chemin': return resoudre(n.chemin, ctx, manquantes);
    case 'binaire': {
      if (n.op === 'ET') {
        // Pas de court-circuit : on veut recenser TOUTES les variables manquantes.
        const g = evaluerNoeud(n.g, ctx, manquantes);
        const d = evaluerNoeud(n.d, ctx, manquantes);
        return Boolean(g) && Boolean(d);
      }
      if (n.op === 'OU') {
        const g = evaluerNoeud(n.g, ctx, manquantes);
        const d = evaluerNoeud(n.d, ctx, manquantes);
        return Boolean(g) || Boolean(d);
      }
      return comparer(n.op, evaluerNoeud(n.g, ctx, manquantes), evaluerNoeud(n.d, ctx, manquantes));
    }
  }
}

const cacheAst = new Map<string, Noeud>();

export interface ResultatCondition {
  vrai: boolean;
  variables_manquantes: string[];
}

/** Compile (avec cache) puis évalue une condition de protocole. */
export function evaluerCondition(condition: string, ctx: ContextePatient): ResultatCondition {
  let ast = cacheAst.get(condition);
  if (!ast) {
    ast = parser(tokeniser(condition));
    cacheAst.set(condition, ast);
  }
  const manquantes = new Set<string>();
  const vrai = Boolean(evaluerNoeud(ast, ctx, manquantes));
  return { vrai, variables_manquantes: [...manquantes] };
}

/** Vérifie qu'une condition est syntaxiquement valide — utilisé à la publication d'un protocole. */
export function validerCondition(condition: string): { valide: boolean; erreur?: string; variables: string[] } {
  try {
    const ast = parser(tokeniser(condition));
    const vars = new Set<string>();
    (function marcher(n: Noeud) {
      if (n.n === 'chemin') vars.add(n.chemin.split('.')[0]);
      else if (n.n === 'binaire') { marcher(n.g); marcher(n.d); }
    })(ast);
    return { valide: true, variables: [...vars] };
  } catch (e) {
    return { valide: false, erreur: (e as Error).message, variables: [] };
  }
}
