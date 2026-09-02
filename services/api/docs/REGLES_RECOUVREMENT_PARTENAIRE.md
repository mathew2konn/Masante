# Règles de recouvrement partenaire

**Phase 4 du lot de facturation (Prompt_ClaudeCode_Tables_Facturation_MaSante v2.1, 26/08/2026).**
Ce document est une **spécification**, pas du code. Il sert de cahier des charges au service qui
implémentera l'imputation des règlements et la bascule au Palier 0 — un prompt distinct, hors de
ce lot (interdiction n°9). Rien ici ne s'exécute : les huit tables et huit modèles qui le portent
sont livrés (Phases 1-3), le service qui appliquera ces règles ne l'est pas.

---

## 1. Imputation

Tout règlement reçu d'un partenaire s'impute sur sa **facture impayée la plus ancienne**
(`date_echeance` la plus proche parmi les factures dont le solde est encore positif), jusqu'à
extinction de son solde. Si le montant reçu excède ce solde, l'excédent s'impute sur la facture
suivante par ancienneté, et ainsi de suite jusqu'à épuisement du montant.

**Le partenaire ne désigne jamais la facture ni la ligne qu'il règle.** La ligne de
`reglements_facture_partenaire` porte le **résultat** de l'imputation, décidé par le service, jamais
un choix du payeur exprimé à la saisie. C'est ce qui rend l'imputation vérifiable après coup : la
règle qui a produit chaque affectation est unique et rejouable, elle ne dépend pas de ce qu'un
partenaire a coché sur un formulaire.

**Cas du trop-perçu.** Si le cumul des règlements dépasse le total dû sur l'ensemble des factures en
cours, l'excédent reste un fait à traiter par le service (report sur la période suivante, avoir,
remboursement) — non spécifié ici, parce qu'il dépend d'une décision commerciale que ce document
n'a pas à trancher. Ce qui est acquis : le solde d'une facture (`solde = montant_total −
montant_regle`) peut devenir négatif transitoirement, et ce n'est pas une anomalie à masquer par un
`max(0, …)` — c'est l'état exact qui doit déclencher le traitement du trop-perçu.

---

## 2. Transitions de statut

Sur `factures_partenaire.statut` :

| Transition | Condition |
|---|---|
| `EMISE → PARTIELLEMENT_REGLEE` | Un premier règlement est imputé, le solde reste strictement positif. |
| `PARTIELLEMENT_REGLEE → PAYEE` | Le solde atteint zéro (ou devient négatif — trop-perçu, voir §1). |
| `EMISE → PAYEE` | Un règlement unique éteint le solde d'un coup — la facture ne transite pas obligatoirement par `PARTIELLEMENT_REGLEE`. |
| `EMISE → IMPAYEE` ou `PARTIELLEMENT_REGLEE → IMPAYEE` | 30 jours après `date_echeance`, si le solde est encore strictement positif. |
| `IMPAYEE → PARTIELLEMENT_REGLEE` ou `IMPAYEE → PAYEE` | Un règlement arrive après le basculement en impayé : rien n'empêche un partenaire de régler en retard, et la transition doit rester possible depuis `IMPAYEE`. |

Aucune transition ne réécrit `montant_total`, `montant_abonnement` ou `montant_commissions` : ces
colonnes sont figées dès `EMISE` par le garde-fou du modèle (Phase 2). Seuls `montant_regle`,
`statut` et `date_paiement` bougent après émission.

---

## 3. Sanction — et le seul mécanisme autorisé

Un solde impayé 30 jours après `date_echeance` déclenche, sur **`abonnements_structure`** et
**uniquement** sur cette table :

```
statut               = SUSPENDU
motif_suspension     = IMPAYE
date_bascule_palier0 = maintenant()
```

**La bascule n'écrit sur aucune autre table.** Ni `structures_sanitaires`, ni `factures_patient`,
ni `paiements`. C'est la raison d'être du choix fait en Phase 2 : `date_bascule_palier0` et
`motif_suspension` vivent sur le **contrat** (`abonnements_structure`), jamais sur
l'**établissement** — voir §6 pour la justification complète.

---

## 4. Ce que la bascule ne touche sous aucun prétexte

| Interdit | Pourquoi |
|---|---|
| `structures_sanitaires.actif` | C'est le commutateur **administratif** (fermeture, fraude, décision d'un administrateur). Le confondre avec l'état commercial mêlerait deux décisions qui n'ont ni le même auteur, ni les mêmes conséquences, ni la même réversibilité. |
| Toute colonne de publication ou de visibilité | Le Palier 0 est **visible par définition** (décision D-E1). Une structure sanctionnée reste sur la carte : ce n'est pas une tolérance, c'est la spécification. |
| La table `paiements` | Hors périmètre de ce lot (facturation `factures_patient` en est désormais la seule source de vérité — voir la migration de `factures_patient`, §6 de son en-tête). |
| La table des rendez-vous | Hors périmètre. |

