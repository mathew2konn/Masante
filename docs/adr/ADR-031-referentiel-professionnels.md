# ADR-031 — Référentiel national des professionnels de santé (P6.5a)

**Statut** : accepté · **Date** : 2026-08-13 · **Corpus** : CDC_09 §5.1/§5.2/§10 · CDC_11 §3.4 · CDC_04 §5.2
**Dépend de** : ADR-024 (enrichissement additif), ADR-025 (socle référentiel), ADR-026 (projection)
**Prépare** : P6.5b (PKI + signature électronique)

---

## 1. Contexte

Étape **5** de l'ordre CDC_09 §14. Le G0 a établi huit constats ; un seul commande tout le reste.

> **`ordonnances.medecin_nom` est un texte libre saisi par le client** (`required|string|max:200`).
> `EcritureSoignantService` réécrit `source` et `added_by` côté serveur — **mais pas le nom du
> prescripteur**. Aujourd'hui, une ordonnance peut porter le nom de n'importe quel médecin, y
> compris écrite depuis le portail par un compte habilité.

Et le porteur manquait autant que le lien : le rôle `medecin`, créé en P1, n'était accepté par aucun
portail. Un praticien qui écrivait au carnet en P7-D0 le faisait sous un compte **`agent_garde`** —
l'identité d'un agent d'accueil. Une signature électronique n'aurait désigné personne.

D'où l'ordre contraint : **l'identité professionnelle d'abord (P6.5a), la clé et la signature
ensuite (P6.5b)**. Signer avant, c'est garantir l'intégrité d'un document dont l'auteur déclaré
reste une chaîne de caractères.

---

## 2. Décision centrale — le référentiel gouverne une projection d'identité professionnelle

Comme ADR-026 pour les établissements, mais **le critère de tri est différent et il fallait le
refaire, pas le recopier**.

ADR-026 excluait `note_moyenne` parce qu'elle est **recalculée automatiquement** à chaque avis :
versionner la table entière aurait rendu l'instantané divergent en permanence. `medecins` n'a pas de
valeur recalculée. La ligne passe donc ailleurs, sur une autre question : **qu'est-ce qui engage une
autorité ?**

| Entre | N'entre pas |
|---|---|
| numéro national, profession, titre/nom/prénom, sexe, spécialité, ordre professionnel, numéro d'ordre, **autorisation d'exercer** et ses dates, université, année de diplôme, lieux d'exercice, actif | tarif de consultation, biographie, téléphone, e-mail, langues, modes de consultation, sous-spécialité |

Ce qui est exclu est réel et utile — la fiche l'affiche — mais **n'engage personne**. Le soumettre à
une double validation nationale transformerait la correction d'un tarif en décision ministérielle.

**Deux vecteurs en miroir le prouvent, et aucun ne suffit seul** : changer le tarif → le référentiel
**ne diverge pas** ; changer le numéro d'ordre ou retirer l'autorisation → il **diverge**.

**Une inclusion assumée** : `nom` et `prénom` sont des données personnelles, pas administratives.
Un référentiel de numéros sans noms serait pourtant inexploitable — personne ne pourrait vérifier
qu'un `PRO000007` est bien la sage-femme dont on parle. Conséquence acceptée : **corriger une faute
d'orthographe fait diverger le référentiel**.

---

## 3. Décisions de structure

**3.1 La table n'est pas renommée.** CDC_04 §5.2 dit `professionnels_sante` ; on garde `medecins`,
comme P6.4a a gardé `structures_sanitaires` là où le corpus disait `etablissements`. Renommer ferait
migrer 29 fichiers pour un gain de vocabulaire (ADR-024).

