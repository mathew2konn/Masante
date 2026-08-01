# CAHIER DES CHARGES N°2 — ARCHITECTURE WEB
## Projet MASANTÉ — Plateforme Numérique de Santé de Nouvelle Génération
**Version 2.0 — Document destiné à CLAUDE CODE**

> **Changement majeur par rapport à la v1.0** : l'intégralité de la plateforme MASANTÉ — web, mobile, backend, base de données, IA, paiement, sécurité — est désormais développée par **Claude Code**, dans un seul environnement de travail, sous le contrôle direct du propriétaire du projet. Ce document remplace intégralement la v1.0 destinée à Google AI Studio.

---

## 0. Position du document dans le corpus MASANTÉ

Corpus de 13 cahiers des charges interdépendants (tableau complet dans CDC_01 §0). Ce document dépend directement de : **CDC_00** (règles transverses), **CDC_01** (Design System, schémas Zod et protocole opératoire partagés), CDC_03 (API), CDC_05/CDC_07 (IA), CDC_06 (paiement), CDC_08 (protocoles), CDC_09 (référentiels), CDC_10 (sécurité), CDC_11 (fonctionnalités par application), CDC_12 (microservices), CDC_13 (données).

### 0.1 Règle de frontière (remplace l'ancienne « règle absolue »)

En v1.0, la séparation frontend / logique métier était garantie par la séparation des équipes. Claude Code écrivant maintenant **les deux côtés**, cette garantie disparaît et est remplacée par une discipline architecturale explicite et auto-contrôlée :

1. **Le web ne contient jamais de logique métier.** Il implémente : UX, UI, navigation, intégration API, gestion d'état, responsive, animations, accessibilité, PWA, SEO, performance et sécurité côté client.
2. **Les Route Handlers et Server Actions de Next.js ne sont pas un backend.** Ils servent uniquement de proxy, de couche de session/cookies et d'adaptateur de rendu. Aucune règle médicale, aucun calcul de tarif, de couverture, de plafond, de ticket modérateur ou de reste à charge n'y est implémenté — ces calculs viennent de CDC_03 / CDC_06 / CDC_08.
3. **Interdiction de contourner la frontière par facilité.** Si une donnée métier manque, on ajoute l'endpoint côté backend, on le documente en OpenAPI, on le teste, puis on le consomme.
4. Rule-001 : aucun accès direct à la base de données depuis le frontend, **y compris depuis un Server Component**. Rule-002 : le code métier ne dépend jamais de Next.js.
5. **Test de conformité systématique** avant validation d'un module : *quelles règles médicales, tarifaires ou d'éligibilité ce module calcule-t-il lui-même ?* La réponse doit être « aucune ».

---

## 1. Présentation

### 1.1 Rôle de la plateforme web
Les interfaces navigateur de MASANTÉ servent principalement : les tâches administratives, la gestion hospitalière, les tableaux de bord, les statistiques nationales, les assurances, la CNAM, les laboratoires, la radiologie, l'administration centrale, le portail du Ministère de la Santé — plus un portail public (information, prévention, actualités, recrutement) et l'accès web des patients et médecins.

### 1.2 Objectifs
Extrêmement rapide (< 2 s), hautement sécurisée, accessible sur tous les appareils (ordinateur, tablette, téléphone, borne tactile, écran médical), optimisée SEO (pages publiques), compatible avec les faibles connexions, installable en **PWA**, maintenable pendant plusieurs décennies, design **minimaliste**.

### 1.3 Public cible (personas)
Identiques au CDC_01 §1.3 : Patient, Médecin, Infirmier, Accueil/Secrétaire, Pharmacien, Laboratoire, Radiologie, Administrateur d'établissement, Super Administrateur — plus les portails institutionnels : **Ministère de la Santé**, **Assurance/CNAM**, **Statistiques**.

### 1.4 Pays et langues
Côte d'Ivoire au lancement ; multi-pays par profil national (référentiels CDC_09, rien en dur — y compris les numéros d'urgence : **SAMU 185** en CI). Français par défaut, anglais, langues nationales à terme. Changement de langue dynamique sans rechargement.

---

## 2. Protocole opératoire Claude Code (section nouvelle — normative)

Le protocole du **CDC_01 §2 s'applique intégralement au web** ; il n'est pas dupliqué ici mais rappelé et complété des spécificités web. Rappel des points blocants : `CLAUDE.md` à la racine, **Phase 0 d'audit avant toute écriture**, plan validé avant code, gates G0→G5, **corrections chirurgicales uniquement**, discipline des dépendances, discipline de contrat API, un fichier à la fois, ADR pour toute décision d'architecture.

