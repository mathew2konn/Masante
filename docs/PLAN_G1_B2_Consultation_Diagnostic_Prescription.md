# Plan G1 — B2 : Consultation, diagnostic et prescription électronique

**Statut : EN ATTENTE DE VALIDATION ÉCRITE DU PROPRIÉTAIRE.** Aucune ligne de code n'est écrite avant.

**Position dans le corpus** — trois cahiers désignent cette étape, elle n'est pas choisie :

| Cahier | Ce qu'il dit |
|---|---|
| CDC_11 §12 | étape **5** — « Consultation + diagnostic + prescription électronique » |
| CDC_04 §12 | étape **7** — « Dossier médical : consultations, diagnostics, observations » |
| CDC_01 §17 | module **8** — « Espace Médecin » |

Et le code du projet la nomme lui-même : `RegistreRetourTriage.php` (P10c-2-i, écrit le 2026-08-28) porte la phrase « *Le diagnostic codé a un porteur nommé : le Module 8 (Espace Médecin)* ». **Ce plan est ce porteur.**

**Nom du lot** : `B2`, dans la continuité de `B1` (parcours Rendez-vous), après `P11.0`/`P11.1`/`P11.2`.

---

## 1. G0 — ce qui a été vérifié dans le code, pas supposé

### Y1 — une seule table médicale sur quinze existe

CDC_04 §103 et §105 énumèrent les tables du dossier médical et des prescriptions. Chacune a été cherchée dans les migrations :

| Manquantes (14) | Présente (1) |
|---|---|
| `consultations`, `diagnostics`, `observations`, `dossiers_medicaux`, `allergies`, `maladies_chroniques`, `traitements`, `hospitalisations`, `admissions`, `comptes_rendus`, `ordonnance_lignes`, `delivrances`, `renouvellements`, `scores_cliniques` | `signatures_electroniques` (P6.5b) |

### Y2 — l'ordonnance n'est reliée à personne

`ordonnances` porte `medecin_nom` et `structure_sanitaire` **en chaînes de caractères**. Aucun `medecin_id`, aucun `structure_id`.

P6.5b a rendu la *valeur* fiable — `EcritureSoignantService` la réécrit avec le nom du soignant connecté, le client ne peut plus la déclarer. Mais **une valeur fiable n'est pas un lien** : « toutes les ordonnances du D<sup>r</sup> X » et « ce prescripteur exerce-t-il encore ? » restent insolubles.

**Le patron du correctif existe déjà dans le projet** : P6.7b a fait exactement ce geste sur `resultats_analyses`, en y ajoutant `medecin_prescripteur_id` et `laboratoire_id`.

### Y3 — les médicaments sont un bloc, pas des lignes

`medicaments_json` est un `encrypted:array`. Il contient pourtant **déjà** des lignes structurées (`medicament_id`, `code_national`, `dci`, `dosage_referentiel`, `posologie`), posées et figées par `ServiceLienMedicament` depuis P6.6b.

La structure existe donc ; ce qui manque est l'**interrogeabilité**, et elle est perdue par le chiffrement, non par l'absence de forme.

### Y4 — la vérification d'allergies du §5.4 est structurellement impossible

Une allergie est aujourd'hui une valeur de l'énumération `antecedents.type`, dont le contenu vit dans `description` — **texte libre chiffré**. Croiser un médicament prescrit avec « allergique à la pénicilline » écrit en prose n'est pas faisable.

Constat déjà posé par P6.8c (V4) et jamais refermé depuis.

### Y5 — `acces_dossier` est devenu la consultation sans en être une

La table porte, ajouté module après module : `membre_id`, `agent_id`, `etablissement`, `type_acces`, `motif_urgence`, `triage_id` (P10c-2-i), `rendez_vous_id` (B1-c), `donnees_ajoutees` (P7-D0), `duree_minutes`, et la corrélation ouverture/clôture.

Le commentaire de la migration de P10c-2-i écrit littéralement : « *triage auquel le soignant a déclaré que cette **consultation** répond* ».

**Le mot est déjà là, l'objet non.** C'est la décision D1.

### Y6 — le contexte « consultation » attend son émetteur

`RegistreContextesProtocole::CONSULTATION` existe depuis P10b-2, commenté « *Aucun écran ne l'émet encore (limite N6)* ». Une consultation réelle en serait le premier émetteur — les protocoles médicaux cesseraient de ne s'appliquer qu'au triage.

