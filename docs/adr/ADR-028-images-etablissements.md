# ADR-028 — Images des établissements : publiques, gouvernées par leur empreinte (P6.4c)

**Statut : Accepté** — 2026-08-13 · Contexte : CDC_09 §4.2 · CDC_11 §3.1 · CDC_04 §20 · §1.2.4 · Suit [ADR-026](ADR-026-referentiel-etablissements.md) et [ADR-027](ADR-027-villes-geolocalisation.md) · Contraint par [ADR-011](README.md) (portail Next) et [ADR-009](README.md) (Expo Go).

---

## 1. Contexte

CDC_09 §4.2 range les « images (logo, photos) » parmi les informations conservées du référentiel des établissements, et CDC_11 §3.1 en fait un **« formulaire dédié »** nommant cinq sujets : logo, accueil, salle d'attente, bloc opératoire, parking.

Le G0 a établi quatre choses.

**H1 — Aucune image nulle part.** `structures_sanitaires` n'a aucune colonne d'image : vérifié sur les trois migrations qui la touchent, sur le modèle et sur le type mobile. La fiche affichait une icône choisie d'après le type. Rien à réparer, tout à poser.

**H2 — Tout ce que ce projet stocke est privé ET chiffré.** Deux chaînes existent et sont identiques dans l'esprit : `DocumentStorageService` (disque privé, blob AES, nom UUID, MIME par `finfo`, antivirus) et `PhotoMembreService` (mêmes gardes, sans antivirus). **Le disque `public` n'a jamais servi** — `services/api/public/` ne contient pas de lien `storage`. Une image publique serait le **premier stockage public du projet**.

**H3 — `APP_URL` vaut l'URL Ngrok**, et `disks.public.url` est bâti dessus. Toute URL absolue changerait à chaque redémarrage du tunnel. S'ajoute que `storage:link` crée un lien symbolique, qui sur Windows exige des droits administrateur.

**H4 — Le portail établissements est en Blade**, et ADR-011 programme sa migration vers Next. **P6.4a a déjà reporté l'onboarding « Méthode 2 » pour ne pas construire deux fois** (limite M1) ; le raisonnement s'applique mot pour mot au formulaire d'images.

---

## 2. Décision principale : les images entrent dans le référentiel gouverné, **par leur empreinte**

Le propriétaire a décidé le 2026-08-13 que les images entrent dans la projection gouvernée d'ADR-026 — **contre la recommandation initiale**, qui les rangeait avec le téléphone et les horaires.

**La décision se tient.** La ligne d'ADR-026 sépare ce qui est *déposé délibérément par un humain identifié* de ce qui est *recalculé automatiquement*. `note_moyenne` est recalculée à chaque avis citoyen ; une photo de bloc opératoire est mise en ligne par un gestionnaire habilité, comme un numéro d'autorisation. La ligne n'est donc pas franchie.

**La conséquence est réelle et assumée** : déposer ou remplacer une image fait **diverger le référentiel publié** jusqu'à la proposition et la publication d'une nouvelle version. C'est précisément ce que « gouverné » signifie.

### Ce qui entre n'est pas le fichier

Trois formes étaient possibles ; deux sont écartées.

| Forme | Écartée parce que |
|---|---|
| Les octets en base64 | L'instantané deviendrait énorme — c'est la limite **L6** d'ADR-025, qu'on aggraverait au lieu de la traiter. |
| Le chemin de stockage | C'est un UUID interne : **redéposer exactement la même image ferait diverger le référentiel alors que rien n'a changé**, et l'instantané publierait des détails d'implémentation. |
| **Catégorie + empreinte SHA-256 du contenu** ✅ | Stable, minuscule, et exactement sensible à ce qui compte. |

L'instantané dit alors : *« cet établissement publie un logo dont le contenu vaut `d78c65…` »*. **Deux vecteurs en miroir le prouvent, et aucun ne suffit seul** : déposer une image **doit** faire diverger ; supprimer puis redéposer le même fichier octet pour octet — donc avec un UUID de stockage neuf — ne **doit pas**.

La liste est **triée** (catégorie, puis empreinte). Sans tri, deux ensembles d'images identiques produiraient deux empreintes différentes selon l'ordre d'insertion, et le référentiel divergerait sans raison.

---

## 3. Décisions secondaires

### 3.1 Disque privé, contenu non chiffré, diffusion par contrôleur *(I2)*

Le propriétaire avait retenu « disque public, non chiffré ». Le **non-chiffrement est conservé** — une vitrine d'hôpital n'a rien à protéger, et la déchiffrer à chaque affichage coûterait pour rien. Le **disque public est écarté** au vu de H3 : il exige un lien symbolique et bâtit ses URL sur `APP_URL`.

