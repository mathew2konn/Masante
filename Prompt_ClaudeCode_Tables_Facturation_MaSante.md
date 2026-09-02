# PROMPT CLAUDE CODE — TABLES DE FACTURATION MaSanté

> ⚠️ **NE PAS EXÉCUTER MAINTENANT.**
> Ce prompt est à conserver. Il ne doit être lancé qu'après :
> 1. la validation du modèle économique par l'encadreur (points A1 à A5 du document `claude_Modele_Economique_et_Partenariats_MaSante.md`) ;
> 2. la finalisation et la validation Postman/Ngrok du **module 1 (triage)** ;
> 3. la résolution de l'incohérence **PostgreSQL / MySQL** dans les documents du projet.
>
> Lancer ce prompt avant ces trois conditions créerait des tables reposant sur des règles non arbitrées.

---

## COPIER À PARTIR D'ICI

---

Tu interviens sur le **backend Laravel de MaSanté** (projet API REST, séparé du projet Expo). Ta mission : créer la couche de **facturation**, en deux lots distincts.

Tu travailles avec une discipline stricte : **Phase 0 d'audit obligatoire avant toute écriture**, une seule préoccupation à la fois, aucune modification hors périmètre.

---

## INTERDICTIONS ABSOLUES

1. **N'écris aucun fichier avant d'avoir terminé la Phase 0 et rendu ton rapport.** Tu t'arrêtes après la Phase 0 et tu attends ma validation.
2. **N'installe aucune dépendance Composer ou npm.** Si tu penses qu'une dépendance est nécessaire, tu le signales et tu t'arrêtes.
3. **Ne modifie aucune migration existante.** Toute évolution passe par une nouvelle migration.
4. **Ne touche à aucun fichier hors du périmètre de facturation** : ni contrôleurs de triage, ni modèles existants, ni routes existantes, ni fichiers de configuration, ni `.env`.
5. **N'exécute jamais `migrate:fresh`, `migrate:refresh`, `migrate:reset` ni aucune commande destructive.** Utilise `php artisan migrate --pretend` pour valider avant toute exécution réelle.
6. **N'utilise jamais de type flottant pour un montant** (`float`, `double`, `decimal`). Voir la convention monétaire ci-dessous.
7. **N'invente aucun nom de table existante.** Les noms réels sont établis en Phase 0.
8. **N'ajoute aucun champ médical** dans les tables de facturation autre que le libellé d'acte prévu, et jamais dans une charge utile de notification.

---

## CONVENTIONS OBLIGATOIRES

**Monnaie.** Le franc CFA (XOF) n'a pas de sous-unité. Tous les montants sont stockés en **entiers**, en francs, via `bigInteger` (ou `unsignedBigInteger` quand le montant ne peut être négatif). Jamais de décimal, jamais de flottant. Une colonne `devise` (string, 3, défaut `XOF`) accompagne chaque montant principal, pour préparer l'extension régionale prévue.

**Taux.** Les pourcentages sont stockés en **points de base entiers** (`unsignedSmallInteger`), où 250 = 2,50 %. Aucun taux en flottant.

**Énumérations.** Colonnes `string` + enum PHP côté application. **Jamais d'enum natif SQL** : cela casse la portabilité entre PostgreSQL et MySQL, et rend les évolutions douloureuses.

**Immutabilité financière.** Aucune table de ce lot ne comporte de `softDeletes`. Un enregistrement financier n'est jamais supprimé ni réécrit : il change de statut. Les clés étrangères pointant vers des données financières utilisent `restrictOnDelete()`.

**Traçabilité.** Toute ligne de commission conserve le **taux appliqué au moment du calcul** et le **volume cumulé qui a servi à déterminer ce taux**. On ne recalcule jamais une commission passée à partir du barème courant.

