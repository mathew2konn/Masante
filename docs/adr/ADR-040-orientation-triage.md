# ADR-040 — Orientation après triage, gouvernance du triage et fiche §5.4 (P10a)

- **Statut** : accepté — G1 validé par le propriétaire le 2026-08-15, implémenté P10a
- **Corpus** : CDC_05 §5 (triage, fiche §5.4), CDC_09 §10 (gouvernance), CDC_04 §115 (version du protocole), CDC_00 §4 (interdits)
- **Remplace / complète** : ADR-025 §5 (limites L1/L2 — seconde moitié), ADR-035 (vocabulaire des spécialités)

---

## 1. Contexte

Le G0 de P10a a établi trois faits, dont deux n'étaient pas anticipés.

**T1 était plus grave que P6.8 ne l'annonçait.** `symptomes.specialite_hint` n'était pas seulement
un libellé libre là où un code était attendu : **trois de ses sept valeurs portaient DEUX spécialités
à la fois** (« Cardiologie / Urgences », « Urgences / Traumatologie », « Gynécologie / Maternité »).
Le problème n'était donc pas de *forme* mais de **cardinalité** — aucune colonne `specialite_id`
n'aurait pu porter « Cardiologie / Urgences ».

**Trois règles médicales en dur vivaient dans `TriageService::deduireSpecialite()`** :

```php
->reject(fn ($h) => str_contains(mb_strtolower($h), 'gyn') && $sexe !== 'F')
$hints->first(fn ($h) => str_contains($h, 'urgenc') || str_contains($h, 'cardio'))
if ($age < 15) return 'Pédiatrie';
```

Trois comparaisons de sous-chaînes sur du **texte modifiable**. Personne ne les avait vues *parce
qu'elles ne ressemblent pas à des règles* — et écrire « Urgence » au singulier aurait supprimé la
priorité **sans que rien ne le signale**.

**Le triage lisait sa table de travail.** C'était la **limite L1** d'ADR-025, dont L1+L2 avait
refermé l'autre moitié (`seuils_mesure`) le 2026-08-14 en laissant celle-ci à son foyer nommé : P10.

---

## 2. Décisions du propriétaire (2026-08-15)

| # | Décision |
|---|---|
| **D1** | Périmètre = orientation **et** gouvernance du triage, ensemble |
| **D2** | Plusieurs spécialités, **ordonnées** |
| **D3** | Le serveur renvoie les **codes ET les établissements** (imposé par CDC_05 §5.4) |
| **D4** | Coordonnées retenues côté client **avec fraîcheur**, et **temps de trajet affiché** |
| **D5** | `maladies_probables_json` **retirée de l'instantané publié** |
| **D6** | Fiche §5.4 = mention + réponses + hôpitaux + **QR** dans P10a ; **PDF exclu** (§2.6) |
| **D7** | **Le logo de l'application au centre de TOUS les QR** |

---

## 3. Le point de conception : l'orientation n'est pas une déduction

À la question du propriétaire — *« sur quoi te bases-tu exactement ? si les symptômes prouvent que
le patient a mal à la dent, il doit être orienté au dentiste »* — la réponse est que le triage
**n'infère rien**. « Mal à la dent » mène au dentiste parce que quelqu'un a **écrit** cette
orientation sur ce symptôme.

C'est le bon niveau, et c'est ce que CDC_05 §1 impose : *« le triage n'est jamais un diagnostic »*.
Ce qui manquait n'était pas l'inférence, c'était **l'agrégation** — elle vivait dans un
`str_contains`.

D'où le renommage de `deduireSpecialite()` en **`orienter()`**, et une classe pure
`ReglesOrientation` (motif `ReglesReversement`, `ReglesIntervalleReference`,
`ReglesCalendrierVaccinal`) : aucune base, aucune horloge, tout par paramètre.

Trois conséquences précises :

- **le `rang` remplace la priorité par sous-chaîne** — et devient une donnée relue par deux agents
  habilités (§10). Corriger un libellé cesse d'être un arbitrage clinique involontaire ;
- **`sexe_requis` remplace `str_contains('gyn')`** — et **un sexe INCONNU n'écarte rien** : un triage
  anonyme ne renseigne pas toujours le sexe, et retirer une orientation faute d'information
  reviendrait à décider à la place du patient (motif des trois silences de P7-D2) ;
- **le repli pédiatrique reste une règle**, mais son code et son seuil sont **fournis**, jamais
  enfouis — et il ne s'applique **que si rien n'a été retenu**.

---

