# Plan G1 — P10b-3-ii : assemblage du score sous protocole + écran §7 de lecture et signature

- **Statut** : **G1 VALIDÉ par le propriétaire le 2026-08-20** — décisions A (les poids restent au
  référentiel, le périmètre annoncé est réduit et l'asymétrie nommée), B (le plafond passe sous
  protocole, `impact_triage` reste déclaré et devient une limite nommée) et C (écran §7 en Blade,
  lecture et signature) validées telles quelles. **Implémentation commencée le 2026-08-21**, après
  le G5 de l'incrément « chaînes d'audit » (ADR-042).
- **Position** : dernier incrément de **P10b** (étape 4 du CDC_08 §13 achevée). Suit P10b-3-i
  (ADR-041 §B3). Précède **P10c** (`triage-service`, IA, CDC_05 §5).
- **Corpus** : CDC_08 §1.2, §4, §6, §7, §10 ; CDC_05 §5.3 ; CDC_04 §115 ; CDC_00 §4.

> **Ce plan modifie le périmètre annoncé, et c'est validé.** `CLAUDE.md` annonce « poids des symptômes
> sous protocole + écran §7 ». L'analyse ci-dessous conclut que **déplacer les poids des symptômes
> serait une erreur**, et pour une raison qui vient de l'incrément précédent lui-même. C'est la
> décision **A**, et elle vous revient : si vous tranchez dans l'autre sens, le plan change de forme.

---

## 1. G0 — ce qui a été mesuré

### Y1 — `impact_triage` est déclaré par le client

`AntecedentController` valide `'impact_triage' => ['nullable','integer','between:0,20']`. C'est donc
le patient — ou son délégué — qui écrit le nombre alimentant le score de triage. Même famille que
`medecin_nom` (P6.5), `vaccinations.obligatoire` (P6.8b), `cmu_statut` (P6.8d).

### Y2 — Le « 20 » vit à trois endroits

Commentaire et défaut de la migration `create_antecedents_table`, validation du contrôleur, et
`TriageService::PLAFOND_ANTECEDENTS`. Ils ne disent pas la même chose — borne **par antécédent**
contre borne **de la somme** — mais partagent le littéral, et rien ne les tient ensemble.

### Y3 — Le référentiel des maladies ne porte aucune gravité

`maladies` : `code`, `libelle`, `code_cim10`, `code_cim11`, `description`, `source`, `source_detail`,
`actif`. Il n'existe donc **rien** d'où le serveur pourrait dériver l'impact d'un antécédent. Et
P6.8c avait explicitement refusé une colonne `categorie` faute de base sourcée : *classer une
maladie est une affirmation clinique*. Inventer une gravité qui décide de l'urgence serait pire.

### Y4 — Le moteur ne sait pas plafonner

`DEFINIR_SCORE_MINIMUM` pose un **plancher** sur `score`. Il n'existe ni plafond, ni action portant
sur un fait intermédiaire comme `score_antecedents`. Une action « multiplier » ou « plafonner »
générique ouvrirait dans la donnée une arithmétique que personne ne relirait — c'est exactement ce
que P10b-3-i a refusé pour le coefficient de l'échelle.

### Y5 — Le portail a dix écrans de référentiel et aucun écran de protocole

`resources/views/portail/` porte `analyses`, `medicaments`, `specialites`, `vaccins`, `maladies`,
`assurances`, `numeros-urgence`, `signature`… et rien pour les protocoles. Les **quatre permissions
§7** existent depuis P10b-1 (`protocole.valider.clinique|reglementaire|scientifique|technique`) et
ne sont portées par **aucun rôle** : les destinataires de l'écran sont prêts, l'écran manque.

### Y6 — `poids_severite` et `drapeau_rouge` sont gouvernés, mais à deux signatures

Ils vivent dans le référentiel `symptomes_triage`, publié sous le cycle **§10** du socle P6.3 :
**deux** agents. Les seuils de niveau (b-1) et l'impact des réponses (b-3-i) sont, eux, sous les
**quatre** validations du §7. L'asymétrie que b-3-i a refermée pour les réponses reste donc ouverte
pour les symptômes. **C'est le fait qui rend la décision A difficile.**

