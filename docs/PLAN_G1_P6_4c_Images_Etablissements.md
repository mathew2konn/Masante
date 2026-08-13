# PLAN G1 — P6.4c « Images des établissements » (CDC_09 §4.2, CDC_11 §3)

**Statut : ✅ VALIDÉ (G5, 2026-08-13) — G2 et G3 prouvés, G4 propriétaire OK.**
Décision consignée : [ADR-028](adr/ADR-028-images-etablissements.md) · Guide : [GUIDE_TEST_REFERENTIELS.md](../GUIDE_TEST_REFERENTIELS.md) **partie 4**.
Suit P6.4b (validé G5) · Précède P6.4d (formulaires du portail).

---

## 1. Le besoin, tel que le corpus l'exprime

> CDC_09 §4.2 — « S'y ajoutent les informations collectées lors de l'onboarding (CDC_11) : […]
> **images (logo, photos)**, description. »
>
> CDC_11 §3.1 — « **Images (formulaire dédié)** : logo, photos, salle d'attente, bloc opératoire,
> accueil, parking s'il existe. »

Le corpus nomme donc **cinq sujets** et qualifie explicitement l'ensemble de **formulaire dédié**.

---

## 2. G0 — ce que la lecture du code a établi

### H1 — Aucune image nulle part sur les établissements
`structures_sanitaires` n'a **aucune** colonne d'image : vérifié sur les trois migrations qui la
touchent, sur le modèle, et sur `apps/mobile/src/types/structure.ts`. La fiche mobile affiche une
icône Ionicons choisie d'après le type. Le manque est total, il n'y a rien à réparer — tout est à
poser.

