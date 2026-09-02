# ADR-048 — Onboarding méthode 2 : l'établissement demande, la plateforme valide

- **Statut** : **Accepté — P11.1 VALIDÉ (G5, 2026-09-01).** G4 propriétaire OK.
- **Date** : 2026-08-30
- **Module** : P11.1 — deuxième incrément de CDC_11 (Applications métier)
- **Corpus** : CDC_11 §3, §3.1, §12 (étape 1) · CDC_02 §0.1 · ADR-026 (M1), ADR-028 (O1), ADR-047

---

## 1. Contexte — une affirmation fausse du corpus, ouverte depuis quatre modules

CDC_11 §3 décrit deux méthodes d'onboarding et conclut : « **Les deux méthodes sont
implémentées.** » C'était faux dans ce projet.

- **Méthode 1** — l'administrateur crée l'établissement, le gestionnaire naît sans mot de passe,
  un lien d'activation à usage unique lui est remis : **livrée depuis le Module 4**, vérifiée au G0.
- **Méthode 2** — « Clinique Saint Joseph souhaite rejoindre la plateforme » : **absente**.

Elle est ouverte depuis **P6.4a** sous le nom de limite **M1**, et a été reportée **deux fois**
(M1, puis O1 en P6.4c). ADR-029 le notait encore : « M1 reste OUVERTE — CDC_11 §3 affirme quelque
chose de faux dans ce projet ». C'est l'**étape 1** de l'ordre de construction que CDC_11 §12 fixe
lui-même, et rien de ce qui suit ne repose sur autre chose.

---

## 2. Décisions

### D1 — Une demande N'EST PAS un établissement

**Aucune ligne n'est écrite dans `structures_sanitaires` avant l'approbation.**

La tentation inverse existe et paraît économique : créer l'établissement « inactif », le publier
ensuite. Elle est mauvaise pour une raison concrète. `structures_sanitaires` est lue par
l'**annuaire public** de P3 (validé G5), par le **référentiel gouverné** de P6.4a et par
l'**orientation après triage** de P10a. Y déposer des candidats non vérifiés ferait dépendre
l'exactitude d'un référentiel national du soin qu'on met à filtrer `actif` **partout** — *un seul
oubli de filtre, et un établissement que personne n'a vérifié apparaît dans les résultats d'un
patient qui cherche où se faire soigner.*

Une candidature vit donc dans **sa propre table**, avec son propre cycle, et ne devient un
établissement qu'au moment où quelqu'un d'habilité l'approuve.

### D2 — Un seul chemin de création, extrait avant d'être partagé

Les deux méthodes **aboutissent au même acte** : un établissement naît, un compte gestionnaire
naît sans mot de passe, un lien d'activation à usage unique est remis. L'écrire deux fois serait
le laisser diverger — *et il diverge toujours du côté qu'on regarde le moins*, ici celui qu'un
administrateur n'emprunte que quelques fois par mois.

`OnboardingEtablissementService` est donc **extrait de `EtablissementController` avant d'être
partagé**, et les deux méthodes l'appellent. C'est le motif de `RendezVousValidationService` (P4),
extrait pour que le portail Blade et l'API Sanctum partagent le même workflow, et celui des gardes
d'image de P6.4d, non réécrites dans le formulaire.

**Défaut corrigé pendant l'écriture** : la première version du service ne rendait que le lien
d'activation, et l'appelant retrouvait l'établissement créé **en relisant le compte par son adresse
e-mail**. Ce détour aurait rattaché la demande au mauvais établissement le jour où deux comptes
auraient partagé une adresse, et redemandait à la base ce que l'appelé tenait déjà. Le service rend
désormais un `ResultatOnboarding` (structure, gestionnaire, lien).

### D3 — Ce que le formulaire public collecte, et pourquoi si peu

Le formulaire d'établissement de P6.4d porte une trentaine de champs. La candidature en demande
une poignée, parce que CDC_11 §3 le dit lui-même : après validation, « **c'est l'hôpital qui
renseigne** » les médecins, les services, les horaires, les tarifs.

