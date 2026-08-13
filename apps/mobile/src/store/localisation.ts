import * as SecureStore from 'expo-secure-store';
import { create } from 'zustand';
import { chargerVilles, localiserVille } from '../api/villes';
import { obtenirPosition } from '../utils/geoloc';
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

  initialiser: () => Promise<void>;
  demanderPosition: () => Promise<void>;
  choisirVille: (code: string) => Promise<void>;
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