### Z1 — L'assemblage des faits existe en TROIS exemplaires (trouvé au G0 d'implémentation)

`TriageController::questions()` (l. 128-134) et `TriageService::analyser()` (l. 155-170 **et**
l. 195-206) composent chacun à la main le tableau de faits remis au moteur. Les six mêmes clés y
sont recopiées.

**Ce n'est pas une redite sans conséquence, et ce n'est pas latent** : `score_antecedents` est
déjà un fait déclaré, et il est passé par les **deux** sites du service — **pas** par celui du
contrôleur. Or, depuis P10b-1, **un fait inconnu lève**. Donc une règle de questionnaire qui
conditionnerait sur les antécédents fonctionnerait dans `POST /triage/analyser` et **rendrait
`POST /triage/questions` inopérant**. Aucune règle seedée ne le fait aujourd'hui : le défaut est
**actif mais non déclenché** — même famille que le `centre_dialyse` de P6.4b et le
`specialite_requise` de P10a.

**Conséquence sur cet incrément** : ajouter `score_antecedents_brut` et `nb_antecedents` aux trois
endroits reproduirait la faute au lieu de la fermer. **L'assemblage doit avoir une seule source**
avant que deux faits ne s'y ajoutent. Ce n'est pas un élargissement de périmètre : c'est la
condition pour que le périmètre validé (§3) soit tenable.

### Y7 — La table `antecedents` est vide en développement

Zéro ligne. Aucune migration de données à prévoir, et aucune mesure possible de ce que valent les
`impact_triage` réels.

---

## 2. Décision A — les poids des symptômes restent au référentiel

### Les deux lectures, et pourquoi la première ne tient pas

**Lecture 1 (celle qu'annonce `CLAUDE.md`)** : par cohérence avec b-3-i, `poids_severite` et
`drapeau_rouge` décident de l'urgence, donc ils doivent passer sous les quatre validations du §7,
donc ils deviennent des règles de protocole (`SI symptome_id contient N ALORS AJOUTER_SCORE p`).

**Ce qui la casse, c'est l'argument central de b-3-i lui-même.** Cet incrément a déplacé les
questions **parce que** question, condition et impact ne peuvent pas vivre dans deux artefacts aux
cycles de publication indépendants : ajouter une question puis sa règle ne peut **jamais** être
atomique, et chaque contrôle qualité bloque l'autre dans les deux sens.

Déplacer les poids reproduirait exactement ce défaut, un cran plus bas : **un symptôme neuf publié
au référentiel pèserait 0 tant que le protocole ne l'aurait pas rattrapé** — sans erreur, sans
signal, avec un patient trié plus bas qu'il ne devrait. C'est la panne muette que P10a a passé un
incrément entier à refermer.

### La ligne qui discrimine, et elle n'est pas arbitraire

> **Ce qui est un attribut de l'objet reste avec l'objet. Ce qui est une règle combinant des objets
> va au protocole.**

Une question **est** la substance du questionnaire ; elle n'appartient à aucun symptôme (b-3-i l'a
montré : trois valeurs de `specialite_hint` en portaient deux à la fois). Un poids, lui, est un
attribut du symptôme, à côté de son nom, de sa catégorie et de ses orientations — le déplacer
couperait le symptôme en deux.

### Ce que cela laisse ouvert, et qui doit être dit

L'asymétrie Y6 **reste** : le poids d'un symptôme continue d'être publié par deux agents, alors
qu'il décide de l'urgence autant qu'un seuil de niveau. La réponse honnête n'est pas de déplacer la
donnée mais **d'élever la gouvernance de ce référentiel** — ce qui touche le cycle du socle P6.3,
partagé par les dix référentiels. **C'est un incrément à part, et il doit être nommé plutôt
qu'oublié** (précédent : une dette sans porteur ne se fait jamais — L1+L2).