Ce qu'il faut à ce stade, c'est **de quoi vérifier**. Et ce qui rend une demande vérifiable est le
**numéro d'autorisation** (CDC_11 §3.1, « informations légales »), que la plateforme confronte à
l'autorité de tutelle : il est **obligatoire**. *Demander trente champs à quelqu'un dont on ignore
encore s'il est légitime, c'est décourager le légitime sans gêner l'autre.*

Le demandeur est collecté **séparément** des coordonnées de l'établissement : le standard d'un
hôpital n'est pas la personne qui répond des informations déposées.

### D4 — L'agent vérifie, il ne ressaisit pas

À l'approbation, **les données de la candidature font foi**. L'agent complète ce que le formulaire
ne demandait pas — les coordonnées GPS, qui ne se déclarent pas de confiance puisqu'elles placent
l'établissement sur la carte que lira un patient (P6.4b).

**Un seul champ est rectifiable : la catégorie.** C'est celui qu'un demandeur se trompe le plus
souvent (« clinique » pour un cabinet), et le laisser faux fausserait durablement les statistiques
que §4.4 assigne au référentiel. Tout le reste — nom, numéro d'autorisation, adresse — vient de la
candidature, quoi que l'agent envoie. Un vecteur l'éprouve en envoyant un nom réécrit et un numéro
falsifié : ni l'un ni l'autre n'atteint la base.

### D5 — Aucune permission neuve

`etablissement.manage` existe déjà et couvre exactement cet acte : **approuver une candidature,
c'est créer un établissement**. En inventer une (`demande.traiter`) aurait donné deux clés pour une
seule porte, et laissé la question « qui a le droit de créer un établissement ? » avoir deux
réponses. C'est aussi la première application qui **se branche sur le registre de zones de
P11.0** : une ligne ajoutée, la garde et la navigation suivent sans qu'aucun code ne bouge.

La permission est vérifiée **dans le contrôleur**, pas par le middleware `permission:` de spatie :
ces routes sont authentifiées par Sanctum alors que les permissions vivent sur le guard `web`
(piège rencontré en P4 sur `rdv.validate`).

### D6 — Anti-abus sans dépendance

Un formulaire public qui écrit en base. Trois gardes, dont aucune ne rattrape les autres :

1. **limiteur strict** (5 dépôts par heure) sur la route ;
2. **une seule demande en attente par adresse du demandeur** — et le refus **rend la référence de
   la demande en cours**, parce que quelqu'un qui envoie deux fois a le plus souvent perdu sa
   référence ;
3. rien de ce qui est déposé n'atteint `structures_sanitaires` (D1).

**Pas de captcha** : ce serait une dépendance externe (§2.6) et un service tiers sur un formulaire
public. Cela n'empêche pas quelqu'un de déterminé d'utiliser dix adresses ; cela empêche le cas
réel, qui est le double envoi par impatience, et garde la file lisible pour ceux qui la traitent.
**Dit plutôt que déguisé en protection forte.**

### D7 — Le suivi rend l'état, jamais le dossier

La référence est **opaque et non séquentielle** (`DEM-` + 10 caractères aléatoires) : un compteur
laisserait deviner le volume de candidatures et énumérer celles des autres établissements
(précédent anti-énumération du NIS, P6.1, et des jetons de fiche de triage, P10a).

`GET /etablissements/demandes/{reference}` rend **uniquement** l'état de la décision et son motif
de rejet. Une référence peut être interceptée ; elle ne doit pas devenir un moyen de relire les
coordonnées d'un établissement candidat. Une référence inconnue rend **404, jamais 403** — un 403
confirmerait qu'une demande existe là.

### D8 — Le motif de rejet est obligatoire, et le moteur le sait aussi

Un rejet sans motif et une approbation ne désignant aucun établissement sont deux incohérences que
le code ne doit pas être seul à empêcher : elles produiraient des lignes qui ne veulent rien dire,
*et c'est en base qu'on les relira dans dix ans*.

