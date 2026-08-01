# CAHIER DES CHARGES N°1 — ARCHITECTURE MOBILE
## Projet MASANTÉ — Plateforme Numérique de Santé de Nouvelle Génération
**Version 2.0 — Document destiné à CLAUDE CODE**

> **Changement majeur par rapport à la v1.0** : l'application n'est plus répartie entre plusieurs agents de génération. **L'intégralité de la plateforme MASANTÉ — mobile, web, backend, base de données, IA, paiement, sécurité — est désormais développée par Claude Code**, dans un seul environnement de travail, sous le contrôle direct du propriétaire du projet. Ce document remplace intégralement la v1.0 destinée à Google AI Studio.

---

## 0. Position du document dans le corpus MASANTÉ

Ce cahier des charges fait partie d'un corpus de 13 documents interdépendants qui forment ensemble l'application complète MASANTÉ :

| N° | Document | Rôle vis-à-vis de ce document |
|----|----------|-------------------------------|
| CDC_00 | Index et règles transverses | Règles fondatrices, interdits absolus, chiffres contractuels, méthode de travail |
| CDC_01 | **Architecture Mobile (ce document)** | — |
| CDC_02 | Architecture Web | Partage le même Design System, les mêmes API, les mêmes schémas Zod et les mêmes règles |
| CDC_03 | Architecture Backend | Fournit toutes les API consommées par le mobile |
| CDC_04 | Architecture Base de Données | Source des modèles de données reflétés dans les types TypeScript |
| CDC_05 | Architecture IA | Fournit les API de triage, prédiction et aide au diagnostic |
| CDC_06 | Architecture Paiement | Fournit les API de paiement Mobile Money, cartes, Wallet, CNAM |
| CDC_07 | Architecture IA Générative | Fournit les API du chat médical et de l'assistant |
| CDC_08 | Architecture Protocoles Médicaux | Alimente le questionnaire de triage dynamique |
| CDC_09 | Architecture Données Nationales | Fournit référentiels (établissements, médecins, médicaments) et Identifiant National de Santé |
| CDC_10 | Architecture Sécurité | Définit OAuth2, JWT, MFA, biométrie, chiffrement appliqués ici |
| CDC_11 | Architecture des Applications | Détaille les fonctionnalités de chaque sous-application |
| CDC_12 | Architecture Microservices | Détaille les services derrière l'API Gateway |
| CDC_13 | Architecture des Données | Règles de collecte et de traçabilité des données produites par le mobile |

**Destinataire unique** : Claude Code. Tous les documents du corpus lui sont désormais adressés. La règle de résolution des conflits du CDC_00 §1.2 reste inchangée : CDC_10 (Sécurité) prévaut sur tout, puis CDC_08 (Protocoles médicaux), puis CDC_09 (Données nationales) ; toute ambiguïté restante se résout par un **ADR**, jamais par une invention locale.

### 0.1 Nouvelle règle de frontière (remplace l'ancienne « règle absolue »)

En v1.0, la séparation frontend / logique métier était garantie par la séparation des équipes. Claude Code écrivant maintenant **les deux côtés**, cette garantie disparaît et doit être remplacée par une **discipline architecturale explicite, auto-contrôlée** :

1. **La couche mobile ne contient jamais de logique métier.** Calculs médicaux, scores de triage, tarification, règles de validation métier, décisions d'éligibilité, calculs de reste à charge : tout vit dans le backend (CDC_03, CDC_05, CDC_06, CDC_08).
2. **Le mobile implémente exclusivement** : UX, UI, navigation, intégration API, gestion d'état, responsive, animations, accessibilité, offline, synchronisation, notifications, sécurité côté client.
3. **Interdiction de contourner la frontière par facilité.** Claude Code écrivant aussi le backend, la tentation est de « faire vite » un calcul côté mobile pour éviter un aller-retour d'API. C'est interdit : si une donnée métier manque, on ajoute l'endpoint côté backend, on le documente en OpenAPI, on le teste, puis on le consomme.
4. **Test de conformité systématique** : avant de valider un module mobile, Claude Code doit pouvoir répondre « aucune » à la question : *quelles règles médicales, tarifaires ou d'éligibilité ce module calcule-t-il lui-même ?*

---

## 1. Présentation du projet

### 1.1 Vision
MASANTÉ est une Digital Health Platform (DHP) nationale, conçue pour la Côte d'Ivoire puis extensible aux autres pays africains (Sénégal, Bénin, Mali, Cameroun, Ghana…), capable d'évoluer pendant 20 ans sans réécriture majeure. Elle interconnecte hôpitaux, cliniques, centres de santé, pharmacies, laboratoires, radiologie, assurances, ambulances, patients, médecins, Ministère de la Santé, chercheurs et modèles d'IA.

### 1.2 Objectifs de l'application mobile
- Point d'accès principal des citoyens et des professionnels de santé : « Mon identité médicale numérique nationale ».
- Fluidité et simplicité d'utilisation dans tout le pays, y compris en zone rurale à connexion faible ou inexistante (**Offline First obligatoire**).
- Design **minimaliste**.
- Sécurité de niveau bancaire/hospitalier international (voir CDC_10).

