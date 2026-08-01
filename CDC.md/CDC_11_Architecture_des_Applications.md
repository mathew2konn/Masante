# CAHIER DES CHARGES N°11 — ARCHITECTURE DES APPLICATIONS
## Projet MASANTÉ — Plateforme Numérique de Santé de Nouvelle Génération
**Version 1.0 — Document destiné à Claude Code**

---

## 0. Position du document dans le corpus MASANTÉ

Corpus de 13 cahiers des charges interdépendants (tableau complet dans CDC_01 §0). Ce document est le **référentiel fonctionnel** : il décrit chaque sous-application et **toutes ses fonctionnalités**. Le « comment » technique est réparti dans CDC_01 (mobile), CDC_02 (web), CDC_03 (backend), CDC_04 (données), CDC_05/CDC_07 (IA), CDC_06 (paiement), CDC_08 (protocoles), CDC_09 (référentiels), CDC_10 (sécurité), CDC_12 (microservices), CDC_13 (données analytiques).

---

## 1. Vision de l'écosystème

MASANTÉ est un **écosystème numérique de santé national** interconnectant citoyens, professionnels de santé, établissements hospitaliers, pharmacies, laboratoires, radiologie, assurances, ambulances, ministère et systèmes intelligents.

C'est une **plateforme modulaire** : chaque établissement (hôpital, clinique, laboratoire, pharmacie) dispose de son propre tableau de bord, de ses utilisateurs, de ses services et de ses intégrations. **Les patients n'utilisent qu'une seule application**, tandis que chaque partenaire conserve son autonomie de gestion.

### 1.1 Architecture applicative
```
UTILISATEURS
     │
API Gateway Nationale
     │
 ┌───┴────────────────────────────────────────────────────────┐
 Patient │ Médecin │ Infirmier │ Pharmacie │ Laboratoire │ Radiologie
 Ambulance │ Administration │ Ministère │ Assurance │ Statistiques │ Console IA
 └───┬────────────────────────────────────────────────────────┘
     │
        MASANTÉ CORE PLATFORM
  Dossier Médical Électronique │ Gestion Patients │ Prescription
  Facturation │ Paiement │ Identité Nationale Santé
     │
        BUS D'ÉVÉNEMENTS
     │
  Ministère │ Assurance │ Statistiques │ Console IA
```

### 1.2 Socle commun à toutes les applications
Identité Santé Nationale, Dossier Médical Électronique, Sécurité Zero Trust, API Gateway, Microservices, Intelligence Artificielle, Data Platform.

