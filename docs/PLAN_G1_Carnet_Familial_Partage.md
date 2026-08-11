# Plan G1 — Carnet familial partagé (remplace P6.2 MPI)

**Origine** : décision du propriétaire du 2026-08-11. La fusion de dossiers est abandonnée ;
le partage familial et la revendication la remplacent.
**Statut** : soumis à validation — aucun code avant accord écrit (CDC_01 §2.4).

---

## 1. Pourquoi la fusion est abandonnée

Le propriétaire a posé la bonne question : **sur quel élément concret s'appuyer pour fusionner
deux dossiers ?** Deux personnes peuvent porter le même nom et la même date de naissance. Ni un
score, ni un agent devant un écran ne connaissent ces deux personnes. CDC_09 §13 le dit déjà :
les homonymes stricts **ne doivent pas** être fusionnés automatiquement.

S'y ajoute un argument que le projet vient de se donner : **le NIS rend la fusion largement
inutile**. Depuis P6.1, chaque dossier porte un identifiant national. La fusion ne répare que les
doublons nés **avant** le NIS — ce projet n'en a pratiquement aucun. Construire un moteur de
fusion aujourd'hui, c'est outiller un problème qu'on n'a pas.

**Le remplacement retenu** : au lieu de réparer un doublon après coup, on l'empêche de naître.
La preuve n'est plus un score, ce sont **deux actes humains indépendants** — le responsable de
famille qui délègue un carnet à un numéro parce qu'il connaît sa famille, et la personne qui
s'authentifie sur ce numéro et le reconnaît comme le sien.

CDC_09 §2.5 (moteur de rapprochement) et §2.6 (fusion) sont **reportés**, pas oubliés : ils
redeviendront utiles quand des **établissements** créeront des dossiers (P6.4 / CDC_11). Écart
déclaré, motivé, daté.

---

## 2. Le scénario cible (mots du propriétaire)

Un responsable crée le compte principal et ajoute tous les carnets de la famille. Quand les autres
membres créent leur propre compte, il leur délègue les carnets. Chacun voit alors les carnets de
tous : ce qu'un médecin ajoute au carnet d'un enfant, les parents le voient ; ce qui est ajouté au
carnet de la mère après une consultation, le père le voit. Si un membre a un accident et que sa
carte vitale est consultée, toute la famille l'apprend sans qu'on l'appelle.

**Écriture par un délégué** : les parents sont en voyage, un enfant de trois ans est malade, la
personne restée à la maison l'emmène à l'hôpital. Elle doit pouvoir écrire — elle ne peut pas
attendre le retour des parents. Ce qu'elle écrit part **au brouillon**. Les responsables reçoivent
une notification, consultent la **fiche de parcours** (médecin, établissement, journal d'audit,
ordonnance), appellent la personne pour vérifier, puis valident. Quand l'un des deux responsables
valide, l'autre en est informé.

---

## 3. La correction qui compte

Le scénario dit « tout ce qui sera ajouté par le médecin **et autre** ». Il faut séparer les deux,
parce qu'ils n'ont ni la même valeur ni le même risque.

| Qui écrit | Voie | Provenance | Traitement |
|-----------|------|-----------|------------|
| **Le médecin, à l'hôpital** | accès professionnel (QR, référent, bris de glace) | `source = medecin` / `structure` | **Écrit directement.** Jamais de brouillon. |
| **Un délégué de la famille** | son application | `source = patient` | **Brouillon**, validé par un responsable |

**Une ordonnance de médecin ne doit jamais attendre l'accord d'un parent.** Si le père est en
réunion pendant trois jours, le traitement de l'enfant n'est pas « en attente de validation ». Le
brouillon encadre la **contribution familiale auto-déclarée**, pas l'acte médical.

Le schéma porte déjà cette distinction : les tables du carnet ont une colonne
`source ENUM('patient','medecin','structure')` et `added_by ENUM('patient','medecin')`. On s'appuie
dessus, on ne réinvente rien.

### Règle de sécurité clinique

**Un brouillon est visible, jamais caché.** Il apparaît dans le carnet, clairement marqué
« en attente de validation — ajouté par X », et il est **inclus dans la session de consultation
professionnelle**, avec la même marque.

