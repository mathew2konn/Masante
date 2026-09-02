# ADR-049 — API d'ingestion partenaire : la troisième population, et le serveur qui ne devine jamais

- **Statut** : **Accepté — P11.2 VALIDÉ (G5, 2026-08-31)**, socle + premier flux. G4 propriétaire OK.
- **Date** : 2026-08-31
- **Module** : P11.2 — troisième incrément de CDC_11 (Applications métier)
- **Corpus** : CDC_11 §2 (« API d'intégration »), §7.7, §12 (étape 9) · ADR-030 · ADR-047, ADR-048
- **Amende** : ADR-030 §« trois populations d'auth » — le choix d'OAuth2 y est remplacé, voir D1

---

## 1. Contexte

ADR-030 avait posé la position d'architecture **sans écrire une ligne de code**, délibérément :
« aucune API de logiciel hospitalier ivoirien n'a été vue ». Ses principes tiennent et sont repris
tels quels :

- **MaSanté ne remplace pas le logiciel d'un établissement qui en a déjà un, elle s'y branche** —
  « le pharmacien n'a rien à ressaisir » (CDC_11 §7.7) ;
- **l'API est un contrat d'échange, jamais un second chemin d'écriture** ;
- **le référentiel est le PIVOT** — d'où l'étape 9, après les étapes 1 à 8 ;
- **trois populations d'authentification, jamais étirées en une**.

Ce qui a changé depuis : les étapes 1 à 8 de CDC_09 sont **faites**, donc le pivot existe. Et le
propriétaire a tranché l'ambiguïté que portait sa demande : on ne « connecte pas leur base de
données » — lire directement la base d'un partenaire serait le second chemin d'écriture
qu'ADR-030 interdit, et nous ne connaissons le schéma d'aucun logiciel hospitalier ivoirien. Ce
qui est constructible est **notre API d'ingestion**, à laquelle leur logiciel pousse ses données.

### Constat de G0 qui a commandé la conception

**Le pivot était vide sur la base réelle.** `medicaments.code` renseigné **0 fois sur 18**,
`structures_sanitaires.identifiant_national` **0 sur 12**, `analyses` **vide**. Les commandes de
backfill de P6.4a et P6.6a existent mais n'avaient pas été rejouées. *Sans elles, un partenaire
n'a rien vers quoi mapper* — c'est un **prérequis de déploiement**, pas un détail.

---

## 2. Décisions

### D1 — Clé de client + signature HMAC par établissement, et non OAuth2 (amendement à ADR-030)

ADR-030 nommait « OAuth2 client credentials », en notant lui-même qu'il n'existait ni Passport ni
`league/oauth2`. Les installer serait une dépendance (§2.6) **et** un point de terminaison de
jetons qu'aucun partenaire réel ne viendrait éprouver — aucun n'a été consulté, ADR-030 le dit.

Or ce projet possède un mécanisme **éprouvé en production** pour exactement ce problème : le
**montage A de GeniusPay** (P5.6b), où un secret marchand par établissement est atteint par un
**identifiant opaque** — *l'identifiant sélectionne, le HMAC décide*, jamais de boucle d'essai qui
coûterait O(n) et offrirait un oracle de temps. C'est ce mécanisme, retourné : là il vérifiait un
webhook entrant, ici il vérifie un envoi entrant.

**Amendement assumé et réversible** : le jour où un partenaire exige OAuth2,
`AuthentificationClientApi` est le seul point à remplacer — le contrat d'ingestion ne le connaît
pas.

Quatre contrôles, dont aucun n'est décoratif :

| Contrôle | Sans lui |
|---|---|
| **Signature sur le corps brut** | Un JSON ré-encodé produit une autre signature — trouvaille de la phase 6 de P5.6b, où les exemples du prestataire ré-encodaient (`10000.00` → `10000.0`) et où **c'était la documentation qui était fautive** |
| **Fraîcheur ±5 min** | L'anti-rejeu devrait mémoriser indéfiniment ; un envoi capté serait rejouable des mois plus tard |
| **Anti-rejeu atomique** (`Cache::add`) | Un « lire puis écrire » laisserait passer deux envois simultanés |
| **Domaine ouvert à la clé** | Une clé émise pour un logiciel d'officine pousserait des résultats de laboratoire le jour où ce flux existera |

Une seule cause d'échec est exposée : **401 générique**. Le motif est journalisé, jamais renvoyé —
distinguer « client inconnu » de « signature fausse » dirait à un attaquant quels identifiants
existent (même règle que `VerificateurPrincipalSigne`).

### D2 — Le partenaire parle SES références, et le serveur ne devine JAMAIS

**C'est le point de conception de l'incrément.** Le logiciel d'une officine a **ses** codes
produits ; lui demander de parler les nôtres, c'est lui demander de remapper son catalogue à la
main — exactement la ressaisie que le §7.7 dit supprimer.

Le partenaire envoie donc sa référence, et MaSanté la résout par une **correspondance déclarée**.
Une référence inconnue est **refusée et nommée**, jamais rapprochée par ressemblance de libellé.
Le précédent est P6.8c, où rapprocher une maladie d'un texte libre aurait été un diagnostic posé
par une machine ; *ici l'enjeu est plus direct encore — se tromper de produit sur un stock
enverrait un patient chercher la mauvaise boîte.*

La correspondance se déclare **d'une seule façon** : le partenaire envoie **une fois** notre code
national à côté de sa référence. C'est une **affirmation d'équivalence de sa part, pas une
déduction de la nôtre**, et elle est retenue — les envois suivants n'ont plus à la répéter. C'est
ce qui rend vraie la promesse du §7.7.

### D3 — Un contrat d'échange, pas un second chemin d'écriture

`IngestionStockOfficine` **n'écrit rien lui-même** : il résout, valide la forme, puis appelle
`PrixMedicamentService` — **le même service que le pharmacien qui saisit au portail**. Les bornes
de plausibilité du prix, la vérification que l'établissement est une pharmacie : tout cela existe
et n'est pas réécrit. Le réécrire aurait produit deux façons d'enregistrer un prix, qui auraient
divergé du côté qu'aucun humain n'ouvre jamais. Un vecteur le prouve : un prix aberrant envoyé par
l'API est refusé **par la garde du service existant**.

La seule chose que l'ingestion ajoute est sa **provenance** : `logiciel_officine`, distincte de la
saisie au portail et du signalement citoyen. *Un relevé ne doit jamais mentir sur d'où il vient*
(précédents `provenance` P6.8d, `source` P7-C, `origine` P10c-1).

### D4 — Acceptation partielle avec rapport nominatif

Un envoi de cinq cents lignes dont trois échouent écrit les quatre cent quatre-vingt-dix-sept
autres — les perdre rendrait l'intégration inutilisable au premier produit mal référencé — et
**nomme les trois avec leur motif**. « 3 refusées » n'aide personne à corriger quoi que ce soit.
Esprit de la catégorie `illisible` de P10c-3-ii : *jamais zéro, qui fabriquerait une donnée*.

**200 et non 207** : l'envoi a bien été traité, et le rapport dit ce qui ne l'a pas été. Un 207
ferait croire à une erreur de transport là où il s'agit de données à corriger chez le partenaire.

### D5 — Idempotence, et journal append-only

`Idempotency-Key` (précédent P5.1) : un partenaire qui rejoue après un délai réseau reçoit le
rapport du premier envoi, à l'identique, sans seconde écriture. L'unicité est **garantie par le
moteur**, pas seulement respectée par le code.

Et chaque envoi laisse une ligne : qui, quand, combien accepté, combien refusé, **et pourquoi**.
*Une intégration qui échoue en silence est pire qu'une intégration qui échoue.*

### D6 — L'émission d'une clé est une commande, pas un écran

Émettre une identité machine qui écrira au nom d'un établissement suppose qu'on ait vérifié à qui
l'on parle, **hors du système**. Précédent : l'autorité de certification de P6.5b. Le secret est
affiché **une seule fois** ; perdu, il se révoque et se réémet — offrir un chemin de récupération
l'offrirait à qui n'y a pas droit. La révocation **exige son motif**, sans défaut.

---

## 3. Deux défauts réels, la même racine, tous deux invisibles en test

**Une garantie qui ne vaut que d'un côté.** Les deux ont échoué au premier contact avec MySQL
alors que la suite SQLite était verte — et le second après avoir **posé une partie du schéma**, le
DDL MySQL n'étant pas transactionnel (exactement le piège d'`audit_chaines.motif` en P10c-3-ii).

