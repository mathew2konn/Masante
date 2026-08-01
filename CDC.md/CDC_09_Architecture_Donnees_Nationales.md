# CAHIER DES CHARGES N°9 — ARCHITECTURE DES DONNÉES NATIONALES
## Projet MASANTÉ — Plateforme Numérique de Santé de Nouvelle Génération
**Version 1.0 — Document destiné à Claude Code**

---

## 0. Position du document dans le corpus MASANTÉ

Corpus de 13 cahiers des charges interdépendants (tableau complet dans CDC_01 §0). Ce document définit les **référentiels nationaux** — socle d'interopérabilité de toute la plateforme. Il est un **prérequis** de CDC_03 (backend), CDC_04 (schéma), CDC_05/CDC_07 (IA), CDC_06 (facturation via actes et tarifs), CDC_08 (protocoles), CDC_11 (applications), CDC_13 (données analytiques). Il applique CDC_10 (sécurité).

---

## 1. Problème à résoudre et principes

### 1.1 Le problème
La fragmentation des données médicales : un même patient possède plusieurs dossiers dans différents établissements, sans lien entre eux. Conséquences : examens redondants, erreurs thérapeutiques, augmentation des coûts, absence de vision globale.

### 1.2 Principes
1. **Unicité du patient** dans tout le pays.
2. **Normalisation** des établissements, professionnels, médicaments, laboratoires et analyses.
3. **Standards internationaux** : HL7 FHIR, SNOMED CT, CIM-10/CIM-11, DICOM, OpenHIE, recommandations OMS.
4. **Référentiel = source unique de vérité** : aucune donnée de référence saisie librement dans un module métier.
5. **Multi-pays** : un jeu de référentiels par pays (`pays_code`) ; ajouter un pays n'implique **aucune modification de code**.
6. Toute donnée de santé est rattachée à l'**Identifiant National de Santé**.

---

## 2. Master Patient Index (MPI) — Patient Unique

### 2.1 Objectif
Identifier une personne **une seule fois** dans tout le système national. Quel que soit l'hôpital, la clinique, le laboratoire, la pharmacie ou le centre de santé, le patient conserve le même dossier.

### 2.2 Le problème traité
```
Centre Hospitalier : Kouassi Jean
Clinique privée    : Jean Kouassi
Laboratoire        : KOUASSI JEAN
```
Sans MPI : trois personnes différentes. Avec MPI : dossiers fusionnés.

