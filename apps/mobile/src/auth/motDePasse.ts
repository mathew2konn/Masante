/**
 * motDePasse.ts — critères de la politique de mot de passe (miroir exact du backend
 * App\Rules\PasswordPolicy). Utilisé par la barre de force (inscription, réinitialisation,
 * changement) pour dire à l'utilisateur, au fur et à mesure, ce qu'il manque.
 *
 * Le contrôle « non compromis » (HIBP) est purement serveur : il n'apparaît pas ici.
 */

export type CritereMdp = { cle: string; libelle: string; ok: boolean };

/** Évalue les 4 critères vérifiables localement (longueur, casse mixte, chiffre, symbole). */
export function criteresMotDePasse(valeur: string): CritereMdp[] {
  return [
    { cle: 'longueur', libelle: 'Au moins 8 caractères', ok: valeur.length >= 8 },
    { cle: 'casse', libelle: 'Une majuscule et une minuscule', ok: /[A-Z]/.test(valeur) && /[a-z]/.test(valeur) },
    { cle: 'chiffre', libelle: 'Au moins un chiffre', ok: /\d/.test(valeur) },
    { cle: 'symbole', libelle: 'Un symbole (#, @, !…)', ok: /[^A-Za-z0-9]/.test(valeur) },
  ];
}

/** Le mot de passe satisfait-il tous les critères locaux ? (le serveur ajoute le contrôle HIBP.) */
export function motDePasseValide(valeur: string): boolean {
  return criteresMotDePasse(valeur).every((c) => c.ok);
}