1. **J'avais écrit que SQLite ne contraint pas les ENUM déclarés par Laravel. C'est faux** : il
   pose un `CHECK`. La nouvelle valeur `logiciel_officine` y était refusée, donc la fonctionnalité
   aurait marché en production **sans qu'aucun vecteur ne puisse le prouver** — le sens le plus
   traître de cette divergence. `->change()` remplace le pilotage manuel du dialecte.
2. **Un nom d'index auto-généré de 65 caractères**, quand MySQL en plafonne 64. SQLite n'a pas
   cette limite. Tous les index de cette migration portent désormais un nom explicite, et un
   contrôle le vérifie.

*La leçon n'est pas « tester sur MySQL » — c'est que toute garantie de schéma doit être posée dans
les termes des deux moteurs, comme les déclencheurs le sont depuis P6.3.*

---

## 4. Deux défauts trouvés par la mutation, et ce qu'ils disent

**p11 a survécu** : le vecteur qui prouvait la provenance assertait
`IngestionStockOfficine::SOURCE` — **la constante qu'il testait**. Il prouvait que la constante
vaut elle-même. **Huitième instance** de la famille « le vecteur prouve autre chose ». La valeur
est désormais écrite en dur, et le vecteur vérifie aussi qu'aucune autre provenance n'apparaît.