**Nommage.** Tables et colonnes en **français, snake_case**, cohérentes avec l'existant (`structures_sanitaires`, `membres_famille`, `rendez_vous`, `tokens_qr`…). Le nom exact de la table des établissements est déterminé en Phase 0, **pas supposé**.

---

## PHASE 0 — AUDIT (obligatoire, aucune écriture)

Localise et lis les fichiers, puis réponds aux questions suivantes. **Termine par ton rapport et arrête-toi.**

1. **Racine du projet** : confirme que tu es bien dans le backend Laravel et non dans le projet Expo. Donne le chemin.
2. **Version de Laravel** : lis `composer.json`. Les documents du projet mentionnent tantôt Laravel 11, tantôt Laravel 13. Donne la version réelle.
3. **Moteur de base de données** : lis `.env` (`DB_CONNECTION`) et `config/database.php`. PostgreSQL ou MySQL ? **Si les deux apparaissent ou si c'est ambigu, signale-le et arrête-toi** — c'est une incohérence connue et non résolue du projet.
4. **Table des établissements** : liste `database/migrations/`. Quel est le nom exact de la table des structures de santé ? Quel est le type de sa clé primaire (`bigIncrements`, `uuid`, autre) ?
5. **Table des utilisateurs / patients** : même question. Type de la clé primaire ?
6. **Table des membres de famille** (bénéficiaires du carnet familial) : existe-t-elle ? Nom exact et clé primaire ?
7. **Conventions monétaires existantes** : cherche dans les migrations actuelles toute colonne représentant un montant (prix, tarif, coût). Quel type est utilisé aujourd'hui ? Y a-t-il déjà du `decimal` ou du `float` ?
8. **Collision de noms** : une table contenant `facture`, `paiement`, `abonnement`, `commission` ou `plan` existe-t-elle déjà ?
9. **Paquets présents** : `spatie/laravel-permission` est-il installé ? Un paquet de gestion de paiement l'est-il ?
10. **Journal d'audit** : une table de journal inaltérable existe-t-elle déjà ? Nom exact.

**Format du rapport attendu :** une liste numérotée de 10 réponses courtes et factuelles, suivie d'une section « Points bloquants » si tu en as identifié. Puis tu t'arrêtes.

---

## PHASE 1 — MIGRATIONS, LOT A : facturation partenaire

*(MaSanté facture les établissements)*

Une migration par table, dans cet ordre. Remplace `structures_sanitaires` par le nom réel trouvé en Phase 0.

### `plans_tarifaires`
```
id
code                        string, unique   -- P0_VISIBILITE | P1_GESTION | P2_INTEGRATION | P1_FORFAIT_0
libelle                     string
categorie_structure         string, nullable -- CABINET | CLINIQUE | HOPITAL | PHARMACIE
montant_mensuel             unsignedBigInteger, défaut 0
devise                      string(3), défaut 'XOF'
commission_incluse          boolean, défaut false   -- true pour le forfait 0 % (A1e)
actif                       boolean, défaut true
date_effet                  date
date_fin                    date, nullable
timestamps
```

### `abonnements_structure`
```
id
structure_sanitaire_id      FK -> structures_sanitaires, restrictOnDelete
plan_tarifaire_id           FK -> plans_tarifaires, restrictOnDelete
rang_signature              unsignedInteger   -- ordre d'arrivée du partenaire, figé, sert à R2
duree_essai_jours           unsignedSmallInteger  -- 90 pour les 20 premiers, 30 ensuite
date_debut                  date
date_fin_essai              date
date_fin                    date, nullable
statut                      string   -- ESSAI | ACTIF | SUSPENDU | RESILIE
motif_suspension            string, nullable
date_prochaine_facturation  date, nullable
timestamps

index (structure_sanitaire_id, statut)
```