### 2.3 Données du Patient Unique
Identifiant National de Santé, nom, prénoms, sexe, date de naissance, lieu de naissance, nationalité, téléphones, email, adresse, personne à prévenir, photo, biométrie (référence sécurisée, jamais l'empreinte brute), **historique des fusions**, **historique des changements**.

### 2.4 Règle d'intégration obligatoire
```
Tous les systèmes interrogent le MPI AVANT toute création de patient.
```
Aucun module (hôpital, clinique, laboratoire, pharmacie) ne crée un patient sans passer par le MPI.

### 2.5 Détection des doublons
Critères combinés : nom, prénom, téléphone, date de naissance, numéro CNAM, Carte Nationale d'Identité, passeport, biométrie, **intelligence artificielle** (score de similarité — appui possible des embeddings du CDC_07).

Exemple imposé :
```
Nom recherché : KOUASSI JEAN
Nom enregistré : Jean Kouassi
Score : 98 %  → Fusion proposée
```

### 2.6 Fusion intelligente
Selon le score obtenu, deux dossiers peuvent être :
- **fusionnés automatiquement** (score très élevé, critères forts concordants) ;
- **fusionnés après validation** humaine (score intermédiaire) ;
- **laissés séparés** (score faible).

Toute fusion est **réversible et tracée** (`patient_fusions`) : dossiers sources conservés, opérateur, date, motif, score. Une défusion est possible en cas d'erreur, avec journalisation.

### 2.7 API
```
POST /api/v1/mpi/rechercher      # recherche avant création (obligatoire)
POST /api/v1/mpi/patients        # création après vérification
GET  /api/v1/mpi/patients/{nis}
POST /api/v1/mpi/fusions         # proposition/validation de fusion
POST /api/v1/mpi/fusions/{id}/annuler
GET  /api/v1/mpi/doublons        # file des doublons à arbitrer
```

---

## 3. Identifiant National de Santé (NIS)

### 3.1 Définition
Chaque citoyen possède un identifiant de santé unique, par exemple : **`CIS241200012547`** (autre format documenté dans les sources : `CI-SANTE-000045789` ; le format définitif fait l'objet d'un **ADR**).

Il accompagne le patient **durant toute sa vie**.

### 3.2 Caractéristiques obligatoires
Unique, permanent, **non réutilisable**, sécurisé, facilement vérifiable.

### 3.3 Génération
```
Code pays + Année + Compteur national + Clé de contrôle (checksum)
```

### 3.4 Vérification
Un algorithme de checksum contrôle : erreurs de saisie, inversions de chiffres, faux identifiants. La validation est effectuée côté client (feedback immédiat) **et** côté serveur (autorité).

### 3.5 Utilisation
Consultations, ordonnances, analyses, radiologies, assurances, CNAM, urgences, vaccinations, certificats médicaux — et toute donnée de santé de la plateforme.

---

## 4. Référentiel National des Établissements de Santé

### 4.1 Périmètre
CHU, hôpitaux généraux, centres de santé urbains, centres de santé ruraux, cliniques privées, cabinets médicaux, pharmacies, laboratoires, centres d'imagerie, centres de dialyse, centres de vaccination.

### 4.2 Informations conservées
Identifiant national, nom officiel, statut juridique, catégorie, **niveau de soins**, adresse, région, **district sanitaire**, coordonnées GPS (latitude/longitude), téléphones, email, directeur, horaires, capacité d'accueil, nombre de lits, services disponibles, agréments, certifications.

S'y ajoutent les informations collectées lors de l'onboarding (CDC_11) : type (public/privé/universitaire/militaire), informations légales (n° d'autorisation, n° fiscal, registre du commerce, date de création, statut, licence d'exploitation, autorité de tutelle), images (logo, photos), description.

### 4.3 Exemple imposé
```
ID : ETS000152
Nom : CHU de Treichville
Type : Centre Hospitalier Universitaire
Ville : Abidjan
Lits : 850
Urgence : Oui   Réanimation : Oui   Bloc opératoire : Oui
```

### 4.4 Usages
Géolocalisation (OpenStreetMap, itinéraire « S'y rendre »), orientation automatique après triage, statistiques nationales, planification sanitaire, cartographie de l'offre de soins.

---

## 5. Référentiel National des Professionnels de Santé

### 5.1 Périmètre
Médecins généralistes, spécialistes, chirurgiens, dentistes, sages-femmes, infirmiers, pharmaciens, biologistes, radiologues, psychologues, kinésithérapeutes.

### 5.2 Informations enregistrées
Numéro professionnel, nom, prénoms, sexe, spécialité, diplômes (optionnel), université (optionnel), date d'obtention (optionnel), ordre professionnel, **autorisation d'exercer**, établissements d'exercice, horaires, téléphones, email, **signature électronique**, **certificat numérique**.

### 5.3 Signature électronique
Chaque professionnel possède un certificat numérique (PKI — CDC_10), une clé cryptographique et une signature sécurisée. **Les prescriptions deviennent juridiquement traçables** (authenticité, intégrité, non-répudiation).

### 5.4 Vérification avant chaque signature (obligatoire)
Le système vérifie : identité, certificat, autorisation d'exercer, expiration, révocation. Une signature est refusée si l'un de ces contrôles échoue, et l'échec est journalisé.

---

## 6. Référentiel National des Médicaments

### 6.1 Objectif
Éviter les incohérences de nommage et garantir une prescription fiable.

### 6.2 Données par médicament
Code national, **Dénomination Commune Internationale (DCI)**, nom commercial, laboratoire fabricant, forme pharmaceutique, dosage, voie d'administration, classe thérapeutique, indications, contre-indications, **interactions**, effets secondaires, **prix homologué**, statut (générique/princeps), disponibilité.

### 6.3 Exemple imposé
```
Code : MED000458
DCI : Paracétamol
Nom commercial : Doliprane®
Dosage : 500 mg
Voie : Orale
Classe : Analgésique
```

### 6.4 Utilisation par l'IA (CDC_05)
Détection des interactions médicamenteuses, proposition d'alternatives thérapeutiques, suggestion de génériques disponibles, signalement des allergies connues du patient, adaptation des doses selon l'âge, le poids ou l'insuffisance rénale.

### 6.5 Synchronisation
Mise à jour depuis les autorités sanitaires nationales : nouvelles autorisations de mise sur le marché, retraits de médicaments, **alertes de pharmacovigilance**, ruptures de stock critiques. Chaque synchronisation est versionnée et journalisée ; une alerte de retrait déclenche un événement propagé aux pharmacies et aux prescripteurs.

---

## 7. Référentiel National des Laboratoires et Catalogue des Analyses

### 7.1 Types de laboratoires
Laboratoires hospitaliers, privés, de santé publique, universitaires, spécialisés (virologie, génétique, toxicologie, anatomopathologie).

### 7.2 Informations par laboratoire
Identifiant national, nom officiel, type, statut (public/privé), adresse, coordonnées GPS, contacts, **responsable scientifique**, accréditations, équipements principaux, analyses disponibles, **délais moyens de rendu**, horaires, connexion au SI national.

### 7.3 Catalogue national des analyses (normalisation)
Chaque examen est normalisé avec : code national de l'analyse, libellé, description, **unité de mesure**, **valeurs de référence**, méthode analytique, conditions de prélèvement, temps de conservation, délai de rendu.

Cette standardisation garantit que **les résultats sont interprétés de manière cohérente, quel que soit le laboratoire**.

### 7.4 Traçabilité des prélèvements
Chaque prélèvement reçoit un identifiant unique permettant de suivre tout son cycle de vie :
```
1. Prescription médicale
2. Enregistrement du prélèvement
3. Étiquetage par code-barres ou QR Code
4. Transport vers le laboratoire
5. Réception et validation
6. Analyse
7. Validation biologique
8. Transmission sécurisée du résultat dans le dossier patient
```
Cette chaîne réduit les risques d'erreur d'identification et facilite les audits qualité.

---

## 8. Autres référentiels nationaux

- **Maladies** : CIM-11 (et CIM-10 pour compatibilité), libellés multilingues.
- **Symptômes et terminologie clinique** : SNOMED CT.
- **Actes médicaux** et tarifs (base de la facturation — CDC_06).
- **Classifications** diverses (niveaux de soins, catégories d'établissements, statuts).
- **Découpage administratif et sanitaire** : régions, districts sanitaires, communes, quartiers, coordonnées GPS.
- **Spécialités médicales** reconnues.
- **Vaccins** disponibles et calendrier vaccinal national.
- **Assurances** : CNAM et assureurs privés agréés.
- **Numéros d'urgence** nationaux.
- **Protocoles nationaux** (gérés par CDC_08, référencés ici).

---

## 9. Interopérabilité

### 9.1 Standards imposés
- **HL7 FHIR** : échanges de dossiers médicaux, ressources patients, observations.
  ```json
  { "resourceType": "Patient", "name": "Kouassi Jean", "birthDate": "1995-04-12" }
  ```
- **DICOM** : imagerie médicale (scanner, IRM, radiologie), avec PACS.
- **SNOMED CT**, **CIM-10/CIM-11**, **LOINC** (recommandé pour les analyses).
- **OpenHIE** comme cadre d'architecture d'échange en santé.

### 9.2 Intégration des systèmes existants
API d'intégration pour les logiciels hospitaliers, logiciels de pharmacie (caisse, stock, ERP), assureurs et prestataires de paiement (CDC_03 §10.3). Authentification par client OAuth2 dédié, quotas, webhooks signés, journalisation complète, mapping vers les codes du référentiel national.

### 9.3 Synchronisation nationale
```
Hôpital A (base locale) ─┐
                          ├→ Synchronisation → Plateforme Nationale MASANTÉ
Hôpital B (base locale) ─┘
```
Centralisation **logique** (gouvernance centralisée) sans imposer une base unique physique. Résolution des conflits documentée, horodatage, file de synchronisation persistante en cas de coupure.

---

## 10. Gouvernance des référentiels

- **Propriété (Data Ownership)** : patient → Ministère de la Santé ; prescription → médecin ; résultat de laboratoire → biologiste ; facture → administration. Chaque référentiel a un responsable désigné.
- **Cycle de vie** : proposition → validation par l'autorité compétente → publication versionnée → diffusion (événement) → archivage.
- **Versionnage** de chaque référentiel : toute décision clinique ou financière conserve la version du référentiel utilisée.
- **Qualité** : contrôles d'unicité, de format, de cohérence, détection de doublons, interdiction des valeurs aberrantes (ex. date de naissance dans le futur).
- **Accès en écriture** strictement réservé aux rôles habilités (autorités, super administrateurs), avec MFA et double validation.
- **Diffusion** : les référentiels sont exposés en lecture à tous les services via API + cache Redis (TTL long, ex. 30 jours pour les données géographiques) et invalidation par événement lors d'une nouvelle version.

---

## 11. Sécurité (détails CDC_10)

Chiffrement AES-256 au repos et TLS 1.3 en transit ; données biométriques stockées sous forme de gabarits protégés, jamais d'image brute ; accès aux référentiels journalisé ; toute modification d'un référentiel produit une entrée d'audit immuable ; anonymisation obligatoire avant usage en IA ou en recherche ; souveraineté des données (hébergement national contrôlé).

---

## 12. Performance

- Lecture d'un référentiel < **50 ms** (cache Redis).
- Recherche dans le MPI < **200 ms** (index dédiés + recherche floue `pg_trgm` / Elasticsearch).
- Vérification du NIS (checksum) instantanée, sans appel réseau.
- Référentiels volumineux (médicaments, analyses, établissements) indexés dans Elasticsearch pour la recherche utilisateur.

---

## 13. Tests

- Tests d'unicité et de non-réutilisation du NIS ; tests du checksum (erreurs de saisie, inversions).
- Tests du moteur de rapprochement MPI : jeux de cas avec variantes orthographiques, dates approximatives, homonymes stricts (deux personnes différentes portant le même nom et la même date de naissance — **ne doivent pas** être fusionnées automatiquement).
- Tests de fusion/défusion et d'intégrité des dossiers après fusion.
- Tests de conformité FHIR/DICOM (validation des ressources produites).
- Tests de synchronisation (coupure réseau, reprise, conflits).
- Tests de vérification de signature (certificat expiré, révoqué, autorisation retirée).

---

## 14. Ordre de construction recommandé

1. Modèle de données des référentiels (CDC_04) + versionnage + audit.
2. **Identifiant National de Santé** (génération, checksum, vérification).
3. **MPI** : recherche, création contrôlée, détection de doublons, fusion validée, historique.
4. Référentiel des **établissements** (prérequis de l'onboarding — CDC_11).
5. Référentiel des **professionnels** + PKI et signature électronique.
6. Référentiel des **médicaments** (prérequis de la prescription et de la pharmacie).
7. Référentiel des **laboratoires** + catalogue des analyses + traçabilité des prélèvements.
8. Référentiels transverses : maladies (CIM), symptômes (SNOMED), actes et tarifs, découpage sanitaire, spécialités, vaccins, assurances, numéros d'urgence.
9. Interopérabilité : FHIR, DICOM, API d'intégration partenaires.
10. Synchronisation nationale + diffusion par événements + caches.
11. Extension multi-pays (ajout de jeux de référentiels, aucun changement de code).

Chaque étape est testée et validée avant de passer à la suivante ; en cas de problème, correction ciblée de la seule partie fautive.

---

*Fin du CDC_09 — Architecture des Données Nationales.*