Un fait médical non validé reste un fait médical. Si un enfant est vu par un second médecin deux
jours plus tard, ce médecin doit voir ce qui a été noté, même sans l'accord du père. La validation
est un **acte de gouvernance familiale et de confiance**, pas un critère de vérité clinique.
Confondre les deux mettrait quelqu'un en danger.

---

## 4. Ce qui existe déjà (bonne nouvelle)

| Besoin | État |
|--------|------|
| Délégation invitée par téléphone, acceptée, révocable des deux côtés | ✅ `delegations` + `DelegationController` (P1/B3) |
| Journal des accès professionnels : agent, type, sections consultées, **données ajoutées** (JSON), IP, durée | ✅ `acces_dossier`, alimenté par QR / référent / bris de glace |
| Provenance des entrées du carnet | ✅ `source` + `added_by` sur les tables du carnet |
| Type d'accès `delegation` | ✅ déjà dans l'ENUM `type_acces` |
| Écran « Partages reçus » | ✅ `/(app)/partages` |

**La fiche de parcours est donc surtout un travail d'assemblage**, pas de collecte.

## 5. Ce qui manque

| Besoin | À construire |
|--------|--------------|
| Un délégué peut **lire** le dossier | `delegations.droits` ne vaut que `qr_generation` ; `MembreFamillePolicy::view` est propriétaire-seul |
| Un délégué peut **écrire** | n'existe pas |
| Brouillon + validation | n'existe pas |
| Second responsable de famille | aucune notion de responsable |
| Notifications | aucune table ; l'invitation de délégation n'est qu'un `Log::info` (stub annoncé « push au M3 ») |
| Revendication d'un carnet | n'existe pas ; P6.1 force la création d'un dossier titulaire |

---

## 6. Décisions de conception

### 6.1 Le brouillon vit dans **une** table, pas dans huit
Une colonne `statut` sur chaque table du carnet (`antecedents`, `ordonnances`, `vaccinations`,
`resultats_analyses`, `documents_medicaux`, `mesures_sante`, `notes_observations`…) obligerait à
modifier huit tables de modules validés G5.

À la place : **`contributions`**, une table unique qui porte la contribution proposée (section
visée, charge utile JSON, auteur, membre, état). À la validation, l'entrée est écrite dans la vraie
table par le service existant. Au rejet, rien n'est écrit. **Zéro modification des tables du
carnet.**

### 6.2 La revendication ne repose sur aucun score
Au moment de déléguer, le responsable coche : **« Ce carnet est celui de la personne que
j'invite »**. C'est une assertion humaine, explicite et tracée.

Côté délégué, avant l'écran de complétion de P6.1 : « Un carnet à votre nom a été créé par X.
Est-ce le vôtre ? » S'il confirme, il en devient **propriétaire** ; une délégation part
automatiquement en sens inverse pour que le responsable continue de le voir.

**L'ordre est impératif** : la revendication passe **avant** la création. Après, il existe deux NIS
pour une personne — et un NIS ne se libère jamais (P6.1). On serait revenu au problème de fusion
par la porte de derrière.

### 6.3 Propriété du dossier
Aujourd'hui, le responsable reste propriétaire du carnet médical d'une autre personne adulte, qui
n'en est que déléguée : elle ne peut ni le modifier, ni en retirer l'accès au responsable. La
revendication corrige cela — **une fois propriétaire de son carnet, la personne décide qui le
voit**. Pour un enfant, la propriété reste au parent : c'est légitime.

### 6.4 Notifications : en application, pas en push
`expo-notifications` est un module natif — dépendance nouvelle (accord écrit requis, §2.6) et
limité sous Expo Go. On livre une **liste de notifications en application avec badge** ; le push
reste « prêt à activer ».

### 6.5 Responsables de famille
Table `responsables_famille` : compte principal → responsable désigné, date, révocation. Le créateur
du compte est responsable de droit ; il peut en désigner **un second** au moment d'une délégation,
ou rester seul. Toutes les familles n'ont pas un père et une mère : la règle est « le créateur
décide », pas « les parents ».

---

## 7. Découpage en quatre sous-incréments

### A — Partage du carnet en lecture
- `delegations.droits` += `lecture`, `lecture_ecriture` (ENUM additif — touche une table de P1).
- `MembreFamillePolicy::view` étendue au délégué actif. **Modification la plus sensible du
  projet** : c'est la barrière anti-IDOR de P2. Chirurgicale, et couverte de tests dédiés.