`CHECK` impossible — `structure_id` subit une action référentielle (`SET NULL`), et MySQL 8.4
refuse alors une contrainte de vérification portant dessus : **erreur 3823**, le mur rencontré
depuis P6.3 (cousin du 1215 de P6.1). Déclencheurs dans **les deux dialectes**, avec
`COALESCE(cond, 0) = 0` et non `NOT(cond)` — une comparaison sur NULL ne déclencherait rien et la
violation passerait sans bruit. Faire vivre la garde dans un seul moteur la rendrait **vraie en
production et fausse en test**, divergence refusée depuis P6.8c (collation) et P6.8e (REGEXP).

### D9 — Une décision déjà prise ne se reprend pas : 409, jamais 403

L'agent **a le droit** de décider ; c'est CETTE demande qui n'est plus à décider. Confondre les
deux ferait croire à un défaut d'habilitation (précédent P7-C). Sous **verrou pessimiste** avec
garde d'état : deux agents qui cliquent en même temps créeraient sinon deux établissements pour une
seule candidature (motif de `ServiceGouvernanceProtocole::publier()` et du décaissement de P5.5b-2).

---

## 3. Preuves

**G3** — 18 vecteurs dédiés ; suite Laravel complète ; Pint propre sur les fichiers neufs ;
typecheck ×3 ; `next lint` sans avertissement ; build Next.

**Mutation : 10/10 conformes**, dont un témoin volontairement vert. Les neuf tueuses couvrent
chacune une décision : le dépôt public écrit dans l'annuaire · `statut` redevient assignable en
masse · le double dépôt n'est plus refusé · une demande traitée peut être re-décidée · la garde
d'habilitation tombe · l'adresse déjà prise n'est plus détectée avant création · l'agent peut
réécrire le nom de la candidature · le suivi public rend tout le dossier · le numéro d'autorisation
devient facultatif.

**Deux mutations ont été refusées par le harnais lui-même** (règle 3 : l'ancre ne doit pas être un
préfixe du remplacement). C'étaient des **définitions fautives, pas des défauts du code** ;
redéfinies, elles tuent toutes deux. *Un harnais qui accepte n'importe quelle définition de
mutation ne prouve rien de ce qu'il annonce.*

**G2 live** sur la base MySQL réelle : dépôt public sans jeton (`statut` et `reference` envoyés par
le client **ignorés**) · **12 structures avant, 12 après** le dépôt · second dépôt refusé en
rappelant la référence · suivi rendant **quatre champs et aucune fuite** · référence inconnue → 404
· `medecin` → 403 / `admin_ivoirsante` → 200 · approbation → **13 structures**, nom et numéro
d'autorisation **de la candidature** malgré la réécriture tentée, catégorie rectifiée, gestionnaire
**sans mot de passe** au bon rôle et à la bonne structure, demande liée et nommant son décideur ·
re-décision → **409** deux fois · les deux gardes du moteur refusent par `ERROR 1644`. Base
restaurée compte pour compte.

---

## 4. Limites

- **La vérification du numéro d'autorisation est un acte HUMAIN.** Aucune API d'autorité de tutelle
  n'existe, et prétendre l'automatiser donnerait à une machine l'apparence d'une habilitation
  qu'elle n'a pas. Le système enregistre **qui** a décidé et **quand** — le fait qu'un litige
  discutera.
- **Aucun courriel n'est envoyé.** Le lien d'activation s'affiche à l'écran de l'agent, qui le
  transmet ; le demandeur n'est pas notifié de la décision. Il n'y a pas de passerelle de courriel
  dans cet environnement, et le prétendre serait pire que de le dire. Le suivi par référence est ce
  qui tient lieu de notification.
- **L'anti-abus n'est pas une protection forte** (D6).
- **L'intégration par API (ADR-030) n'est pas dans cet incrément.** C'est un autre axe : la méthode
  2 fait entrer un établissement *dans* la plateforme, l'API fait circuler les données d'un
  établissement qui a *déjà* son logiciel. C'est l'étape 9 de CDC_11 §12, elle suppose une
  **troisième population d'authentification** que ce projet n'a pas, et un partenaire réel dont
  aucun n'a été consulté.
