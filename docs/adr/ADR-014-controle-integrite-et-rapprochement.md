# ADR-014 — Contrôle d'intégrité interne (P5.3b-4) & point d'extension du rapprochement à deux sources (S11.x)

- **Statut** : **Accepté** (volet interne, livré en P5.3b-4) · **Perspective** (volet rapprochement à deux sources, S11.x)
- **Date** : 2026-08-05
- **Corpus** : CDC_06 §6.3 (« Journal comptable immuable, rapprochement quotidien automatique, alerte en cas d'écart »), §11 (« Rapprochement automatique quotidien : transactions opérateurs ↔ transactions MASANTÉ ↔ factures ↔ reversements. Écarts détectés et signalés, jamais corrigés silencieusement »), §12 (batch planifié).
- **Lié à** : [[ADR-013]] (microservice paiement), P5.3a (wallet/double écriture), P5.3b-3 (cashback).

## Contexte

Le CDC demande un « rapprochement quotidien automatique ». **Un rapprochement confronte DEUX sources indépendantes** (relevé opérateur ↔ base MASANTÉ, reversements ↔ factures). Or, à ce stade :

- la **passerelle opérateur est SIMULÉE** (aucun relevé de settlement réel — FT5) ;
- les **reversements (§11) n'existent pas** encore.

Il n'existe donc **qu'une seule source** : notre propre base. Confronter la base à elle-même **n'est pas un rapprochement** — c'est un **auditeur d'intégrité interne**. Nommer « rapprochement » un auto-contrôle serait une imprécision attaquable (soutenance, audit). Par ailleurs, écrire dès maintenant des « adaptateurs opérateur prêts à activer » reviendrait à coder contre une API/format **jamais vus** (fichier de settlement Wave/Orange/MTN ? webhook ? SFTP ?) : ce serait du **code mort** réécrit le jour venu, et une branche annoncée « prête » que **aucun test n'a exercée** — un passif, pas un actif.

## Décision

### 1. P5.3b-4 = « Contrôle d'intégrité financière **interne** » (livré, Accepté)

Trois contrôles **déterministes**, **en lecture seule**, **détection seule** (jamais de correction — §11), sur un **snapshot** figé à un arrêté `T` (cut-off = fin de journée comptable UTC ; n'examine que `created_at < T`, ce qui évite les faux positifs pendant l'écriture concurrente) :

1. **Double écriture wallet (§6.3)** — chaque opération a exactement 2 écritures de somme nulle ; Σ global du grand livre = 0 ; solde ≥ 0 **sauf** motif de dette **énuméré** (`OwnerTypeWallet.SYSTEME`, comptes de contrepartie/émission).
2. **Facture ↔ règlement (§7.3)** — cohérence statut ↔ montant réglé **par rapport au reste à payer** (pas au TTC : une facture couverte CNAM/assurance est PAYEE dès que son reste est soldé) ; et cross-grand-livre **directionnel** : Σ des paiements passerelle SUCCESS d'une facture ≤ son montant réglé (un règlement wallet gonfle légitimement le réglé sans ligne `payments`).
3. **Cashback (§6.1/§6.2)** — cashback net consommé ≤ budget de campagne ; Σ clawback ≤ cashback d'origine.

Runs et écarts **persistés** (tables `controle_runs` / `controle_ecarts`, migration V8) ; **idempotent** par journée. Déclenchement **automatique** quotidien (`@Scheduled`, horaire = donnée) **et** manuel (`POST /api/v1/integrity-checks/run?date=`) pour la preuve. Un **seedeur d'anomalies** (dév uniquement, gaté OFF) prouve que chaque contrôle **détecte réellement** son anomalie (un run vert sur des données saines ne prouve rien — il peut être vert parce que vide).

Monnaie : **entiers XOF** (le franc n'a pas de sous-unité) ; la double écriture porte la **même** valeur arrondie sur ses deux jambes → **Σ = 0 exact** par construction, quel que soit l'arrondi des pourcentages (cashback plancher).

### 2. Le vrai rapprochement à deux sources = **S11.x** (Perspective) — point d'extension documenté

Aucun code opérateur n'est écrit maintenant. On **fige le contrat**, pas l'implémentation :

- **Format pivot** attendu d'un relevé de settlement opérateur (à normaliser à l'import, quel que soit le transport — fichier / webhook / SFTP) :
  `reference_operateur`, `reference_masante` (corrélation), `montant` (entier, devise), `canal`, `statut_operateur`, `horodatage`, `frais_operateur`.
- **Point d'insertion** : la notion de « **sources d'un run** ». Aujourd'hui : une seule source (la base). S11 ajoute deux sources supplémentaires — « relevé opérateur » (importé → pivot) et « reversements » — que le run confronte à la source interne.
- **Taxonomie des écarts à deux sources** (à ajouter à `TypeEcart` lors de S11, aujourd'hui **absente à dessein**) : `MONTANT_DIVERGENT`, `MANQUANT_COTE_OPERATEUR`, `MANQUANT_COTE_PLATEFORME`, `DOUBLON`, `DECALAGE_DATE`.

### 3. Classification honnête

Ce volet S11 est classé **« conçu, point d'extension documenté »** — **PAS « prêt à activer »**. Aucune branche opérateur n'existe ni n'a été testée. La rigueur perçue vient de la franchise de la classification, pas du nombre de cases cochées.

## Conséquences

- **+** Le §6.3 (« rapprochement quotidien automatique, alerte en cas d'écart ») est **livré en entier** côté cohérence interne, avec un contrôle qui **détecte vraiment** (fixtures d'anomalies), sans écrire une ligne contre une API non vue.
- **+** Frontière respectée : tout le jugement est backend (règles pures `ReglesControle`) ; le rapport est une donnée.
- **−** Le rapprochement **inter-sources** (l'esprit complet du §11) reste à faire en S11.x, après les reversements et un accès sandbox opérateur (dépendance externe, non garantie). Assumé et tracé ici.
- **Dette / promotion** : la taxonomie d'écarts et le verdict de run sont des **enums backend-only** pour l'instant ; ils seront **promus dans `@masante/shared`** (source unique) quand l'écran d'administration web les consommera — coût de rattrapage faible (aucun client ne les consomme aujourd'hui), même logique que « ne pas coder contre un consommateur inexistant ».
