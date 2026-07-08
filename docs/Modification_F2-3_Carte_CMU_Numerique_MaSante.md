# MaSanté (ex-IVOIRSANTÉ) — Modification de spécification
## F2.3 « Intégration CMU » → ajout de la Carte CMU numérique

> **Portée** : ce document couvre **une seule modification** — l'enrichissement de la fonctionnalité **F2.3** existante du Module 2 (Carnet de Santé Familial) par une **carte CMU numérique présentable**. Aucune autre fonctionnalité n'est modifiée. Inspiré du certificat électronique d'assurance maladie chinois (碼医保电子凭证), adapté au contexte ivoirien et à la Couverture Maladie Universelle (CMU / CNAM).

---

## 1. Justification et cadrage

En Chine, la carte d'assurance physique a été remplacée par un **certificat électronique** présenté sous forme de QR code depuis le téléphone : le patient n'a plus besoin de sa carte physique pour les rendez-vous, visites, examens et achats de médicaments. Ce modèle repose toutefois sur une **plateforme nationale interconnectée en temps réel** entre assureur public et hôpitaux.

**Limite assumée pour MaSanté** : la CMU ivoirienne (CNAM) n'expose pas d'API publique de règlement en temps réel accessible à ce projet. Le **remboursement / règlement automatique de l'assurance est donc explicitement hors périmètre** et ne doit pas être promis. Ce qui est transposable et réaliste, c'est la **dématérialisation de la carte** : « présenter son téléphone au lieu de la carte physique ».

La fonctionnalité F2.3 actuelle gère déjà la **saisie** du numéro CMU, le **statut** (actif / expiré / non-inscrit), la **date de validité** et l'**alerte 30 jours avant expiration**. La présente modification ajoute la **couche de présentation** : une carte numérique affichable et vérifiable à l'accueil.

---

## 2. Modification de la fonctionnalité F2.3

| ID | Fonctionnalité | Description détaillée (mise à jour) | Acteur | Prio. |
|----|----------------|--------------------------------------|--------|-------|
| **F2.3** | **Intégration CMU + Carte CMU numérique** | Saisie du numéro CMU par membre, statut de couverture (actif / expiré / non-inscrit), date de validité, alerte automatique 30 jours avant expiration *(inchangé)*. **Ajout** : génération d'une **carte CMU numérique** présentable à l'accueil d'une structure — vue « carte » affichant le nom du titulaire, le numéro CMU **masqué** (seuls les derniers chiffres visibles), un **badge de statut** coloré, la date de validité et un **code de présentation** vérifiable visuellement par l'agent. La carte n'est disponible que pour un **compte au palier vérifié**. Le numéro complet reste chiffré et n'est jamais inclus dans le code présenté. | Utilisateur / Agent | HAUTE |

---

## 3. Description détaillée de la carte CMU numérique

### 3.1 Contenu affiché (vue « carte »)
- Nom et prénom du membre titulaire.
- Numéro CMU **masqué** (format `•••• •••• 1234` — seuls les 4 derniers chiffres visibles).
- **Badge de statut** : Actif (vert) / Expiré (rouge) / Non-inscrit (gris).
- Date de validité (et rappel visuel si expiration proche, cohérent avec l'alerte 30 jours existante).
- **Code de présentation** (QR ou code-barres) destiné à la vérification par l'agent d'accueil.

### 3.2 Vérification côté structure
- L'agent d'accueil **confirme visuellement** la carte (nom, statut, validité) et, si besoin, scanne le code de présentation.
- La vérification est **tolérante au réseau** : elle ne dépend d'aucune API CNAM en temps réel. Le code de présentation ne fait que restituer, côté serveur MaSanté, le **statut déclaré** du membre — jamais un règlement.

### 3.3 Palier de confiance requis
- La carte CMU numérique n'est générée que pour un **compte au palier vérifié** (identité confirmée par CNI ou CMU). C'est l'équivalent local de l'authentification « personne réelle » (reconnaissance faciale) exigée par le système chinois avant d'activer le certificat électronique.
- Un compte au palier de base (téléphone vérifié uniquement) peut saisir ses informations CMU mais ne peut pas présenter la carte comme justificatif tant que l'identité n'est pas confirmée.

---

## 4. Assurances privées (hors CMU) — filet low-tech via F2.10

Pour les assurances **privées** (hors CMU), le mécanisme est déjà couvert par la fonctionnalité **F2.10 (Documents médicaux importés)** : la catégorie `assurance` permet au patient de **photographier et stocker sa carte** d'assurance privée dans son carnet. C'est le **filet low-tech qui couvre tous les assureurs sans intégration** — aucun connecteur spécifique n'est nécessaire, le patient conserve simplement une image consultable et présentable de sa carte.

Ainsi, la couverture est complète :
- **CMU** → carte numérique structurée et présentable (F2.3, ce document).
- **Assurances privées** → carte importée en photo/document (F2.10, catégorie `assurance`).

---

## 5. Impact technique

### 5.1 Base de données
Impact **minimal** : les champs CMU existent déjà sur `membres_famille` (`cmu_numero` chiffré, `cmu_statut`, `cmu_validite`). La carte numérique est une **couche de présentation** ; elle ne requiert **aucune nouvelle table**. Optionnellement, si un code de présentation à durée de vie doit être tracé, il réutilise le mécanisme de token existant plutôt qu'une table dédiée.

### 5.2 Sécurité (cohérence avec le document Sécurité MaSanté)
- Numéro CMU **chiffré AES-256** en base (déjà le cas), **masqué** à l'affichage, et **jamais** inclus dans le QR/code présenté — même principe que le matricule interne jamais exposé.
- Carte réservée au **palier vérifié** ; tout accès reste inscrit au **journal d'audit** (FT6), conforme à la loi n°2013-450.
- Rappel de vigilance : la donnée d'assurance/identité est parmi les plus sensibles (des fuites de données de santé revendues en ligne ont été documentées sur le système chinois) → exposition minimale et chiffrement systématique.

### 5.3 Design (minimaliste)
- Une **vue carte** épurée, lisible d'un coup d'œil, avec le badge de statut comme élément visuel principal.
- Un bouton unique « Présenter ma carte » qui affiche le code en grand pour l'agent.
- Aucun encombrement : le numéro complet n'est jamais affiché, seule l'information utile à la vérification l'est.

---

*Modification de spécification isolée — à intégrer au §5.2 du CdC en remplacement de la ligne F2.3 actuelle. Aucune donnée inventée : ancré au CdC v3.1, au document Sécurité MaSanté, et aux pratiques documentées du certificat électronique d'assurance chinois.*