### 1.3 Public cible (personas)
1. **Patient** — tout citoyen ; usage principal : triage, recherche d'établissement, rendez-vous, paiement, dossier médical, pharmacie, téléconsultation.
2. **Médecin** — consultations, diagnostics, prescriptions, aide IA, collaboration.
3. **Infirmier** — patients hospitalisés, constantes vitales, administration des traitements, alertes.
4. **Accueil / Secrétaire** — gestion des créneaux, pré-validation des rendez-vous, file d'attente.
5. **Pharmacien** — ordonnances électroniques, stock, délivrance, commandes, livraisons.
6. **Laboratoire** — demandes d'examens, résultats, validation biologique.
7. **Radiologie** — examens d'imagerie, comptes rendus.
8. **Administrateur (établissement)** — services, personnel, chambres, finances.
9. **Super Administrateur (plateforme)** — création des établissements, validation des inscriptions, supervision nationale.

### 1.4 Pays et langues
- Pays de lancement : **Côte d'Ivoire** (protocoles du Ministère ivoirien de la Santé, CNAM, numéros d'urgence ivoiriens — **SAMU 185**, médicaments autorisés en CI).
- Multi-pays par « profil national » : **aucune donnée pays codée en dur** — tout provient des référentiels (CDC_09).
- Langues : **Français** (défaut), **Anglais**, langues nationales à terme. Changement de langue dynamique, i18n dès la conception.

---

## 2. Protocole opératoire Claude Code (section nouvelle — normative)

Cette section a valeur contractuelle au même titre que les spécifications fonctionnelles. Elle existe parce qu'un projet antérieur du propriétaire a perdu un temps considérable sur **des conflits de versions de paquets** et **des échecs de communication API ↔ mobile**. Neutraliser ces deux modes de défaillance est une contrainte de conception, pas une recommandation.

### 2.1 Fichier CLAUDE.md (obligatoire, à la racine du dépôt mobile)

Le dépôt mobile contient un `CLAUDE.md` maintenu à jour qui rappelle en permanence : la stack et **les versions exactes verrouillées**, l'arborescence imposée (§5.1), les commandes du projet (`npx expo start --tunnel`, lint, typecheck, test), les interdits absolus du CDC_00 §4, les règles de frontière (§0.1), le module en cours et le module suivant, et l'état de validation de chaque module. `CLAUDE.md` est la mémoire courte du projet ; le **Knowledge Book** (CDC_00 §8) en est la mémoire longue.

### 2.2 Phase 0 — Audit obligatoire avant toute écriture

Avant de créer ou de modifier le moindre fichier, Claude Code exécute une **Phase 0** :

1. Localiser et **lire réellement** les fichiers concernés (jamais supposer leur contenu).
2. Vérifier ce qui existe déjà : composant, hook, service, type, endpoint, migration.
3. Vérifier la version installée de chaque paquet impliqué (`package.json` + lockfile), pas la version supposée.
4. Produire un **rapport d'audit court** : ce qui existe, ce qui manque, ce qui doit être modifié, ce qui ne doit pas être touché.
5. **S'arrêter et rendre compte** si un blocage, une incohérence avec le corpus, ou une ambiguïté est détecté. Ne jamais « deviner pour avancer ».

### 2.3 Plan avant code

Pour tout module ou fonctionnalité non triviale, Claude Code propose d'abord un **plan** : fichiers à créer, fichiers à modifier, endpoints consommés, écrans concernés, tests prévus, risques. Le code ne commence qu'après validation écrite explicite du propriétaire. Le plan ne dépasse jamais le périmètre du module en cours.

### 2.4 Gates de validation (blocantes)

| Gate | Condition de franchissement |
|------|------------------------------|
| **G0 — Audit** | Rapport de Phase 0 remis et accepté |
| **G1 — Plan** | Plan validé par écrit par le propriétaire |
| **G2 — Backend d'abord** | Chaque endpoint consommé par l'écran existe, est documenté en OpenAPI et **testé via Postman sur tunnel Ngrok** — avant toute ligne d'écran |
| **G3 — Qualité** | `lint` + `typecheck` + `tests` verts en local, puis en CI |
| **G4 — Test appareil réel** | Module testé sur **Expo Go SDK 54** sur le téléphone du propriétaire via **tunnel Ngrok** |
| **G5 — Validation propriétaire** | Confirmation écrite explicite : « module validé » |

**Aucun module suivant ne démarre avant G5 du module courant.** Chevauchement de modules interdit.

### 2.5 Corrections chirurgicales

En cas de problème : **analyse complète pour isoler uniquement la partie fautive**, correction ciblée, **sans modifier une autre partie**. Interdits pendant une correction : refactor opportuniste, renommage de variables non fautives, reformatage de fichiers non concernés, mise à jour de dépendance, changement d'architecture. Toute correction s'accompagne de : cause identifiée, fichier(s) touché(s), diff minimal, test de non-régression.

### 2.6 Discipline des dépendances (verrou anti-conflit de versions)

1. **Expo SDK 54 est la référence.** Toute bibliothèque doit être compatible SDK 54 ; on privilégie systématiquement les paquets `expo-*` officiels.
2. Installation exclusivement via `npx expo install` (jamais `npm install` direct pour un paquet natif).
3. **Aucune nouvelle dépendance sans validation préalable écrite** du propriétaire, accompagnée de : pourquoi elle est nécessaire, alternative native/Expo écartée et pourquoi, compatibilité SDK 54, poids ajouté.
4. Lockfile committé ; versions épinglées ; jamais de plage `^` ou `~` introduite volontairement sur un paquet natif.
5. `npx expo-doctor` exécuté après toute modification de dépendance ; sortie propre exigée avant G3.
6. Interdiction d'installer un paquet « pour tester » puis de l'oublier : toute dépendance non utilisée est retirée avant G5.