- **Chaque lecture déléguée journalisée** dans `acces_dossier` (`type_acces = delegation`).
- Délégation en masse : partager tous les carnets d'un coup avec un nouveau membre.
- Écran « Partages reçus » : les carnets délégués apparaissent à côté des siens, marqués de leur
  origine (« partagé par X »).
- **Révocation en un geste**, sans justification, effet immédiat.

**Prouve** : un délégué lit, un non-délégué reçoit 403, la révocation coupe instantanément, chaque
lecture laisse une trace nominative.

### B — Revendication du carnet
- `delegations.est_le_dossier_du_delegue`.
- Écran de revendication **avant** l'écran de complétion P6.1.
- Transfert : `user_id`, `est_titulaire`, délégation inverse automatique, trace dans
  `carnet_transferts` (append-only).
- Gardes : revendication offerte seulement si le demandeur n'a **pas** de dossier titulaire ; le
  NIS suit le carnet et n'est jamais réattribué.

**Prouve** : aucun second NIS créé ; l'ancien propriétaire garde la vue ; le nouveau propriétaire
peut lui retirer l'accès.

### C — Contributions au brouillon + responsables
- `responsables_famille` (désignation, révocation, trace).
- `contributions` : section visée, charge utile, auteur, membre, état
  `BROUILLON → VALIDEE | REJETEE`.
- Écriture d'un délégué → contribution ; écriture du propriétaire → directe.
- Validation par un responsable → l'entrée est écrite dans la table réelle par le service existant.
- Le brouillon **est affiché** dans le carnet et dans la session professionnelle, marqué.

**Prouve** : un délégué ne peut pas écrire directement ; un non-responsable ne peut pas valider ;
une contribution validée produit exactement l'entrée attendue ; une contribution rejetée n'écrit
rien.

### D — Notifications et fiche de parcours
- `notifications` : destinataire, type, référence, lu/non lu.
- Événements : contribution déposée (→ responsables), validée (→ second responsable + auteur),
  rejetée (→ auteur), délégation reçue (remplace le `Log::info` actuel), désignation de responsable.
- **Fiche de parcours** : assemble la contribution, les lignes d'`acces_dossier` de la fenêtre
  (médecin, type d'accès, sections consultées, données ajoutées), et les entrées de source
  `medecin` créées sur la période — de quoi vérifier étape par étape avant de valider.
- Message au second responsable : « X a validé l'ajout au carnet de Y par Z », avec accès au détail.

**Prouve** : les deux responsables sont notifiés ; la fiche restitue le médecin, l'établissement et
le journal ; la validation de l'un est visible de l'autre.

---

## 8. Nommage

Ce module n'est pas le MPI. Il relève du carnet (CDC_01/CDC_02), pas des données nationales
(CDC_09). Le classer « P6.2 » le rendrait introuvable.

**Proposition : `P7 — Carnet familial partagé`**, et CDC_09 §2 (MPI) explicitement reporté au
moment où des établissements créeront des dossiers.

---

## 9. Interdits vérifiés (CDC_00 §4)

- Aucune règle médicale : le module gère des droits et un circuit de validation, jamais un soin.
- Aucune IA.
- **Accès au dossier sans lien de prise en charge** : la délégation *est* le lien, elle est
  explicite, acceptée, tracée et révocable — et chaque lecture est journalisée.
- Aucune logique métier dans le front : états, droits et transitions viennent du backend.
- Le brouillon ne masque aucune donnée à un soignant (§3, règle de sécurité clinique).

---

## 10. Preuves attendues

| Gate | Preuve |
|------|--------|
| **G2** | MySQL live : lecture déléguée + 403 du non-délégué, révocation immédiate, journalisation, revendication sans second NIS, contribution validée/rejetée, notifications émises |
| **G3** | Suites PHP vertes (dont tests dédiés à la Policy étendue), `pnpm typecheck` |
| **G4** | Expo Go, deux comptes réels : responsable + délégué, sur un vrai partage |
| **G5** | `GUIDE_TEST_CARNET_FAMILIAL.md` (une partie par sous-incrément), ADR dédié, CLAUDE.md et mémoire à jour |
