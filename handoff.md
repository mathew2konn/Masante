# Handoff — MaSanté (IVOIRSANTÉ)

> **Point de reprise.** Écrit pour quelqu'un qui reprendrait le projet demain sans rien en savoir.
> Dernière mise à jour : **2026-09-04**. Branche : **`feat/masante-p0-socle`**. Avant ce passage, le
> dernier commit poussé était **`1451ce1`** (B3-b validé G5) — **B3-c est VALIDÉ (G5, 2026-09-04),
> G4 propriétaire OK, et committé dans ce même passage** (voir §5) ; son hash exact se lit avec
> `git log -1`.
>
> Ce fichier dit **où l'on en est**. Le **journal exhaustif** de chaque module vit dans `CLAUDE.md`.
> Les **plans de conception** vivent dans `plan.md`. Les **décisions d'architecture** vivent dans
> `docs/adr/`.

---

## 1. Ce qu'est ce projet

Plateforme numérique nationale de santé pour la **Côte d'Ivoire** (conçue multi-pays). Le corpus qui
fait autorité est constitué des **14 cahiers des charges** de `CDC.md/` (CDC_00 → CDC_13). Ordre de
résolution des conflits : **CDC_10 Sécurité > CDC_08 Protocoles > CDC_09 Données nationales > ADR
validé par le propriétaire**. On n'invente jamais : toute ambiguïté devient un ADR.

### Architecture (monorepo, ADR-003)

```text
apps/mobile/              Expo SDK 54 — React Native, TS strict, NativeWind, Expo Router
apps/web/                 Next.js 15 App Router — portail professionnel moderne
packages/shared/          @masante/shared — SOURCE UNIQUE (tokens, enums, Zod, i18n)
services/api/             Laravel 13 / PHP 8.3 / MySQL — le cœur
services/payment/         Java Spring Boot — paiement (ADR-013), Postgres + Redis, Docker
services/fraud-detection/ Python FastAPI — fraude IA (ADR-017), détection seule
services/triage-service/  Python FastAPI — triage IA (ADR-043/045/046), mode observation
CDC.md/                   cahiers des charges (lecture seule)
```

Gestionnaire **pnpm 9**, `node-linker=hoisted`. MySQL conservé en MVP. Auth **Sanctum + OTP**.

### Les règles qui ne se négocient pas

| | |
|---|---|
| **Frontière** | **Aucune logique métier dans le front.** Scores, tarifs, plafonds, éligibilité, transitions d'état : **backend uniquement**. Test de fin de module : « quelles règles métier ce module calcule-t-il ? » → réponse obligatoire **« aucune »** |
| **Source unique** | Tokens, schémas Zod, enums, i18n : définis **une fois** dans `@masante/shared`. Aucune redéfinition locale, aucune couleur en dur |
| **Interdits absolus** (CDC_00 §4) | Règle médicale en dur · triage présenté comme diagnostic · IA décidant seule · secret dans le code · fichier médical en base · accès dossier sans lien de prise en charge (hors bris de glace audité) · sortie IA sans explication + confiance + limites. **SAMU 185**, jamais le 15 |
| **Dépendances** | **Aucune dépendance sans accord écrit du propriétaire** (§2.6). Mobile : `npx expo install` uniquement |

---

## 2. La méthode de travail (gates bloquantes, CDC_01 §2.4)

**G0** Audit — lire réellement le code, ne rien supposer →
**G1** Plan validé **par écrit** par le propriétaire →
**G2** Backend prouvé **en direct** contre la vraie base MySQL →
**G3** Qualité : tests, Pint, typecheck, campagne de mutation →
**G4** **Test réel par le propriétaire** →
**G5** « validé » **écrit par le propriétaire**.

> **Le G5 n'est JAMAIS auto-déclaré.** Il attend le mot du propriétaire. Aucun module suivant avant
> le G5 du précédent. Corrections chirurgicales uniquement.

### Les trois fichiers de suivi (règle propriétaire du 2026-09-03)