## 4. La bascule : refus bruyant, et pourquoi la question méritait d'être reposée

Le triage lit désormais la **version publiée**. Sans version en vigueur : **503**, jamais un repli
sur la table — *un repli laisserait un oubli de publication passer inaperçu, et la garantie serait
inactive sans que personne ne le sache* (décision de L1+L2, reprise telle quelle).

**P6.8e venait pourtant de ne PAS l'appliquer** : les numéros d'urgence se replient côté client,
parce que leur consommateur n'a *ni réseau, ni session, ni compte*. L'argument ne vaut pas ici — un
triage est un appel API, il n'existe pas sans réseau. C'est le précédent L1+L2 qui s'applique.

**Conséquence assumée** : la mise en vigueur de la v1 devient une **étape de déploiement**, faite par
deux agents habilités. Elle ne peut pas venir d'un seeder — publier depuis un seeder contournerait le
quatre-yeux dès le premier jour.

### 4.1 La seconde lecture, trouvée en cherchant les lecteurs restants

`AnalyserTriageRequest` validait `exists:symptomes,id` — **la table**. Un symptôme présent en base
mais absent de la version publiée aurait été **accepté puis ignoré en silence** : le citoyen l'aurait
coché, son score n'en aurait pas tenu compte, et rien ne le lui aurait dit.

C'est mot pour mot le **constat C1 de L1+L2**. Il n'est pas ressorti par relecture, mais en
énumérant tous les lecteurs de la table.

Corollaire : la relation `Symptome::orientations()` a été **retirée**. Elle lirait la table alors que
le triage doit lire la version publiée ; ne pas ouvrir la porte coûte moins cher que de la garder.

### 4.2 Une seule version par requête

`ServiceSymptomesTriage` est lié en **`scoped`** : la validation, le contrôleur et l'algorithme
doivent voir la même version. En liaison ordinaire, une publication survenant au milieu produirait un
triage **jugé par une version et estampillé par une autre**. `scoped` et non `singleton` : sur un
serveur persistant (Octane), un singleton servirait indéfiniment une version périmée.

---

## 5. Ce qui entre dans l'instantané, et ce qui en sort

**Entre** : les orientations, portées **par code** (jamais par `specialite_id` — un identifiant
technique ne veut rien dire hors de cette base), avec leur **libellé FIGÉ**.

Figer le libellé plutôt que le résoudre à la lecture évite deux choses : exiger que **deux**
référentiels soient en vigueur, et laisser un renommage changer le texte lu par un patient sans
aucune publication. Un instantané est par nature un enregistrement **daté** — motif de P6.6b (la DCI
figée dans une ordonnance) et de P7-D2 (l'établissement copié dans le journal d'accès).

**Sortent, pour deux raisons différentes** :

- **`maladies_probables_json`** (D5) — elle **n'a aucun lecteur**, et sa seule sortie du serveur était
  l'instantané, c'est-à-dire l'endroit qui lui donnait le plus d'autorité ;
- **`specialite_hint`** — raison plus forte : elle **contredirait** `orientations`. Deux
  représentations du même fait dans le même instantané, capables de diverger : les « deux vérités »
  refusées en P6.6a. *Cette sortie n'était pas dans D5 ; elle en découle.*

Les deux colonnes sont **conservées** (ADR-024), plus personne ne les écrit — même énoncé honnête que
`vaccinations.statut` (P6.8b) et les colonnes `cmu_*` (P6.8d).

### 5.1 Le contrôle qualité qui compte

**Orienter vers un terme désactivé** est le seul défaut de cette famille qui ne fait **aucun bruit**.
Le déclencheur `ck_orientation_specialite_inactive` refuse d'écrire une orientation vers un terme
déjà désactivé — mais il ne se déclenche pas quand la désactivation vient **après**, du côté du
vocabulaire : la clé étrangère reste satisfaite, la ligne reste en base, et le triage propose une
spécialité que l'annuaire ne peut plus rendre. *L'écran est vide et rien ne le signale.*

Ne sont **délibérément pas** contrôlés : deux orientations au même rang (l'ordre reste déterministe,
interdire une donnée inoffensive ferait un contrôle plus strict que la règle) et un symptôme à
drapeau rouge n'orientant pas vers les urgences (ce serait un **arbitrage clinique**, que le §10 ne
donne pas au socle qualité).

---

## 6. La fiche §5.4, et la porte qu'elle a obligé à fermer

Sur les dix éléments qu'exige le §5.4, **quatre manquaient** : les réponses au questionnaire
(stockées depuis le Module 1, jamais sorties), les hôpitaux proches, le QR et la mention obligatoire.