**3.2 `PRO` + 6 chiffres, littéral, sans clé de contrôle.** §3.2 impose explicitement un checksum
pour le NIS et **ne le fait nulle part ailleurs**. La clé du NIS protège d'une faute de frappe
commise par un citoyen qui saisit son numéro de tête ; un professionnel est toujours choisi dans une
liste ou rattaché à son compte. **`UNIQUE(pays_code, numero_professionnel)`** : le pays qualifie, il
ne s'écrit pas dedans — deux pays peuvent porter `PRO000001`, contrairement au NIS qui discrimine
DANS sa valeur parce qu'un patient traverse les frontières, pas un ordre professionnel.
**Hors `$fillable`** : un client ne choisit pas son identifiant national.

**3.3 `profession` n'est pas `specialite`.** ENUM de onze valeurs (§5.1) contre libellé libre. Un
radiologue et un cardiologue exercent la même profession et deux spécialités. Les fondre rendrait
insoluble la question du §4.4 : « combien de sages-femmes dans ce district ? ». Le référentiel des
spécialités reste l'**étape 8** (décision propriétaire P4).

**3.4 Exercices multiples par table additive.** `professionnel_etablissement` s'ajoute ;
`medecins.structure_id` — NOT NULL, lu par P3, P4 et les référents, tous validés G5 — demeure et
devient l'exercice **principal**, doublé par une ligne `est_principal = true`.
**Cette redondance est assumée et elle a un gardien** : la supprimer d'un côté casserait des modules
G5, de l'autre laisserait le référentiel incapable de dire où exerce un professionnel. Un contrôle
qualité signale toute divergence, et l'exercice principal n'est pas retirable par l'écran.

**3.5 Tout est nullable.** Comme P6.4a, mais l'enjeu est plus grand ici : `autorisation_statut` est
la colonne que le §5.4 interrogera. Un professionnel sans autorisation enregistrée n'est pas
« probablement autorisé » — c'est un professionnel dont nul ne sait s'il a le droit d'exercer.
**L'absence doit se dire pour que le contrôle puisse refuser.**

**3.6 Statut et expiration sont deux contrôles, pas un.** Une autorisation peut être **retirée avant
son terme** (statut) ou simplement **arriver à échéance** (date). Les confondre laisserait passer
l'un des deux cas — §5.4 les nomme d'ailleurs séparément.

---

## 4. La garde qui donnera sa valeur à la signature

> **Un établissement décrit ses praticiens ; il ne déclare pas leur droit d'exercer.**

Le bloc « ordre professionnel + autorisation d'exercer » n'est accepté que d'un compte portant
**`professionnel.habiliter`** — permission **attribuée à aucun rôle métier** (cinquième occurrence
du précédent `urgence.bris_de_glace` / `dossier.ecrire` / `referentiel.*`).

Ce n'est pas une précaution de forme. Ces colonnes sont celles dont dépendra le §5.4 avant de laisser
signer une ordonnance : **si l'hôpital qui emploie le praticien pouvait les écrire, le contrôle qui
autorise la signature reposerait sur la déclaration de l'intéressé.** L'employeur signerait le
contrôle qui le vise.