### 2.1 Gates adaptées au web

| Gate | Condition de franchissement |
|------|------------------------------|
| **G0 — Audit** | Rapport de Phase 0 remis et accepté (fichiers lus, existant recensé, versions vérifiées) |
| **G1 — Plan** | Plan validé par écrit par le propriétaire (fichiers, routes, endpoints, tests) |
| **G2 — Backend d'abord** | Chaque endpoint consommé existe, est documenté en OpenAPI et **testé via Postman** — avant toute ligne de page |
| **G3 — Qualité** | `lint` + `typecheck` + tests + **axe-core** verts, en local puis en CI |
| **G4 — Test navigateur réel** | Testé sur Chrome et Firefox, **mobile + desktop**, avec réseau bridé (profil 3G lent) et en mode hors ligne PWA |
| **G5 — Validation propriétaire** | Confirmation écrite explicite : « module validé » |

Aucun module suivant ne démarre avant G5 du module courant.

### 2.2 Cohérence obligatoire avec le mobile

Le web et le mobile étant produits par le même agent, **la divergence silencieuse est le risque principal**. Trois règles :

1. **Source unique partagée** : les schémas **Zod**, les types dérivés d'OpenAPI, les libellés i18n, les tokens du Design System et les enums d'état (`EN_ATTENTE_VALIDATION`, `CONFIRMÉ_EN_ATTENTE_PAIEMENT`, `PAYÉ`, `ANNULÉ`, `REFUSÉ`, `TERMINÉ`) sont définis **une seule fois** et consommés par les deux applications (paquet partagé `@masante/shared` ou dossier partagé versionné). Aucune redéfinition locale.
2. **Parité fonctionnelle vérifiée** : quand un workflow existe des deux côtés (RDV en deux étapes, paiement, dossier médical), toute évolution d'un côté ouvre obligatoirement une tâche de mise en cohérence de l'autre, tracée dans le Knowledge Book.
3. **Divergences assumées documentées** : les écarts légitimes (SEO, densité des tableaux, raccourcis clavier, visionneuse DICOM web) sont listés explicitement — jamais implicites.

### 2.3 Interdits propres à Claude Code sur le web

1. Mettre de la logique métier dans un Route Handler, une Server Action ou un middleware.
2. Appeler la base de données depuis un Server Component.
3. Stocker un token dans `localStorage` ou `sessionStorage` (§13).
4. Exposer une variable d'environnement serveur au client (préfixe public utilisé par erreur).
5. Créer un composant visuel local alors qu'un équivalent existe dans le Design System.
6. Ajouter une dépendance UI (librairie de tableaux, de graphiques, de dates) sans validation écrite préalable.
7. Écrire un type de réponse d'API à la main au lieu de le dériver d'OpenAPI.
8. Livrer une page sans `loading.tsx`, `error.tsx` et état vide.
9. Poursuivre malgré un blocage au lieu de s'arrêter et rendre compte.

---

## 3. Technologies imposées (non négociables)

| Domaine | Technologie |
|---------|-------------|
| Bibliothèque UI | **React** |
| Framework | **Next.js** (App Router) — SSR, SSG, ISR, Middleware, Route Handlers (**proxy uniquement, pas de métier**), Image Optimization, Streaming, Server Components |
| Langage | **TypeScript** strict (100 % typé) |
| Style | **Tailwind CSS** + composants **Shadcn UI** adaptés au Design System MASANTÉ |
| État serveur | **TanStack Query** |
| État global | **Zustand** |
| Formulaires | **React Hook Form + Zod** (schémas partagés avec le mobile — §2.2) |
| Icônes | **Lucide React** + icônes médicales personnalisées |
| Police | **Inter** |
| Cartes | **OpenStreetMap** |
| Temps réel | WebSocket (Socket.IO), SSE pour les flux de tableaux de bord, WebRTC pour la téléconsultation |
| PWA | Service Worker (cache, offline partiel, synchronisation différée, push) |
| Tests / outillage | ESLint, Prettier, Jest, Testing Library, Playwright, axe-core |