**Conséquence pratique de cette liste :** l'établissement **perd les fonctions du Palier 1** —
encaissement en ligne, agenda, dossier partagé par QR, rappels, statistiques, export comptable — et
repasse à **30 demandes de rendez-vous par mois**. Ce que la sanction ne fait jamais : sa fiche, ses
tarifs et sa mention « Carte CMU acceptée ici » restent affichés ; ses données restent exportables
sous 7 jours ; **le module urgence n'est jamais affecté** — une structure au solde impayé reste
joignable en SOS. **Aucune suppression d'établissement, en aucune circonstance.**

---

## 5. Réactivation

Au règlement intégral du solde (`solde ≤ 0` sur l'ensemble des factures en `IMPAYEE` ou
`PARTIELLEMENT_REGLEE`), retour **immédiat** au Palier 1 :

```
statut               = ACTIF
motif_suspension     = NULL
date_bascule_palier0 reste inchangée (trace historique du dernier basculement, jamais effacée)
```

**Sans ressaisie** : ni nouveau dossier, ni nouvelles pièces légales, ni nouvelle vérification.
L'établissement n'a jamais quitté le référentiel — il a seulement perdu, temporairement, l'accès
aux fonctions payantes de son plan. La réactivation lève cette restriction, elle ne recrée rien.

`date_bascule_palier0` **n'est pas remise à `NULL`** à la réactivation : c'est un horodatage, pas un
drapeau. L'effacer ferait disparaître la trace qu'une suspension a eu lieu, ce qui contredirait la
raison d'être de la colonne (audit) et rendrait invisible un partenaire suspendu plusieurs fois.

---

## 6. Deux avertissements à écrire noir sur blanc pour le prochain développeur

### 6.1 Pourquoi la sanction porte sur le solde, et jamais sur une ligne isolée

`factures_partenaire` porte un **montant total unique** (décision D-E3, Phase 1) : il n'existe
aucun moyen de régler l'abonnement en laissant la commission en suspens, ni l'inverse. Sanctionner
une ligne isolée — par exemple suspendre parce que la part « commission » d'une facture reste due,
alors que la part « abonnement » a été réglée — est **structurellement impossible** avec ce modèle,
et c'est voulu : désactiver un partenaire qui a payé son abonnement mais conteste sa commission
reviendrait à **encaisser sans servir**. La sanction ne peut porter que sur le solde de la facture
entière, parce que la facture entière est la seule unité qu'un partenaire peut régler ou laisser
impayée.

### 6.2 Le piège de la colonne `actif`

`structures_sanitaires.actif` porte, dans le commentaire de sa migration d'origine (Module 4 / 4.2),
l'affirmation suivante : *« `actif=false` la retire de l'annuaire public (mobile) »*.

**C'est faux.** Vérifié ligne à ligne dans `StructureService::rechercher()` (Phase 0 de ce lot,
26/08/2026) : la recherche patient ne filtre **jamais** sur cette colonne. Une structure désactivée
reste aujourd'hui visible dans la recherche, malgré ce que son propre commentaire promet.

Cette divergence n'a **aucune conséquence sur ce lot** : la bascule au Palier 0 n'écrit jamais sur
`actif` (§4), donc elle ne dépend pas de ce que cette colonne fait ou ne fait pas. Mais elle en aura
une le jour où quelqu'un la corrigera pour la faire enfin filtrer la recherche, comme son
commentaire le promet depuis le Module 4. Ce jour-là, il faudra **distinguer deux populations qui
partageront la même colonne** si l'on n'y prend pas garde :

- une structure désactivée **administrativement** (fermeture, fraude) doit **disparaître** de la
  recherche — c'est l'intention originelle de `actif` ;
- une structure suspendue pour **impayé** (Palier 0, ce document) doit **rester visible** —
  c'est la décision D-E1, non négociable.

Si le futur correctif de `actif` ne fait que « filtrer sur `actif=true` », il rendra invisibles
toutes les structures au Palier 0 **en même temps** qu'il corrige le défaut qu'il visait à réparer —
une régression silencieuse, parce qu'aucun test de recherche patient ne pense aujourd'hui à vérifier
qu'un établissement suspendu pour impayé y figure toujours. **Ce sont deux règles opposées portées
par deux colonnes distinctes** (`structures_sanitaires.actif` d'un côté,
`abonnements_structure.statut` de l'autre), et c'est exactement pourquoi la bascule de ce lot
n'utilise pas `actif` : les mélanger aurait rendu cette séparation impossible à faire plus tard sans
casser l'une des deux garanties.