### 1.3 Qualités attendues
Scalable (plusieurs millions d'utilisateurs), interopérable (HL7, FHIR, DICOM), sécurisée, résiliente 24h/24, intelligente, évolutive (ajout de services sans refonte).

---

## 2. Structure de la plateforme

```
Plateforme MaSanté
 ├── Administration centrale
 ├── Hôpitaux (Services, Médecins, Rendez-vous, Facturation, Paiements, Dossier médical)
 ├── Pharmacies (Médicaments, Stocks, Commandes, Ordonnances, Livraisons)
 ├── Laboratoires
 ├── Patients
 ├── Assurances
 ├── Paiements
 ├── Notifications
 └── API d'intégration (Logiciels hospitaliers, Logiciels de pharmacie, Assureurs, Prestataires de paiement)
```

### 2.1 Modules fonctionnels de la plateforme
1. Gestion des patients — 2. Gestion des établissements de santé — 3. Gestion des médecins — 4. Gestion des pharmacies — 5. Gestion des laboratoires — 6. Rendez-vous — 7. Paiement — 8. Dossier médical — 9. **Moteur de triage de symptômes** — 10. Notifications — 11. Administration nationale — 12. API d'intégration.

---

## 3. Onboarding des établissements (deux méthodes obligatoires)

**Principe** : l'administrateur de la plateforme **ne saisit pas les données médicales au quotidien**. Il crée les établissements ; les responsables de chaque établissement gèrent ensuite leurs propres informations.

### Méthode 1 — Création par l'administrateur
L'administrateur crée le compte de l'hôpital → le directeur ou un responsable reçoit un accès → **c'est l'hôpital qui renseigne** : les médecins, les spécialités, les horaires, les chambres, les services, les urgences, les prix, etc.

### Méthode 2 — Demande d'inscription
L'établissement demande son inscription (ex. « Clinique Saint Joseph souhaite rejoindre la plateforme ») → le responsable remplit un formulaire → l'équipe de la plateforme vérifie les informations → après validation, l'établissement est publié.

**Les deux méthodes sont implémentées.**

### 3.1 Informations de l'établissement

**Informations générales (à sélectionner)**
- Nom de l'hôpital
- Type : public, privé, universitaire, militaire
- Catégorie : Hôpital, Clinique, Centre médical, Centre de santé, Laboratoire, Cabinet

**Informations légales (formulaire dédié)**
Numéro d'autorisation, numéro fiscal, registre du commerce, date de création, statut, licence d'exploitation, autorité de tutelle.

**Coordonnées (formulaire dédié)**
Adresse complète, pays, ville, commune, quartier, **latitude GPS**, **longitude GPS**, téléphone, email, site web.

**Localisation** : **OpenStreetMap** avec latitude et longitude, permettant au patient de cliquer sur « **S'y rendre** ».

**Horaires (formulaire dédié)** : lundi à dimanche + **Urgence 24h/24**.

**Images (formulaire dédié)** : logo, photos, salle d'attente, bloc opératoire, accueil, parking s'il existe.

**Description** : présentation, historique (optionnel), mission, valeurs (optionnel).

### 3.2 Services proposés (l'hôpital coche les services disponibles)
Urgence, Pédiatrie, Maternité, Chirurgie, Cardiologie, Neurologie, Dentiste, Ophtalmologie, Radiologie, Psychiatrie, ORL, Kinésithérapie, Vaccination, Laboratoire, Scanner, IRM, Dialyse.

**Chaque service possède** : description (optionnel), **tarif**, **durée moyenne**, **responsable**.

### 3.3 Spécialités (table séparée)
Cardiologue, Dermatologue, Pédiatre, Chirurgien, Orthopédiste, Gynécologue, Urologue, Neurologue, Psychologue, Nutritionniste… **Chaque médecin appartient à une ou plusieurs spécialités.**

### 3.4 Médecins (ajoutés par l'hôpital)
Nom, prénom, photo, sexe, date de naissance, diplômes, université, expérience, spécialité, sous-spécialité, **numéro d'ordre**, téléphone, email, biographie (optionnel), langues parlées, horaires, **consultation en ligne**, **consultation physique**, prix (si nécessaire).

---

## 4. Application Patient

**Concept** : « Mon identité médicale numérique nationale ». Point d'entrée principal du citoyen.

### 4.1 Création et gestion du profil
Identité civile, **numéro national de santé**, date de naissance, sexe, groupe sanguin, allergies, antécédents médicaux, contacts d'urgence, **personnes autorisées**.

### 4.2 Dossier Médical Électronique personnel
Consultation de : historique des consultations, diagnostics, prescriptions, résultats de laboratoire, images médicales, comptes rendus radiologiques, vaccinations, hospitalisations, analyses, allergies, antécédents, maladies, traitements, documents.
```
Patient
 ├── Consultation 12/05/2026
 ├── Diagnostic : Paludisme
 └── Traitement : Artemether/Lumefantrine
```

### 4.3 Prise de rendez-vous
Recherche d'établissement, recherche de médecin, choix d'une disponibilité, **workflow de validation à deux étapes** (§9), confirmation, notification SMS/App.

### 4.4 Téléconsultation
Consultation vidéo, chat médical sécurisé, partage de documents, paiement numérique.

### 4.5 Gestion des médicaments
Voir ses prescriptions, recevoir des rappels, commander en pharmacie, **vérifier les interactions médicamenteuses grâce à l'IA**.

### 4.6 Paiement santé
Assurance maladie, paiement mobile, carte bancaire, paiement établissement, Wallet, CNAM (CDC_06).

### 4.7 Triage
Voir §10.

### 4.8 Autres
Factures et reçus PDF, historique, urgences (numéros nationaux, appel, suivi ambulance), assurance/CNAM (couverture, reste à charge), notifications, messagerie, gestion des personnes autorisées et du consentement.

---

## 5. Application Médecin

### 5.1 Tableau de bord médical
Vue quotidienne : rendez-vous, patients attendus, alertes médicales, statistiques personnelles.

### 5.2 Consultation numérique
```
Patient → Accueil → Consultation → Diagnostic → Prescription → Suivi
```
Le médecin peut : consulter le dossier patient, ajouter des observations, poser un diagnostic, demander des examens, prescrire des médicaments.

### 5.3 Aide au diagnostic IA
Hypothèses diagnostiques, analyse des symptômes, alertes de risques.
```
Symptômes : Fièvre, Fatigue, Douleur articulaire
IA : Paludisme 72 % | Dengue 18 % | Grippe 10 %
```
**Le médecin garde toujours la décision finale.** Chaque proposition est accompagnée de son explication, de son niveau de preuve et de ses sources (CDC_05, CDC_08).

### 5.4 Prescription électronique
Création : médicaments (référentiel national), posologie, durée, instructions patient. Vérifications automatiques (interactions, allergies, contre-indications, adaptation de dose). Signature électronique. Transmission automatique :
```
Médecin → Patient → Pharmacie
```

### 5.5 Collaboration médicale
Demande d'avis spécialiste, réunion virtuelle, partage sécurisé du dossier.

### 5.6 Autres
Agenda, validation finale des rendez-vous, scan du QR code de la fiche de triage et de la carte patient, demandes d'examens, réception des résultats, téléconsultation, messagerie sécurisée, statistiques.

---

## 6. Application Infirmier

- **Gestion des patients hospitalisés** : liste des patients, chambres, services, surveillance.
- **Suivi clinique** : saisie de la température, tension, glycémie, fréquence cardiaque, saturation en oxygène.
- **Administration des traitements** : médicament administré, heure, dose, **signature infirmier**.
- **Alertes intelligentes** :
```
Patient chambre 204 — Tension : 180/110
IA : Risque hypertension sévère
Action recommandée : Notifier le médecin
```
- Planning de soins, transmissions.

---

## 7. Application Pharmacien

### 7.1 Gestion des prescriptions électroniques
```
Médecin → Prescription numérique → Pharmacien
```

### 7.2 Vérification des médicaments
Authenticité, disponibilité, **interactions**, contre-indications.

### 7.3 Gestion du stock
Entrées, sorties, péremption, alertes de rupture, stock minimum.

### 7.4 Fiche pharmacie
Nom, logo, adresse, GPS, téléphone, horaires, **pharmacien responsable**, licence, livraison, rayon.

### 7.5 Fiche médicament (saisie par le pharmacien)
Nom commercial, **DCI**, photo, catégorie, description, laboratoire, **code-barres**, dosage, forme (comprimé, sirop, injection, pommade, gélule), prix, TVA, stock, stock minimum, date d'expiration, **ordonnance obligatoire**, disponibilité.

### 7.6 Traçabilité nationale des médicaments
Lutte contre les médicaments falsifiés, suivi de consommation, statistiques nationales.

### 7.7 Deux modes d'intégration (au choix de la pharmacie)
1. **Gestion directe dans la plateforme** : le pharmacien gère son stock dans l'application.
2. **API d'intégration** : si la pharmacie possède déjà un logiciel (caisse, stock, ERP), ce logiciel envoie automatiquement stock, prix, disponibilité, ordonnances, commandes. **Le pharmacien n'a rien à ressaisir.**

---

## 8. Applications Laboratoire, Radiologie, Ambulance, Administration, Portails

### 8.1 Application Laboratoire
Gestion des demandes d'examens (`Médecin → Demande → Laboratoire → Résultat → Dossier patient`), processus (réception du prélèvement, analyse, **validation biologiste**, publication du résultat), connexion aux **automates biologiques** et machines d'analyse, catalogue national des analyses, traçabilité des prélèvements par code-barres/QR (CDC_09 §7.4).

### 8.2 Application Radiologie
Technologies **DICOM** et **PACS**. Gestion des examens : radiographie, scanner, IRM, échographie. **Vision IA médicale** : détection d'anomalies, aide au radiologue, comparaison d'examens.
```
Image Scanner → IA : Suspicion anomalie pulmonaire (confiance 86 %) → Validation : Radiologue
```
Comptes rendus signés électroniquement.

### 8.3 Application Ambulance
Gestion des appels d'urgence :
```
Patient → Centre urgence → Ambulance disponible → GPS → Hôpital destination
```
Géolocalisation temps réel (GPS, cartographie, routage intelligent). **Optimisation IA** : ambulance disponible la plus proche, temps d'arrivée estimé, hôpital adapté.

### 8.4 Application Administration (établissement)
Utilisateurs : directeur d'hôpital, gestionnaire, responsable financier, RH.
- **Gestion établissement** : services, personnel, chambres, équipements.
- **Gestion financière** : facturation, paiements, assurance, rapports.
- **Gouvernance** : audit, logs, permissions.

### 8.5 Portail Ministère de la Santé
- **Pilotage national** : nombre de patients, maladies fréquentes, occupation des hôpitaux, disponibilité des médicaments.
- **Surveillance épidémiologique par IA** : détection d'épidémies, zones à risque, tendances sanitaires.

### 8.6 Portail Assurance
Vérification de la couverture patient, validation de prise en charge, paiement automatique, contrôle de fraude.
```
Patient → Consultation → Facture → Assurance → Validation → Paiement
```

### 8.7 Portail Statistiques
Sources : hôpitaux, laboratoires, pharmacies, patients. Technologies : Data Warehouse, Data Lake, BI (CDC_13). Analyses : santé publique, budget, performance, population.

### 8.8 Console IA MASANTÉ
```
Données Santé → Data Lake → Machine Learning Platform → Services IA
```
Modules : diagnostic assisté, prédiction médicale (risque diabète, risque cardiovasculaire, réhospitalisation), optimisation hospitalière (prévision d'affluence, gestion des lits, planning des médecins), détection de fraude (facturations suspectes, comportements anormaux), assistant médical conversationnel (LLM médical + protocoles officiels + base scientifique — CDC_07).

---

## 9. Workflows transverses

### 9.1 Rendez-vous — validation en deux étapes (obligatoire)
Chaque **secrétaire** définit (si la structure le permet) : jours disponibles, heures, temps de consultation, nombre maximal de patients, congés, urgences. **Le médecin fait la validation finale.** Si les médecins n'ont pas de secrétaire, ils le font eux-mêmes.

```
Le patient réserve
→ Le système vérifie : Disponibilité, Conflit, Paiement, Confirmation, Notification
→ La secrétaire pré-valide
→ Le médecin confirme
→ Le patient reçoit la confirmation et effectue le paiement
```

### 9.2 Paiement du rendez-vous
Paiement **à la réservation** selon la séquence : réservation → confirmation par le médecin ou la secrétaire → paiement par le patient.
Moyens : carte bancaire, Visa, Mastercard, Orange Money, MTN Money, Wave, Moov Money.
Le système génère : **facture, reçu, numéro de transaction, historique**.

### 9.3 Factures
Après consultation, la facture agrège : consultation, examens, radiologie, médicaments, hospitalisation, TVA, réduction, assurance, montant payé, **reste à payer** (optionnel si l'hôpital autorise le paiement par étapes). La facture est enregistrée ; le patient peut **télécharger le PDF**.

### 9.4 Assurance maladie
Le patient choisit son assurance. Le système vérifie : couverture, plafond, ticket modérateur, reste à charge. **Le patient ne paie que la différence.**

### 9.5 Achat d'un médicament
```
Le patient recherche
→ Choisit la pharmacie où le médicament est disponible, avec itinéraire calculé
→ Ajoute au panier
→ Choisit Retrait ou Livraison
→ Paiement → Commande
```
**Si ordonnance obligatoire** : le patient importe son ordonnance → le pharmacien valide → la vente est autorisée.

### 9.6 Intégration des paiements
**Paiement direct** : le paiement est traité directement par le prestataire de paiement de l'hôpital ou de la pharmacie. **La plateforme ne manipule jamais les fonds.**

---

## 10. Moteur de triage (composant central)

### 10.1 Règle absolue
**Le triage ne doit jamais être présenté comme un diagnostic médical.** Il oriente uniquement le patient vers le niveau de soins approprié.

### 10.2 Fonctionnement
**1. Le patient décrit son état** : choix des symptômes (fièvre, toux, douleur abdominale…), localisation de la douleur (schéma du corps), intensité (0 à 10), depuis quand, évolution (stable, s'aggrave, s'améliore), âge, sexe, grossesse (si applicable), maladies chroniques, allergies, médicaments en cours.

**2. Le moteur analyse** : règles médicales validées (CDC_08) **puis** algorithme d'IA (CDC_05).

**3. Résultat (4 niveaux côté patient)** :
- 🟢 **Faible priorité** : surveillance à domicile, conseils généraux, si nécessaire consulter un pharmacien.
- 🟡 **Consultation recommandée** : rendez-vous avec un généraliste ou un spécialiste ; l'application propose directement les établissements disponibles.
- 🟠 **Consultation rapide (24 h)** : prendre rendez-vous dès que possible.
- 🔴 **Urgence** : se rendre immédiatement aux urgences ou appeler les services d'urgence.

(Le triage hospitalier utilise 5 niveaux — Rouge/Orange/Jaune/Vert/Bleu — dans les applications professionnelles.)

### 10.3 Fiche de triage
Numéro du triage, date et heure, symptômes déclarés, réponses aux questions, niveau de priorité, recommandation, service recommandé (cardiologie, ORL, pédiatrie…), hôpitaux proches proposant ce service, **QR Code** permettant au médecin d'accéder au triage, et la mention :
> *« Ce document constitue une aide à l'orientation et ne remplace pas un diagnostic médical. »*

Téléchargeable en PDF, partageable, transmise au médecin avant le rendez-vous.

### 10.4 Architecture du parcours
```
Patient → Questionnaire intelligent → Moteur de protocoles médicaux → IA médicale
→ Calcul du niveau d'urgence → Orientation (Hôpital | Pharmacie | Téléconsultation | Conseils à domicile)
```
**L'IA n'est jamais le seul décideur.**

### 10.5 Lien avec les pharmacies
- **Médicament sans ordonnance** : l'application peut proposer des médicaments autorisés en vente libre (paracétamol, sérum physiologique…) puis afficher les pharmacies qui les ont en stock.
- **Médicament nécessitant une ordonnance** : l'application **ne doit pas** permettre l'achat sur la base du triage. Le patient doit d'abord consulter un professionnel de santé qui établira une ordonnance si nécessaire.

---

## 11. Évolutions prévues (à ne pas empêcher par conception)

Télémédecine, dossier médical partagé régional, portail des assurances, portail du Ministère de la Santé, intégration des laboratoires privés, gestion des campagnes de vaccination, surveillance épidémiologique, pharmacie nationale, banque de sang, gestion des ambulances, prise de rendez-vous nationale, intelligence artificielle générative pour l'assistance médicale. Chaque nouveau domaine s'ajoute comme module ou microservice, sans remettre en cause les fondations (Rule-001, Rule-004).

---

## 12. Ordre de construction recommandé

1. Socle : identité, référentiels (CDC_09), établissements + onboarding (deux méthodes).
2. Patients + Dossier Médical Électronique.
3. Médecins, spécialités, services, horaires.
4. Rendez-vous (validation deux étapes) + notifications.
5. Consultation + diagnostic + prescription électronique.
6. Paiement + facturation + assurance/CNAM.
7. Pharmacie (gestion directe puis API d'intégration).
8. Triage (protocoles seuls, puis IA).
9. Laboratoire, puis Radiologie.
10. Infirmier / hospitalisation, Urgences/Ambulance, Téléconsultation.
11. Administration d'établissement, Super Administration.
12. Portails Ministère, Assurance, Statistiques, Console IA.

Chaque module est testé (Expo Go SDK 54 + tunnel Ngrok pour le mobile) et validé avant de passer au suivant ; en cas de problème, analyse complète pour isoler **uniquement** la partie fautive et la corriger sans modifier le reste.

---

*Fin du CDC_11 — Architecture des Applications.*
