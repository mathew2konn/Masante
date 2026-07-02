# MaSanté — Incrément de spécification Module 2 (Carnet de Santé Familial)
## Nouvelles fonctionnalités F2.10 à F2.13 — à intégrer au §5.2.1 du Cahier des Charges

> **Statut** : ajout au Module 2 existant (F2.1 → F2.9 inchangées). Issu de l'analyse du modèle chinois (conversation Perplexity) confronté au CdC v3.1. Les éléments déjà couverts ne sont pas redéfinis : **allergies** restent en F2.4 (Antécédents médicaux) ; les **résultats radiologiques** restent en F2.6 (Résultats analyses), F2.10 ne couvrant que l'imagerie importée comme *document*.

---

## 1. Nouvelles fonctionnalités détaillées

| ID | Fonctionnalité | Description détaillée | Acteur | Prio. |
|----|----------------|------------------------|--------|-------|
| **F2.10** | **Documents médicaux importés (multi-format)** | Espace d'import libre permettant au patient de constituer son dossier à partir de tout document de soin : certificats médicaux, fiches de sortie et résumés d'hospitalisation, comptes rendus de consultation, **rapports d'imagerie (radiographie, scanner, IRM, échographie et toute autre imagerie)**, cartes d'assurance et pièces administratives liées aux soins. **Tous les formats de fichier sont acceptés** via une liste blanche large : PDF, images (JPG, PNG, HEIC), documents scannés, Word (.doc/.docx), tableurs (CSV, XLS, XLSX), données structurées (JSON/FHIR), et imagerie médicale (DICOM). Chaque document est catégorisé, daté, et rattaché à un membre. Validation du type MIME réel + analyse antivirus + chiffrement au stockage. Compression à l'upload pour le contexte réseau 3G. | Utilisateur / Médecin | MOY. |
| **F2.11** | **Contacts d'urgence par membre** | Enregistrement, pour **chaque membre** de la famille (et non plus seulement au niveau du compte comme en FT2), d'une ou plusieurs personnes à prévenir : nom, lien de parenté, téléphone. Ces contacts alimentent la carte vitale d'urgence (Module 5) et sont accessibles en lecture lors d'un accès au dossier autorisé (scan QR / médecin référent). | Utilisateur | MOY. |
| **F2.12** | **Notes et observations médicales** | Champ texte libre, horodaté et attribué à son auteur, permettant de consigner des observations sur le dossier d'un membre : remarques de consultation, suivi d'évolution, précisions sur un traitement. Saisissable par le patient ou enrichi par le médecin lors d'un accès au dossier. Donnée sensible : chiffrée et tracée au journal d'audit (FT6). | Utilisateur / Médecin | BASSE |
| **F2.13** | **Traçabilité de la provenance des données** | Chaque entrée du carnet (antécédent, document, résultat, note) porte une **origine** : `patient` (auto-renseigné), `médecin` (enrichi via accès dossier) ou `structure` (produit par un établissement partenaire). Permet de distinguer visuellement, dans l'interface, le *dossier hospitalier* (source de vérité) du *carnet auto-renseigné*, comme dans le modèle hybride numérique/papier. | Système | BASSE |

---

## 2. Ajustements connexes du Cahier des Charges

### 2.1 §5.2.1 — Structure du Carnet
La mention actuelle « **6 sections de dossier** » doit être actualisée pour intégrer les nouvelles rubriques. Le carnet d'un membre comprend désormais : Antécédents (F2.4), Ordonnances (F2.5), Résultats d'analyses (F2.6), Vaccination (F2.7), **Documents importés (F2.10)**, **Contacts d'urgence (F2.11)** et **Notes & observations (F2.12)**. Reformuler en « **sections de dossier** » sans figer le compte, ou indiquer le nouveau total retenu.

### 2.2 Impact sur le modèle de données (Groupe 3 — Carnet de Santé)
Trois ajouts au schéma MySQL, dans le style des tables existantes :

| Table | Rôle | Champs principaux |
|-------|------|-------------------|
| `documents_medicaux` | F2.10 | `id`, `membre_id` (FK), `categorie` ENUM(certificat, fiche_sortie, compte_rendu, imagerie, assurance, autre), `fichier` (chemin chiffré), `mime_type`, `taille`, `source` ENUM(patient, medecin, structure), `date_document`, `created_at` |
| `contacts_urgence` | F2.11 | `id`, `membre_id` (FK), `nom`, `lien_parente`, `telephone`, `created_at` |
| `notes_observations` | F2.12 | `id`, `membre_id` (FK), `contenu` (TEXT, **chiffré AES-256**), `auteur_type` ENUM(patient, medecin), `auteur_id`, `created_at` |

Pour **F2.13**, ajouter un champ `source` ENUM(`patient`,`medecin`,`structure`) aux tables de dossier existantes (`antecedents`, ordonnances, résultats) plutôt qu'une table dédiée.

### 2.3 Sécurité (cohérence avec le document Sécurité)
- F2.10 : l'import « tous formats » est **encadré** (liste blanche + validation MIME réelle + ClamAV + chiffrement). À formuler dans le mémoire comme « **import universel sécurisé** » — jamais « accepte n'importe quel fichier », pour rester défendable.
- F2.11 et F2.12 : données personnelles/sensibles → chiffrement et inscription au journal d'audit (FT6), conformes loi n°2013-450.

### 2.4 Priorités vs périmètre prototype L3
F2.10 à F2.13 enrichissent le carnet sans dépendance externe (pas de passerelle de paiement ni de SMS). Elles sont donc **implémentables dans le prototype** si le temps le permet, F2.10 étant la plus à forte valeur. F2.12 et F2.13 peuvent rester en conception documentée si le planning est tendu.

---

*Incrément de spécification — à coller dans le §5.2 du CdC après F2.9. Aucune donnée inventée : ancré au CdC v3.1, à la conversation Perplexity, et au document Sécurité MaSanté.*