### 2.7 Discipline de communication API ↔ mobile (verrou anti-échec d'intégration)

1. **Contrat d'abord** : le type TypeScript de la requête et de la réponse dérive de la documentation **OpenAPI** du backend (CDC_03, Rule-003). Jamais de type inventé côté mobile.
2. **Preuve avant code** : capture Postman (statut, headers, corps de réponse réel) attachée au module avant écriture de l'écran.
3. **Aucune URL en dur**, aucune donnée simulée en dur. Si un mock est temporairement nécessaire, il est isolé dans `src/api/__mocks__/`, explicitement signalé, et **supprimé avant G5**.
4. Un client HTTP unique et centralisé (intercepteurs : token, refresh 401, `X-Request-Id`, journalisation). Aucun `fetch`/`axios` appelé directement depuis un composant (DIP — le composant dépend d'un hook).
5. En cas d'échec d'intégration, le diagnostic suit toujours cet ordre : **réseau/tunnel → URL et base URL → headers/auth → forme du payload → code mobile**. Ne jamais modifier le backend et le mobile dans la même passe de débogage.

### 2.8 Gestion du contexte et découpage du travail

- **Un fichier à la fois**, une responsabilité à la fois. Pas de génération massive multi-fichiers non planifiée.
- Les tâches de recherche large dans le dépôt (localiser des usages, vérifier des conventions) peuvent être déléguées à un **agent d'exploration** ; les décisions d'architecture et les écritures restent dans la session principale, sous contrôle du propriétaire.
- Le contexte est réinitialisé entre modules ; `CLAUDE.md` et le Knowledge Book garantissent la continuité, pas la mémoire de session.

### 2.9 Traçabilité

Chaque décision importante produit un **ADR** (Rule-003). Chaque endpoint possède une documentation **OpenAPI**. Chaque module validé est consigné dans le Knowledge Book : ce qui a été construit, ce qui a échoué et pourquoi, ce qui a été abandonné et pourquoi. Commits atomiques, messages explicites, une Pull Request par module avec revue et CI blocante.

### 2.10 Interdits propres à Claude Code

1. Modifier un fichier non listé dans le plan validé.
2. Écrire du code sans avoir lu les fichiers existants concernés (violation de Phase 0).
3. Inventer un endpoint, un champ de réponse, un nom de table ou un référentiel.
4. Corriger un symptôme sans avoir identifié la cause.
5. Livrer un module non testé sur appareil réel.
6. Mettre à jour une dépendance ou l'SDK Expo de sa propre initiative.
7. Déclarer un module terminé sans validation écrite du propriétaire.
8. Poursuivre malgré un blocage au lieu de s'arrêter et rendre compte.

---

## 3. Technologies imposées (non négociables)

| Domaine | Technologie |
|---------|-------------|
| Framework | **React Native** |
| Plateforme | **Expo** (SDK 54 — tests avec Expo Go sur le téléphone du propriétaire via tunnel Ngrok) |
| Langage | **TypeScript** (strict, 100 % du code typé) |
| Style | **NativeWind** (Tailwind CSS pour React Native) |
| Navigation | **Expo Router** (routage par fichiers, typé — gère nativement le deep linking et les notifications) |
| État serveur | **TanStack Query** (cache, retry, invalidation, optimistic updates, pagination) |
| État global | **Zustand** (auth, utilisateur, langue, thème, permissions, notifications) |
| Formulaires | **React Hook Form + Zod** (schémas partagés avec le web) |
| Stockage local | **SQLite** (données offline), **MMKV** (préférences/cache clé-valeur), **Expo SecureStore** (tokens, secrets), **FileSystem** (documents, images) |
| Notifications | **Firebase Cloud Messaging** (Android) + **APNs** (iOS) via le Notification Service backend |
| Temps réel | WebSocket (Socket.IO client) vers les services NodeJS ; WebRTC pour la téléconsultation |
| Cartes | **OpenStreetMap** (jamais d'API cartographique payante par défaut) |
| Listes | **FlashList** (préféré à FlatList pour la performance des listes longues) |
| Mises à jour | **OTA Expo Updates** (JS uniquement ; le natif passe par les stores) |
| Icônes | Lucide (RN) + icônes médicales personnalisées |

**Interdictions** : pas de logique métier dans l'app (§0.1) ; pas de secret ou clé API dans le code (variables d'environnement/config Expo uniquement — Twelve-Factor, facteur 3) ; pas d'accès direct à une base de données distante (Rule-001) ; pas de stockage de données sensibles non chiffrées ; pas de dépendance ajoutée hors procédure §2.6.

---

## 4. Design System MASANTÉ (partagé avec le web — CDC_02)

### 4.1 Principes
Design **minimaliste**, homogène, accessible, dark mode et light mode obligatoires.

### 4.2 Couleurs sémantiques (tokens centralisés, jamais de valeur en dur dans les écrans)
- `primary` : Bleu Santé
- `secondary`
- `success` : Vert Validation
- `danger` : Rouge Urgence
- `warning` : Orange Alerte
- `info`
- `surface`, `background`
- Couleurs de triage : 🔴 rouge, 🟠 orange, 🟡 jaune, 🟢 vert, 🔵 bleu (voir §8.1)

### 4.3 Typographie
Police **Inter**. Échelle normalisée : 12, 14, 16, 18, 20, 24, 32, 40, 48.

### 4.4 Espacements
Échelle normalisée : 4, 8, 12, 16, 24, 32, 48, 64. Aucune valeur hors échelle.

### 4.5 Composants du Design System
Boutons (variantes primaire/secondaire/danger/ghost, états normal/pressed/disabled/loading), champs de formulaire (label, aide, erreur), cartes, badges (dont badges de priorité de triage), tableaux/listes, calendrier, timeline, avatars, modales, toasts/snackbars, skeletons, empty states, tabs, steppers, chips de filtre. Tous réutilisables, tous documentés, tous accessibles.

### 4.6 Règle Claude Code sur le Design System
Le Design System est construit **en premier** et **une seule fois** (module 1 de l'ordre de construction). Ensuite : **aucun écran ne crée un composant visuel local** si un équivalent existe. Avant de créer un composant, Claude Code vérifie son absence dans `src/components/` (Phase 0). Aucune couleur, taille de police ou espacement littéral dans un écran — uniquement des tokens de `theme/`.

---

## 5. Architecture du projet

### 5.1 Organisation des dossiers
```
app/               # Expo Router : routes par fichiers, groupées par rôle
src/
  components/      # composants réutilisables du Design System
  screens/         # vues, groupées par module fonctionnel
  hooks/           # hooks personnalisés
  contexts/        # providers React
  services/        # clients API, socket, notifications, sync
  api/             # définitions d'endpoints + types dérivés d'OpenAPI
  storage/         # SQLite, MMKV, SecureStore, file d'attente offline
  utils/           # helpers purs, testés
  assets/          # images, polices, icônes
  theme/           # tokens du Design System
  types/           # types TypeScript partagés
  i18n/            # traductions
CLAUDE.md          # règles permanentes du dépôt (§2.1)
```

### 5.2 Découpage par fonctionnalités (modules indépendants)
`patients/`, `medecins/`, `consultations/`, `rendez-vous/`, `triage/`, `pharmacie/`, `laboratoire/`, `radiologie/`, `urgences/`, `paiements/`, `teleconsultation/`, `dossier-medical/`, `notifications/`, `profil/`, `administration/`. Chaque module contient ses écrans, hooks, services et types. Un module ne dépend jamais directement d'un autre module : communication par navigation, événements ou API (Rule-001).

### 5.3 Règles de codage (Handbook MASANTÉ, applicables à 100 %)
1. **Clean Code** : noms explicites (`nombreJoursHospitalisation`, `enregistrerDossierPatient`, `PatientCard`), une fonction = une responsabilité, fonctions courtes, composants courts (jamais un composant de 1000 lignes — découper en `PatientCard.tsx`, `PatientTable.tsx`, `PatientForm.tsx`).
2. **DRY** : aucune logique dupliquée ; une seule source de vérité par calcul d'affichage.
3. **KISS / YAGNI** : pas de complexité ni de fonctionnalité non requise. Claude Code n'ajoute **jamais** de fonctionnalité non demandée, même « utile ».
4. **SOLID adapté au front** : responsabilité unique (SRP) ; extension par props/composition (OCP) ; le composant dépend d'un hook, jamais d'axios directement (DIP).
5. Erreurs jamais silencieuses : tout `catch` journalise avec contexte et affiche un état d'erreur UX.
6. Commentaires uniquement pour le « pourquoi » (contraintes métier/réglementaires).
7. Aucune logique métier dans les composants.
8. Outillage obligatoire : **ESLint, Prettier, TypeScript strict, Jest** ; CI blocante : lint + typecheck + tests avant tout merge ; toute modification par Pull Request revue.
9. Rule-004 : chaque fonctionnalité répond aux 5 questions (pourquoi existe-t-elle, qui l'utilise, quelles données, quels modules appelés, comment évoluera-t-elle dans 5 ans) **avant** intégration — réponses consignées dans la PR.

---

## 6. Navigation et parcours utilisateur

### 6.1 Navigation par rôle
Après authentification, l'app charge la navigation correspondant au rôle contenu dans le JWT (RBAC — CDC_10). Un utilisateur ne voit **jamais** un écran auquel son rôle ne donne pas droit (ISP : chaque rôle ne dispose que des capacités nécessaires). Avec Expo Router, la protection se fait par groupes de routes et garde de layout — jamais par simple masquage visuel.

### 6.2 Parcours Patient (parcours principal)
```
Connexion → Accueil → (Triage optionnel) → Recherche hôpital/médecin
→ Prise de rendez-vous → Confirmation par l'établissement → Paiement
→ Consultation → Ordonnance → Pharmacie → Suivi
```

### 6.3 Parcours Rendez-vous à validation en deux étapes (obligatoire)
1. Le patient choisit établissement, médecin, créneau et réserve.
2. La secrétaire pré-valide (si la structure a des secrétaires) : jours disponibles, heures, durée de consultation, nombre maximal de patients, congés, urgences.
3. Le médecin effectue la validation finale (ou fait tout lui-même s'il n'a pas de secrétaire).
4. Le patient reçoit la confirmation **puis** effectue le paiement.
5. Le système vérifie : disponibilité, conflit, paiement, confirmation, notification.

L'UI doit refléter chaque état : `EN_ATTENTE_VALIDATION`, `CONFIRMÉ_EN_ATTENTE_PAIEMENT`, `PAYÉ`, `ANNULÉ`, `REFUSÉ`, `TERMINÉ`. Ces états sont **fournis par le backend** ; le mobile ne les calcule ni ne les déduit.

### 6.4 Parcours Médecin
`Tableau de bord → Patients du jour → Dossier patient (+ fiche de triage via QR code) → Consultation (observations → diagnostic → examens → prescription) → Suivi`.

### 6.5 Parcours Pharmacien
`Ordonnances reçues → Vérification (authenticité, disponibilité, interactions, contre-indications) → Validation → Délivrance/Commande → Stock`. Les vérifications d'interactions et de contre-indications sont **calculées par le backend** (CDC_08) et seulement affichées par le mobile.

### 6.6 Parcours Achat de médicament (patient)
`Recherche médicament → Pharmacies l'ayant en stock (avec itinéraire OpenStreetMap « S'y rendre ») → Panier → Retrait ou Livraison → Paiement → Suivi de commande`. Si ordonnance obligatoire : upload de l'ordonnance → validation du pharmacien → vente autorisée. **Jamais d'achat de médicament sous ordonnance sur la seule base du triage.**

---

## 7. Liste complète des écrans

### 7.1 Écrans communs
Splash, Onboarding, Connexion, Inscription, Mot de passe oublié, Vérification OTP, Configuration MFA/biométrie, Choix de langue, Profil, Paramètres, Notifications, Messages/Chat, Aide/FAQ, À propos, Mentions légales/Consentement.

### 7.2 Écrans Patient
Accueil patient, Triage (questionnaire dynamique), Résultat de triage + fiche PDF/QR, Recherche établissements (liste + carte), Fiche établissement (services, horaires, photos, itinéraire), Recherche médecins (filtres spécialité/ville/langue/prix), Fiche médecin, Prise de rendez-vous (calendrier de créneaux), Mes rendez-vous, Paiement (choix du moyen), Confirmation de paiement, Factures et reçus (PDF), Dossier médical (consultations, ordonnances, analyses, imagerie, vaccins, allergies, antécédents, maladies, traitements, documents), Ordonnances, Rappels de médicaments, Pharmacie (recherche, panier, commande, suivi livraison), Téléconsultation (salle d'attente, appel vidéo, chat, partage documents), Urgences (appel **SAMU 185**, numéros nationaux, suivi ambulance sur carte), Assurance/CNAM (carte, couverture, reste à charge), Wallet (solde, historique, recharge), Historique complet, Personnes autorisées/contacts d'urgence.

### 7.3 Écrans Médecin
Dashboard (RDV du jour, patients attendus, alertes médicales, statistiques personnelles), Agenda, File des demandes de RDV (validation finale), Liste patients, Dossier patient complet, Scanner QR fiche de triage / carte patient, Consultation (notes, symptômes, observations), Aide au diagnostic IA (hypothèses probabilisées + explications + sources — décision finale au médecin), Prescription électronique (recherche dans le référentiel national des médicaments, posologie, durée, instructions ; alertes interactions/allergies), Demande d'examens (labo/radio), Résultats reçus, Téléconsultation, Avis spécialisé/collaboration, Messagerie sécurisée, Statistiques.

### 7.4 Écrans Infirmier
Dashboard, Patients hospitalisés (par service/chambre/lit), Saisie des constantes (température, tension, glycémie, fréquence cardiaque, SpO2), Administration des traitements (médicament, heure, dose, signature infirmier), Alertes intelligentes (ex. tension 180/110 → « Risque hypertension sévère — notifier médecin » ; **seuil évalué par le backend**, jamais codé en dur — CDC_00 §4, interdit n°1), Planning de soins.

### 7.5 Écrans Secrétaire/Accueil
Dashboard, Gestion des créneaux (jours, heures, durée, quotas, congés, urgences), Pré-validation des RDV, File d'attente du jour, Enregistrement d'arrivée du patient.

### 7.6 Écrans Pharmacien
Dashboard, Ordonnances électroniques reçues, Détail ordonnance + vérifications, Stock (entrées, sorties, péremption, alertes de rupture, stock minimum), Catalogue médicaments (DCI, code-barres, dosage, forme, prix, TVA, ordonnance obligatoire), Commandes clients, Livraisons, Statistiques.

### 7.7 Écrans Laboratoire
Dashboard, Demandes d'examens, Suivi des prélèvements (scan code-barres/QR), Saisie/import de résultats, Validation biologique, Résultats publiés, Catalogue des analyses.

### 7.8 Écrans Radiologie
Dashboard, Examens demandés (radio, scanner, IRM, écho), Visualisation d'images (DICOM via visionneuse), Analyse IA (suspicion + confiance, validation radiologue), Comptes rendus.

### 7.9 Écrans Administrateur d'établissement
Dashboard, Fiche établissement (infos générales, légales, coordonnées + GPS, horaires, images, description), Services (tarif, durée moyenne, responsable), Spécialités, Médecins (diplômes, n° d'ordre, langues, horaires, consultation en ligne/physique, prix), Personnel, Chambres et lits, Équipements, Facturation, Paiements et reversements, Rapports, Audit et permissions.

### 7.10 Écrans Super Administrateur
Dashboard national, Création d'établissement (méthode 1 : création directe + envoi d'accès au directeur), Demandes d'inscription (méthode 2 : formulaire → vérification → validation → publication), Gestion des référentiels, Supervision, Statistiques nationales, Gestion des utilisateurs et rôles.

### 7.11 Fonctionnalités UI transverses (obligatoires)
Recherche, filtres, pagination, tableaux, calendrier, timeline, cartes (OpenStreetMap), graphiques, **scanner QR Code**, **scanner de carte patient**, upload de fichiers, caméra (photos de documents/ordonnances), GPS/géolocalisation, notifications push, chat, mode offline, synchronisation.

---

## 8. Spécification type de chaque écran (gabarit obligatoire)

Chaque écran DOIT être spécifié **puis** implémenté avec les 11 points suivants. Claude Code rédige cette spécification **avant** le code de l'écran ; elle est vérifiée à la revue de PR.

1. **Objectif** de l'écran.
2. **Composants** utilisés (du Design System uniquement).
3. **Validation** des saisies (schéma Zod, messages d'erreur en français clair).
4. **Animations** (transitions douces, micro-interactions ; jamais bloquantes).
5. **API appelées** (méthode, endpoint, paramètres, headers, réponse attendue — contrat OpenAPI du CDC_03) **+ preuve Postman** (gate G2).
6. **Gestion des erreurs** (réseau, 401 → refresh token, 403 → écran non autorisé, 422 → erreurs de champ, 5xx → retry + message).
7. **Loader** (skeleton loading — jamais d'écran blanc).
8. **Empty state** (illustration + texte + action).
9. **Responsive** (téléphones petits/grands, tablettes, orientation).
10. **Accessibilité** (labels, contrastes, taille de police dynamique, lecteur d'écran, cibles ≥ 44 pt).
11. **Comportement offline** (ce que voit l'utilisateur sans réseau, ce qui est mis en file d'attente).

### 8.1 Écran de triage (spécification renforcée)
- Questionnaire **dynamique piloté par le backend** (moteur de protocoles — CDC_08) : l'app affiche les questions reçues, elle ne contient **aucune règle médicale**. Aucun seuil, aucun score, aucun arbre de décision côté mobile.
- Saisies : symptômes (choix), localisation de la douleur (schéma du corps interactif), intensité (0–10), durée, évolution (stable/s'aggrave/s'améliore), âge, sexe, grossesse, maladies chroniques, allergies, médicaments en cours.
- Résultat côté patient : 4 niveaux — 🟢 faible priorité (surveillance à domicile, conseils, pharmacien), 🟡 consultation recommandée (proposer les établissements disponibles), 🟠 consultation rapide < 24 h, 🔴 urgence (urgences immédiates + numéros d'urgence dont **SAMU 185**). Le triage hospitalier utilise 5 niveaux (Rouge immédiat, Orange < 10 min, Jaune < 60 min, Vert < 120 min, Bleu programmé) affichés dans les écrans professionnels.
- **Fiche de triage** : numéro, date/heure, symptômes déclarés, réponses, niveau, recommandation, service recommandé, hôpitaux proches proposant ce service, **QR Code** pour le médecin, et la mention obligatoire : *« Ce document constitue une aide à l'orientation et ne remplace pas un diagnostic médical. »* Téléchargeable en PDF, partageable, transmise au médecin avant le rendez-vous.
- Le triage n'est **jamais** présenté comme un diagnostic.

---

## 9. Intégration API

### 9.1 Règles générales
- Toutes les requêtes passent par l'**API Gateway** (jamais d'appel direct à un microservice).
- Base URL par environnement via configuration (dev : tunnel Ngrok ; staging ; production `api.masante.ci`). **Aucune URL en dur.**
- Headers systématiques : `Authorization: Bearer <JWT>`, `Accept-Language`, `X-Country-Code`, `X-App-Version`, `X-Request-Id` (UUID par requête), `Idempotency-Key` sur toute écriture sensible (paiements, rendez-vous).
- Toutes les listes sont paginées (**pagination par curseur** privilégiée) ; filtres par query params (`/medecins?specialite=Cardiologie`, `/hopitaux?ville=Abidjan`) ; projection de champs (`?fields=id,nom,prenom`) quand disponible.
- Cache HTTP respecté (`ETag`, `Cache-Control`) + cache TanStack Query avec TTL alignés sur le backend : **médecins 15 min · hôpitaux 24 h · pharmacies 12 h · données géographiques 30 jours**.
- Gestion des erreurs normalisée (format d'erreur du CDC_03) ; retry exponentiel sur 5xx et erreurs réseau ; **jamais de retry automatique sur une écriture non idempotente**.
- Contrats générés depuis la documentation **OpenAPI** du backend (Rule-003) — les types TypeScript en dérivent, ils ne sont jamais écrits à la main.

### 9.2 Authentification (voir CDC_10)
- OAuth2 + OpenID Connect ; l'app reçoit `access_token` (JWT courte durée) + `refresh_token`.
- Tokens stockés **exclusivement** dans Expo SecureStore (jamais AsyncStorage).
- Refresh automatique et transparent ; sur échec → déconnexion propre.
- MFA : obligatoire pour les rôles professionnels et administratifs, proposée aux patients (OTP SMS, TOTP, biométrie).
- **Verrou biométrique applicatif** (`expo-local-authentication`, repli PIN) sur les sections sensibles. Données biométriques **jamais** stockées ni transmises (Android Keystore / iOS Secure Enclave).
- **Sections toujours accessibles sans verrou biométrique** (choix de conception délibéré, vies humaines en jeu) : Triage, carte des établissements de santé, bouton **SOS 185**, alertes épidémiques.
- Déconnexion : révocation serveur + purge SecureStore + purge des caches sensibles.

### 9.3 Temps réel
- WebSocket authentifié (JWT) pour : chat, notifications in-app, suivi ambulance (position GPS), statut de file d'attente, statut de paiement.
- WebRTC pour la téléconsultation (audio/vidéo, adaptation automatique de qualité, reprise sur perte de connexion).
- Reconnexion automatique avec backoff ; état de connexion visible dans l'UI.

### 9.4 Voies d'accès au dossier médical (à activer avec les modules 4/5)
Quatre voies, toutes auditées : accès par le patient lui-même ; accès du professionnel dans le cadre d'une prise en charge établie ; **délégué de confiance** (table `delegations`) ; **bris de glace d'urgence** (lecture seule, 15 minutes, journal d'audit, revue administrateur obligatoire). Aucun accès à un dossier sans lien de prise en charge hors bris de glace justifié (CDC_00 §4, interdit n°11).

---

## 10. Offline First et synchronisation (exigence majeure)

### 10.1 Données disponibles hors ligne (lecture)
Dossier médical, rendez-vous, prescriptions, historique des consultations, examens téléchargés, résultats d'analyses, ordonnances, vaccinations, données administratives, référentiels consultés récemment. **Priorité absolue au cache hors ligne du SOS et de la carte des établissements** (connectivité variable hors des grandes villes).

### 10.2 Écritures hors ligne (file d'attente persistante)
Nouvelle demande de rendez-vous, mise à jour du profil, saisie de constantes (infirmier), notes de consultation (médecin), réponses de triage, paiement en attente de confirmation. Chaque opération est horodatée et enregistrée dans une **file d'attente locale persistante** (SQLite), rejouée automatiquement au retour du réseau, avec `Idempotency-Key` pour empêcher tout doublon.

### 10.3 Synchronisation intelligente
```
Connexion détectée → vérification des modifications → détection des conflits
→ fusion → envoi au serveur → confirmation → purge de la file
```
Stratégies de résolution des conflits (combinables selon le type de donnée) :
- dernier enregistrement validé ;
- **priorité au personnel médical** ;
- fusion quand c'est possible ;
- **validation manuelle pour les informations critiques**.

Toute opération est horodatée pour traçabilité complète (CDC_13). Indicateur visuel d'état de synchronisation (synchronisé / en attente / conflit). La **journalisation d'audit asynchrone doit garantir la livraison** (file persistante, rejeu, accusé serveur) pour ne pas rompre l'exigence de journal d'audit immuable (CDC_10).

---

## 11. Notifications Push

Via Notification Service backend → FCM (Android) / APNs (iOS). Cas d'usage obligatoires : rappel de rendez-vous, confirmation/refus de rendez-vous, disponibilité de résultats d'analyse, renouvellement d'ordonnance, confirmation de paiement, arrivée de l'ambulance, demande de téléconsultation, messages du médecin, alertes sanitaires nationales, campagnes de vaccination, rappels de traitement. Notifications ciblées par profil, enrichies de données contextuelles, deep links vers l'écran concerné (natif avec Expo Router), préférences par catégorie dans les Paramètres. **Aucune donnée médicale sensible dans le corps de la notification** (minimisation).

---

## 12. Performance

- Démarrage rapide (lazy loading des modules lourds : cartes, graphiques, scanner, visionneuse DICOM).
- Listes virtualisées systématiques (**FlashList**).
- Images optimisées, cache d'images, formats modernes.
- Skeleton loading partout ; jamais de blocage du thread UI.
- Consommation batterie et données minimale (polling interdit quand le WebSocket suffit ; compression activée).
- **Élimination des N+1 côté API et pagination par curseur** : décisions à coût nul, prises immédiatement. Redis et files d'attente Laravel constituent la couche de montée en charge suivante (CDC_03).
- Objectifs alignés sur CDC_00 §5 : API P95 < 150 ms ; réponse perçue < 2 s sur les écrans principaux en réseau normal ; utilisable en 2G/3G dégradé grâce à l'offline.

---

## 13. Accessibilité

Conforme **WCAG 2.2 niveau AA** (adapté mobile) : labels accessibles sur tous les éléments interactifs, contrastes conformes via les tokens, tailles de police dynamiques (respect des réglages système), cibles tactiles ≥ 44 pt (48 dp recommandé), support lecteur d'écran (TalkBack/VoiceOver), messages d'erreur annoncés, navigation logique, dark/light mode, **aucune information portée par la couleur seule** (les niveaux de triage combinent couleur + texte + icône).

---

## 14. Sécurité côté mobile (résumé — détails CDC_10)

- OAuth2/OIDC + JWT + refresh token ; SecureStore ; biométrie ; MFA.
- TLS 1.3 exclusivement ; **certificate pinning** sur les API critiques.
- Chiffrement des données locales sensibles avant écriture (SQLite/MMKV chiffrés) ; clés protégées par l'OS.
- Permissions par rôle appliquées à la navigation **ET** au rendu (un bouton non autorisé n'est pas rendu).
- Validation Zod de toutes les entrées ; jamais de rendu de HTML non filtré.
- Écran de verrouillage après inactivité ; masquage du contenu sensible dans le multitâche.
- Journalisation des actions importantes vers le backend (audit — CDC_10/CDC_13) ; **aucune donnée médicale dans les logs applicatifs locaux**.
- Aucune décision IA affichée sans explication, score de confiance et mention des limites (Rule-005).
- **Contrôle Claude Code** : aucun secret, clé, mot de passe ou token en dur dans le code ou dans un commit. `.gitignore` configuré **avant le premier commit** pour protéger les fichiers d'environnement. Scan de secrets dans la CI, blocant.

---

## 15. Tests

- **Unitaires** (Jest) : hooks, utils, services, réducteurs de synchronisation, résolution de conflits.
- **Composants** (React Native Testing Library) : chaque composant du Design System + écrans critiques.
- **E2E** (Detox ou Maestro) : parcours patient complet (connexion → triage → RDV → paiement), parcours médecin (consultation → prescription), parcours pharmacien (validation ordonnance), scénarios offline/synchronisation.
- Toute fonctionnalité critique possède des tests ; CI blocante (lint + typecheck + tests + scan de sécurité).

### 15.1 Protocole de test imposé par le propriétaire
La construction est **module par module**. Quand un module est terminé :
1. Backend validé au préalable via **Postman sur tunnel Ngrok** (gate G2).
2. Module mobile testé sur **Expo Go (SDK 54)** installé sur le téléphone du propriétaire, via **tunnel Ngrok** vers le backend local (gate G4).
3. S'il y a un problème : **analyse complète pour isoler uniquement la partie fautive**, correction ciblée, **sans modifier autre chose** (§2.5).
4. On ne passe au module suivant que lorsque **tout fonctionne** et que le propriétaire a écrit sa validation (gate G5).

### 15.2 Checklist de fin de module (à joindre à chaque PR)
- [ ] Phase 0 documentée (fichiers lus, existant recensé)
- [ ] Plan validé respecté — aucun fichier hors plan modifié
- [ ] Endpoints consommés : preuve Postman jointe
- [ ] Aucun mock, aucune URL en dur, aucun secret
- [ ] `lint` / `typecheck` / `tests` verts · `expo-doctor` propre
- [ ] Aucune dépendance ajoutée sans validation
- [ ] Testé sur appareil réel via Expo Go + Ngrok
- [ ] Accessibilité vérifiée (labels, contrastes, cibles 44 pt)
- [ ] Comportement offline vérifié
- [ ] Aucune règle médicale/tarifaire côté mobile
- [ ] ADR rédigé si une décision d'architecture a été prise
- [ ] Knowledge Book mis à jour · `CLAUDE.md` mis à jour

---

## 16. Mises à jour OTA

Expo Updates : publication → détection → téléchargement → validation → redémarrage sécurisé → version active. Les changements natifs (permissions, bibliothèques natives) passent par les stores. Canaux : dev / staging / production. Aucune publication OTA sans passage complet des gates G3 à G5.

---

## 17. Ordre de construction (module par module — impératif)

| # | Module | Gate de sortie |
|---|--------|----------------|
| 0 | Socle : projet Expo SDK 54 + TypeScript strict + NativeWind + **Expo Router** + Design System + i18n + client API centralisé | G3 + G4 (écran de démonstration sur appareil) |
| 1 | Authentification (OAuth2/OIDC, SecureStore, biométrie, MFA, RBAC de navigation) | G2→G5 |
| 2 | Profil patient + dossier médical (lecture, offline) | G2→G5 |
| 3 | Recherche établissements/médecins + fiches + carte OpenStreetMap (offline) | G2→G5 |
| 4 | Rendez-vous (workflow deux étapes complet) | G2→G5 |
| 5 | Paiement (CDC_06 : Mobile Money, cartes, Wallet, CNAM, assurance) | G2→G5 |
| 6 | Triage (questionnaire dynamique + fiche PDF/QR) | G2→G5 |
| 7 | Pharmacie (recherche, panier, ordonnance, commande) | G2→G5 |
| 8 | Espace Médecin, puis Infirmier, Secrétaire, Pharmacien, Laboratoire, Radiologie | G2→G5 par espace |
| 9 | Téléconsultation + chat temps réel | G2→G5 |
| 10 | Urgences / ambulance (SOS 185, suivi carte) | G2→G5 |
| 11 | Administration d'établissement + Super Administration | G2→G5 |
| 12 | Notifications, synchronisation avancée, délégation + bris de glace, OTA, durcissement sécurité, E2E complets | G2→G5 |

> **Note sur l'état d'avancement réel** : le module Triage possède déjà un début d'implémentation backend (migrations, modèles, seeder de symptômes, service de calcul). Sa reprise commence obligatoirement par une **Phase 0 d'audit** de l'existant, sans réécriture des parties fonctionnelles déjà validées.

---

## 18. Distinction à maintenir pour la soutenance

Trois catégories, à tenir à jour en continu (c'est le signal le plus fort de rigueur architecturale devant un jury) :
1. **Implémenté et prouvé** — construit, testé sur appareil réel, validé.
2. **Prêt à activer** — spécifié, dimensionné, non encore branché (ex. Redis, files d'attente, délégation, bris de glace).
3. **Documenté comme perspective** — pensé, justifié, planifié pour une phase ultérieure.

---

*Fin du CDC_01 v2.0 — Architecture Mobile. Destinataire : Claude Code. Toute ambiguïté d'implémentation se résout en consultant le document lié indiqué, jamais par une invention locale ; à défaut, elle se tranche par un ADR validé par le propriétaire.*
