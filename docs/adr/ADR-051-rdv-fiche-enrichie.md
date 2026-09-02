# ADR-051 — Fiche RDV enrichie : photo du médecin, référent, tarif visible, triage après coup

- **Statut** : **Accepté — B1-b VALIDÉ (G5, 2026-09-02).** G4 propriétaire OK.
- **Date** : 2026-09-02
- **Module** : B1-b — second sous-incrément du lot RDV (suite de B1-a, ADR-050)
- **Corpus** : CDC_11 §3.1 (formulaire dédié, images) · CDC_09 §5.2 (numéro professionnel) · CDC_01 §2.2 (source unique)

---

## 1. Contexte

B1-a a posé le vrai workflow à deux étapes et le tarif au service. B1-b enrichit la fiche que ce
workflow affiche : le G1 (`docs/PLAN_G1_B1_Parcours_RDV.md`, D5/D6/D7) demandait un profil médecin
complet (photo, numéro professionnel), le médecin référent, l'association d'un triage après coup,
et le tarif visible directement — sans naviguer vers un écran séparé.

**Défaut trouvé au G0 de B1-b, dans la propre livraison de B1-a** : `resources/views/portail/rdv/show.blade.php`
n'avait jamais été mise à jour pour le workflow à deux étapes — elle proposait TOUJOURS le
formulaire de confirmation dès `en_attente`, alors que `RendezVousValidationService::confirmer()`
exige désormais `prevalide`. Un accueil qui suivait l'écran Blade tel quel aurait reçu un **409**
que rien n'expliquait à l'écran. Les 46 vecteurs de B1-a exerçaient tous les actions PATCH
directement (curl, `->patch(route(...))`), jamais le RENDU de la vue — aucun ne l'aurait attrapé.
Corrigé ici, avec des vecteurs dédiés qui exercent le rendu HTML pour chaque statut (précédent :
« un vecteur peut prouver le validateur/le service sans jamais prouver la vue »).

## 2. Décisions

### D5 — Photo du médecin, patron ALLÉGÉ de P6.4c
`medecins` gagne `photo_uuid`/`photo_mime`/`photo_empreinte_sha256` (nullables). Contrairement à
`ImagesEtablissement`, **une seule photo par praticien** : pas de table séparée, pas de quota, pas
de catégorie — `PhotoMedecin` (`App\Services\Professionnel`) fait le même double crible sur la
nature du fichier (MIME sur les octets, dimensions réellement positives — le vecteur du PNG à
zéro pixel de P6.4c est reproduit ici et reste vert) mais ne revérifie PAS l'habilitation : elle
vit déjà dans le groupe de routes (`permission:medecin.manage`) et dans
`Portail\MedecinController::fichePossedee()`, la dupliquer créerait deux endroits où elle
pourrait diverger. **Hors projection gouvernée (P6.5a)** : même famille que biographie/tarif —
une photo n'engage aucune autorité nationale. Diffusion publique par
`GET /v1/medecins/{medecin}/photo`, URL relative, `ETag` = empreinte SHA-256 (même patron que
`ImageEtablissementController`).

`numero_professionnel` est exposé pour la première fois à l'API mobile/patient (`Medecin` n'a
jamais eu de `$hidden` ; il n'était simplement jamais SÉLECTIONNÉ par les requêtes à colonnes
restreintes de la fiche RDV patient — corrigé en même temps, avec un vecteur dédié).

### D6 — Référent, orientation libre, triage après coup
Le médecin référent est lu via `ReferentService::actif()` (P6.5a, aucun nouveau mécanisme) et
exposé sur le détail staff (Blade + API Next). `rendez_vous` gagne `motif_orientation`/
`message_orientation` (nullables, facultatifs, saisis par le patient à la réservation) —
**distincts du référent**, qui reste porté par sa table dédiée, et sans aucune conséquence sur le
workflow ou le tarif : affichage staff seul.