### Y7 — la transmission à la pharmacie n'a aucun support

§5.4 décrit `Médecin → Patient → Pharmacie`. Aucun lien ordonnance→pharmacie, aucune table `delivrances`. Les seuls modèles pharmacie sont `PharmacieGarde` et `PrixPharmacie`.

### Y8 — le rôle `medecin` a déjà les permissions nécessaires

`dossier.ecrire`, `document.signer`, `qr.scan`, `triage.retour`, `dossier.referent`, `rdv.validate`. Rien n'est bloqué côté droits.

### Y9 — précédent trouvé en chemin, et il décide de D3

`resultats_analyses` **mélange déjà** colonnes en clair et colonne chiffrée : `type_analyse`, `intitule`, `date_analyse`, `laboratoire`, et depuis P6.7b `medecin_prescripteur_id`/`laboratoire_id`/`laboratoire_nom` sont **en clair** ; seul `resultats_json` (les valeurs mesurées) est chiffré.

Le projet a donc déjà tranché la ligne : **les identifiants et les métadonnées sont en clair, le contenu mesuré est chiffré.**

Corollaire dérangeant, dit franchement : `ordonnances` chiffre **tout**, y compris le nom du médicament, alors que `resultats_analyses` laisse « Hémoglobine » en clair. Le nom d'un médicament est traité comme plus sensible que l'intitulé d'une analyse, sans qu'aucun document ne l'ait décidé. Ce plan **ne corrige pas** cette asymétrie (module G5), il la constate.

### Y10 — écart au corpus, hors périmètre

CDC_04 §101 fixe les états de `rendez_vous` à `EN_ATTENTE_VALIDATION`, `PREVALIDE_SECRETAIRE`, `CONFIRME_EN_ATTENTE_PAIEMENT`, `PAYE`, `ANNULE`, `REFUSE`, `TERMINE`. B1-a (validé G5 le 2026-09-02) a retenu `en_attente`, `prevalide`, `confirme`, `refuse`, `annule`, `honore` — le vocabulaire que MySQL portait déjà.

Les deux disent la même chose sans être les mêmes mots. **Signalé, non corrigé** : ADR-050 est validé, et le corriger toucherait un module G5 sans bénéfice clinique.

---

## 2. Les cinq décisions

### D1 — La consultation est-elle une entité nouvelle, ou `acces_dossier` enrichi ?

**Le besoin.** Le §5.2 décrit une chaîne `Patient → Accueil → Consultation → Diagnostic → Prescription → Suivi`. Aujourd'hui chaque écriture du soignant est isolée : une ordonnance et un antécédent écrits le même jour par le même médecin ne se savent pas liés.

**Le problème.** `acces_dossier` porte déjà presque tout ce qu'une consultation porterait, et son propre commentaire l'appelle « consultation ». La tentation d'y ajouter trois colonnes est réelle et serait beaucoup plus courte.

| Option | Ce que ça donne |
|---|---|
| **(a)** Enrichir `acces_dossier` | Le plus court. Aucune table neuve, la fiche de parcours fonctionne telle quelle |
| **(b)** Entité `consultations`, reliée par identifiant | Deux objets à relier, la fiche de parcours doit apprendre à afficher les deux |
| **(c)** Renommer `acces_dossier` en `consultations` | Touche 40+ fichiers et un module G5 ; écarté d'emblée |

**Ma décision : (b), une entité `consultations` distincte.** Quatre raisons, dont deux viennent de décisions déjà prises par ce projet.