### `baremes_commission`
```
id
palier_ordre                unsignedTinyInteger
volume_mensuel_min          unsignedBigInteger
volume_mensuel_max          unsignedBigInteger, nullable   -- null = pas de plafond
taux_bps                    unsignedSmallInteger           -- 250 = 2,50 %
date_effet                  date
date_fin                    date, nullable
timestamps

index (date_effet, date_fin)
```
> Un barème n'est **jamais modifié en place**. Une évolution ferme la ligne courante (`date_fin`) et en insère une nouvelle.

### `commissions_transaction`
```
id
structure_sanitaire_id      FK, restrictOnDelete
facture_patient_id          FK -> factures_patient, nullable, restrictOnDelete
reference_geniuspay         string, unique, nullable
montant_brut                unsignedBigInteger
frais_passerelle            unsignedBigInteger, défaut 0   -- Wave, PawaPay...
frais_prestataire           unsignedBigInteger, défaut 0   -- GeniusPay : 100 + 1 %
taux_bps_applique           unsignedSmallInteger
volume_cumule_au_calcul     unsignedBigInteger             -- volume du mois au moment du calcul
montant_commission          unsignedBigInteger
montant_net_structure       unsignedBigInteger
devise                      string(3), défaut 'XOF'
statut                      string   -- CALCULEE | FACTUREE | ANNULEE
date_transaction            timestamp
timestamps

index (structure_sanitaire_id, date_transaction)
```
> `montant_brut = frais_passerelle + frais_prestataire + montant_commission + montant_net_structure`.
> Cette égalité doit être vérifiée par un test (Phase 4) : c'est elle qui garantit le reçu transparent promis aux partenaires.

### `factures_partenaire`
```
id
structure_sanitaire_id      FK, restrictOnDelete
reference                   string, unique
periode_debut               date
periode_fin                 date
montant_abonnement          unsignedBigInteger, défaut 0
montant_commissions         unsignedBigInteger, défaut 0
montant_total               unsignedBigInteger
devise                      string(3), défaut 'XOF'
statut                      string   -- BROUILLON | EMISE | PAYEE | IMPAYEE
date_emission               date, nullable
date_echeance               date, nullable
date_paiement               date, nullable
timestamps

unique (structure_sanitaire_id, periode_debut, periode_fin)
```

---

## PHASE 2 — MIGRATIONS, LOT B : facturation patient

*(L'établissement facture le patient — voir §2.7 du document de référence)*

### `factures_patient`
```
id
structure_sanitaire_id      FK, restrictOnDelete
patient_id                  FK -> table utilisateurs, restrictOnDelete   -- titulaire du compte
beneficiaire_id             FK -> membres_famille, nullable, restrictOnDelete
reference                   string, unique
moment_paiement             string   -- AVANT_ACTE | APRES_ACTE
montant_brut                unsignedBigInteger
montant_pris_en_charge_cmu  unsignedBigInteger, défaut 0
montant_reste_a_charge      unsignedBigInteger
devise                      string(3), défaut 'XOF'
statut                      string   -- A_REGLER | PAYEE | PRISE_EN_CHARGE_TOTALE | ANNULEE | EXPIREE
paiement_en_ligne_autorise  boolean, défaut true   -- false si montant < plancher (R17)
date_emission               timestamp
date_echeance               date, nullable
date_reglement              timestamp, nullable
relance_envoyee_le          timestamp, nullable    -- une seule relance, jamais deux (R18)
timestamps

index (patient_id, statut)
index (structure_sanitaire_id, date_emission)
```

### `lignes_facture_patient`
```
id
facture_patient_id          FK, cascadeOnDelete
libelle_acte                string
code_acte_national          string, nullable
quantite                    unsignedInteger, défaut 1
prix_unitaire               unsignedBigInteger
taux_cmu_bps                unsignedSmallInteger, défaut 0
montant_ligne               unsignedBigInteger
timestamps
```
> ⚠️ `libelle_acte` est une **donnée médicale**. Elle ne doit jamais quitter la couche authentifiée : ni dans une notification, ni dans un log applicatif, ni dans un message d'erreur. Voir règle R14.

---

## PHASE 3 — MODÈLES ELOQUENT

Un modèle par table, dans `app/Models/`. Pour chacun :

- `$fillable` explicite — jamais `$guarded = []`
- `$casts` pour les dates, booléens et enums PHP
- Relations Eloquent dans les deux sens
- **Aucune logique de calcul dans les modèles** — les calculs iront dans un service dédié, hors périmètre de ce prompt
- Sur `CommissionTransaction` et `FacturePatient` : un garde-fou empêchant la modification d'un enregistrement dont le statut est `PAYEE` ou `FACTUREE` (via l'événement `updating`)