Le refus est un **silence**, pas un 403 : les champs traversent la requête et ne sont pas repris
(précédent P6.4d, où `identifiant_national` était ignoré malgré l'envoi). La garde est vérifiée
**en service** par `can()`, pas par le middleware spatie — piège de `rdv.validate` en P4.

Même raisonnement pour les **lieux d'exercice** : déclarer qu'un praticien exerce ailleurs est une
affirmation sur sa situation, pas la description de son propre annuaire. Un hôpital qui pourrait
l'écrire seul se rattacherait le médecin d'un confrère sans que celui-ci en sache rien.

---

## 5. Le rôle `medecin` entre au portail (décision propriétaire P5)

`Portail\AuthController::ROLES_PORTAIL` l'accueille. Il reçoit `qr.scan`, `triage.view`,
`dossier.referent` et **`dossier.ecrire`**.

**Pourquoi `dossier.ecrire` alors qu'elle n'était donnée à aucun rôle.** Le commentaire de P7-D0
disait : « `agent_garde` porte `qr.scan` et sert l'accueil ; un agent d'accueil ne rédige pas une
ordonnance — le gestionnaire l'accorde individuellement aux soignants habilités. » L'exclusion visait
un rôle d'accueil **faute de rôle de soin**. Ce rôle existe désormais : c'est le destinataire que la
phrase annonçait. Les **trois gardes cumulatives** de D0 restent intégralement en place — permission,
voie consentie (`qr_scan` ou `referent`, jamais le bris de glace), liste blanche des sections.

**Ce qu'il ne reçoit pas** : `rdv.validate` et `disponibilite.manage` restent à l'accueil (CDC_11 §9
prévoit une validation finale par le médecin, mais ce circuit est P4, validé G5, et on ne le rouvre
pas au détour d'un incrément sur les référentiels) ; `medecin.manage`, car un praticien ne se décrit
pas lui-même dans l'annuaire national ; `professionnel.habiliter`, car il ne se déclare pas
lui-même autorisé.

---

## 6. Ce que le G2 live a trouvé et que les tests ne disaient pas

**Le contrôle de doublon était plus strict que le moteur.** `controlerQualite` comparait les numéros
**sans le pays** : il signalait `PRO000001` comme dupliqué alors que l'index autorise
`CI-PRO000001` **et** `SN-PRO000001`. Le référentiel serait devenu **impubliable dès le premier pays
ajouté** — c'est-à-dire que le principe multi-pays du §1.2.5, défendu jusque dans la forme de
l'identifiant, aurait été annulé par un contrôle de qualité.

Corrigé : la clé de doublon porte le pays, comme l'index. Un vecteur dédié a été ajouté à la suite —
**c'est le G2 qui a dû trouver ce que les tests auraient dû dire.**

---

## 7. Conséquences

**Acquis.** Un professionnel a une identité nationale vérifiable, une autorisation d'exercer datée et
statuée, des lieux d'exercice, et un compte à son propre nom. La projection est sous gouvernance
versionnée : un retrait d'autorisation devient un fait publié.

**Limites annoncées.**

- **Aucune signature, aucun certificat, aucune PKI** — c'est P6.5b. `ordonnances.medecin_nom` reste
  un texte libre : **le trou du G0 n'est pas encore refermé**, il est seulement outillé.
- **Spécialités en texte libre** jusqu'à l'étape 8.
- **Dix métiers du §5.1 sans rôle de portail.**
- **Pas de circuit de rattachement inter-établissements.**
- **L1/L2 d'ADR-025 s'appliquent** : P3 et P4 lisent `medecins` en direct, le référentiel publié
  n'est branché sur aucun écran.
- **Aucune vérification d'authenticité des diplômes** n'est faite ni promise. Ce qui autorise à
  exercer est l'autorisation délivrée par un ordre, pas le diplôme déclaré.

---

## 8. Preuves

**G3** — 45 tests dédiés (`ReferentielProfessionnelsTest` 29, `PortailProfessionnelTest` 16) ; suite
complète **590 tests / 14 870 assertions, 0 échec** ; typechecks shared + web + mobile verts. La
garde centrale a été **vérifiée par mutation** : neutralisée, le vecteur échoue
(`Failed asserting that 'valide' is null`).

**G2 live MySQL — 23 vecteurs.** Schéma (3 tables, 21 colonnes, `uq_professionnel_numero`), 28 fiches
intactes, dry-run muet → backfill → rejeu sans effet, 28 numéros consécutifs et distincts, exercices
principaux concordants (0 incohérence), doublon refusé par le moteur (`ERROR 1062` sur
`CI-PRO000001`), **CI et SN partagent `PRO000001`**, contrôle qualité 88 → 60 anomalies après
backfill des établissements, **les trois vecteurs d'empreinte en miroir**, et la **chaîne HTTP réelle
avec CSRF** : bloc absent de la page, `autorisation_statut=valide` et `numero_professionnel=PRO999999`
envoyés → **NULL et PRO000029** en base, `profession` bien reprise, dates incohérentes → message
d'écran et rien créé. **Base restaurée et vérifiée compte par compte.**

Guide : `GUIDE_TEST_PROFESSIONNELS.md` partie 1.