1. **Deux natures de vérité dans la même table.** `acces_dossier` est un **journal d'accès** régi par la loi 2013-450 — P7-D2 a explicitement décidé que le journal brut reste réservé au propriétaire du dossier, parce qu'il porte l'adresse IP et **toutes** les lectures familiales. Y verser le contenu clinique mêlerait un registre de surveillance et un acte de soin. C'est le refus que P6.6a a opposé aux interactions (« deux vérités sur un fait clinique ») et P6.3 au journal de gouvernance.
2. **Un journal est immuable, une consultation se rédige.** `acces_dossier` est écrit une fois, à l'ouverture et à la clôture. Une consultation se complète pendant qu'elle a lieu, puis se clôture. Rendre éditable une ligne de journal d'audit détruirait ce que ce journal existe pour prouver.
3. **Les cardinalités ne coïncident pas.** Un accès existe sans consultation (une lecture familiale, un bris de glace, un médecin qui ouvre puis referme sans acte). Et un même accès peut couvrir plusieurs actes distincts.
4. **Précédent direct, pris il y a cinq jours.** P10c-3-ii a créé `retours_cliniques_triage` en table séparée plutôt que d'ajouter trois colonnes à `protocole_applications` — parce que modifier la charge d'un journal chaîné aurait fait crier à l'altération sur **toutes** les entrées existantes. Le même argument vaut ici.

**Le lien** se fait par `acces_dossier_id` **identifiant sans clé étrangère**, patron ADR-042 D1 : supprimer une ligne de journal ne doit pas effacer un acte de soin, et inversement.

**Coût assumé.** Deux objets au lieu d'un ; la fiche de parcours (P7-D2, module G5) devra apprendre à afficher la consultation à côté de la visite — enrichissement additif, son contrat actuel ne change pas.

---

### D2 — Le diagnostic : codé CIM, texte libre, ou lien au référentiel ?

**Le besoin.** §5.2 : « poser un diagnostic ». CDC_04 §103 précise « `diagnostics` (codés CIM-10/CIM-11) ».

**Le problème.** La table `maladies` existe (P6.8c) avec `code_cim10` et `code_cim11` **vides** — P6.8c a refusé d'inventer des codes CIM, qui sont sous licence. Exiger un code CIM rendrait le diagnostic impossible à saisir.

| Option | Ce que ça donne |
|---|---|
| **(a)** Texte libre seul | Saisissable partout, inexploitable : « combien de paludismes ce mois-ci ? » reste insoluble |
| **(b)** Lien obligatoire au référentiel | 21 maladies de démonstration : la plupart des diagnostics deviendraient impossibles |
| **(c)** Lien **facultatif** + texte libre conservé, valeurs figées | Le patron déjà en service |

**Ma décision : (c), le patron exact de P6.8c**, c'est-à-dire `maladie_id` + `maladie_code` + `maladie_libelle` **figés à l'enregistrement**, à côté du texte du médecin qui n'est **jamais réécrit**.

1. **Ce patron existe déjà et il est validé G5** : `antecedents` le porte depuis P6.8c. En inventer un second créerait deux façons de désigner une maladie dans le même dossier.
2. **Obligatoire serait faux**, exactement pour la raison qu'a écrite P6.8c-E4 : *une maladie émergente n'est dans aucune nomenclature au moment où elle émerge*. Refuser un diagnostic parce que le référentiel est incomplet ferait porter à un patient les lacunes de notre catalogue.
3. **Le serveur ne devine jamais.** Aucun rapprochement automatique entre le texte du médecin et un libellé du référentiel — P6.8c a posé que rapprocher serait un diagnostic posé par une machine (CDC_00 §4). Le médecin choisit, ou ne choisit pas.
4. **Un diagnostic de consultation n'est PAS un antécédent**, et c'est le point le plus important. `RegistreRetourTriage` l'a déjà écrit : *« y consigner chaque grippe la transformerait en antécédent permanent pesant sur toutes les orientations futures — on dégraderait l'orientation qu'on cherche à améliorer »*. `diagnostics` est donc une table **à part**, et le passage d'un diagnostic vers les antécédents est un **acte délibéré du médecin**, jamais automatique.

**Coût assumé.** Les codes CIM restent vides : le diagnostic sera « codé » au sens du référentiel national, **pas** au sens CIM. Les charger reste de la donnée, zéro code — et tant que ce n'est pas fait, il faut le dire plutôt que le laisser croire.

---

### D3 — L'ordonnance : passe-t-on aux lignes structurées ?

**Le besoin.** CDC_04 §105 prévoit `ordonnance_lignes` (médicament, dosage, posologie, durée, instructions) et `delivrances`.

**Le problème.** `ordonnances` est validée G5 et sert **trois chemins d'écriture** (patient, délégué, soignant). Son `medicaments_json` chiffré porte déjà les bonnes clés (Y3). La restructurer toucherait un module validé, et ADR-024 impose l'enrichissement additif.