**Conséquence sur le titre de l'incrément** : « poids des symptômes sous protocole » sort du
périmètre. Il devient **« assemblage du score sous protocole + écran §7 »**.

---

## 3. Décision B — le plafond est la réponse à Y1, pas une incohérence avec lui

J'avais avancé que plafonner sous serment une valeur libre serait indéfendable devant le §7.
**C'est l'inverse.** Le plafond existe précisément **parce que** l'entrée est une déclaration non
vérifiée : il répond à la question « quel poids une auto-déclaration peut-elle avoir sur l'urgence
d'un citoyen ? ». C'est une décision clinique, et elle a toute sa place sous quatre validations.

**Donc : le plafond passe sous protocole, et `impact_triage` reste déclaré par le patient.**

Les deux autres voies ont été examinées et écartées :

- **dériver l'impact d'une gravité gouvernée** supposerait d'inventer, dans le référentiel des
  maladies, une échelle de gravité que personne n'a validée (Y3) ;
- **refuser la déclaration du patient** ferait tomber `score_antecedents` à 0 pour tous les
  antécédents saisis au carnet, donc **baisserait les scores de triage** — un défaut qui pousse vers
  le **sous-triage**, la direction dangereuse. *On ne retire pas une information au motif qu'elle
  n'est pas vérifiée, quand son absence est plus risquée que son imprécision.*

Y1 est donc **nommé comme limite**, avec son porteur : un impact posé par le chemin soignant
(P7-D0), ou une gravité gouvernée au référentiel des maladies le jour où une source existe.

### Mécanisme proposé

Deux faits neufs et une action neuve, tous en liste blanche fermée :

- fait `score_antecedents_brut` — la somme **non plafonnée** ;
- fait `nb_antecedents` — pour qu'un protocole puisse réagir à la **présence** d'antécédents sans
  dépendre d'un poids par item ;
- action `DEFINIR_SCORE_ANTECEDENTS` (valeur entière) — écrit le fait, exactement comme
  `DEFINIR_SCORE_MINIMUM` écrit `score`.

La règle devient une ligne relisible et signable :
`SI score_antecedents_brut > 20 ALORS DEFINIR_SCORE_ANTECEDENTS 20`.

`TriageService::PLAFOND_ANTECEDENTS` disparaît. La borne `between:0,20` du contrôleur **reste** :
elle n'a pas le même objet (borne de saisie, pas règle d'agrégation) et sera documentée comme telle,
pour que Y2 cesse d'être deux copies muettes du même chiffre.

### Où vit la règle

Dans un **protocole distinct** `TRIAGE-ANTECEDENTS`, partageant le contexte `triage_questionnaire`
(la phase évaluée avant que le score ne se ferme). Le sélecteur de b-2 sait déjà porter plusieurs
protocoles dans un contexte et cumuler leurs actions non exclusives. Séparer permet de re-signer le
plafond sans re-signer le questionnaire — le même argument qui a séparé le questionnaire des seuils.

**Conséquence de déploiement, dite d'avance : CINQ mises en vigueur** (`seuils_mesure`,
`symptomes_triage`, `TRIAGE-NIVEAU`, `TRIAGE-QUESTIONNAIRE`, `TRIAGE-ANTECEDENTS`). Le nom du
contexte dira « questionnaire » alors qu'il porte aussi le plafond : **tension de vocabulaire
assumée**, un renommage invaliderait des versions publiées.

---

## 4. Décision C — l'écran §7 : lire et signer, jamais éditer

En Blade, sur le modèle des dix écrans de référentiel (précédent **K1** de P6.4d : compléter en
Blade sans investir dans le design, la migration du portail restant un module identifié).

**Ce que l'écran montre** :

- la version, son statut, et son **contenu rendu lisible** — les règles écrites en français à partir
  des libellés des trois listes blanches, jamais du JSON brut ;
- les **quatre validations** du §7 : qui a signé, quel rôle, quand ;
- et surtout, pour chacune, **si elle porte sur le contenu actuel** (`porte_sur_le_contenu_actuel`).

