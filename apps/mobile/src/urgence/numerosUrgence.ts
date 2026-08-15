/**
 * numerosUrgence.ts — P6.8e. **Le seul endroit de l'application qui sait quoi composer.**
 *
 * ═══ POURQUOI UN MODULE, ET PAS UN APPEL D'API DE PLUS ═══
 *
 * Le numéro d'urgence vivait en dur ici (`SAMU_NUMERO`), lu par cinq écrans, en double avec le
 * backend et avec les traductions partagées. CDC_02 §37 l'interdit nommément : « rien en dur —
 * y compris les numéros d'urgence ». Mais le remplacer par un simple `GET` aurait été pire que le
 * défaut : **ce module s'adresse à un écran qui n'a ni réseau, ni session, ni compte.**
 *
 * ═══ TROIS NIVEAUX, DANS CET ORDRE (décision propriétaire C1) ═══
 *
 *   1. le **référentiel publié**, rafraîchi à chaque passage en ligne ;
 *   2. le **cache `SecureStore`**, même périmé, quand il n'y a pas de réseau ;
 *   3. la **valeur livrée avec l'application**, quand ce téléphone n'a jamais rien reçu.
 *
 * ═══ `SecureStore` ET NON LE CACHE CHIFFRÉ P2 — LE PIÈGE ÉVITÉ ═══
 *
 * Le réflexe aurait été de faire comme `chargerVilles`, qui passe par `lireAvecCache`. Or
 * `SessionContext` appelle `viderDossierCache()` à la DÉCONNEXION : les numéros d'urgence
 * disparaîtraient précisément dans l'état où se trouve le téléphone que consulte un secouriste.
 * `SecureStore` est celui de la carte vitale (`carteVitale.ts`) — il survit à la déconnexion par
 * construction, et c'est la raison pour laquelle FN2 l'a choisi.
 *
 * ═══ L'ÉCRAN NE DIT RIEN DE LA PROVENANCE ═══
 *
 * `provenance` est transportée pour les tests et le diagnostic, jamais affichée : un avertissement
 * « numéro par défaut, non vérifié » présenté à quelqu'un qui compose des secours est du bruit au
 * pire moment. L'honnêteté sur le repli est due à l'exploitant — elle vit dans les journaux du
 * serveur et sur l'écran du portail.
 *
 * FRONTIÈRE : aucune règle ici. Ni quel numéro appeler pour quel symptôme, ni dans quel ordre —
 * l'ordre lui-même est une donnée du référentiel.
 */
import { useEffect, useState } from 'react';
import * as SecureStore from 'expo-secure-store';
import { api } from '../config/api';
import { SAMU_NUMERO_REPLI } from '../config/constants';
import type { NumeroUrgence, NumerosUrgenceEtat } from '../types/urgence';

const CLE = 'urgence.numeros';

/** Code du secours médical — le seul que l'application connaisse sans le référentiel. */
export const CODE_SAMU = 'samu';

/**
 * Le niveau 3. Un seul numéro, et c'est raisonné : les autres n'ont pas été confrontés à une
 * publication officielle, et compiler une valeur non vérifiée dans l'application referait le défaut
 * que ce module supprime.
 */
const REPLI: NumeroUrgence[] = [
  {
    code: CODE_SAMU,
    numero: SAMU_NUMERO_REPLI,
    libelle: 'SAMU',
    description: 'Service d\'aide médicale urgente.',
    ordre: 10,
    source: null,
    source_detail: null,
  },
];

interface ReponseApi {
  numeros: NumeroUrgence[];
  version: number | null;
}

/** Ce que le téléphone a en mémoire, ou `null`. Ne lève jamais : un cache illisible n'est pas une panne. */
async function lireCache(): Promise<NumerosUrgenceEtat | null> {
  try {
    const brut = await SecureStore.getItemAsync(CLE);
    if (!brut) return null;

    const parse: unknown = JSON.parse(brut);
    const numeros = (parse as ReponseApi)?.numeros;

    // Un cache vide ne vaut pas mieux que pas de cache : le rendre tel quel priverait l'écran de
    // tout numéro alors que le repli existe.
    if (!Array.isArray(numeros) || numeros.length === 0) return null;

    return { numeros, provenance: 'cache', version: (parse as ReponseApi).version ?? null };
  } catch {
    return null;
  }
}

/**
 * Les numéros à afficher, dans l'ordre du référentiel. **Ne rejette jamais.**
 *
 * L'ordre des tentatives suit la décision C1. Le réseau est tenté en premier parce qu'une
 * renumérotation doit prendre effet dès qu'elle est joignable — mais son échec n'est pas une
 * erreur, c'est le cas nominal d'un téléphone en mode avion.
 */
export async function chargerNumerosUrgence(): Promise<NumerosUrgenceEtat> {
  try {
    const { data } = await api.get<ReponseApi>('/v1/numeros-urgence');

    if (Array.isArray(data?.numeros) && data.numeros.length > 0) {
      // Écrit AVANT de rendre : si l'application est fermée juste après, le prochain démarrage hors
      // ligne disposera déjà de la version reçue.
      await SecureStore.setItemAsync(CLE, JSON.stringify(data));

      return { numeros: data.numeros, provenance: 'referentiel', version: data.version ?? null };
    }
    // Réponse vide ou 503 (aucune version publiée) : on ne remplace pas un cache valide par rien.
  } catch {
    // Hors ligne, serveur injoignable, 503 : tous traités pareil — on descend d'un niveau.
  }

  return (await lireCache()) ?? { numeros: REPLI, provenance: 'repli', version: null };
}

/**
 * Le numéro à composer pour un code donné, jamais vide pour le SAMU.
 *
 * Renvoie `null` pour un code absent du référentiel : **aucun repli n'est inventé** pour les autres
 * secours — *un numéro d'urgence faux est plus dangereux qu'un numéro absent, parce qu'il sera
 * composé.*
 */
export async function numeroUrgence(code: string): Promise<string | null> {
  const { numeros } = await chargerNumerosUrgence();

  return numeros.find((n) => n.code === code)?.numero ?? null;
}

/**
 * Version React des mêmes trois niveaux.
 *
 * **L'état initial est le repli, jamais une liste vide** : un écran d'urgence ne doit à aucun
 * instant afficher un bouton sans numéro, pas même pendant le temps d'un chargement.
 */
export function useNumerosUrgence(): NumerosUrgenceEtat {
  const [etat, setEtat] = useState<NumerosUrgenceEtat>({
    numeros: REPLI,
    provenance: 'repli',
    version: null,
  });

  useEffect(() => {
    let vivant = true;

    void chargerNumerosUrgence().then((resultat) => {
      if (vivant) setEtat(resultat);
    });

    return () => {
      vivant = false;
    };
  }, []);

  return etat;
}

/** Le numéro du SAMU tel que le connaît l'état courant — raccourci des écrans qui n'affichent que lui. */
export function numeroSamu(etat: NumerosUrgenceEtat): string {
  return etat.numeros.find((n) => n.code === CODE_SAMU)?.numero ?? SAMU_NUMERO_REPLI;
}