| Option | Ce que ça donne |
|---|---|
| **(a)** Ne rien changer | Y2 reste ouvert : l'ordonnance ne désigne toujours aucun médecin |
| **(b)** Créer `ordonnance_lignes` et migrer l'existant | Touche un module G5 ; et l'interrogeabilité ne s'obtient qu'en cessant de chiffrer |
| **(c)** Créer les lignes en parallèle du JSON | Deux vérités sur le même fait — refusé partout ailleurs dans ce projet |
| **(d)** Enrichir sans restructurer | Ordonnance reliée au médecin, à l'établissement et à la consultation ; lignes reportées |

**Ma décision : (d).** On ajoute `medecin_id`, `structure_id` et `consultation_id` à `ordonnances` — **le geste exact que P6.7b a fait sur `resultats_analyses`** — et on ne touche pas à `medicaments_json`.

1. **`ordonnance_lignes` n'a aucun consommateur aujourd'hui.** Sa raison d'être est la **délivrance en pharmacie** (Y7), qui n'existe pas. Créer les lignes maintenant serait le « socle à vide » que P6.3-D3 a refusé et que P5.3b-4 a nommé « un contrôle toujours vert ne prouve rien ».
2. **L'interrogeabilité ne s'obtient qu'en cessant de chiffrer**, et cette décision-là mérite d'être prise pour elle-même, avec l'arbitrage du propriétaire — pas en passant, au détour d'une restructuration.
3. **Y2 se referme quand même** : ajouter `medecin_id` répond à « toutes les ordonnances du D<sup>r</sup> X » et « ce prescripteur exerce-t-il encore ? », qui étaient les deux questions réellement insolubles.

