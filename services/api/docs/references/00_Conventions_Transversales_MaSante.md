# CONVENTIONS TRANSVERSALES — Backend Laravel de facturation MaSanté

> Ce document est **référencé, jamais recopié**, par tous les prompts de la séquence de facturation. Toute contradiction entre un prompt et ce document est une erreur du prompt : ce document prime.
> Un prompt qui commence par « Applique les conventions de ce document » n'a pas besoin de les redéfinir. S'il les redéfinit quand même, c'est un signal qu'il a dérivé — arrête-toi et compare.

---

## 1. Monnaie et taux

- XOF, aucune sous-unité. Tout montant est un entier en francs : `bigInteger` ou `unsignedBigInteger`. Jamais `float`, `double`, `decimal`.
- Une colonne `devise` (string, 3, défaut `'XOF'`) accompagne chaque montant principal.
- Les taux sont des points de base entiers (`unsignedSmallInteger`) : 250 = 2,50 %.
- **Arrondi.** Tout calcul produisant un montant non entier (commission = brut × taux/10000) s'arrondit à l'entier le plus proche, arrondi commercial (0,5 arrondit au-dessus). La méthode d'arrondi doit être un point unique et testé, jamais dupliquée d'un service à l'autre.

## 2. Énumérations

- Colonnes `string` en base. **Jamais d'enum natif SQL.**
- Enum PHP natif, dans **`app/Support/`** — c'est la convention déjà en place dans ce projet (`TypeNotification`, `TypeAccesDossier`…). Ne crée jamais `app/Enums/`.

## 3. Immutabilité financière

- Aucune table financière ne porte `softDeletes`. Un enregistrement financier change de statut, il ne se supprime jamais et ne se réécrit jamais.
- Un fait daté (un règlement, une transaction) s'écrit une fois, en une ligne, et n'est **ni modifiable ni supprimable** — jamais, quel que soit le statut englobant. Une correction s'exprime par une nouvelle ligne de sens contraire, jamais par une édition.
- Un solde ne se stocke jamais : il se dérive (`total − réglé`). Aucune colonne `solde` en base.
- Les clés étrangères vers des données financières utilisent `restrictOnDelete()`.

## 4. Nommage

- Tables et colonnes en français, snake_case, cohérentes avec l'existant (`structures_sanitaires`, `membres_famille`, `rendez_vous`…).
- Un nom de table ou de colonne existant n'est jamais supposé : il est vérifié en Phase 0 du prompt qui en a besoin.

## 5. Fuseau horaire

Tout en UTC (`Instant`/`timestamp`). Abidjan est UTC+0 sans heure d'été ; l'explicitation évite l'ambiguïté avec un futur module opérant sur un autre fuseau.

## 6. Discipline d'exécution — non négociable sur tout le programme

1. **Phase 0 d'audit avant toute écriture.** Même quand une Phase 0 antérieure a déjà couvert le projet : chaque nouveau lot vérifie ce qui le concerne spécifiquement, jamais par supposition.
2. **Arrêt et rapport à la fin de chaque phase.** Pas d'enchaînement silencieux vers la phase suivante.
3. **Aucune modification hors périmètre déclaré.** Un fichier touché qui n'est pas dans la liste explicite du prompt est une erreur, même si la correction semble juste.
4. **Aucune dépendance ajoutée sans signalement et arrêt.**
5. **Aucune commande destructive** (`migrate:fresh`, `migrate:refresh`, `migrate:reset`, suppression de branche, etc.) sans autorisation explicite, hors du cadre de ce prompt.
6. **`migrate --pretend` ne prouve rien sur l'ordre des contraintes.** Toute migration doit être validée par une exécution réelle (`migrate` puis `migrate:rollback`) sur une base de test, jamais par la seule lecture du SQL généré.
7. **Un test qui échoue ou une ambiguïté de Phase 0 est un motif d'arrêt, jamais de contournement improvisé.**

## 7. Documents de référence — à lire selon le lot

**Ces documents ne sont pas nativement dans le dépôt.** Ce sont des livrables du projet Cowork ; ils doivent être copiés physiquement dans `docs/references/` du dépôt concerné avant de lancer le premier prompt qui les cite — voir la procédure de préparation dans `10_Sequence_Implementation_MaSante.md`. Un prompt dont la Phase 0 ne trouve pas le fichier attendu doit s'arrêter et le rapporter, jamais deviner son contenu.

| Sujet | Nom de fichier | Emplacement une fois copié |
|---|---|---|
| Modèle économique, paliers, barème | `Modele_Economique_et_Partenariats_MaSante.md` | `docs/references/` (Laravel) |
| Essai 30 jours, solde unique, bascule Palier 0 | `Amendement_Essai_et_Recouvrement_MaSante.md` | `docs/references/` (Laravel) |
| Règles d'imputation et de recouvrement, produites par la Phase 4 du schéma | `REGLES_RECOUVREMENT_PARTENAIRE.md` | `docs/` (Laravel) — **celui-ci est un vrai fichier du dépôt**, généré par Claude Code lui-même en Phase 4 du lot Tables_Facturation, jamais copié à la main |
| Arbitrages GeniusPay (B1–B7), montage A, machine à états | `Arbitrage_Audit_Phase0_GeniusPay.md` | `docs/references/` (Java, `paiement-service`) |
| Prélèvement à la source — pourquoi ce n'est pas fait aujourd'hui | `Prelevement_Commission_a_la_Source.md` | `docs/references/` (Laravel **et** Java) |