> **Une validation caduque doit avoir l'air caduque.** C'est l'information dont un signataire a le
> plus besoin : elle lui dit que le texte a bougé depuis qu'un confrère l'a relu. L'afficher
> discrètement reviendrait à laisser signer par-dessus une relecture périmée — précisément ce que
> l'anti-substitution de b-1 existe pour empêcher.

**Ce que l'écran ne fait pas** : éditer. Aucun bouton « modifier ». Un éditeur de règles complet en
Blade serait le plus gros investissement Blade du projet, dans un portail qu'ADR-011 condamne
(décision **Q2**, prise au G1 de P10b-3).

**Habilitations** : les quatre permissions §7 existantes, vérifiées **en service** et non par le
middleware spatie (piège P4, routes du portail en guard `web`). La publication §10 reste offerte
séparément à `protocole.publier`, et **le quatre-yeux refuse par son motif** (publieur ≠ rédacteur).

---

## 5. Surface technique prévue

- **Aucune migration** : deux faits et une action sont des entrées de registre ; le protocole
  `TRIAGE-ANTECEDENTS` est de la donnée.
- `RegistreFaitsProtocole` (+2), `RegistreActionsProtocole` (+1, non exclusive),
  `ControleQualiteProtocole` (l'action doit porter une valeur entière dans [0,100]).
- `TriageService` : la constante disparaît, le fait brut est assemblé, le fait plafonné est **lu
  après évaluation** — comme `plancher` en b-3-i.
- `ProtocoleSeeder` : `TRIAGE-ANTECEDENTS` en brouillon **non validé** (décision N3 : on ne fabrique
  jamais une validation clinique).
- Portail : contrôleur + vues `portail/protocoles` (liste, détail, signature, publication).

---

## 6. Vecteurs prévus

**G3** — une garantie, un vecteur ; les vecteurs de garde **dédoublés** (un par HTTP, un appelant le
service directement, parade de P6.6b) :

1. `score_antecedents_brut` non plafonné est bien le brut ;
2. sous le plafond → la somme passe telle quelle ;
3. au-dessus → plafonnée à la valeur **du protocole**, pas à 20 codé ;
4. **le plafond change avec la version publiée** : mêmes antécédents, deux versions, deux scores ;
5. refus bruyant **503** tant que `TRIAGE-ANTECEDENTS` n'est pas en vigueur ;
6. `nb_antecedents` exposé et exact ;
7. une action `DEFINIR_SCORE_ANTECEDENTS` sans valeur entière → refus de publication **par son
   motif** ;
8. l'écran §7 : sans permission → refus ; avec → la page ; **aucun formulaire d'édition dans le
   HTML rendu** ;
9. une validation caduque est **rendue comme caduque** (le vecteur qui protège la garantie de b-1) ;
10. publieur = rédacteur → refus **par son motif**.

**G2 live MySQL** : cinq mises en vigueur, plafond réel appliqué, changement de version changeant le
plafond, `UPDATE` direct sans effet, écran parcouru en HTTP réel avec CSRF, signature d'une des
quatre validations, anti-substitution déclenchée puis résolue, base **restaurée compte pour compte**.

**Mutations** : plafond, refus 503, contrôle de valeur, garde de permission, rendu de caducité —
chacune doit tuer ses vecteurs, harnais des six règles.

---

## 7. Limites qui seront annoncées

1. **`poids_severite` et `drapeau_rouge` restent sous deux signatures** (Y6). Porteur : un incrément
   de gouvernance du socle, à nommer.
2. **`impact_triage` reste déclaré par le patient** (Y1). Porteur : chemin soignant, ou gravité
   gouvernée au référentiel des maladies.
3. **Aucun écran d'authoring** : un brouillon se construit toujours en curl ou par seeder.
4. **Cinq mises en vigueur** avant qu'un triage fonctionne.
5. Le nom du contexte `triage_questionnaire` porte désormais autre chose qu'un questionnaire.
6. Contenu de démonstration, `niveau_preuve = 'D'`, aucun validateur forgé.
7. **§11 (< 100 ms)** toujours non déclaré atteint.