La diffusion passe donc par un contrôleur, ce qui donne trois choses que le disque public ne donnait pas : une **URL relative** (`/api/v1/structures/12/images/34`), stable quel que soit le tunnel ; un `ETag` **gratuit**, puisque l'empreinte est déjà calculée ; et la possibilité d'ajouter une garde si une image devait un jour cesser d'être publique.

La lecture est **publique**, comme le reste de l'annuaire : exiger une authentification empêcherait un citoyen de reconnaître un établissement avant sa première connexion.

### 3.2 Les catégories sont des données, et le quota aussi *(I4)*

Table de référence `categories_image_etablissement`, pas une énumération PHP : ajouter « pharmacie interne » demain doit coûter **une ligne** (§1.2.4). Elle porte `max_par_etablissement`, ce qui fait de **« un établissement n'a qu'un logo » une donnée** et non un `if ($categorie === 'logo')` — même principe que `villes.affiche_communes` en P6.4b, pour la même raison (CDC_04 §20).

Le quota est vérifié **par le service sous verrou pessimiste** et non par un déclencheur : sous MySQL, un déclencheur ne peut pas interroger la table qu'il garde (erreur 1442). La limite est **annoncée** (O5) plutôt que déguisée en garantie du moteur. Seule l'unicité « même image dans la même catégorie » est déclarative.

### 3.3 Cinq gardes au dépôt, dont aucune ne rattrape les autres

Habilitation · catégorie active · quota sous verrou · **nature réelle du fichier** · nom de stockage UUID.

L'habilitation a **deux chemins**, et le second est le plus important : la permission nationale `etablissement.manage`, **ou** être gestionnaire de **cet** établissement (`users.structure_id`) — CDC_11 §3 fait remplir la vitrine par l'hôpital lui-même. La vérification se fait par `can()` **dans le service** et non par le middleware `permission:` de spatie : les routes sont Sanctum, les permissions sont sur le guard `web` (piège de P4, déjà retombé en P6.3).

### 3.4 Le formulaire attend le portail Next *(I1)*

API et affichage mobile maintenant ; écran d'administration avec la migration du portail (H4). Les citoyens **voient** les images dès à présent ; seul le formulaire attend.

### 3.5 Pas d'antivirus, par symétrie explicite

`PhotoMembreService` n'en a pas non plus : image publique, déposée par un gestionnaire identifié et habilité, jamais exécutée, servie avec son type réel. Le crible reste MIME réel + liste blanche + « vraie image ».

La liste blanche est **plus étroite** que celle des photos de profil : `heic`/`heif` en sont absents. Une photo de profil vient du téléphone du patient et n'est vue que par lui ; une vitrine est servie à tous les navigateurs, or HEIC n'est pas rendu partout — **accepter un format qu'une partie des lecteurs ne sait pas afficher reviendrait à publier une image invisible**.

---

## 4. Ce que le G2 et les tests ont trouvé

**Un trou réel dans la garde 4.** `getimagesizefromstring` ne renvoie pas `false` sur une image de zéro pixel mais `[0, 0]`. Un PNG dont l'en-tête IHDR porte des dimensions nulles est annoncé « image/png » par `finfo` — donc le premier crible le laisse passer — et le second, qui ne testait que `false`, le laissait entrer dans le stockage public. Corrigé en exigeant des dimensions **positives**. C'est le vecteur de test qui l'a trouvé, pas la relecture.

**Dix tests qui ne vérifiaient rien.** `abort()` lève une `HttpException` dont `getCode()` vaut 0 : les assertions écrites avec `expectExceptionCode(403)` passaient sans rien contrôler, et un 500 s'y serait fait passer pour un 403.

**Un commentaire qui promettait plus que le code**, et une fuite mineure : le modèle annonçait que l'empreinte ne sortait pas alors que seul `chemin` était caché, et `depose_par` — un identifiant de compte — sortait sur un endpoint **public**. Corrigé : `depose_par` reste en base pour l'imputabilité, il ne devient pas une donnée d'annuaire.

---

## 5. Conséquences et limites

**Acquis.** Les établissements publient logo et photos dans les cinq catégories du corpus ; les images sont servies publiquement, affichées sur la carte et la fiche, et gouvernées par leur empreinte.

**Limites assumées, reportées dans le guide (partie 4).**

- **O1 — Aucun écran d'envoi** ; API seule jusqu'au portail Next *(§3.4)*.
- **O2 — Pas d'antivirus** *(§3.5)*.
- **O3 — Ni redimensionnement ni vignette** : ce serait du traitement d'image, donc une dépendance ou une logique neuve. La taille est bornée à l'entrée (4 Mo).
- **O4 — Pas d'images hors ligne** : le cache P2 stocke du JSON chiffré, pas du binaire. La fiche retombe sur l'icône, et **ce n'est pas une panne**.
- **O5 — Le quota est tenu par le service, pas par le moteur** *(§3.2)*.

**Aucune dépendance nouvelle.** GD est fourni avec PHP ; le hachage et `finfo` sont natifs.
