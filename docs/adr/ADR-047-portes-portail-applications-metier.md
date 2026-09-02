# ADR-047 — Les portes du portail : rôles réconciliés, permissions exposées, zones gardées

- **Statut** : **Accepté — P11.0 VALIDÉ (G5, 2026-09-01).** G4 propriétaire OK.
- **Date** : 2026-08-30
- **Module** : P11.0 — premier incrément de CDC_11 (Applications métier)
- **Corpus** : CDC_11 §3, §5→§8, §12 · CDC_10 §3.6 · CDC_02 §0.1, §2.2 · ADR-011, ADR-017 §7, ADR-020 §B2

---

## 1. Contexte — ce que le G0 a mesuré

CDC_11 décrit onze applications professionnelles (Médecin, Infirmier, Pharmacien, Laboratoire,
Radiologie, Ambulance, Administration d'établissement, Ministère, Assurance, Statistiques,
Console IA). Avant d'en écrire une seule, le G0 a compté ce qui existait :

| | Portail Blade (`services/api`) | Portail Next (`apps/web`) |
|---|---|---|
| Routes | **145** | 8 pages |
| Contrôleurs / zones | **30** | 4 |
| Vues | **77** | — |
| Permissions gardant les routes | **29** | **0** |
| Authentification | Session Laravel, guard `web` | Sanctum Bearer en cookie httpOnly |

Cinq constats en sont sortis, dont trois n'étaient pas prévus.

### G0-1 — `/v1/auth/me` ne renvoyait que les rôles

```php
'roles' => $user->getRoleNames(),   // et c'est tout
```

Or ce projet garde ses routes sur des **permissions**, et quatorze d'entre elles n'appartiennent
délibérément à aucun rôle : elles s'accordent nominativement. Le portail était donc
**structurellement incapable de reproduire les gardes du backend**. Il ne pouvait qu'afficher un
menu au jugé et laisser l'utilisateur découvrir ses refus par un 403.

### G0-2 — Une seule porte, et grossière

```ts
const ROLES_PATIENT: Role[] = ['patient'];
estProfessionnel = (u) => u.roles.some((r) => !ROLES_PATIENT.includes(r));
```

« Tout ce qui n'est pas patient entre, et une fois entré atteint tout. » Tenable avec trois
modules. **Défaut de sécurité avec onze applications** : un infirmier atteindrait le portail du
Ministère. Il n'existait aucun RBAC par zone.

### G0-3 — Trois paires de rôles doublons, pas une

Quatorze rôles vivaient en base. L'enum `Role` de `@masante/shared` et le seeder Laravel avaient
été écrits séparément, à des modules d'écart, et avaient dérivé :

| Nom dormant | Permissions | Nom vivant | Permissions |
|---|---|---|---|
| `secretaire` | 0 | `agent_garde` | 5 |
| `admin_etablissement` | 0 | `gestionnaire_etablissement` | 8 |
| `super_admin` | 0 | `admin_ivoirsante` | 40 |

**La troisième paire n'avait pas été vue au G1** : `agent_garde` *est* le personnel d'accueil — le
commentaire de `Portail\AuthController` le disait lui-même, « sous l'identité d'un agent
d'accueil ». Et symétriquement, **les trois rôles qui font réellement tourner le portail ne
figuraient nulle part côté front**. Une source unique qui ignore les seuls acteurs en service
n'est pas une source unique.

Sept autres rôles portaient **zéro permission** depuis P1 — huit modules durant, un infirmier, un
pharmacien ou un laborantin n'avait aucune porte, alors que `RoleSeeder` promettait en commentaire
qu'ils « recevront leurs permissions lors de la construction des modules web ».

### G0-4 — Shadcn UI n'est pas installé

ADR-011 le nomme dans la pile CDC_02. Le portail utilise trois composants écrits à la main.

### G0-5 — Migrer une zone Blade coûte le double

Les contrôleurs Blade rendent des vues **directement depuis Eloquent** ; il n'existe aucun
endpoint JSON pour eux. Les 172 routes d'`api.php` sont l'API citoyenne. Chaque zone migrée exige
donc **son API en plus de son écran**.

---

## 2. Décision

### D1 — Onze rôles, un nom par métier, et on adopte le nom qui porte déjà quelque chose