**Interdictions** : aucune logique métier ; aucun secret dans le code (variables d'environnement — Twelve-Factor facteur 3) ; aucun accès direct à la base ; aucun token en `localStorage` ; aucune dépendance ajoutée hors procédure (CDC_01 §2.6).

---

## 4. Organisation du projet

```
src/
  app/            # App Router : routes, layouts, pages, loading/error/not-found
  components/     # Design System + composants d'affichage métier
  hooks/
  services/       # clients API, socket, notifications
  store/          # stores Zustand
  providers/
  layouts/
  middleware/     # auth, i18n, en-têtes de sécurité
  styles/
  types/          # types dérivés d'OpenAPI
  utils/
  config/
  constants/
  i18n/
public/
CLAUDE.md         # règles permanentes du dépôt (CDC_01 §2.1)
```
Chaque dossier a une responsabilité unique. Modules fonctionnels indépendants (Rule-001). Mêmes règles de codage que CDC_01 §5.3 (Clean Code, DRY, KISS, YAGNI, SOLID front, erreurs jamais silencieuses, commentaires « pourquoi », PR + revue + CI blocante, Rule-004 : 5 questions par fonctionnalité consignées dans la PR).

### 4.1 Atomic Design (obligatoire)
`Atoms → Molecules → Organisms → Templates → Pages`. Exemples : `Button` → `Input` → `SearchBar` → `Header` → `Dashboard`. Composants d'affichage typiques : `<CardPatient/>`, `<TableauConsultation/>`, `<StatistiqueHopital/>`, `<CalendrierMedecin/>`, `<CarteAmbulance/>`, `<HistoriquePaiement/>`, `<Ordonnance/>`, `<ResultatAnalyse/>`.

### 4.2 Règle Claude Code sur les composants
Avant de créer un composant, vérifier son absence dans `src/components/` (Phase 0). Aucune couleur, taille de police ou espacement littéral dans une page — uniquement des tokens Tailwind centralisés issus du Design System partagé.

---

## 5. Routes (App Router)

```
/                    # portail public
/connexion  /inscription  /mot-de-passe-oublie
/dashboard           # redirigé selon le rôle
/patient             /patient/[id]
/rendez-vous
/triage
/consultation
/dossier-medical
/ordonnances
/pharmacie
/laboratoire
/radiologie
/ambulance           /urgence
/paiements           /factures        /wallet
/teleconsultation
/messages            /notifications
/administration      # établissement
/statistiques
/ministere
/cnam
/assurance
/super-admin
/profil              /parametres
/aide                /mentions-legales
```
Chaque module possède : `layout.tsx`, `page.tsx`, `loading.tsx`, `error.tsx`, `not-found.tsx`. Routes protégées par middleware d'authentification + contrôle de rôle (RBAC) ; une route non autorisée renvoie vers une page **403** dédiée.

### 5.1 Stratégies de rendu
- **SSG/ISR** : portail public, campagnes de prévention, actualités, informations institutionnelles, documentation publique, recrutement (pages SEO).
- **SSR** : pages authentifiées nécessitant des données fraîches au premier rendu.
- **CSR + TanStack Query** : tableaux de bord interactifs, données temps réel.

**Règle Claude Code** : la stratégie de rendu de chaque route est décidée et **écrite dans le plan** avant implémentation, avec sa justification. Aucun changement de stratégie en cours de module sans ADR.

---

## 6. Design System (partagé avec CDC_01)

Identique au mobile : couleurs sémantiques (`primary` Bleu Santé, `success` Vert Validation, `danger` Rouge Urgence, `warning` Orange Alerte, `secondary`, `info`, `surface`, `background`), typographie Inter (12→48), espacements (4→64), dark/light mode, badges de triage (🔴🟠🟡🟢🔵 côté professionnel, 🟢🟡🟠🔴 côté patient), composants documentés et accessibles. Toutes les couleurs proviennent des tokens Tailwind centralisés — **aucune valeur en dur**. Les tokens sont la **même source** que ceux du mobile (§2.2).

---

## 7. Gestion des états

- **Local** : `useState`, `useReducer` pour les composants simples.
- **Serveur** : **TanStack Query** — cache intelligent, synchronisation automatique, retry, invalidation, optimistic updates, pagination, infinite scrolling. TTL alignés backend : **médecins 15 min · hôpitaux 24 h · pharmacies 12 h · géographie 30 jours** ; statistiques selon en-têtes de cache.
- **Global** : **Zustand** — authentification, utilisateur connecté, langue, thème, permissions, notifications non lues.

Les Queries ne modifient jamais les données ; toute mutation passe par une intention explicite (miroir du principe CQRS du backend).

---

## 8. Pages et fonctionnalités par espace

Le détail fonctionnel complet de chaque application est dans **CDC_11** ; le web implémente les mêmes workflows que le mobile (CDC_01 §6–§7) avec les spécificités suivantes.

### 8.1 Portail public (SEO)
Présentation de MASANTÉ, recherche publique d'établissements et de médecins, campagnes de prévention, actualités, FAQ, contact, recrutement, mentions légales, politique de confidentialité et consentement.

### 8.2 Espace Patient (web)
Mêmes fonctionnalités que CDC_01 §7.2 (triage, recherche, RDV en deux étapes, paiement, dossier médical, ordonnances, pharmacie, téléconsultation, urgences, assurance/CNAM, Wallet, factures PDF).

### 8.3 Espaces Médecin / Infirmier / Secrétaire / Pharmacien / Laboratoire / Radiologie
Mêmes workflows que CDC_01 §7.3–§7.8, optimisés grand écran : tableaux denses avec tri/filtre/export, calendriers complets, visionneuse d'imagerie (DICOM web), saisie clavier rapide, raccourcis clavier documentés. Les seuils d'alerte clinique (constantes vitales) sont **évalués par le backend** — jamais codés en dur dans une page (CDC_00 §4, interdit n°1).

### 8.4 Administration d'établissement
Fiche établissement complète (deux méthodes d'onboarding — CDC_11 §3) : informations générales (type : public/privé/universitaire/militaire ; catégorie : hôpital/clinique/centre médical/centre de santé/laboratoire/cabinet), informations légales (n° d'autorisation, n° fiscal, registre du commerce, date de création, statut, licence d'exploitation, autorité de tutelle), coordonnées + GPS (OpenStreetMap, bouton « S'y rendre »), horaires 7 j + urgences 24h/24, images (logo, photos), description ; services cochés avec tarif/durée/responsable ; spécialités ; médecins ; personnel ; chambres/lits ; équipements ; facturation ; paiements et reversements ; rapports ; audit ; permissions.

### 8.5 Super Administration (plateforme)
Création directe d'établissements + traitement des demandes d'inscription (formulaire → vérification → validation → publication), gestion des référentiels nationaux (lecture/synchronisation — CDC_09), gestion des utilisateurs/rôles, supervision, statistiques nationales.

### 8.6 Portail Ministère
Pilotage national : nombre de patients, maladies fréquentes, occupation des hôpitaux, disponibilité des médicaments ; surveillance épidémiologique assistée par IA (épidémies, zones à risque, tendances) ; exports et rapports. Toute sortie d'IA est accompagnée de son explication, de son score de confiance et de ses limites (Rule-005).

### 8.7 Portail Assurance / CNAM
Vérification de couverture, validation de prise en charge, affichage des calculs (plafond, ticket modérateur, reste à charge — **calculés par le backend**, CDC_06), paiement automatique, contrôle de fraude, suivi des dossiers de remboursement (rejets, corrections, régularisations).

### 8.8 Portail Statistiques
Tableaux de bord BI alimentés par le Data Warehouse (CDC_13) : santé publique, budget, performance, population. Graphiques interactifs, exports. Aucun agrégat recalculé côté navigateur à partir de données brutes sensibles.

---

## 9. Gabarit de spécification de chaque page (obligatoire)

Identique au CDC_01 §8, rédigé **avant** le code et vérifié à la revue de PR : objectif, composants (Design System uniquement), validation Zod, animations, **API appelées + preuve Postman** (gate G2), gestion des erreurs (401 refresh / 403 page dédiée / 422 champs / 5xx retry), loader skeleton, empty state, responsive, accessibilité, comportement offline (PWA). Ajout web : **stratégie de rendu** (SSG/ISR/SSR/CSR) et **indexation** (indexable ou exclue).

---

## 10. Intégration API

Identique au CDC_01 §9 : passage exclusif par l'**API Gateway**, headers systématiques (`Authorization`, `Accept-Language`, `X-Country-Code`, `X-Request-Id`, `Idempotency-Key` sur écritures sensibles), pagination (curseur privilégié) / filtrage / projection, respect `ETag` et `Cache-Control`, format d'erreur normalisé, retry exponentiel sur 5xx uniquement, **types TypeScript générés depuis OpenAPI** (jamais écrits à la main), aucune URL en dur.

Spécifique web :
- Les appels serveur (Server Components / SSR / Route Handlers) utilisent **les mêmes contrats** que le client.
- Le middleware Next.js vérifie la session **avant** rendu.
- **Aucune donnée sensible dans le HTML public ni dans un cache CDN.** Vérification explicite avant G5 : inspection du HTML rendu d'au moins une page authentifiée par espace.
- Le mock est proscrit ; si temporairement nécessaire, il est isolé, signalé et supprimé avant G5.

---

## 11. Performance

- **Objectifs** : chargement page < **2 s**, API P95 < **150 ms** (backend), Core Web Vitals sains (**LCP, INP, CLS**) suivis en continu.
- Code splitting par route ; lazy loading des composants lourds (cartes, graphiques, visualisations, OCR, visionneuse DICOM) ; imports dynamiques.
- Images optimisées par Next.js (compression, redimensionnement, WebP/AVIF, lazy).
- Caches : navigateur, CDN (ressources statiques), Next.js, API, Redis côté serveur.
- Streaming React + préchargement intelligent des pages fréquentes.
- Compression Gzip/Brotli assurée par l'infrastructure (Nginx — CDC_03).
- **Élimination des N+1 et pagination par curseur** côté API : décisions immédiates, à coût nul.
- Monitoring UX : Core Web Vitals, temps de réponse, taux d'erreurs JS, disponibilité, parcours, performances par navigateur/appareil — remontés aux tableaux de bord techniques.

---

## 12. Accessibilité (WCAG 2.2 AA obligatoire)

Navigation clavier complète (ordre de tabulation logique, raccourcis, focus visible, modales et menus accessibles), lecteurs d'écran (HTML sémantique : `header`/`main`/`nav`/`section`/`article`/`footer` ; ARIA : `aria-label`, `aria-labelledby`, `aria-live`), formulaires avec labels explicites et erreurs annoncées, contrastes conformes via tokens, taille de police adaptable, dark/light mode, **aucune information portée par la couleur seule**, i18n dynamique. **axe-core est exécuté dans la CI et blocant** (gate G3).

---

## 13. Sécurité côté frontend (détails CDC_10)

- **Content Security Policy (CSP)** stricte ; protection XSS (jamais de HTML non filtré, jamais de `dangerouslySetInnerHTML` sur contenu non assaini) ; validation systématique des entrées (Zod).
- Session : cookies **HTTPOnly + Secure + SameSite** pour les tokens (**jamais localStorage**) ; protection **CSRF** en lien avec le backend ; refresh silencieux ; déconnexion = révocation serveur + purge.
- OAuth2 + OpenID Connect ; **MFA obligatoire** pour médecins, administrateurs, ministère, assurances, super admins.
- RBAC/ABAC appliqués à la navigation **ET** au rendu (un élément non autorisé n'est pas rendu, pas seulement masqué) ; pages 401/403 dédiées.
- Limitation des informations exposées au navigateur ; en-têtes de sécurité via middleware (CSP, X-Frame-Options, Referrer-Policy, HSTS…).
- Verrouillage de session après inactivité pour les postes partagés (bornes, accueil).
- Journalisation d'audit des actions importantes via le backend (CDC_10/CDC_13).
- TLS 1.3 exclusivement (HTTPS partout) ; **AES-256 au repos** côté serveur.
- **Contrôle Claude Code** : aucun secret en dur ; `.gitignore` configuré avant le premier commit ; scan de secrets blocant en CI ; vérification qu'aucune variable serveur n'est exposée au client.

---

## 14. SEO (pages publiques uniquement)

Métadonnées dynamiques, Open Graph, Twitter Cards, Sitemap XML, `robots.txt`, données structurées Schema.org, URLs lisibles, balises canoniques, gestion des redirections. **Les pages privées sont explicitement exclues de l'indexation** — vérification obligatoire avant G5.

---

## 15. PWA

Installation depuis le navigateur, plein écran, icône bureau, notifications push, synchronisation en arrière-plan, mise à jour silencieuse. **Service Worker** : cache des ressources statiques, cache des API compatibles, stratégie de revalidation, synchronisation différée. **Offline partiel** : consultation des données en cache, rédaction de formulaires, préparation de dossiers, lecture des documents téléchargés — synchronisation automatique au retour du réseau, avec `Idempotency-Key` pour empêcher les doublons.

Stratégies de conflits identiques au CDC_01 §10.3 : dernier enregistrement validé, **priorité au personnel médical**, fusion quand possible, **validation manuelle pour le critique**, tout horodaté. Indicateur visuel d'état de synchronisation. **Aucune donnée médicale mise en cache par le Service Worker sans chiffrement ni purge à la déconnexion.**

---

## 16. Temps réel

WebSocket authentifié : notifications, messagerie, files d'attente, suivi ambulance sur carte, statuts de paiement, tableaux de bord temps réel (SSE possible pour les flux unidirectionnels). WebRTC pour la téléconsultation (adaptation de qualité, reprise automatique). Reconnexion avec backoff et indicateur d'état visible.

---

## 17. Tests

- **Unitaires** (Jest) : hooks, utils, stores, services.
- **Composants** (Testing Library) : Design System + pages critiques.
- **E2E** (Playwright) : parcours patient complet, parcours médecin, administration d'établissement, portails CNAM/assurance, scénarios PWA offline.
- **Accessibilité automatisée** (axe-core) dans la CI.
- CI blocante : lint + typecheck + tests + a11y + scan de sécurité avant merge ; toute modification par PR revue.

### 17.1 Protocole de test imposé par le propriétaire
Construction **module par module**. Backend validé d'abord via **Postman** (G2). Puis test du module web sur navigateur réel, **desktop et mobile**, avec réseau bridé et scénario hors ligne (G4). En cas de problème : **analyse complète pour isoler uniquement la partie fautive**, correction ciblée, **sans modifier autre chose**. On ne passe au module suivant qu'après validation écrite du propriétaire (G5).

### 17.2 Checklist de fin de module (à joindre à chaque PR)
- [ ] Phase 0 documentée (fichiers lus, existant recensé)
- [ ] Plan validé respecté — aucun fichier hors plan modifié
- [ ] Endpoints consommés : preuve Postman jointe
- [ ] Stratégie de rendu justifiée par route
- [ ] `loading.tsx`, `error.tsx`, `not-found.tsx`, empty state présents
- [ ] Aucun mock, aucune URL en dur, aucun secret, aucune variable serveur exposée
- [ ] Aucun token en `localStorage` · cookies HTTPOnly vérifiés
- [ ] `lint` / `typecheck` / tests / **axe-core** verts
- [ ] Aucune dépendance ajoutée sans validation
- [ ] Testé desktop + mobile, réseau bridé, hors ligne PWA
- [ ] HTML rendu inspecté : aucune donnée sensible exposée · pages privées non indexables
- [ ] Aucune règle médicale/tarifaire côté web (y compris Route Handlers et Server Actions)
- [ ] Cohérence vérifiée avec l'écran mobile équivalent (§2.2)
- [ ] ADR rédigé si décision d'architecture · Knowledge Book et `CLAUDE.md` mis à jour

---

## 18. Ordre de construction (module par module — impératif)

| # | Module | Gate de sortie |
|---|--------|----------------|
| 0 | Socle Next.js + TypeScript strict + Tailwind + Shadcn + Design System partagé + i18n + middleware de sécurité + client API centralisé | G3 + G4 |
| 1 | Authentification complète (OAuth2/OIDC, cookies HTTPOnly, MFA, RBAC de routage) | G2→G5 |
| 2 | Portail public (SEO) + recherche d'établissements/médecins | G2→G5 |
| 3 | Espace Patient (RDV deux étapes, paiement, dossier médical) | G2→G5 |
| 4 | Espaces professionnels : Médecin → Infirmier → Secrétaire → Pharmacien → Laboratoire → Radiologie | G2→G5 par espace |
| 5 | Administration d'établissement, puis Super Administration | G2→G5 |
| 6 | Portails Ministère, CNAM/Assurance, Statistiques | G2→G5 |
| 7 | Téléconsultation + temps réel, PWA/offline, durcissement sécurité, E2E complets | G2→G5 |

**Séquencement mobile / web** : le mobile est prioritaire (c'est le point d'accès principal des citoyens et le support de la démonstration de soutenance). Le socle web et l'authentification web peuvent être construits en parallèle du mobile **uniquement si** les endpoints correspondants sont déjà validés (G2). Aucun autre module web ne démarre tant qu'un module mobile est en cours.

---

## 19. Distinction à maintenir pour la soutenance

1. **Implémenté et prouvé** — construit, testé, validé.
2. **Prêt à activer** — spécifié, dimensionné, non encore branché.
3. **Documenté comme perspective** — pensé, justifié, planifié pour une phase ultérieure.

---

*Fin du CDC_02 v2.0 — Architecture Web. Destinataire : Claude Code. Toute ambiguïté se résout via les documents liés, jamais par une invention locale ; à défaut, elle se tranche par un ADR validé par le propriétaire.*
