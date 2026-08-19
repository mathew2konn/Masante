import * as SecureStore from 'expo-secure-store';
import { create } from 'zustand';
import { chargerVilles, localiserVille } from '../api/villes';
import { obtenirPosition } from '../utils/geoloc';
import type { Coordonnees } from '../types/structure';
import type { Localisation, SourceVille, TypeEtablissement, Ville, VilleProche } from '../types/ville';

/**
 * store/localisation.ts — La ville courante de l'utilisateur (P6.4b).
 *
 * ═══ CE QUE CE STORE NE FAIT PAS ═══
 *
 * Il ne décide jamais dans quelle ville se trouve l'utilisateur, ni si des communes doivent être
 * proposées. Il demande la position au téléphone, l'envoie au serveur, et conserve la réponse.
 * Toute la décision est backend (règle de frontière).
 *
 * ═══ TROIS SITUATIONS, TROIS PHRASES DIFFÉRENTES ═══
 *
 *  · `position` — la position réelle a répondu : « Vous êtes à Abidjan » ;
 *  · `choix`    — l'utilisateur a refusé la localisation et choisi sa ville : « Ville : Bouaké » ;
 *  · `memoire`  — hors ligne, on ressort la dernière ville connue : « Dernière position connue ».
 *
 * La distinction n'est pas cosmétique : « vous êtes à X » est une AFFIRMATION. La servir depuis un
 * cache la rendrait fausse dès que l'utilisateur se déplace hors couverture réseau. On garde donc
 * la mémoire — sans elle l'écran serait vide en mode avion — mais on ne la fait jamais passer pour
 * une mesure.
 *
 * ═══ POURQUOI SECURESTORE ═══
 *
 * Un code de ville n'est pas une donnée sensible. SecureStore est simplement le seul magasin
 * persistant déjà présent (il porte la clé du cache chiffré P2) : s'en servir n'ajoute aucune
 * dépendance, ce qui est la contrainte §2.6.
 */

const CLE_MEMOIRE = 'masante.ville_connue';

/**
 * P10a — Durée pendant laquelle une position mesurée reste utilisable pour classer des hôpitaux.
 *
 * ═══ POURQUOI UNE FRAÎCHEUR, ET NON UN CACHE ═══
 *
 * ADR-027 avait délibérément REFUSÉ de retenir les coordonnées : « une seule mesure au moment du
 * tap », parce qu'une position en cache dirait « vous êtes à Abidjan » à quelqu'un arrivé à Bouaké.
 * Retenir les coordonnées sans précaution reviendrait donc sur cette décision.
 *
 * La fraîcheur est ce qui permet de faire les deux. Elle sépare deux questions que ce store
 * traitait comme une seule :
 *   · « dans quelle ville suis-je ? » — reste une AFFIRMATION, toujours servie par la règle des
 *     trois sources (`position` / `choix` / `memoire`), inchangée ;
 *   · « quels hôpitaux sont les plus proches ? » — un CLASSEMENT, qui tolère quelques minutes
 *     d'ancienneté mais devient faux si l'on a roulé une heure.
 *
 * Au-delà du délai, la position n'est pas « approximative » : elle est **remesurée**. On ne
 * dégrade jamais silencieusement une donnée de position.
 */
const FRAICHEUR_POSITION_MS = 5 * 60 * 1000;

interface EtatLocalisation {
  /** Les villes couvertes, pour le sélecteur de repli. */
  villes: Ville[];
  /** Les 13 catégories et leurs libellés — servies par le serveur, plus jamais recopiées ici. */
  typesEtablissement: TypeEtablissement[];

  /** Ville affichée, ou `null` tant que rien n'a abouti. */
  ville: { code: string; nom: string; affiche_communes: boolean } | null;
  source: SourceVille | null;
  /** Vrai quand la position est connue mais qu'aucune ville couverte ne la contient. */
  horsZone: boolean;
  /** Communes à proposer en filtre — vide si la ville n'en affiche pas. Décidé par le serveur. */
  communes: string[];
  /** Ordre d'affichage des structures quand l'utilisateur est hors zone. */
  villesParProximite: VilleProche[];

  /** Vrai quand l'utilisateur a explicitement refusé la localisation : on lui propose de choisir. */
  choixRequis: boolean;
  enCours: boolean;

  /**
   * P10a — Les coordonnées de la DERNIÈRE mesure, et l'instant où elle a eu lieu.
   *
   * Elles ne servent jamais à dire où l'on est (voir `FRAICHEUR_POSITION_MS`) : elles servent à
   * classer des établissements par proximité et à demander des temps de trajet. Elles ne sont
   * volontairement PAS persistées — au prochain lancement, on remesure.
   */
  coords: Coordonnees | null;
  mesureeA: number | null;

  initialiser: () => Promise<void>;
  demanderPosition: () => Promise<void>;
  choisirVille: (code: string) => Promise<void>;

  /**
   * Une position utilisable maintenant, ou `null`.
   *
   * Remesure si la précédente a dépassé la fraîcheur. Renvoie `null` sans jamais lever : un refus
   * de localisation ne doit pas empêcher l'écran de s'afficher — il le prive seulement du tri par
   * proximité, et l'écran le dit.
   */
  positionFraiche: () => Promise<Coordonnees | null>;
}