| Fichier | Rôle | Quand |
|---|---|---|
| **`CLAUDE.md`** | Toute décision d'architecture et de conception (nom de classe, raison d'un choix, contrainte) | **AVANT d'écrire la moindre ligne de code** |
| **`plan.md`** | Le plan de travail et d'exécution détaillé, un bloc par réflexion sous `# PLAN n : …` | Après la décision, avant l'exécution |
| **`handoff.md`** | Ce fichier : fait, pourquoi, état exact, prochaines étapes | Après l'exécution |

**Ordre contraignant** : décision → `CLAUDE.md` → `plan.md` → exécution → `handoff.md`.

### Habitudes de test qui ont trouvé de vrais défauts

- **Campagne de mutation manuelle** : sauvegarder → muter une garde → **asserter la mutation
  appliquée** → asserter le **rouge** → restaurer → **vérifier par `diff`**. Toujours **un témoin
  volontairement vert** : *un harnais qui ne prévoit que des tueuses ne se teste jamais lui-même.*
- **Vérifier un refus PAR SON MOTIF**, jamais par son seul code HTTP — plusieurs gardes partagent
  le même 409.
- **Le G2 live trouve ce que la relecture ne voit pas** — il l'a prouvé une dizaine de fois.
- **Baseline Pint établie AVANT** de formater : plusieurs fichiers du dépôt échouent **déjà**
  (style d'alignement délibéré), il ne faut pas les reformater.

### Guides de test

Un guide par module, `GUIDE_TEST_<SUJET>.md` à la racine, indexé par `GUIDE_TEST_INDEX.md`. **Un
module sans guide ne peut pas être déclaré validé.** Un domaine à incréments ajoute une **partie**
au guide existant.

---

## 3. Ce qui a été fait, et pourquoi

Le détail exhaustif est dans `CLAUDE.md`. Vue d'ensemble :

| Domaine | État |
|---|---|
| **P0 → P4** — socle, identité (RBAC + MFA), dossier médical hors ligne chiffré, annuaire + carte, rendez-vous | ✅ validés |
| **P5** — Paiement, **microservice Java** (ADR-013) : passerelle OCP, facturation, wallet en partie double, fraude, cartes + 3DS, mandats, reversements, rapprochement, **intégration GeniusPay réelle** | ✅ 21 incréments validés |
| **Fraude IA** — microservice Python (ADR-017), **détection seule**, jamais de gel | ✅ validé (+ extraction réelle, routage, écran) |
| **P6** — Données nationales CDC_09 : NIS, socle référentiel gouverné, établissements, professionnels + PKI, médicaments, laboratoires, transverses | ✅ **étapes 1 à 8 complètes** |
| **P7** — Carnet familial partagé (la fusion MPI a été **abandonnée** au profit de deux actes humains) | ✅ complet, 6 incréments |
| **P10** — Triage : orientation gouvernée, **protocoles médicaux CDC_08**, microservice IA en **mode observation** | ✅ complet dans son découpage annoncé |
| **P11** — Applications métier CDC_11 : portes du portail, onboarding méthode 2, API d'ingestion partenaire | ✅ validés |
| **B1** — Refonte du parcours Rendez-vous | ✅ complet (a, b, c, d) |
| **B2** — Consultation, diagnostic, prescription électronique | ✅ complet (a, b, c) |
| **B3** — **Pharmacie** | 🔵 a ✅, b ✅, **c ✅ (G5, 2026-09-04)** ; **d (panier/commande, dernier sous-lot annoncé) reste à faire** |

### Deux principes qui reviennent partout, et qu'il faut comprendre pour reprendre

1. **On ne devine jamais.** Le serveur ne rapproche pas un texte libre d'une entrée de référentiel
   (ce serait un diagnostic posé par une machine) ; il ne déduit pas une provenance ; il ne
   complète pas une réponse incomplète. **Une absence se dit** — et elle se **compte** à l'écran.
2. **Une valeur recalculable n'est jamais stockée.** Le solde d'un wallet, le stock d'une officine,
   le statut d'une vaccination : des **sommes** et des **calculs**, jamais des colonnes — *une
   valeur stockée finit par diverger de ce qu'elle résume*.

---

## 4. État exact du système, aujourd'hui

### Dépôt

- Branche **`feat/masante-p0-socle`**. Avant ce passage, dernier commit poussé : **`1451ce1`** —
  *feat(pharmacie) : l'officine tient enfin un vrai stock (B3-b)*.
- **B3-c est VALIDÉ (G5, 2026-09-04) — G4 propriétaire OK, et committé dans ce passage.** 16 fichiers
  touchés (7 nouveaux, 9 modifiés — détail dans le commit lui-même).
- Suite Laravel au dernier passage complet (avec B3-c) : **1676 tests / 17 821 assertions / 0
  échec**.
- **Aucun secret suivi par git** (vérifié : 0 correspondance sur les `.env`).

### Base de données de développement — à jour, 0 migration `Pending`

Toutes les migrations sont **`Ran`**, y compris les cinq qu'un précédent G2 avait laissées
`Pending` (consultations, diagnostics, ordonnance_prescripteur, delivrance_ordonnance,
stock_officine) et la nouvelle de B3-c (`tracabilite_medicaments`) — le G2 live de B3-c les a
toutes rejouées avant de sauvegarder la base, et la restauration a donc capturé cet état à jour.
**L'avertissement d'une précédente version de ce fichier ne s'applique plus.**

Si un futur G2 restaure de nouveau une base ancienne et fait réapparaître des migrations
`Pending`, la commande reste :

```bash
cd services/api
XDEBUG_MODE=off "C:/wamp64/bin/php/php8.3.28/php.exe" artisan migrate
XDEBUG_MODE=off "C:/wamp64/bin/php/php8.3.28/php.exe" artisan db:seed --class=PortailRolesSeeder
```

Le seeder de rôles est **nécessaire** : une restauration efface aussi les permissions posées par
l'incrément.

### Environnement — pièges de poste vérifiés

| | |
|---|---|
| **PATH Bash cassé** | `export PATH="/usr/bin:/bin:$PATH"` en tête de chaque commande ; git est dans `/c/Program Files/Git/cmd` |
| **Python** | `/c/Users/HP/AppData/Local/Programs/Python/Python314` (3.14.7) |
| **PHP** | `C:/wamp64/bin/php/php8.3.28/php.exe`, toujours préfixé `XDEBUG_MODE=off` |
| **MySQL** | WAMP 8.4.7, base `ivoirsante` |
| **`Write` écrit en CRLF** | `Edit` préserve les fins de ligne — plusieurs fichiers ont échoué Pint pour cette seule raison |
| **PHPUnit** | `memory_limit` doit être dans `phpunit.xml` ; un flag `php -d` n'atteint pas le bon processus |
| **Mesures de durée** | ne jamais chronométrer pendant qu'une autre exécution tourne |

---

## 5. Où en est le lot B3 (Pharmacie), précisément

Le G0 du lot avait relevé **neuf manques**. État :

| Sous-lot | Contenu | État |
|---|---|---|
| **B3-a** | Lignes d'ordonnance, jeton de partage, **délivrance** (§7.1, §7.2 partiel) | ✅ **G5, 2026-09-03** |
| **B3-b** | Fiche officine, **stock réel**, mouvements, seuils (§7.3, §7.5) + renommage | ✅ **G5, 2026-09-03** |
| **B3-c** | **Code-barres + traçabilité nationale (§7.6)** | ✅ **G5, 2026-09-04** |
| **B3-d** | Panier, commande, renouvellement | ⚪ à faire — **prochaine étape** |

### Ce que B3-a, B3-b et B3-c ont acquis, et qu'il ne faut pas défaire

- Le pharmacien **ne voit que l'ordonnance**, jamais le dossier : le jeton de partage porté par
  l'ordonnance remplace une session de dossier. **Le vecteur central du lot est une absence** :
  servir ne crée **aucune ligne de journal d'accès**, parce qu'aucun accès n'a lieu.
- Le **stock est une somme**, jamais une colonne ; le **signe est déduit du type** de mouvement ;
  l'inventaire **alimente** le relevé public sans le doubler.
- Une **délivrance passe même si l'officine ne tient pas l'article** : refuser priverait un patient
  de son traitement pour une raison qui ne le concerne pas.
- Le §7.2 est atteint **aux trois quarts** : authenticité ✅, disponibilité ✅, interactions ✅ (en
  consultation explicite), **contre-indications ❌ impossibles** — les allergies sont du texte libre
  chiffré, et une vérification partielle dirait « aucune contre-indication » à un patient qui en a
  une : **plus dangereux que pas de vérification du tout**.
- **B3-c referme §7.6** (falsifiés, consommation, statistiques) : chaque médicament peut porter un
  code-barres (vide tant que personne ne le saisit, et l'absence est comptée) ; chaque délivrance
  alimente un **registre national dénominalisé** (`traces_dispensation`) qui **survit** à la
  suppression de l'ordonnance dans le carnet du patient — sans jamais porter de donnée nominative.
  Un code-barres reconnu **ne prouve jamais l'authenticité**, seulement que le code est connu.
- **Défaut réel corrigé pendant l'écriture de B3-c, à retenir pour la suite** : ne jamais mettre une
  clé étrangère `nullOnDelete` sur une colonne d'une table **append-only** — la nullification
  déclenchée par le moteur à la suppression du parent est elle-même un `UPDATE`, qu'un déclencheur
  append-only bloquant tout refuse, empêchant la suppression du parent. Toujours un identifiant
  **sans contrainte** dans ce cas (ADR-042 D1). Détail : ADR-055 §10.10.

---

## 6. Prochaines étapes

### Immédiat — B3-c est clos, place à B3-d

Le G4 du propriétaire est fait, la validation G5 est écrite, le commit est passé dans cette même
session. **Il n'y a plus rien à faire sur B3-c.** Prochaine étape : B3-d.

### Ensuite — B3-d

Panier, commande et renouvellement côté mobile. Referme les manques **P1** (commandes,
renouvellements) et **P5**. **Un G0 propre reste entièrement à faire** : *ne pas partir du plan G1
du lot sans le confronter au code réel* — sur B3-c, le G0 a corrigé **trois** de ses affirmations, et
sur B3-c encore, un vecteur de **G3** a corrigé une décision du **G1** que le G0 n'avait pas vue
(la clé étrangère `nullOnDelete` sur `medicament_id` — §5 ci-dessus). Ce schéma se répétera
probablement : lire le code réel avant de faire confiance à un plan écrit avant lui.

**Rappel du processus à trois fichiers (règle propriétaire du 2026-09-03), à appliquer dès le début
de B3-d** : décision → `CLAUDE.md` (avant d'écrire une ligne de code) → `plan.md` (un bloc
`# PLAN n : …`) → exécution → `handoff.md`. B3-c est le premier incrément mené sous cette règle.

### Après le lot B3

Étapes restantes de CDC_11 §12 et dettes nommées avec leur porteur :

- **Migration du portail Blade vers Next.js** — ADR-011 la tranche, ADR-029 en a fait « un module
  identifié ». **29 zones, 77 vues**, chacune ayant besoin de son API en plus de son écran. C'est
  là que le design moderne se fera **une fois**, sur le design system partagé.
- **Référentiel d'allergènes** — verrou de la vérification des contre-indications (§7.2, §5.4).
- **Élévation de la gouvernance du socle P6.3** — porteur des asymétries « donnée gouvernée, lecture
  non gouvernée » (`poids_severite` de P10b-3-ii, `code_barres` de B3-c).
- **Trois CDN subsistent** au portail (`html5-qrcode`, Chart.js ×2) — même défaut que Bootstrap,
  corrigé en P6.4d pour lui seul.
- **Contenus de référentiels** — la plupart sont des **jeux de démonstration** honnêtement
  étiquetés (médicaments, maladies, vaccins, analyses, assurances, numéros d'urgence). Les charger
  pour de vrai est **de la donnée, zéro code** — mais tant que ce n'est pas fait, **ce ne sont pas
  des référentiels nationaux**, et les écrans le disent.