**p10 a survécu pour une autre raison** : elle ne changeait pas le comportement. Le service avait
**deux blocs `catch`**, dont un pour `ValidationException` lisant `errors()->flatten()->first()`.
Vérifié et non supposé : Laravel place déjà ce message dans `getMessage()`, **les deux branches
produisaient exactement la même chose**. La branche morte est retirée — *une branche qui ne change
rien est une branche qu'un lecteur croira significative* — et la mutation redéfinie sur la vraie
garantie (le lot s'arrête à la première ligne fautive), où elle tue.

---

## 5. Preuves

**G3** — 19 vecteurs dédiés ; suite Laravel complète ; **mutation 13/13 conformes**, dont un
témoin volontairement vert et un témoin qui vérifie que la comparaison de signature est à temps
constant ; Pint propre sur les fichiers neufs.

**G2 live** sur la base MySQL réelle, contre un `artisan serve` réel et un client Python qui signe
comme le ferait le logiciel du partenaire : backfill du pivot (**18/18 codes**) · clé émise par la
commande · premier envoi déclarant deux équivalences → **2 acceptées** · second envoi **sans les
codes** → accepté (la promesse du §7.7 tenue) · référence inconnue → **refusée et nommée**, avec
« le serveur ne la devine pas » · lot de 3 dont 1 fautive → **2 écrites, la fautive nommée par son
index** · signature fausse, client inconnu et horodatage d'une heure → **401 au message identique**
· rejeu avec la même clé d'idempotence → `rejeu: true`, **aucune seconde écriture** · 6 relevés
tous en `logiciel_officine`, une quantité nulle rendue en rupture sans prix · **5 envois
journalisés, refus détaillés**. Base restaurée ; les codes nationaux sont **conservés** — ils sont
le prérequis de déploiement, pas un artefact de test.

---

## 6. Limites

- **Un seul flux** : le stock d'officine. Les autres (résultats de laboratoire, ordonnances,
  commandes) sont **une classe et une ligne de route** chacun, mais ils ne sont pas écrits.
- **Aucun partenaire réel n'a été consulté**, et ADR-030 le disait déjà. Le contrat est
  raisonnable ; il n'est pas éprouvé contre un logiciel de caisse ivoirien.
- **Pas de flux sortant.** MaSanté reçoit ; elle ne notifie pas le partenaire d'un événement
  (une commande, une ordonnance à honorer). Le webhook signé est une conception **prouvée** en
  P5.4a, mais son code est en Java et ne se réutilise pas.
- **Le secret est chiffré, donc recouvrable** — vérifier un HMAC l'exige. Une fuite de la base
  **et** d'`APP_KEY` exposerait les secrets partenaires. Dit plutôt que tu ; la parade est la
  révocation, qui est un geste.
- **L'établissement ne gère pas ses propres clés** : l'émission est une commande d'exploitation
  (D6). Régression d'ergonomie assumée pour cet incrément, pas un principe.
- **Le pivot doit être alimenté** : sans `masante:medicaments:backfill`, aucune correspondance ne
  peut être déclarée. C'est de la donnée, pas du code — mais tant que ce n'est pas fait, l'API
  refuse tout, et le dit.