### H2 — Tout ce que ce projet stocke est privé ET chiffré
Deux chaînes existent, et elles sont identiques dans l'esprit :
`DocumentStorageService` (disque `documents`, blob `Crypt` AES, nom UUID, MIME par `finfo`,
antivirus) et `PhotoMembreService` (disque `avatars`, mêmes gardes moins l'antivirus, « image prise
par l'utilisateur, affichée à lui seul »).
**Le disque `public` n'a jamais servi** : `services/api/public/` ne contient pas de lien `storage`,
`artisan storage:link` n'a jamais été exécuté. Une image publique serait le **premier stockage
public du projet**.

### H3 — `APP_URL` vaut l'URL Ngrok, et `disks.public.url` est bâti dessus
Toute URL absolue produite par `Storage::url()` changerait à **chaque redémarrage de Ngrok**.
S'ajoute que `storage:link` crée un **lien symbolique**, qui sur Windows exige le mode développeur
ou des droits administrateur. C'est un risque de G2 concret, constaté et non supposé.

### H4 — Le portail établissements est en Blade, et ADR-011 le condamne
Gardé par `permission:etablissement.manage`. Or **P6.4a a explicitement reporté l'onboarding
« Méthode 2 » au portail Next pour ne pas construire deux fois** (limite M1). Le même raisonnement
s'applique mot pour mot au formulaire d'images.

### H5 — Le lien gestionnaire ↔ établissement existe déjà
`users.structure_id` + rôle `gestionnaire_etablissement` : un établissement a déjà ses gestionnaires
identifiés. Rien à inventer pour savoir « qui a le droit de publier les images de cet hôpital ».

---

## 3. Décisions du propriétaire (2026-08-13)

| # | Décision | Justification |
|---|---|---|
| **I1** | **API + affichage mobile** ; l'écran d'administration viendra avec le portail Next. | Cohérent avec la limite **M1** de P6.4a (H4). Les images sont **vues** dès maintenant par les citoyens ; seul le formulaire attend. |
| **I2** | **Route de contrôleur, disque privé NON CHIFFRÉ.** | Tient la décision « non chiffré » (une image publique n'a rien à protéger, et déchiffrer à chaque affichage coûterait pour rien), **sans** le lien symbolique ni la dépendance à `APP_URL` (H3). L'URL servie est **relative** — donc insensible au changement de Ngrok. |
| **I3** | **Les images ENTRENT dans le référentiel gouverné** (projection ADR-026). | Choix du propriétaire, **contre ma recommandation** — voir §4, où la conséquence est énoncée et où la forme retenue la rend tenable. |
| **I4** | **Liste fermée de catégories, EN DONNÉES.** | §1.2.4. Une table de référence, pas une constante PHP : ajouter « pharmacie interne » demain doit coûter **une ligne de données**. La table porte aussi `max_par_etablissement`, ce qui fait de « le logo est unique » une **donnée** et non un `if categorie === 'logo'`. |

---

## 4. I3 — ce que « les images entrent dans le référentiel » implique vraiment

**La conséquence, dite avant de coder** : à partir de maintenant, **ajouter ou remplacer une image
fait diverger le référentiel publié** jusqu'à ce qu'une nouvelle version soit proposée et publiée.
C'est exactement ce que la gouvernance signifie, et c'est cohérent : une photo est déposée
**délibérément par un humain identifié**, comme un numéro d'autorisation — pas recalculée
automatiquement comme une note d'étoiles. La ligne d'exclusion d'ADR-026 n'est donc pas franchie.

**Ce qui entre dans l'instantané n'est PAS le fichier.** Trois formes étaient possibles :

| Forme | Écartée parce que |
|---|---|
| Les octets en base64 | L'instantané deviendrait énorme — c'est précisément la limite **L6** d'ADR-025, qu'on aggraverait au lieu de la traiter. |
| Le chemin de stockage | C'est un UUID interne : **renvoyer exactement la même image ferait diverger le référentiel** alors que rien n'a changé, et l'instantané publierait des détails d'implémentation. |
| **La catégorie + l'empreinte SHA-256 du contenu** ✅ | Stable, minuscule, et **exactement sensible à ce qui compte** : changer le logo diverge, redéposer le même octet pour octet ne diverge pas. |

L'instantané dira donc : *« cet établissement publie un logo dont le contenu vaut `a3f…`, et des
photos d'accueil et de bloc opératoire. »* La liste est **triée** (catégorie, puis empreinte) : sans
tri, deux ensembles identiques produiraient deux empreintes différentes selon l'ordre d'insertion,
et le référentiel divergerait sans raison.

---

## 5. Périmètre

1. Table de référence `categories_image_etablissement` (`code`, `libelle`, `max_par_etablissement`,
   `ordre`, `actif`) + seeder des **cinq catégories du CDC_11** — logo (max 1), accueil, salle
   d'attente, bloc opératoire, parking.
2. Table `etablissement_images` : structure, catégorie, chemin UUID, MIME réel, taille, **empreinte
   SHA-256**, dimensions, ordre, auteur.
3. Disque `etablissements` (privé, **non chiffré**).
4. `ImagesEtablissement` — service portant **toutes** les gardes : habilitation, catégorie active,
   quota sous verrou, MIME `finfo`, taille, image réelle, nom UUID, extension déduite du MIME.
5. `POST /v1/structures/{s}/images` · `DELETE /v1/structures/{s}/images/{i}` (Sanctum) ·
   `GET /v1/structures/{s}/images/{i}` (**public**, diffusé par le contrôleur, `ETag` = empreinte).
6. `SourceEtablissements` += `images` (catégorie + empreinte, triées).
7. Mobile : logo sur la carte de résultat, galerie sur la fiche.

## 6. Hors périmètre

| # | Limite |
|---|---|
| **O1** | **Aucun écran d'envoi** — API seule jusqu'au portail Next *(I1, H4)*. |
| **O2** | **Pas d'antivirus** sur ces images, par symétrie explicite avec `PhotoMembreService` : image publique déposée par un gestionnaire **identifié et habilité**, jamais exécutée. Le crible reste « vraie image » (`getimagesize`) + MIME réel + liste blanche. |
| **O3** | **Ni redimensionnement ni vignette** : ce serait du traitement d'image, donc une dépendance ou une logique neuve. La taille est **bornée** à l'entrée. |
| **O4** | **Pas d'images hors ligne** : le cache P2 stocke du JSON chiffré, pas du binaire. La fiche retombe sur l'icône. |
| **O5** | Le quota « au plus N par catégorie » est tenu **par le service sous verrou**, pas par le moteur : un déclencheur MySQL ne peut pas interroger la table qu'il garde (erreur 1442). À vérifier au G2, pas à supposer. |

## 7. Preuves attendues

- **G2 live** : envoi des 5 catégories, quota du logo refusé, MIME menteur refusé, fichier non-image
  refusé, diffusion publique avec le bon `Content-Type`, URL insensible à Ngrok, **ajout d'image →
  référentiel DIVERGENT**, redépôt du même octet pour octet → **PAS de divergence**, suppression.
- **G3** : tests dédiés dans les deux sens ; suite complète (référence : **500 tests / 14 649
  assertions**) ; typecheck ×3 ; `expo-doctor`.
- **G4** : **partie 4** du guide `GUIDE_TEST_REFERENTIELS.md`.