Règle de départage : **on adopte, on ne réinvente pas** (précédent P6.8a, où les codes de
spécialité ont été adoptés plutôt qu'inventés pour ne pas casser le contrat de P3).

- `secretaire` ⟶ **`personnel_accueil`**, qui est `agent_garde` renommé. Le terme est celui du
  propriétaire (décision B1) : « agent de garde » évoque une astreinte, alors que ce rôle vérifie
  une fiche de rendez-vous au guichet.
- `admin_etablissement` ⟶ **`gestionnaire_etablissement`**.
- `super_admin` ⟶ **`admin_ivoirsante`**. Celui-là avait un consommateur réel — la garde du module
  fraude — qui l'avait choisi **faute de mieux** : ADR-020 §B2 notait lui-même qu'`admin_finance`
  était « absent de l'enum `Role` ». Ce n'était pas une décision d'architecture mais un pis-aller.
  Le contrôleur indépendant qu'exige ADR-017 §7 reste `ministere`.

**Les comptes sont transférés avant que le rôle retiré ne soit supprimé.** Supprimer d'abord
ferait disparaître l'attribution par cascade et « nettoierait » silencieusement un utilisateur de
son rôle — la panne muette que ce projet refuse partout ailleurs.

### D2 — `/me` expose les permissions, sans déplacer l'autorité

La décision reste entièrement au backend, qui revérifie à chaque requête. Le front s'en sert
uniquement pour **n'afficher que ce qui est atteignable**. C'est la défense en profondeur du
module fraude (ADR-020 §B2), où Next vérifie le rôle avant de signer un principal que le paiement
revérifie de son côté.

Elles sont renvoyées **à plat** (`getAllPermissions`), rôles et attributions nominatives
confondus : la distinction intéresse celui qui administre les comptes, pas celui qui affiche un
menu.

### D3 — Un registre de zones, source unique du « qui voit quoi »

Une zone déclare **la permission qui l'ouvre**, une seule fois. Cette déclaration sert deux choses
qui ne peuvent alors plus diverger : la **garde serveur** de la zone et la **navigation** qui la
propose. C'est la propriété qui compte — *un utilisateur ne voit que ce qu'il peut atteindre*, non
parce qu'on a pensé à masquer le lien, mais parce que le lien et la garde lisent la même ligne.

Quand une zone déclare **et** une permission **et** des rôles, les deux conditions se **cumulent**.
Une zone qui exigerait « la permission OU le rôle » laisserait le rôle contourner la permission, ce
qui viderait de son sens le fait que ce projet garde sur des permissions. Le seul cas à rôle
aujourd'hui est celui des alertes de fraude, dont ADR-017 §7 exige un contrôleur **indépendant** de
la structure signalée : aucune permission n'exprime cette indépendance, c'est une propriété du rôle.

**Le registre est court, et c'est voulu** : il ne liste que les zones qui existent. Y inscrire les
dix applications de CDC_11 avant de les avoir écrites afficherait un menu vers des pages absentes —
le « socle à vide » refusé en P6.3-D3.

### D4 — Les sept rôles muets reçoivent ce qui EXISTE, et rien de plus

**On n'invente aucune permission pour un écran qui n'existe pas.** Créer `hospitalisation.manage`
aujourd'hui donnerait une clé pour une porte non percée.

| Rôle | Reçoit | Ne reçoit pas, et pourquoi |
|---|---|---|
| `infirmier` | `qr.scan`, `triage.view`, `dossier.ecrire` | `document.signer` — la « signature infirmier » du §6 n'a aucun type de document dans le registre de P6.5b |
| `pharmacien` | `medicament.manage`, `qr.scan` | `medicament.referentiel` — un fabricant serait juge et partie sur son produit (P6.6a) |
| `laborantin` | `qr.scan`, `triage.view`, `dossier.ecrire` | `analyse.referentiel` — un laboratoire ne fixe pas les valeurs contre lesquelles ses résultats seront lus (P6.7a) |
| `radiologue` | `qr.scan`, `triage.view` | `dossier.ecrire` — il n'existe ni imagerie, ni DICOM, ni compte rendu radiologique ; aucune des quatre sections ouvertes au soignant n'en est un |
| `ministere` | `stats.global`, `sante_publique.manage` | `stats.etablissement` — bornée à l'établissement du compte, ce qui n'a aucun sens pour qui les regarde tous |
| `assurance` | **rien** | voir D5 |
| `personnel_accueil` | inchangé (les 5 de l'ex-`agent_garde`) | — |

`medecin` gagne **`rdv.validate`** : CDC_11 §9.1 est littéral, « le médecin fait la validation
finale », et jusqu'ici l'accueil pouvait confirmer un rendez-vous et le praticien concerné, non.
C'est une dette annoncée par P6.5a avec son porteur ; P11.0 est ce porteur.

### D5 — L'assurance ne reçoit aucune permission, et ce vide est la réponse honnête

Le §8.6 lui demande de vérifier une couverture et de valider une prise en charge. Aucune de ces
capacités n'existe : la vérification auprès d'un organisme est la limite ouverte de P6.8d
(« l'étape 2 du §8.1 — l'API CNAM — n'existe pas »). Lui fabriquer une permission lui donnerait une
clé sans serrure et ferait **croire** que son portail existe. La ligne est écrite, vide et
commentée, pour que l'absence se voie au lieu de se deviner ; et son tableau de bord le lui dit en
toutes lettres plutôt que d'afficher un écran vide qui ressemblerait à une panne.

### D6 — Une garde anti-divergence, parce qu'on vient de payer le prix de son absence

Les permissions vivent désormais des deux côtés. Le défaut de G0-3 est donc reproductible — en
pire, puisqu'une permission absente du front fait disparaître une entrée de menu **sans erreur**.
`tests/Unit/PermissionsSourceUniqueTest.php` **casse le build** à la première divergence, dans un
sens comme dans l'autre. C'est le motif de `NisVecteursPartagesTest` (P6.1).

**Et ce test se teste lui-même** : il lit un fichier TypeScript par expression régulière, donc il
pourrait échouer à trouver quoi que ce soit et « passer » en comparant deux listes vides. Il vérifie
donc d'abord qu'il a extrait un nombre plausible d'entrées — le « contrôle toujours vert » refusé
en P5.3b-4.

---

## 3. Défaut réel corrigé, trouvé en renommant

`ScanController::structure()` exigeait le rôle `agent_garde` **par son nom**, en plus de la
permission `qr.scan` que la route impose déjà :

```php
$user->structure_id === null || ! $user->hasRole('agent_garde')
```

Conséquence : **depuis P6.5a, le rôle `medecin` portait `qr.scan` et ne pouvait pas scanner.** Il
voyait l'entrée du menu et recevait un 403 disant que le scan « est réservé aux agents de garde ».
La décision propriétaire « le rôle `medecin` devient utilisable » était restée à moitié inopérante,
sans que rien ne le signale. Le même défaut aurait frappé les cinq rôles soignants dotés ici.

La garde vérifie désormais ce que la route ne peut pas vérifier — le rattachement à un
établissement, sans lequel on ne saurait pas au nom de qui la session de dossier est ouverte.

---

## 4. Ce qui est hors périmètre, dit plutôt que déguisé

- **Aucune zone Blade n'est migrée.** Le portail Blade fonctionne à l'identique. La migration est
  le lot suivant, zone par zone, chacune avec son API (G0-5).
- **Aucune application métier n'est écrite.** Cet incrément livre la porte, la serrure, les clés et
  le couloir — pas les pièces.
- **Shadcn UI n'est pas installé.** Le G1 l'annonçait ; à l'écriture, installer cinq dépendances
  pour un menu et trois composants existants aurait été ajouter des dépendances **avant** l'usage,
  ce que §2.6 décourage et ce que le `CLAUDE.md` d'`apps/web` formule déjà comme « à ajouter au
  besoin ». Le besoin arrive avec le premier écran d'application métier. **Écart au G1 assumé et
  signalé, non silencieux.**
- **`laravel/reverb` n'est pas installé non plus**, pour la même raison : il servira au « en train
  d'écrire » du lot RDV, et l'installer ici le laisserait dormir.

---

## 5. Preuves

**G3** — Laravel : 16 vecteurs dédiés (`PortesPortailTest` 14, `PermissionsSourceUniqueTest` 2) ;
suite complète verte. Pint propre sur les fichiers neufs — les six fichiers préexistants touchés
échouaient **déjà** Pint avant modification (style d'alignement délibéré du dépôt, baseline
vérifiée contre `HEAD`), ils ne sont pas reformatés. Typecheck ×3, `next lint` sans avertissement,
build Next.

**Mutation : 8/8 conformes**, dont un témoin volontairement vert — *un harnais qui ne prévoit que
des mutations tueuses ne se teste jamais lui-même*. Les sept tueuses couvrent chacune une décision :
`/me` cesse d'exposer les permissions · n'expose que celles du rôle · la porte se referme sur les
quatre rôles d'avant · le scan redevient gardé par le nom du rôle · le médecin reperd
`rdv.validate` · l'assurance reçoit une permission inventée · la migration supprime avant de
transférer. Arbre restauré et vérifié octet pour octet à chaque tour.

**Vecteur hérité réécrit, pas corrigé pour passer** :
`PortailProfessionnelTest::test_le_role_medecin_ecrit_au_carnet_mais_ne_tient_pas_l_accueil`
affirmait `assertFalse($role->hasPermissionTo('rdv.validate'))`. Cette garantie change ici ; le
vecteur dit désormais la garantie neuve et cite la dette qu'il solde (précédent P6.4d).

---

## 6. Conséquence de déploiement

Une base existante doit exécuter la migration `2026_08_30_000002_p11_reconciliation_roles`, qui
transfère les comptes des trois noms retirés vers leurs survivants, **puis** rejouer `RoleSeeder`
et `PortailRolesSeeder`. La migration **refuse bruyamment** si un renommage tomberait sur un nom
déjà pris, en disant quoi faire — plutôt que de laisser le moteur rendre une erreur d'unicité nue
au milieu d'un déploiement.

---

## 7. Limites

- Le registre ne contient que **trois zones**, parce qu'il n'y en a que trois. La navigation d'un
  compte `radiologue` est donc vide ; il entre, et son tableau de bord le lui dit.
- **La migration Blade → Next n'est pas commencée** : vingt-neuf zones, soixante-dix-sept vues,
  chacune ayant besoin de son API. C'est le lot suivant.
- `estProfessionnel()` reste une porte d'entrée par rôle. C'est voulu : elle dit qui est un
  professionnel, le registre dit qui atteint quoi.
- **Le workflow RDV à deux étapes du §9.1 n'est toujours pas implémenté** — `medecin` peut
  désormais valider, mais l'état intermédiaire `PREVALIDE_SECRETAIRE` que `@masante/shared` déclare
  depuis P0 reste une **clé morte**, et la table MySQL porte cinq valeurs sans rapport. C'est le
  constat B1 du G0 de CDC_11, et c'est le lot RDV qui le porte.