export const useLocalisation = create<EtatLocalisation>((set, get) => ({
  villes: [],
  typesEtablissement: [],
  ville: null,
  source: null,
  horsZone: false,
  communes: [],
  villesParProximite: [],
  choixRequis: false,
  enCours: false,
  coords: null,
  mesureeA: null,

  /**
   * Au démarrage : charger les villes, puis tenter la position.
   *
   * L'ordre compte. Les villes d'abord, parce qu'elles sont nécessaires au sélecteur de repli —
   * si l'on demandait la position en premier et qu'elle échouait, on n'aurait rien à proposer.
   */
  async initialiser() {
    if (get().enCours) return;
    set({ enCours: true });

    try {
      const { villes, types_etablissement } = await chargerVilles();
      set({ villes, typesEtablissement: types_etablissement });
    } catch {
      // Hors ligne au tout premier lancement : le cache est vide. L'écran fonctionnera sans
      // filtres de commune plutôt que de refuser de s'afficher.
    }

    await get().demanderPosition();
    set({ enCours: false });
  },

  /**
   * Demande la position et interroge le serveur.
   *
   * Trois issues, aucune n'étant une erreur bloquante :
   *  · position obtenue → la réponse du serveur fait foi ;
   *  · permission refusée → on demande à l'utilisateur de choisir sa ville ;
   *  · réseau absent → on ressort la dernière ville connue, annoncée comme telle.
   */
  async demanderPosition() {
    const resultat = await obtenirPosition();

    if (!resultat.ok) {
      // Refus explicite : il n'existe AUCUN repli automatique. Android et iOS fusionnent GPS,
      // Wi-Fi et réseau derrière une seule autorisation — refusée, le système ne donne rien.
      // On demande donc à l'utilisateur, ce qui est exact par construction.
      // Une ville déjà mémorisée évite de redemander à chaque ouverture ; sinon on demande.
      const memorisee = await lireVilleMemorisee();
      set(
        memorisee !== null
          ? appliquerMemoire(get().villes, memorisee)
          : { choixRequis: true },
      );
      return;
    }

    // La mesure est retenue AVANT l'appel réseau : elle est valable même si le serveur ne répond
    // pas. Savoir où l'on est et savoir dans quelle ville cela tombe sont deux choses distinctes,
    // et la seconde seule dépend du réseau.
    set({ coords: resultat.coords, mesureeA: Date.now() });

    try {
      const localisation = await localiserVille(resultat.coords);
      set(depuisReponse(localisation));
      if (localisation.ville !== null) {
        await memoriserVille(localisation.ville.code);
      }
    } catch {
      // Position connue mais serveur injoignable (mode avion). La mémoire prend le relais, et
      // l'écran dira « dernière position connue » — jamais « vous êtes à ».
      const memorisee = await lireVilleMemorisee();
      set(
        memorisee !== null
          ? appliquerMemoire(get().villes, memorisee)
          : { choixRequis: get().villes.length > 0 },
      );
    }
  },

  /** L'utilisateur choisit sa ville (après refus de la localisation). Le choix est mémorisé. */
  async choisirVille(code: string) {
    const ville = get().villes.find((v) => v.code === code);
    if (!ville) return;

    await memoriserVille(code);

    set({
      ville: { code: ville.code, nom: ville.nom, affiche_communes: ville.affiche_communes },
      source: 'choix',
      horsZone: false,
      communes: ville.communes,
      choixRequis: false,
    });
  },

  async positionFraiche() {
    const { coords, mesureeA } = get();

    if (coords !== null && mesureeA !== null && Date.now() - mesureeA < FRAICHEUR_POSITION_MS) {
      return coords;
    }

    // Périmée ou absente : on REMESURE. Une position vieille d'une heure classerait les hôpitaux
    // depuis un endroit où l'on n'est plus.
    const resultat = await obtenirPosition();

    if (!resultat.ok) {
      // On efface ce qu'on avait : garder une position périmée après un refus reviendrait à
      // continuer d'utiliser ce que l'utilisateur vient de refuser.
      set({ coords: null, mesureeA: null });
      return null;
    }

    set({ coords: resultat.coords, mesureeA: Date.now() });

    return resultat.coords;
  },
}));

/** Traduit la réponse du serveur en état d'écran. Aucune règle ici : on recopie. */
function depuisReponse(localisation: Localisation) {
  return {
    ville: localisation.ville,
    source: 'position' as SourceVille,
    horsZone: localisation.hors_zone,
    communes: localisation.communes,
    villesParProximite: localisation.villes_par_proximite,
    choixRequis: false,
  };
}

/** Ressort une ville mémorisée, en la marquant clairement comme telle. */
function appliquerMemoire(villes: Ville[], code: string) {
  const ville = villes.find((v) => v.code === code);
  if (!ville) return { choixRequis: villes.length > 0 };

  return {
    ville: { code: ville.code, nom: ville.nom, affiche_communes: ville.affiche_communes },
    source: 'memoire' as SourceVille,
    horsZone: false,
    communes: ville.communes,
    choixRequis: false,
  };
}

async function memoriserVille(code: string): Promise<void> {
  try {
    await SecureStore.setItemAsync(CLE_MEMOIRE, code);
  } catch {
    // Mémoriser est un confort, pas une condition de fonctionnement.
  }
}

async function lireVilleMemorisee(): Promise<string | null> {
  try {
    return await SecureStore.getItemAsync(CLE_MEMOIRE);
  } catch {
    return null;
  }
}
