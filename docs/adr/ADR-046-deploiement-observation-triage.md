# ADR-046 — Déploiement en observation, captation des faits manquants, comparaison et dérive (P10c-3-ii)

- **Statut** : **Accepté — P10c-3-ii VALIDÉ (G5, 2026-08-30), lots A et B.** G4 propriétaire OK.
- **Date** : 2026-08-30
- **Corpus** : CDC_05 §1.1 (Rule-005), §1.3 (l'IA complète, ne remplace pas), §1.7 et §10
  (dégradation gracieuse), §5.1/§5.2/§5.3/§5.5.4, §7.2 (pipeline), §8 (registre, statuts, canary/
  shadow, rollback), §9.2/§9.3/§9.6/§9.7 (traçabilité, explicabilité, validation clinique, limites)
  · CDC_08 §3 (l'IA au sixième et dernier rang) et §9 · CDC_04 §115/§123 · CDC_00 §4 · CDC_13 §12.
- **Lié à** : [[ADR-045]] (P10c-3-i, dont ceci est la suite directe), [[ADR-042]] (chaînes d'audit :
  origine déclarée, identifiants et non relations vivantes), [[ADR-041]] (§10, la seule chaîne
  portant du clinique jusqu'ici), [[ADR-040]] (l'orientation est une agrégation gouvernée, jamais
  une déduction), [[ADR-037]] (référentiel des maladies), [[ADR-035]] (vocabulaire des spécialités),
  [[ADR-020]] (appel sortant hors transaction), [[ADR-017]] (honnêteté sur ce qu'un modèle vaut).
  Plan : `docs/PLAN_G1_P10c3ii_Deploiement_Shadow.md` (F22→F39 ; lot A = F22-F28/F30-F36,
  lot B = F29/F37-F39).

---

## 1. Contexte — et le constat qui a déplacé le périmètre

Le propriétaire a demandé « qu'on allume le modèle pour de vrai ». Le G0 a montré que la question
n'était pas *comment* mais **pour qui**, et la réponse a redéfini l'incrément.

**Z1 — le modèle entraîné ne prédit pas une priorité.** CDC_05 §5.1 pt.2 décrit un XGBoost qui
estime la priorité. Celui de P10c-3-i apprend `adaptee`/`sur_triage`/`sous_triage` : **le jugement
qu'un soignant porte sur l'orientation**. Ce n'est pas un écart au corpus, c'est le seul label réel
que la boucle du §5.5.4 produit. Mais « brancher sur le flux vivant » ne peut donc pas signifier
« il influence le niveau » — ni techniquement, ni réglementairement (Z2).

**Z2 — le corpus l'interdit deux fois, indépendamment.** CDC_08 §3 classe le raisonnement IA
**sixième et dernier**, « jamais pour contredire un protocole officiel » ; CDC_05 §1.3 dit la même
chose.

**Z3 — montrer la prédiction au soignant contaminerait l'étiquette.**
`ServiceRetourTriage::enregistrer()` alimente le jeu d'apprentissage **dans la même transaction** :
le verdict du soignant *devient* l'étiquette. L'afficher à celui-là même dont le verdict entraînera
la génération suivante ferme la boucle — et **le défaut serait invisible dans les métriques, elles
s'amélioreraient**.

**Z4 — aucun destinataire dans le parcours citoyen.** Annoncer une probabilité de sous-triage à un
patient est non actionnable, alarmant, et proche de l'affirmation que §5.5.3 proscrit.

Les quatre convergent vers le déploiement que le corpus nomme lui-même au §8 : **shadow**. Décision
du propriétaire, avec une surface de lecture sur l'espace administrateur (lot B).

---

## 2. Décision — le mot compte autant que le mécanisme

### F22 — le mode s'appelle `observation`, jamais `hybride`

`hybride` était réservé dans l'ENUM depuis P10c-2-i pour le jour où l'IA participerait à la
décision. Ce jour n'est pas celui-ci. **Écrire `hybride` affirmerait une participation qui n'a pas
lieu**, et un mot faux dans un journal médico-légal est un défaut, pas une approximation.
`hybride` reste dans l'ENUM et reste inatteignable — même motif qu'`actif`/`archive` en P10c-3-i.

### F23 — le registre décide quel modèle répond, le service obéit

Laravel envoie le `mlflow_run_id` de la version `actif`. Le service charge **cet artefact**, et
refuse s'il ne l'a pas (`modele_absent_du_service`, motif distinct). Servir un autre fichier ferait
dire à la base « l'actif est X » pendant que la réponse viendrait de Y : **deux vérités sur ce qui a
produit une prédiction médicale**, ce que ce projet refuse depuis P6.6a. Le motif distinct rend
visible une panne d'exploitation réelle (artefacts sur disque et non dans MinIO, §10) au lieu de la
noyer dans le régime nominal.

### F24 — un seul actif par pays, rollback explicite, pas de quatre-yeux de plus

« Quel modèle a produit cette prédiction ? » doit avoir une réponse unique — même résolution que
l'ambiguïté du §6.1 tranchée en P10b-1. Activer archive le précédent dans la même transaction ; un
`archive` peut redevenir `actif` (§8, rollback), donc **`archive` n'est pas terminal et c'est dit**.
Aucun quatre-yeux supplémentaire : la validation clinique du §9.6 a eu lieu au passage
`candidat → valide`, par quelqu'un qui n'est pas l'entraîneur ; en exiger un troisième serait un
garde-fou **plus strict que sa propre règle**, refusé en P6.8c.

### F25 — le vecteur est partagé par la STRUCTURE, pas par la discipline

Base Pydantic `TraitsCliniques`, dont héritent la ligne d'entraînement et la requête de score. Trois
conséquences qui ne dépendent plus de personne : les deux côtés voient les mêmes champs ;
`niveau_protocole` et `reference`, déclarés sur la sous-classe de score, sont **inaccessibles** à
`vecteur_features` (D3 de P10c-2-i cesse d'être une promesse de commentaire) ; les cibles
d'entraînement le sont tout autant, donc on ne peut pas prédire le diagnostic à partir du diagnostic.

*Ce n'était pas théorique* : le G0 a trouvé que la discipline avait déjà lâché — `score_antecedents`,
envoyé par Laravel depuis F14, n'était pas déclaré côté service et Pydantic l'écartait **en
silence** (Z8). Sans effet tant que tout répondait 503 ; le jour où le modèle tourne, la feature
aurait valu `NaN` à l'inférence alors qu'elle existait à l'entraînement.

### F26 — Laravel envoie la tranche, le service ne reçoit plus l'âge exact

Une seule définition des bornes (la config qu'utilise déjà l'export) ; le service ne garde que
l'**ordre** des étiquettes et refuse bruyamment celles qu'il ignore. Gain non cherché : l'âge exact
cesse de sortir du backend (§9.4).

### F27 — explication obligatoire, jamais vide

Classe prédite, trois probabilités, facteurs SHAP **de cette prédiction**, confiance et limites.
Seuils de confiance en **données**, côté service uniquement — deux définitions du même seuil
pourraient diverger, et l'écran afficherait une confiance que le service n'a pas voulu dire. Une
réponse incomplète est **dégradée avec un motif qui le nomme**, jamais recomplétée : une explication
inventée côté Laravel serait pire que pas d'explication, elle aurait l'air d'en être une.

### F28 — `predictions_ia` devient une chaîne d'audit

Sa propre migration l'annonçait « pour le jour où un modèle réel écrira la première explication ».
Une explication SHAP nomme les valeurs cliniques qui l'ont produite. Les **7 entrées antérieures**
restent à `chaine = NULL` : leur inventer une empreinte serait un mensonge d'archive, et les inclure
ferait crier une rupture permanente sur des lignes intactes. La déclaration d'origine **porte leur
nombre**, pour que leur existence soit écrite plutôt que déduite d'un trou.

### F30 — aucun re-scoring rétroactif, et c'est la seule garantie disponible

La prédiction n'a lieu qu'au moment du triage. C'est ce qui garantit qu'un modèle n'est jamais
évalué sur des triages de son propre entraînement : **l'export ayant retiré `triage_id` (F20), on ne
pourrait pas le vérifier après coup**. L'anonymisation qu'on a voulue nous prive du contrôle, la
chronologie le fait à notre place, et un rattrapage casserait cela en silence.

---

## 3. La captation des trois faits — ce qui est possible, et ce qui ne l'est pas

### F32 — ils sont CAPTÉS maintenant, ils ne peuvent pas être PRÉDITS maintenant

Le §5.5.4 pt.4 demande d'enregistrer « le diagnostic final posé par le médecin » ; ce projet n'avait
**aucune entité consultation/diagnostic** (dette nommée en P10c-2-i). Ce lot en livre le premier
fragment.

Ce qu'il ne livre pas, et pourquoi : **un modèle n'apprend que ce qu'on lui a montré**, et il y avait
zéro diagnostic enregistré. Ce n'est donc pas un refus, c'est un **ordre** : la captation est le
verrou, et une fois posée la prédiction devient *de la donnée plus une tête d'entraînement*, jamais
un changement d'architecture.

**Contrainte qui survivra au volume** : même entraînée, une tête « maladie » ne remontera pas dans la
fiche de triage — CDC_00 §4 est un interdit absolu, et l'exemple de réponse du §5.2 ne comporte
lui-même aucune maladie. Le diagnostic sert la base d'apprentissage et le futur §6.8, pas le triage.

### Pourquoi une table neuve, et non trois colonnes sur `protocole_applications`

La raison est **mécanique avant d'être conceptuelle** : `JournalApplicationProtocole::charge()` fige
une liste de clés, et l'empreinte de chaque entrée est calculée dessus. Y ajouter les trois faits
**recalculerait l'empreinte des 37 entrées déjà écrites** — la chaîne entière crierait à l'altération
sans que rien n'ait bougé. P10c-2-i avait refusé une colonne « nature » pour ce motif exact.

La raison conceptuelle la rejoint : le §10 journalise l'évaluation d'un protocole et la décision du
soignant à son sujet ; un diagnostic est un fait d'une autre nature. Une chaîne séparée est
d'ailleurs la règle du projet depuis P10b-1.

### F33 — `niveau_reel` s'ajoute au verdict, et un contrôle empêche les deux vérités

Comparé au niveau du protocole, `niveau_reel` **implique** `decision_finale`. Deux façons de dire le
même fait peuvent se contredire. On ne départage pas : **lui seul sait laquelle il pensait**. Le
refus nomme la contradiction ; écraser l'une par l'autre inscrirait dans une chaîne immuable un
verdict que personne n'a formulé. Facultatif, pour ne pas changer le contrat d'un module G5.

### F34 — le diagnostic est un lien, jamais du texte libre

`maladie_id` vers le référentiel de P6.8c, `specialite_id` vers celui de P6.8a. Un texte libre
rendrait insoluble « combien de paludismes parmi les triages sous-évalués ? » et ferait d'une faute
de frappe une catégorie. **Le serveur ne devine jamais** un diagnostic depuis les symptômes — ce
serait l'affirmation clinique posée par une machine que P6.8c a déjà refusée. Codes et libellés
**figés** : un fait historique, pas un état courant (à l'inverse d'ADR-038, où la réponse était
l'opposée pour une raison opposée).

### F35 — le diagnostic dégrade l'anonymat, et le chiffre doit le dire

« Femme, 25-44 ans, août 2026 » n'identifie personne ; ajoutez une maladie rare et ce peut être une
seule personne. La clé de `k_estime` s'étend donc au diagnostic. **L'ignorer ne rendrait pas
l'export plus sûr : ça rendrait le chiffre faux** — il annoncerait un k confortable en laissant hors
de son calcul la colonne la plus discriminante, et un indicateur qui rassure à tort est pire qu'un
indicateur absent. Non bloquant (motif P6.7a inchangé).

---

## 4. Ce que le G2 live a établi (2026-08-30)

Base MySQL réelle, sauvegardée puis restaurée. Migration appliquée : ENUM à trois valeurs, quatre
déclencheurs append-only, **7 entrées antérieures à `chaine = NULL`** et une déclaration d'origine
portant ce chiffre. Les quatre tentatives `UPDATE`/`DELETE` directes refusées par le moteur
(`1644`). Altération volontaire → chaîne rompue, puis rétablie.

36 retours réels via les services réels → export de 36 lignes sans `triage_id`. Entraînement réel
contre un `triage-service` réellement démarré → run MLflow `6c1b3471…`. Quatre-yeux prouvé **par son
motif** ; second agent valide ; mise en service avec `activee_par`, **un seul actif**.

**Triage citoyen bout-en-bout** (`POST /api/v1/triage/analyser` réel, IA allumée) → prédiction
`observation`, SHAP réel, confiance `elevee`, 748 ms, `triages.modele_version` renseignée (§115) —
et **frontière vérifiée** : ni `sous_triage`, ni `observation`, ni `shap`, ni l'identifiant du modèle
dans la réponse rendue au patient.

Captation : diagnostic et spécialité **figés** (corriger le référentiel ne les réécrit pas) ;
contradiction verdict/niveau refusée **en nommant les deux moitiés**, rien écrit ; diagnostic inconnu
refusé. `k_estime` **tombe de 2 à 1** dès qu'une seule ligne porte un diagnostic.

Guide `GUIDE_TEST_TRIAGE.md` **partie 9**.

### Quatre défauts réels, et une racine commune

**Les trois premiers viennent d'une garantie qui ne vaut que d'un côté** : SQLite laissait passer ce
que MySQL refuse ou modifie. Aucun des 1390 tests verts ne pouvait les voir.

1. **Dépassement de colonne.** `audit_chaines.motif` est un `string(300)` ; ma déclaration dépassait.
   SQLite n'impose pas la longueur d'un `VARCHAR`. La migration a échoué au premier contact avec la
   base réelle, **après avoir posé une partie du schéma** — le DDL MySQL n'est pas transactionnel.
2. **Arrondi.** La colonne est un `decimal(5,4)` ; le service rend `0.752762`, MySQL stocke `0.7528`.
   La valeur hachée n'était pas la valeur stockée.
3. **Typage, et le plus sournois.** SHAP rend `0.0` pour une feature sans influence ; **MySQL le
   range en `0` et le relit en entier**. Les features qui ne pesaient rien suffisaient à casser
   l'empreinte.

Les deux derniers produisaient le pire défaut possible pour un journal médico-légal : **une fausse
accusation** — la chaîne se déclarait rompue sur des entrées que personne n'avait touchées. Ce n'est
plus la leçon d'`entierOuNull` de P10b-2, où *le pilote retypait* : ici **c'est la base qui modifie
la valeur**. Les trois parades normalisent en PHP, donc identiquement sur les deux moteurs, et
chacune a son vecteur.

4. **Un refus de contrat déguisé en panne.** Le service **nomme** la cause (`bande_age_inconnue` :
   les bornes ont divergé), et Laravel l'écrasait en `reponse_inattendue_422` en comptant l'appel
   comme un échec. Une divergence de **configuration** se serait donc présentée comme un service en
   panne, puis aurait ouvert le disjoncteur — précisément ce que le motif distinct de F23 existe
   pour empêcher. Un refus de contrat garde son motif et n'ouvre pas le circuit ; un 500 sans motif,
   si.

### Deux corrections de méthode

**Un vecteur du G2 ne prouvait rien** : j'ai « altéré » `confiance` en y remettant sa valeur
courante ; c'est la restauration qui a réellement cassé la chaîne. Famille du « le vecteur prouve
autre chose », ici en direct plutôt qu'en test.

**Une affirmation fausse, corrigée** : j'avais annoncé un `k_estime` « abaissé par le diagnostic »
alors que la table `maladies` était vide (constat Z9) et qu'aucun diagnostic n'avait été capté. Le
chiffre venait de la seule répartition âge/sexe. Après le seeder **et** le backfill des codes —
l'étape de déploiement que Z9 annonçait —, une ligne diagnostiquée fait bien tomber `k` de 2 à 1.

### Un défaut de couplage trouvé en chemin, hors périmètre

La migration d'ADR-042 **itérait sur `ChaineAudit::JOURNAUX`**, un registre vivant. Y inscrire les
deux journaux neufs a fait qu'une migration du 21 août s'est mise à vouloir altérer des tables **qui
n'existaient pas encore à sa date** : `migrate:fresh` cassait net. Même famille que « ajouter une clé
à `charge()` recalcule les vieilles empreintes » : **une migration est un fait historique, elle ne
doit pas changer de sens quand le code évolue.** Sa liste est désormais figée.

---

## 4bis. Le lot B — voir sans fausser, et surveiller sans décider

### F29 — la comparaison vit sur la surface administrateur, et nulle part ailleurs

C'est le seul endroit où une prédiction se lit. La montrer au soignant **avant** son verdict
fermerait la boucle de Z3 ; la montrer au patient tomberait sous Z4. L'écran est donc réservé aux
détenteurs de `ia_triage.valider` — contrôleurs plateforme indépendants, jamais l'établissement dont
les triages sont examinés (ADR-017 §7).

Il rend la **matrice de confusion en production** (§8) et, seul sur sa ligne, le **rappel sur
`sous_triage`** confronté à celui du jeu de test : un sur-triage coûte une place aux urgences, un
sous-triage peut coûter la vie, et noyer ce rappel parmi les autres métriques laisserait croire
qu'ils se valent.

**Deux verdicts sur un même triage comptent deux fois** — cohérence stricte avec F13 : écarter l'un
reviendrait à choisir à la place du médecin qui l'a validé, et le taire fausserait la mesure **dans
le sens le plus flatteur**.

**`null` n'est pas `0`.** Un rappel affiché « 0 % » alors qu'aucun sous-triage n'est encore survenu
serait une accusation gratuite — même prudence que le `zero_division=0` de l'entraînement.

**Aucun filtre n'exclut les triages d'apprentissage, et il n'en faut pas** : l'export ayant retiré
`triage_id` (F20), on ne *pourrait* pas le faire. C'est F30 qui tient la garantie — une prédiction
n'a lieu qu'au moment du triage, donc un modèle activé après son export ne peut prédire que des
triages postérieurs. Un re-scoring rétroactif casserait cela **en silence**, et c'est écrit dans le
service parce que c'est là que ça se verrait.

### F37 — la distribution de production est RE-DÉRIVÉE, jamais dupliquée

Mesurer une dérive suppose de connaître les entrées d'aujourd'hui. Le réflexe était de recopier le
vecteur de features à côté de chaque prédiction : plus rapide, et **une seconde copie de données
cliniques** — ce que le §9.2 interdit en toutes lettres (« données d'entrée référencées, non
dupliquées en clair »).

Elle est donc recalculée depuis les tables du triage, ce qui impose que « traduire un triage en
ligne plate » existe à **un seul endroit** (`TraitsDepuisTriage`, désormais partagé avec
`ServiceRetourTriage`). Sans cela la dérive mesurerait une population légèrement différente de celle
qui a nourri l'apprentissage, et **une part de l'écart constaté serait la nôtre** — ce qu'un vecteur
mal outillé a d'ailleurs démontré en creux au premier essai.

La référence, elle, vient de l'export sur lequel le modèle actif a été entraîné : prendre « les
triages du mois dernier » comparerait deux populations dont aucune n'est celle de l'apprentissage.

### F38 — deux natures de dérive, jamais fondues

`entree` (la population a changé, PSI par feature) et `performance` (le rappel sur `sous_triage` a
chuté). Les fondre en un score global dirait « ça va » d'un modèle dont la population est stable et
la performance effondrée. **Pas de moyenne**, donc, et chaque alerte porte sa nature.

Deux choix de calcul méritent d'être dits. Le **lissage** n'est pas cosmétique : PSI divise et prend
un logarithme, donc une catégorie absente donnerait l'infini, et *une seule catégorie jamais
rencontrée noierait la vraie dérive sous un chiffre ininterprétable*. Et la **chute de rappel se lit
dans un seul sens** : un rappel qui monte n'est pas une dérive à signaler, le signaler
symétriquement noierait le seul cas dangereux sous des alertes sans conséquence.

Les seuils (0,1 / 0,25 pour PSI, 0,15 pour la chute) sont les repères usuels de la littérature, pas
une vérité mesurée sur cette population — d'où leur place en configuration.

### F39 — détection seule, et la table est construite pour que ça reste vrai

`alertes_drift` ne porte ni `action`, ni `modele_desactive`, ni `traitee`. Ce n'est pas un oubli :
retirer un modèle du service sur un indice statistique serait une décision d'exploitation prise par
une machine — la ligne tenue depuis ADR-017 pour la fraude, et pour la même raison. L'alerte
prévient un contrôleur plateforme ; un humain décide, avec le rollback de F24.

**Le silence est une réponse** : une journée sans dérive n'écrit rien. Remplir la table de « stable »
la rendrait illisible, et un rapport qu'on ne lit plus ne prévient plus.

---

## 4ter. Ce que le G2 live du lot B a établi (2026-08-30)

Base MySQL réelle, sauvegardée puis restaurée. Migration appliquée (`alertes_drift`, clé unique à
quatre colonnes). Trente triages réels d'une population **âgée** confrontés à un export de référence
d'adultes : **9 alertes** — huit dérives d'entrée dont `bande_age` à 17,8 (populations disjointes),
plus la chute de rappel (**0,80 au test contre 0,00 en production**). Comparaison réelle : 30
prédictions, 30 couples, concordance 50 %, matrice complète, latence 30 ms. **Le modèle reste
`actif`** après le rapport. Lignes idempotentes au rejeu.

*Honnêteté sur la lecture* : la fenêtre d'observation mêlait ces trente triages aux onze triages de
développement préexistants, qui n'ont ni constantes ni réponses. Les indices sont exacts, leur
interprétation ne vaut que pour ce jeu — **ce n'est pas une cohorte propre**, et le dire importe
plus que le chiffre.

### Trois défauts de plus, dont deux invisibles aux vecteurs

**L'idempotence des LIGNES ne fonctionnait pas.** `date_rapport` est castée en `date` : Eloquent
range `2026-08-29 00:00:00`, une clause `where('date_rapport', '2026-08-29')` compare la chaîne
brute. Les deux ne se rencontrent jamais, `updateOrCreate` créait une seconde ligne que la contrainte
refusait ensuite. **Troisième occurrence de la même famille dans cet incrément** : *la valeur n'est
pas stockée sous la forme où on l'interroge*.

**L'idempotence des NOTIFICATIONS n'existait pas du tout**, et aucun vecteur ne la couvrait. Le G2 a
produit trois messages identiques pour la même journée, parce que le rapport avait tourné trois
fois. Un contrôleur qui reçoit le même avertissement à chaque passage **cesse de les lire** — c'est
ainsi qu'une alerte devient invisible. Précédents : le drapeau `notifiee` de B1, le rejeu muet de
P7-D1.

**La production n'est pas homogène.** Ce service est la première pièce de l'incrément à parcourir
des données historiques *arbitraires* plutôt que celles qu'elle a écrites : la base en portait une
dont `symptomes_json` était **doublement encodé**, et une seule ligne malformée tuait le rapport
quotidien entier. Rangée en catégorie `illisible` — jamais en « zéro symptôme », qui aurait
**fabriqué une donnée** et biaisé la distribution. Et si de telles lignes se multipliaient, PSI le
signalerait : une dégradation de la qualité des saisies **est** un changement de la population que
le modèle rencontre.

### Un piège de méthode, attrapé par la mutation

Le vecteur écrit pour la garantie neuve **passait en ne mesurant rien** :
`Notification::sentNotifications()` rend un tableau imbriqué à quatre niveaux, et `count()` dessus
compte les *classes de destinataires* — toujours 1, avant comme après. Seule la mutation « on
re-prévient à chaque passage » l'a révélé, **en survivant là où elle aurait dû tuer**. C'est
exactement ce à quoi sert la campagne : un test vert ne prouve rien tant qu'on n'a pas vérifié qu'il
sait échouer.

---

## 5. Conséquences / limites

- **Aucune prédiction de maladie, de spécialité ou de priorité** : la captation est posée, le volume
  n'existe pas. Z1 est **outillé, pas comblé**.
- **Aucune IA sur la fiche du patient**, et ce n'est pas de la prudence : le modèle n'a rien à y
  écrire. Même avec du volume, la maladie restera hors du triage (CDC_00 §4).
- **La comparaison ne dit pas si le triage est bon** : elle mesure l'accord entre un modèle et des
  soignants, sur un échantillon qui n'a pas été constitué pour être représentatif. Le bandeau de
  l'écran le dit avant les chiffres.
- **PSI est un indice, pas une preuve** : il dit qu'une distribution a bougé, jamais pourquoi.
- **Équité et biais (§8) toujours non traités**, ni canary ni déploiement progressif par fraction de
  trafic : le shadow est total.
- **La tâche quotidienne est « prête à activer »** (ADR-014) : aucun `schedule:run` n'est branché sur
  un cron dans cet environnement.
- **Deux étapes de déploiement** : activer une version, et rejouer `MaladieSeeder` **puis**
  `masante:maladies:backfill` (le seeder seul laisse les codes nuls — constat Z9, vérifié).
- **Artefacts sur le disque du service**, pas dans MinIO (§10) : en multi-instance un artefact peut
  manquer — c'est ce que `modele_absent_du_service` rend visible plutôt que silencieux.
- **Le modèle reste entraîné sur un volume faible** : réel dans son mécanisme, pas validé
  statistiquement.
- **La chaîne ne témoigne pas du passé** : les 7 prédictions antérieures ne sont pas scellées
  rétroactivement, et la déclaration d'origine le dit.
- **`retours_cliniques_triage` ne remplace pas une entité clinique** : c'est un fragment minimal,
  attaché à un retour de triage, pas un dossier de consultation.
- **§5.5.1 et §5.5.2** toujours sans porteur numéroté.
- **Un blob JSON haché reste fragile au typage** : la parade est locale à ce journal. Les autres
  chaînes du projet n'ont pas de flottants dans leur charge — vérifié, mais **non garanti par un
  mécanisme**, et c'est dit.
