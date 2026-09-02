# PROMPT CLAUDE CODE — SERVICE DE CALCUL DE COMMISSION
## Backend Laravel — Lot 2 de la séquence post-facturation

> Applique `claude/00_Conventions_Transversales_MaSante.md`, non recopiées ici.

---

## CONDITIONS D'EXÉCUTION

`Prompt_ClaudeCode_Tables_Facturation_MaSante_v2.md` exécuté, testé, mergé — en particulier `baremes_commission`, `commissions_transaction`, `plans_tarifaires`.

Ce service est **indépendant** du lot 1 (recouvrement) et du lot 6 (canal interne) : il expose une méthode publique que le lot 6 appellera plus tard. Il peut être développé et testé seul, avec des appels directs depuis les tests.

---

## COPIER À PARTIR D'ICI

---

Tu écris le service qui calcule la commission MaSanté sur une transaction de paiement patient et l'enregistre. Ce service ne parle jamais à GeniusPay ni au microservice Java : il reçoit des montants déjà calculés par ailleurs et applique le barème.

## INTERDICTIONS ABSOLUES

1. **Aucune nouvelle migration.**
2. **Tu n'appelles aucune API externe, aucun réseau.** Ce service est pur calcul et écriture en base.
3. **Tu ne génères aucune facture partenaire.**
4. **Les frais (`frais_passerelle`, `frais_prestataire`) sont des paramètres d'entrée, jamais recalculés ou estimés.** Voir la règle R4 du modèle économique : ils viennent de GeniusPay, ils ne se reconstituent pas.
5. **Tu ne touches à aucun fichier hors de `app/Services/`, `app/Support/`, `tests/Feature/`.**

## PHASE 0 — AUDIT CIBLÉ

1. Confirme la structure exacte de `commissions_transaction` et de `baremes_commission` telle que produite par le lot Tables_Facturation.
2. Confirme comment `plans_tarifaires.commission_incluse` (forfait 0 %, P1_FORFAIT_0) et la catégorie `PHARMACIE` sont représentées sur une structure — pour appliquer R5 (pharmacie : commission uniquement si réglé en ligne).
3. Recherche l'existence d'un utilitaire d'arrondi déjà présent dans le projet (`grep -ri "arrondi\|round"` dans `app/`). S'il en existe un pour de l'argent, réutilise-le plutôt que d'en écrire un nouveau.

**Arrête-toi et rapporte.**

## PHASE 1 — `CommissionService`

Fichier unique : `app/Services/CommissionService.php`.

### `calculerEtEnregistrer(array $donnees): CommissionTransaction`

Entrée attendue (tableau ou DTO, à ton choix, mais types stricts) :
```
structureSanitaireId, facturePatientId (nullable), montantBrut,
fraisPasserelle, fraisPrestataire, referenceGeniuspay (nullable),
referenceInternePaiement, dateTransaction
```

1. **Idempotence en premier geste.** Si une `commissions_transaction` existe déjà avec ce `reference_interne_paiement`, retourne-la telle quelle, **sans rien recalculer ni recréer**. C'est le garde-fou contre une notification rejouée par le lot 6.
2. **Exemption pharmacie (R5).** Si la structure est une pharmacie **et** que la transaction n'est pas un règlement en ligne (déterminé par le type de facture patient associée, ou un paramètre explicite `regleEnLigne: bool` — à défaut d'information, considère `false` et journalise un avertissement plutôt que de deviner), la commission est nulle : `taux_bps_applique = 0`, `montant_commission = 0`.
3. **Exemption forfait 0 % (A1e).** Si le plan de la structure a `commission_incluse = true`, même traitement : commission nulle, mais **enregistre quand même la ligne** — la traçabilité du volume reste utile pour le tableau de bord.
4. **Sélection du barème (R3).** Calcule le volume mensuel cumulé de la structure (somme de `montant_brut` des `commissions_transaction` au statut `CALCULEE` ou `FACTUREE`, du premier au dernier jour du mois de `dateTransaction`, **avant** d'ajouter la transaction courante). Sélectionne la ligne de `baremes_commission` active à cette date dont `volume_mensuel_min ≤ volume cumulé ≤ volume_mensuel_max` (ou pas de plafond).
5. **Calcul.** `montant_commission = round(montantBrut × taux_bps / 10000)`, arrondi commercial. `montant_net_structure = montantBrut − fraisPasserelle − fraisPrestataire − montant_commission`.
6. **Vérification d'équilibre avant écriture.** `montantBrut === fraisPasserelle + fraisPrestataire + montant_commission + montant_net_structure`, sinon exception explicite — ne jamais écrire une ligne déséquilibrée.
7. Enregistre la `CommissionTransaction`, statut `CALCULEE`, `volume_cumule_au_calcul` = le volume cumulé utilisé au point 4 (avant ajout de cette transaction — c'est la valeur qui justifie le taux choisi, elle ne bouge jamais après coup).

## PHASE 2 — TESTS (`tests/Feature/`)

1. `test_bareme_selectionne_selon_volume_cumule` — 4 cas, un par palier, aux bornes exactes du §2.3.
2. `test_pharmacie_hors_ligne_commission_nulle`.
3. `test_pharmacie_en_ligne_commission_normale`.
4. `test_forfait_zero_pourcent_commission_nulle_mais_ligne_enregistree`.
5. `test_montants_equilibres` — brut = passerelle + prestataire + commission + net, sur plusieurs cas dont des montants qui forcent un arrondi.
6. `test_arrondi_commercial` — un montant dont la commission calculée tombe exactement sur ,5.
7. `test_idempotence_meme_reference_interne` — deux appels avec la même `reference_interne_paiement` : une seule ligne, la deuxième retourne la première sans la modifier.
8. `test_volume_cumule_ne_compte_pas_la_transaction_courante` — le taux appliqué à la transaction qui fait franchir un palier est celui du palier **avant** franchissement, pas après.

## CHECKLIST FINALE

- [ ] Aucune migration ajoutée
- [ ] Aucun appel réseau dans le service (`grep -rn "Http::\|curl\|GuzzleHttp"` ne retourne rien dans ce fichier)
- [ ] Les 8 tests passent
- [ ] Aucune commission n'est jamais recalculée après enregistrement (vérifié par le garde-fou d'immutabilité du modèle, lot Tables_Facturation)

## HORS PÉRIMÈTRE

L'appel réseau vers le microservice Java (lot 6), la génération de facture (lot 3), la bascule de palier (lot 1).

## FIN DU PROMPT