Mais le G0 a trouvé autre chose. **`GET /triage/{id}/fiche` et `GET /triage/historique` étaient
publics et sans contrôle de propriété** — vérifié par `route:list`. L'historique renvoyait les **50
derniers triages de tout le monde** : nom du patient, âge, sexe, symptômes, score. Le mobile les
affichait. C'est un accès à des données de santé sans lien de prise en charge, que CDC_00 §4 range
parmi les interdits absolus.

**Le défaut date du Module 1, pas de P10a.** Mais poser le QR du §5.4 par-dessus l'aurait
**aggravé** : d'un accès théorique par incrémentation, on serait passé à un lien partagé
délibérément. Le jeton n'est donc pas un ajout de périmètre, **c'est le préalable de ce que le §5.4
demande**.

- `triages.jeton_partage`, 48 caractères, **hors `$fillable`**, posé par un crochet du modèle pour
  que la garantie vaille sur **tout chemin d'écriture** (motif `preparerDonnees()` de P6.6b) ;
- deux clés, et l'identifiant n'en est pas une : **propriétaire authentifié** ou **détention du
  jeton** ; comparaison en temps constant ;
- refus en **404, jamais 403** — un 403 confirmerait qu'un triage existe là.

**Ce n'est pas le jeton QR du dossier (P2/P4).** `QrTokenService` ouvre une **session** : usage
unique, dix minutes, consommé. Une fiche de triage doit rester lisible quand le patient la montre à
l'accueil deux heures plus tard. Ce que le jeton garantit est la **non-énumérabilité**, pas
l'éphémérité.

**Conséquence assumée** : un triage fait sans compte n'apparaît dans aucun historique — contrepartie
exacte de l'anonymat.

---

## 7. Le QR à logo (D7) — une décision technique, pas esthétique

Poser un logo au centre d'un QR, c'est **effacer des modules de données**. Le code ne reste lisible
que par la redondance de Reed-Solomon, et seul le niveau **H** (~30 %) tolère une occultation
centrale. Le logo est **plafonné à 20 %** du côté : au-delà, même « H » ne suffit plus.

Aux niveaux inférieurs, le QR *paraît* correct et cesse d'être scanné par certains lecteurs — **une
panne qui ne se voit pas sur l'écran du développeur**. D'où le composant unique `QrMasante` : la
décision « le logo sur TOUS les QR » ne se tient que si un seul endroit décide de ce à quoi
ressemble un QR — sinon le cinquième appel l'oubliera (récidive du constat G-a de P6.4b).

**Ce qui reste à prouver au G4** : la lisibilité **réelle**, en particulier l'enrôlement MFA scanné
par une application d'authentification tierce. Si un lecteur échoue, le logo saute **sur ce site-là**.

---

## 8. Le temps de trajet (D4)

**Aucun service nouveau** : le client OSRM existe depuis P3 (`routing.openstreetmap.de`). P10a
ajoute `dureesVers()`, qui utilise le service **`table`** — une matrice de durées **en un seul
appel**, quel que soit le nombre d'établissements. C'est la borne promise, faite par la forme de la
requête et non par un compteur.

**Une durée manquante ne retire jamais un hôpital** : la fonction renvoie un tableau de la même
longueur, avec `null` là où la durée est inconnue, et ne lève pas. *Un service d'itinéraire n'a pas à
décider où l'on se soigne.*

Côté position, ADR-027 avait **refusé** de retenir les coordonnées (« une seule mesure au moment du
tap »). La **fraîcheur** permet de tenir les deux, en séparant deux questions confondues :

- « dans quelle ville suis-je ? » — une **affirmation**, servie par les trois sources d'ADR-027,
  inchangée ;
- « quels hôpitaux sont les plus proches ? » — un **classement**, qui tolère quelques minutes.

Au-delà de cinq minutes, la position n'est pas « approximative » : elle est **remesurée**.

---

## 9. Limites (L1→L8)

Voir `GUIDE_TEST_TRIAGE.md` §1. Les deux qui engagent la suite :

- **L1** — aucun protocole clinique : P10b (CDC_08) puis P10c (`triage-service`, CDC_05 §5) ;
- **L7** — le libellé du repli pédiatrique est le **seul non figé**, faute de porteur dans
  l'instantané. Renommer « Pédiatrie » change le texte lu sans publication, alors que renommer
  « Cardiologie » ne le change pas. C'est inconfortable, et c'est la vérité du modèle actuel.