Crée les enums PHP correspondants dans `app/Enums/` : `StatutAbonnement`, `StatutCommission`, `StatutFacturePartenaire`, `StatutFacturePatient`, `MomentPaiement`.

---

## PHASE 4 — SEEDERS ET TESTS

### Seeder `PlansTarifairesSeeder`
| code | libellé | catégorie | montant |
|---|---|---|---|
| P0_VISIBILITE | Visibilité | — | 0 |
| P1_GESTION | Gestion — cabinet / centre de santé | CABINET | 15 000 |
| P1_GESTION | Gestion — clinique / laboratoire | CLINIQUE | 30 000 |
| P1_GESTION | Gestion — hôpital / polyclinique | HOPITAL | 50 000 |
| P1_GESTION | Gestion — pharmacie | PHARMACIE | 15 000 |

### Seeder `BaremesCommissionSeeder`
| palier | volume min | volume max | taux |
|---|---:|---:|---:|
| 1 | 0 | 250 000 | 250 bps |
| 2 | 250 001 | 1 000 000 | 200 bps |
| 3 | 1 000 001 | 3 000 000 | 150 bps |
| 4 | 3 000 001 | null | 100 bps |

Les deux seeders doivent être **idempotents** (`updateOrCreate` sur le code / le palier).

### Tests à écrire (`tests/Feature/`)
1. `test_montants_commission_equilibres` — pour toute commission, brut = passerelle + prestataire + commission + net.
2. `test_aucun_montant_en_flottant` — parcourt le schéma et vérifie qu'aucune colonne de montant n'est de type flottant ou décimal.
3. `test_facture_payee_non_modifiable` — toute tentative de mise à jour d'une facture `PAYEE` lève une exception.
4. `test_plancher_paiement_en_ligne` — une facture sous 5 000 FCFA a `paiement_en_ligne_autorise = false`.
5. `test_bareme_selectionne_selon_volume` — 4 cas, un par palier, aux bornes exactes.
6. `test_pharmacie_sans_commission_hors_ligne` — une facture de pharmacie non réglée en ligne ne génère aucune commission.
7. `test_relance_unique` — une seconde relance sur la même facture est refusée.

---

## CHECKLIST DE VALIDATION FINALE

- [ ] `php artisan migrate --pretend` s'exécute sans erreur
- [ ] `php artisan migrate` puis `php artisan migrate:rollback` fonctionnent dans les deux sens
- [ ] Aucune migration existante n'a été modifiée (`git diff` sur `database/migrations/` ne montre que des ajouts)
- [ ] Aucun ajout dans `composer.json`
- [ ] Les 7 tests passent
- [ ] Aucun fichier hors `database/migrations/`, `database/seeders/`, `app/Models/`, `app/Enums/`, `tests/Feature/` n'a été touché

---

## CE QUI N'EST PAS DANS CE PROMPT

À traiter dans des prompts ultérieurs, séparément :

- Le service de calcul de commission (`CommissionService`)
- L'intégration GeniusPay (client HTTP et endpoint webhook)
- Les contrôleurs et routes API de facturation
- La génération des factures partenaires en fin de mois
- Les notifications temps réel (Firebase) et leurs règles de confidentialité
- Les écrans du portail établissement et de l'application patient

---

## FIN DU PROMPT
