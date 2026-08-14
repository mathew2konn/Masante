# ADR-035 — Vocabulaire national des spécialités (P6.8a)

- **Statut** : Accepté (2026-08-14)
- **Corpus** : CDC_09 §8 « Spécialités médicales reconnues » — **étape 8** de l'ordre §14
- **Se rattache à** : ADR-024 (référentiels additifs), ADR-025 (socle de gouvernance),
  ADR-026 (projection d'identité), ADR-031 (référentiel professionnel)

---

## 1. Contexte — cinq vocabulaires, trois formes, aucun rapprochement possible

Le G0 de P6.8 a trouvé la spécialité médicale à **cinq endroits** :

| # | Où | Forme | Exemple réel |
|---|---|---|---|
| 1 | `symptomes.specialite_hint` | libellé | `ORL (Oto-Rhino-Laryngologie)` |
| 2 | `services_etablissement.specialite` | code | `orl` |
| 3 | `medecins.specialite` | libellé libre | recopié du **nom du service** par le seeder |
| 4 | `apps/mobile/src/api/donSang.ts` | **constante du client** | `don_sang` |
| 5 | `structures_sanitaires.specialites_json` | colonne **morte** (J4, P6.4d) | écrite, lue par personne |

Deux faits en découlent, et ils commandent tout le reste.

**Le « matching triage (F1.5) » promis par trois commentaires de migration depuis le Module 3 n'a
jamais pu aboutir.** Le triage produit la forme (1) ; l'annuaire compare en **égalité exacte** contre
la forme (2). Le mobile n'affiche aujourd'hui que le libellé, donc le défaut est **latent** — et
c'est ce qui le rend dangereux : le brancher produirait une liste vide, sans erreur.

**Le vocabulaire était défini par ce qui avait été tapé en premier.** `Portail\ServiceController`
validait `regex:/^[a-z_]+$/` — n'importe quel mot en minuscules — et le formulaire proposait « les
codes déjà en base ». C'est l'argument de P6.4a pour le découpage sanitaire (`Abidjan` / `ABIDJAN` /
`Abidjan 1`), et l'avertissement que P6.4d avait laissé ouvert en le nommant.

Enfin, `App\Support\ProfessionsSante` promettait explicitement ce module : « `specialite` reste un
libellé libre **jusqu'à l'étape 8 du corpus** ».

---

## 2. Décisions du propriétaire (2026-08-14)

- **D1 — le référentiel ici, le matching triage en P10.** P6.8a crée le vocabulaire et rattache les
  deux consommateurs ; le branchement du triage reste à P10, qui le refond déjà.
- **D2** — assurances dans P6.8, actes et tarifs renvoyés à un incrément de paiement **nommé**.
- **D3** — CIM : structure, jeu de démonstration étiqueté, codes vides et dits.
- **D4** — cinq incréments, le pivot d'abord.

D1 **tient les deux reports antérieurs à la fois** — celui de P6.5 (« à l'étape 8 ») et celui de
P6.4d (« la vraie table de référence à P10 ») — parce qu'ils ne portaient pas sur la même chose :
l'un sur le **vocabulaire**, l'autre sur le **branchement**.

---

## 3. Les codes sont ADOPTÉS, jamais réinventés

`services_etablissement.specialite` contenait déjà `orl`, `cardiologie`, `medecine_generale`… Ces
valeurs **sont** le vocabulaire de fait. Les promouvoir en codes canoniques :

- laisse intact le contrat `?specialite=orl` de **P3, validé G5** — vérifié par vecteur ;
- transforme la constante `don_sang` du mobile en valeur **vérifiée** au lieu d'**espérée** ;
- rend la bascule **additive** (ADR-024).

Inventer de nouveaux codes aurait cassé les deux premiers.

### 3.1 Pas d'identifiant national généré, et c'est raisonné

`ETS`, `PRO`, `MED`, `ANA` numérotent des **instances** : cet hôpital-ci, ce médicament-là. Une
spécialité est un **terme de nomenclature** — même nature que `regions.code` et
`districts_sanitaires.code` (P6.4a), qui portent un code littéral et aucun numéro. Ajouter un
`SPE000001` par symétrie créerait un second identifiant que personne n'emploierait et contredirait
l'adoption. **La symétrie décorative a déjà été refusée en P6.4a** (pas de journal de
non-réutilisation pour un établissement).

### 3.2 Le code est IMMUABLE après création

`services_etablissement.specialite` porte le code **en texte**, et le filtre public de P3 compare
dessus en égalité exacte. Renommer `orl` laisserait tous les services existants désigner un terme
disparu, **sans qu'aucune erreur ne soit levée**. Un terme qui ne convient plus se **désactive**.
Le libellé, lui, se corrige — c'est une décision de gouvernance, pas une correction de saisie.

---

## 4. `nature` — la colonne qui dit la vérité sur ce vocabulaire

`services_etablissement.specialite` a toujours mélangé deux choses : des **spécialités médicales**
(`cardiologie`, `orl`) et des **activités de service** (`pharmacie`, `biologie`, `don_sang`).

**Le code le savait déjà sans le dire** : `StructureSanitaireSeeder` porte une constante
`SPECIALITES_SANS_MEDECIN = ['pharmacie', 'biologie']` — une exception codée en dur, qui est le
symptôme du mélange.

Le §8 demande les « spécialités médicales **reconnues** ». Ranger `don_sang` parmi elles ferait dire
au référentiel national que la collecte de sang est une spécialité médicale : ce serait **faux**.
`nature` distingue donc les deux **sans scinder le vocabulaire** — ils partagent un espace de codes,
et deux tables auraient rendu la colonne `specialite` insoluble.

`urgences` reste une **spécialité** : le service des urgences est tenu par des médecins, et le même
seeder ne l'a jamais rangée parmi ses exceptions.

---

## 5. La projection prend la ligne entière — question reposée, pas recopiée

P6.4a excluait la note d'avis parce qu'elle est **recalculée** à chaque avis. La question est
reposée ici, comme en P6.6a : **rien n'écrit automatiquement dans `specialites_medicales`**. Aucun
compteur, aucune moyenne. Un compteur « nombre de services rattachés » aurait été utile à l'écran ;
il n'a **délibérément pas** été mis en colonne, justement pour que cette réponse reste vraie.

### 5.1 Deux vecteurs en miroir, aucun ne suffisant seul

| Action | Empreinte du vocabulaire |
|---|---|
| Modifier le **tarif** d'un praticien | **inchangée** (`08777e06…`) |
| Renommer le **libellé** d'un terme | **change** (`27cce197…`) |

### 5.2 Conséquence assumée, écrite avant de coder

`specialite` figure **déjà** dans la projection du référentiel des **professionnels**
(`SourceProfessionnels`, P6.5a). Écrire le libellé d'après le vocabulaire fait donc **changer
l'empreinte de ce référentiel-là**. Ce n'est pas une dérive — même cas que `forme_juridique` en
P6.4d — mais cela devait être **prouvé, pas supposé** : un vecteur dédié le fait.

---

## 6. Les gardes

- **`specialite.referentiel`, portée par aucun rôle métier** — **huitième** occurrence du précédent
  posé par `urgence.bris_de_glace`. `service.manage` appartient au gestionnaire pour décrire les
  services de SON établissement ; l'étendre au vocabulaire national laisserait chaque hôpital
  ajouter le terme qui l'arrange. C'est aussi ce qui rendrait insoluble la question du §4.4 —
  « combien de services de cardiologie dans ce district ? » — si `cardio` et `cardiologie`
  coexistaient.
- **De la détection à l'interdiction** : le formulaire valide contre le référentiel et **nomme les
  termes admis**. Refuser sans dire quoi saisir obligerait l'agent à deviner, et deviner ramènerait
  la faute de frappe qu'on vient de fermer. Même renversement qu'en P6.4d pour région/district.
- **Le rattachement est résolu par le serveur.** `specialite_id` est `fillable` (le chemin
  d'écriture est une assignation de masse — piège de P6.7b) mais n'apparaît dans aucune règle : un
  `specialite_id` envoyé par le client est écarté par `validate()` puis écrasé. Prouvé live : le
  client envoie l'identifiant de `cardiologie`, la base porte celui d'`orl`.
- **Rien n'est supprimé.** `specialites_json`, la colonne morte de P6.4d, est **conservée** : une
  migration destructive perdrait de l'information réelle pour un gain nul (précédent P6.4d-K2).

---

## 7. Ce que le backfill ne fait pas

Un service porte déjà son code : le rattachement est une simple résolution. Un praticien, lui, porte
un **libellé libre** où le seeder a recopié le **nom du service** (« Maternité »). Le rapprocher par
ressemblance produirait des rattachements faux **avec l'assurance d'une machine** → on passe par le
lien **structurel** `medecins.service_id`.

Et **aucun libellé n'est réécrit**. « Maternité » reste « Maternité » : c'est ce que
l'établissement affiche, et le remplacer d'office changerait ce que le patient lit sans que personne
ne l'ait décidé — **leçon de P6.7b**, où un serveur qui réécrivait une déclaration humaine se
trompait avec autorité. L'écart est **signalé à l'écran du référentiel**, là où quelqu'un peut le
corriger en connaissance de cause, et **ne bloque pas la publication** : le vocabulaire, lui, est
valide.

---

## 8. Vérification par mutation

Quatre gardes neutralisées → **exactement quatre vecteurs morts**, chaque mutation **assertée comme
appliquée** avant d'être interprétée (leçon de P6.7b : *une mutation qui ne s'applique pas ressemble
exactement à un vecteur qui survit*).

| Mutation | Vecteurs tués |
|---|---|
| `Rule::in(vocabulaire)` → `'string'` | code hors vocabulaire ; terme désactivé |
| résolution serveur → valeur du client | rattachement envoyé par le client |
| libellé du référentiel → valeur du client | le libellé devient « Oreilles » |
| code immuable → modifiable | `orl` devient `oto_rhino_laryngologie` |

**Piège de méthode rencontré** : deux mutations visant le même fichier, la seconde sauvegarde `.bak`
écrasant l'originale par la version **déjà mutée** — la restauration réintroduisait la première
mutation. Vérifier l'état du fichier après restauration, pas la présence du `.bak`.

---

## 9. Défaut trouvé au G2 live, pas par les tests

Le `--dry-run` du backfill annonçait « **0 praticien** » puis le passage réel en rattachait **28** :
en simulation le service n'étant pas écrit, lire son `specialite_id` renvoyait NULL. **Un aperçu qui
sous-estime ce qu'il va faire n'aide pas à décider.** Corrigé, avec un vecteur qui compare les deux
annonces.

---

## 10. Limites

1. **L'orientation après triage n'est pas branchée** — le défaut central du G0 est **outillé, pas
   refermé**. Foyer : **P10**, qui refond le triage (y toucher ici modifierait deux fois un module
   G5). `symptomes.specialite_hint` reste un libellé libre.
2. **Le contenu est une ADOPTION, pas la nomenclature officielle.** Treize termes, tous déjà
   présents dans le projet. La nomenclature ivoirienne relève d'un arrêté non vu : en inventer
   trente produirait une liste **qui a l'air juste**, donc qui ne se fait jamais corriger
   (raisonnement de P6.4a sur les régions sanitaires d'Abidjan). La charger = **donnée, zéro code**.
3. **Actes et tarifs hors périmètre** (D2), porteur nommé.
4. **L1/L2 d'ADR-025 s'appliquent** : P3 et P4 lisent les tables en direct, pas la version publiée.
5. **Aucun écran mobile de gouvernance** — comme tous les référentiels depuis P6.3.
6. **`structures_sanitaires.specialites_json` reste morte**, conservée délibérément.