Le lien `triage_id` existe depuis la création de la table (P4) ; rien ne permettait de l'ajouter
APRÈS COUP. `PATCH /v1/rendez-vous/{id}/triage` referme ce trou, avec les **mêmes vérifications
anti-IDOR que `store()`** : le membre doit appartenir au compte, le triage doit appartenir au
compte. Bloqué sur un RDV `annule`/`refuse`/`honore` (l'associer n'aurait plus de sens) ; encore
accepté sur `confirme` (le staff peut encore le lire avant l'acte).

### D7 — Tarif visible sans naviguer, statut réglé exposé
`RecuRdvService::tarifPour()` passe de `private` à `public` (B1-a l'avait créée pour la facture ;
B1-b la réutilise en LECTURE SEULE, sans effet de bord, pour un APERÇU) — jamais une seconde
façon de calculer le même montant. Le détail staff (Blade + Next) et la liste patient
(`GET /v1/rendez-vous`) portent désormais `tarif`/`tarif_source`, calculés à CHAQUE lecture et
jamais persistés côté RDV (ils peuvent changer tant que le RDV n'est pas payé — la facture,
elle, fige la source au moment du paiement, comme B1-a l'a posé).

La liste patient porte aussi `regle` (un reçu existe-t-il ?, via un eager-load léger de la
relation `recu` rendu invisible ensuite — `makeHidden`) : c'est ce qui permet à la fiche mobile de
distinguer « Payer » de « Voir le reçu » sans navigation préalable, remplaçant le bouton générique
« Reçu / paiement ». Le montant est aussi transmis à l'écran de paiement lui-même
(`recu/[id].tsx`), qui ne l'affichait pas avant B1-b.

## 3. Preuve

**G3** : suite Laravel complète **1510/1510**, 17 400 assertions (dont les 4 tests de rendu Blade
qui referment le défaut ci-dessus, absents des 46 vecteurs de B1-a) ; Pint propre sur tout le
code neuf (baseline établie contre `HEAD`, méthode inchangée depuis B1-a) ; typecheck ×3 ; `next
lint` sans avertissement ; build Next vert (routes `/rejoindre`, `/rendez-vous/[id]` etc.
inchangées) ; `expo-doctor` **18/18**. **Mutation, quatre gardes tuées** : les deux vérifications
anti-IDOR d'`associerTriage()` (membre, triage), le blocage sur un RDV clos, et le second crible
de `PhotoMedecin` (dimensions réellement positives — même vecteur que P6.4c) ; chaque mutation
confirmée appliquée (le test échoue), puis le fichier restauré et vérifié **octet pour octet**
contre sa copie pré-mutation.

**Deux défauts de test réels trouvés en écrivant les vecteurs, corrigés avant le run vert** :
(1) un vecteur « HEIC refusé » nommait le fichier `.heic` mais lui donnait un contenu PNG réel —
`finfo` lit les OCTETS, jamais l'extension, donc le fichier passait ; remplacé par un vrai GIF
(même vecteur que P6.4c) ; (2) un vecteur assertait `medecin.numero_professionnel` après l'avoir
posé via `Medecin::create([...])` — colonne **délibérément hors `$fillable`** (P6.5a, un client
ne choisit pas son numéro national) : l'assignation de masse l'ignorait silencieusement, la
colonne restait NULL en base et le test échouait sur une fixture mal posée, pas sur le code
testé. Corrigé par assignation directe (`$medecin->numero_professionnel = ...; $medecin->save();`),
qui contourne `$fillable` comme le ferait `AttributeurNumeroProfessionnel` en production.

**G2 live réel** (curl/PowerShell contre un `php artisan serve` dédié, base MySQL dev réelle
sauvegardée puis restaurée compte pour compte) :
- **Le défaut de B1-a, reproduit puis refermé en direct** : la fiche `en_attente` (Blade,
  session `personnel_accueil`) affiche « Patient Awa Kouassi », « 17 500 FCFA (source : service) »,
  « Dr Founeke Traore » (référent), et **« Pré-valider (accueil) » seul** — « Confirmer (médecin) »
  absent de la page. La même fiche vue en session `medecin` après pré-validation affiche
  **« Confirmer (médecin) » seul**. L'onglet filtré `?statut=prevalide` affiche **« Pré-validé »**,
  jamais le mot technique brut.
- **Photo** : dépôt réel (gestionnaire, multipart CSRF) → `photo_uuid`/`photo_mime`/
  `photo_empreinte_sha256` persistés en base ; diffusion publique sans jeton → 200, bon
  `Content-Type`, bon `ETag` ; revalidation `If-None-Match` → 304 ; médecin sans photo → 404 ;
  **upload d'un fichier truqué → 422, la photo réelle déjà en place reste inchangée (même UUID)**.
- **Référent + tarif** (API Next `/api/v1/portail/rendez-vous/{id}`, Bearer réel) :
  `referent.medecin.nom = "Traore"`, `tarif = 17500`, `tarif_source = "service"`.
- **Liste patient** (`GET /api/v1/rendez-vous`, Bearer réel) : `tarif`/`tarif_source`/`regle`
  corrects sur les deux RDV, `regle = false` avant paiement.
- **Triage après coup** : association du triage du compte → 200, `triage_id` posé en base ;
  tentative avec le triage d'un **autre compte** sur un **second** RDV → **403**, `triage_id`
  reste `NULL` en base — vérifié directement en SQL, pas seulement sur la réponse HTTP.

Base restaurée : migrations B1-a/B1-b revenues à `Pending`, zéro compte de test résiduel,
décompte des médecins/RDV identique à l'état d'avant le G2.

## 4. Ce qui n'est pas dans ce lot

Partage temporaire 30 min + présence temps réel Reverb → B1-c. Facture/vérification/pont
GeniusPay/notification de clôture → B1-d.

Voir `docs/PLAN_G1_B1_Parcours_RDV.md` ; guide `GUIDE_TEST_APPLICATIONS_METIER.md` **partie 5**.