**Coût assumé, écrit avant de coder.** `ordonnance_lignes` et `delivrances` restent **non livrées**, avec leur porteur nommé : le lot **pharmacie** (CDC_11 §7, étape 7 de l'ordre). *Nommer un manque ne le comble pas, mais un manque nommé ne s'oublie pas.*

---

### D4 — Les allergies structurées et les vérifications automatiques du §5.4

**Le besoin.** §5.4 est explicite : « Vérifications automatiques (interactions, **allergies**, contre-indications, adaptation de dose) ».

**Le problème.** Y4 : une allergie est du texte libre chiffré. Et P6.6b a **délibérément** décidé (choix du propriétaire) que les interactions ne sont **pas calculées à la prescription**, mais consultables explicitement — calculer rapprocherait le module d'une aide à la décision, terrain de CDC_05 et CDC_08.

| Option | Ce que ça donne |
|---|---|
| **(a)** Structurer les allergies + vérifier | §5.4 tenu ; mais suppose un référentiel d'allergènes qui n'existe pas |
| **(b)** Vérification sur les seules allergies saisies après ce lot | **Dangereux** — voir ci-dessous |
| **(c)** Hors périmètre, nommé, + accès explicite aux interactions existantes | §5.4 partiellement atteint, et dit comme tel |

**Ma décision : (c).**

L'argument décisif est contre **(b)**, et il est de sécurité clinique : une vérification qui ne couvrirait que les allergies saisies **après** ce lot afficherait « aucune allergie signalée » sur un patient dont l'allergie est écrite en prose depuis des mois. **C'est plus dangereux que pas de vérification du tout** — le médecin cesserait de demander, en croyant que la machine a regardé. Ce projet applique déjà ce raisonnement mot pour mot en P6.8e : *un numéro d'urgence faux est plus dangereux qu'un numéro absent, parce qu'il sera composé.*

Contre **(a)** : structurer une allergie exige un **référentiel d'allergènes** (substances, classes, réactions croisées) — un référentiel national de plein droit, au même titre que les médicaments ou les maladies, donc un module CDC_09 entier. Le glisser dans ce lot serait cacher un module dans un autre.

Ce que fait **(c)** concrètement : l'écran de prescription donne accès à la consultation d'interactions **déjà livrée** (P6.6b), sans rien calculer ni décider à la place du médecin — le choix du propriétaire sur la consultation explicite est tenu, pas contourné.

**Coût assumé.** **Le §5.4 sera partiellement atteint et le lot doit le dire** : interactions consultables, allergies et contre-indications non vérifiées, adaptation de dose absente. Porteur nommé : un **référentiel d'allergènes** (CDC_09), à ouvrir comme P6.6 l'a été pour les médicaments.

---

### D5 — Quelle taille pour ce lot ?

**Le problème.** L'étape 5 complète couvre la consultation, les observations, le diagnostic, la prescription, les demandes d'examens, les comptes rendus, la transmission en pharmacie, l'aide au diagnostic IA (§5.3) et la collaboration médicale (§5.5). C'est plusieurs modules.

**Ma décision : trois sous-incréments, et un seul est proposé aujourd'hui.**

| Sous-lot | Contenu | Statut |
|---|---|---|
| **B2-a** | L'entité `consultations` : cycle de vie, rattachement au rendez-vous / au triage / à l'accès, **observations**, écran médecin au portail | **proposé maintenant** |
| **B2-b** | `diagnostics` : lien facultatif au référentiel, passage délibéré vers les antécédents | après validation de B2-a |
| **B2-c** | Prescription rattachée à la consultation (`medecin_id`, `structure_id`, `consultation_id`), demandes d'examens | après B2-b |

**Pourquoi commencer par B2-a seul** : c'est le socle dont B2-b et B2-c dépendent, et il a un **consommateur immédiat** — les écritures du soignant, qui existent depuis P7-D0 et se rattacheront enfin à un acte identifié. Le projet a appris en P6.3-D3 qu'un socle sans consommateur ne prouve rien ; celui-ci en a un le jour de sa livraison.

---

## 3. Hors périmètre de B2, avec la raison

| Écarté | Raison |
|---|---|
| Transmission à la pharmacie, `delivrances`, `ordonnance_lignes` | Lot **pharmacie** (CDC_11 §7). Sans lui, les lignes n'ont aucun consommateur |
| Aide au diagnostic IA (§5.3) | CDC_05, et CDC_08 §3 classe le raisonnement IA **dernier**. Le triage-service ne fait pas de diagnostic, et CDC_00 §4 interdit qu'une IA décide seule |
| Collaboration médicale (§5.5), téléconsultation | Module 9 de CDC_01 §17 |
| Référentiel d'allergènes | Module CDC_09 à ouvrir (D4) |
| Hospitalisations, admissions, scores cliniques | Étape 10 de CDC_11 §12 |
| Codes CIM réels | Donnée sous licence, jamais inventée (P6.8c) |

---

## 4. Ce qu'il faudra prouver

**G2 (backend prouvé live)** — base MySQL réelle, sauvegardée puis restaurée : consultation ouverte depuis un rendez-vous réel, rattachée au bon accès, écritures du soignant liées à l'acte, clôture, anti-IDOR vérifié sur un médecin d'un autre service, refus par leur **motif exact** et non par leur seul code HTTP.

**G3 (qualité)** — suite Laravel complète verte (référence actuelle : **1546 tests**, 17 469 assertions), Pint sur les fichiers touchés avec baseline établie contre `HEAD`, typecheck des trois espaces, campagne de **mutation** avec au moins un témoin volontairement vert.

**G4** — test réel par le propriétaire au portail.

---

## 5. Pièges connus qui s'appliquent ici

- **`CHECK` impossible** sur une colonne subissant une action référentielle (MySQL 8.4, erreur 3823) → déclencheurs dans les **deux** dialectes, jamais un seul, sinon la garantie serait vraie en production et fausse en test (P6.8c, P6.8e).
- **Le vecteur qui prouve autre chose** — neuvième occurrence en B1-d : quand plusieurs gardes partagent un code HTTP, satisfaire délibérément les autres pour isoler celle qu'on teste, et vérifier le **message**.
- **Vérifier le vert avant de muter** — un vecteur rouge meurt sous n'importe quelle mutation (leçon P10b-3-i).
- **`Write` écrit en CRLF sur ce poste**, `Edit` préserve les fins de ligne : Pint échoue pour cette seule raison.
- **Une valeur peut n'être pas stockée sous la forme où on l'interroge** (P10c-3-ii) : MySQL arrondit, ne distingue pas `0.0` de `0`, et n'impose pas ce que SQLite impose.

---

## 6. ADR

Ce lot produira **ADR-054**, et amendera par renvoi ADR-024 (additivité) et ADR-042 D1 (un identifiant de journal n'est pas une relation vivante).
