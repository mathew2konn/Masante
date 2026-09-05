import { create } from 'zustand';

/**
 * Le panier de médicaments (B3-d, F1) — état LOCAL, jamais envoyé au serveur tant que la
 * commande n'est pas passée.
 *
 * *Le contenu d'un panier de médicaments dit ce dont on se soigne* — c'est une donnée de santé
 * que le serveur n'a pas à conserver pour le seul confort de survivre à un changement d'appareil.
 * Le serveur ne reçoit que la COMMANDE (l'acte), jamais le panier (l'intention).
 *
 * MONO-OFFICINE (F2) : « le patient choisit LA pharmacie où le médicament est disponible ». Un
 * panier réparti sur plusieurs officines produirait une commande que personne ne peut honorer. Le
 * store reste volontairement SANS RÈGLE : c'est l'écran qui décide quoi faire d'un changement
 * d'officine (proposer de vider le panier) — la frontière CDC_01 §0.1 vaut aussi pour ce genre de
 * décision, même minime.
 */
export interface LignePanier {
  medicamentId: number;
  nom: string;
  dosage?: string | null;
  ordonnanceRequise: boolean;
  prixUnitaireCfa: number | null;
  quantite: number;
}

interface PanierState {
  structureId: number | null;
  structureNom: string | null;
  lignes: LignePanier[];

  /** Démarre (ou poursuit) un panier pour cette officine. */
  definirOfficine: (structureId: number, structureNom: string) => void;

  /** Ajoute une ligne, ou augmente sa quantité si le produit y est déjà. */
  ajouterLigne: (ligne: Omit<LignePanier, 'quantite'>, quantite?: number) => void;

  retirerLigne: (medicamentId: number) => void;

  modifierQuantite: (medicamentId: number, quantite: number) => void;

  vider: () => void;
}

export const usePanier = create<PanierState>((set) => ({
  structureId: null,
  structureNom: null,
  lignes: [],

  definirOfficine: (structureId, structureNom) => set({ structureId, structureNom, lignes: [] }),

  ajouterLigne: (ligne, quantite = 1) =>
    set((etat) => {
      const existante = etat.lignes.find((l) => l.medicamentId === ligne.medicamentId);
      if (existante) {
        return {
          lignes: etat.lignes.map((l) =>
            l.medicamentId === ligne.medicamentId ? { ...l, quantite: l.quantite + quantite } : l,
          ),
        };
      }
      return { lignes: [...etat.lignes, { ...ligne, quantite }] };
    }),

  retirerLigne: (medicamentId) =>
    set((etat) => ({ lignes: etat.lignes.filter((l) => l.medicamentId !== medicamentId) })),

  modifierQuantite: (medicamentId, quantite) =>
    set((etat) => ({
      lignes: quantite < 1
        ? etat.lignes.filter((l) => l.medicamentId !== medicamentId)
        : etat.lignes.map((l) => (l.medicamentId === medicamentId ? { ...l, quantite } : l)),
    })),

  vider: () => set({ structureId: null, structureNom: null, lignes: [] }),
}));
