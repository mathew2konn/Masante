/**
 * types/document.ts — Documents médicaux importés (F2.10).
 *
 * Miroir des champs exposés par le backend (DocumentMedicalController). Le chemin de stockage
 * (`fichier_url`, blob chiffré) N'EST PAS exposé ici : le téléchargement passe par l'endpoint dédié.
 */

/** Catégories (miroir de DocumentMedical::CATEGORIES côté API). */
export type CategorieDocument =
  | 'certificat_medical'
  | 'fiche_sortie'
  | 'compte_rendu'
  | 'imagerie'
  | 'resultat_labo'
  | 'assurance'
  | 'ordonnance_externe'
  | 'autre';

/** Statut antivirus : verrou de téléchargement côté serveur. */
export type StatutAntivirus = 'en_attente' | 'sain' | 'infecte';

export type DocumentMedical = {
  id: number;
  categorie: CategorieDocument;
  titre: string;
  nom_fichier_original: string;
  mime_type: string;
  extension: string;
  taille_octets: number;
  statut_antivirus: StatutAntivirus;
  source: 'patient' | 'medecin' | 'structure';
  date_document: string | null;
  created_at: string;
};

/** Libellés lisibles + ordre d'affichage des catégories (regroupement de la liste). */
export const CATEGORIES: { value: CategorieDocument; label: string; icone: string }[] = [
  { value: 'certificat_medical', label: 'Certificat médical', icone: 'ribbon-outline' },
  { value: 'ordonnance_externe', label: 'Ordonnance', icone: 'reader-outline' },
  { value: 'resultat_labo', label: "Résultat d'analyse", icone: 'flask-outline' },
  { value: 'imagerie', label: 'Imagerie', icone: 'scan-outline' },
  { value: 'compte_rendu', label: 'Compte rendu', icone: 'document-text-outline' },
  { value: 'fiche_sortie', label: 'Fiche de sortie', icone: 'exit-outline' },
  { value: 'assurance', label: 'Assurance', icone: 'card-outline' },
  { value: 'autre', label: 'Autre', icone: 'ellipsis-horizontal-circle-outline' },
];

export const LIBELLE_CATEGORIE: Record<CategorieDocument, string> = Object.fromEntries(
  CATEGORIES.map((c) => [c.value, c.label]),
) as Record<CategorieDocument, string>;

/** Présentation d'un statut antivirus (libellé + rôle sémantique de couleur). */
export const STATUT_PRESENTATION: Record<
  StatutAntivirus,
  { label: string; ton: 'success' | 'warning' | 'danger' }
> = {
  sain: { label: 'Disponible', ton: 'success' },
  en_attente: { label: 'Analyse en cours', ton: 'warning' },
  infecte: { label: 'Fichier rejeté', ton: 'danger' },
};
