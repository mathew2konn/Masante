# ADR-056 — Paiement en ligne réel (GeniusPay) : le canal (B4-a)

**Statut : Accepté.** **B4-a VALIDÉ (G5, 2026-09-04)** — G1 validé (« je valide le G1 de B4-a »),
G4 propriétaire OK, **G5 « c'est bon pour le G5 »**.
Contexte : CDC_11 §9.6 (paiement direct, la plateforme ne manipule jamais les fonds), §9.2
(rendez-vous). Plan G1 : [`plan.md`](../../plan.md) PLAN 3 · **Amende [[ADR-013]]**
(le domaine paiement vit dans le microservice Java — décision jamais matérialisée en fichier
séparé, référencée par convention `[[…]]` dans tout `docs/adr/`, comme ici) et
**[ADR-044](ADR-044-geniuspay-reutilisation-abstractions.md)** (montage A : compte marchand par
établissement, GeniusPay n'est pas un second port).

---

## 1. Contexte

CDC_11 §9.6 est déjà satisfait par le montage A que P5.6b a construit : chaque établissement a
**son** compte marchand GeniusPay, l'argent va **chez lui**, MaSanté ouvre le checkout et **constate**
l'issue par webhook. La commission de plateforme est **facturée séparément**
(`FacturePartenaire`), jamais prélevée sur les fonds.

Ce qui manquait n'était pas le corpus, mais le **canal** : depuis le lot 6, un commentaire du code
affirme que « le domaine ne porte pas d'identifiant d'établissement », et
`CommissionService::calculerEtEnregistrer()` — complet, testé, portant textuellement la règle
« pharmacie hors ligne = exonérée » — n'avait **aucun appelant en production**
(`commissions_transaction` : 0 ligne, base réelle).

## 2. Le constat qui renverse le blocage (R2)

Vérifié dans le code Java, pas supposé : l'agrégat `Paiement` **porte** `etablissementRef` (colonne
`etablissement_ref`, réelle, `updatable = false`) **et** `factureId`, et c'est cette même classe
(`setStatut()`) qui construit l'événement de transition terminale. **`TransitionTerminaleEvenement`
ne les recopiait simplement pas.**

Le commentaire du lot 6 n'était pas absurde pour autant : sans émetteur (Laravel n'initiait aucun
paiement), ces champs étaient réellement **nuls en pratique**, et rattacher une commission à un
`etablissementRef` nul aurait été pire que ne rien faire. *Le champ existait ; en devenir
l'émetteur, c'est le remplir.* Ce lot supprime la **cause**, pas seulement le symptôme.

## 3. Décisions de conception

### 3.1 `etablissementRef` porte l'identifiant national préfixé du pays

Forme `CI-ETS000001`, jamais l'id technique Laravel — un identifiant technique ne veut rien dire
hors de cette base (argument déjà opposé à `symptome_id`, P10b-3-i). Le préfixe est **obligatoire** :
l'unicité de `identifiant_national` est `(pays_code, identifiant)` (P6.4a), donc deux pays peuvent
partager le même identifiant. Conséquence dure et dite : le backfill de P6.4a devient un
**prérequis de déploiement**, et un établissement sans identifiant national ne peut pas encaisser en
ligne — le refus **nomme** l'établissement.

### 3.2 L'événement porte ce que le domaine SAIT ; Laravel refuse quand il ne sait pas

`etablissementRef`/`factureId` sont recopiés de l'agrégat, jamais devinés. S'ils sont nuls, ils
partent nuls, et Laravel refuse de calculer une commission — **en le journalisant**, jamais en
silence. C'est la garantie que le lot 6 cherchait, tenue par le **refus** plutôt que par l'absence
du champ.

**Écart trouvé en implémentant, pas au G1** : `etablissementRef` seul ne suffisait pas à isoler
GeniusPay. La carte et le mobile money (`ServiceCarte`, `ServicePaiement`) portent **eux aussi** un
`etablissementRef` — filtrer sur sa seule présence aurait déclenché une commission MaSanté sur
**tous** les paiements de la plateforme, une décision de politique commerciale jamais prise.
**`canal` est ajouté à l'événement** (recopié de `Paiement.getCanal()`, jamais recalculé) :
Laravel ne déclenche `CommissionService` que sur `canal === 'geniuspay'` **et** `statut === SUCCESS`.

**`paiementId` est ajouté également**, pour la même raison de méthode : ni `factureId` seul (pas
garanti unique dans le temps si un second checkout suit un échec) ni `referenceInterne` (vit sur la
table satellite, jamais recopiée sur `Paiement`) ne convenaient comme clé d'idempotence pour
`CommissionService::calculerEtEnregistrer()`. `paiementId` l'est : un `Paiement` n'atteint un état
terminal qu'**une seule fois** (garde de répétition de `setStatut`), donc
`geniuspay-paiement:{paiementId}` identifie sans ambiguïté LA transition qui a déclenché la
notification, même après un rejeu du relais.

### 3.3 Les frais cessent d'être `0` en dur, et leur absence se dit

La distinction qui compte : la commission vaut `montantBrut × taux`, **les frais n'y entrent pas**.
Ils entrent dans `montantNetStructure`, donc des frais faux ne faussent pas la commission — ils
faussent le **net dû au partenaire**. Décision du propriétaire (2026-09-04, arbitrage B) : calculer
la commission avec des frais à **0 explicite** quand ils sont inconnus, plutôt que de refuser —
refuser laisserait des paiements réels sans aucune commission, en attendant une passe de
complétion qui n'existe pas. La colonne neuve `commissions_transaction.frais_connus` (additive,
défaut `true` pour tout appelant antérieur) dit honnêtement si `frais_passerelle` était **su** ou
**supposé** — l'égalité du reçu transparent reste vraie dans les deux cas, mais un
`montant_net_structure` calculé avec des frais inconnus peut être **surestimé**. Ce lot donne enfin
un **porteur** à la dette de P5.6b (« passe de complétion des frais »), sans la refermer.

### 3.4 Aucun drapeau global : la disponibilité est une propriété de l'établissement

Arbitrage du propriétaire (2026-09-04, arbitrage C) : **actif d'emblée**, contre la recommandation
initiale d'un drapeau « prêt à activer ». Un drapeau global aurait été binaire pour tous les
établissements, alors que la réalité du montage A est **par établissement** : un interrupteur
unique aurait dit « oui » pour des officines sans compte marchand, et « non » pour celles qui en
ont un.

La bonne question devient : **cet établissement-ci peut-il encaisser ?** — deux conditions réelles
(identifiant national + compte marchand déclaré). Java expose `GET
/api/v1/interne/geniuspay/marchands/{etablissementRef}` (« configuré : oui/non », **jamais les
clés** — la méthode `ServiceMarchandGeniusPay::estConfigure()` ne les lit même pas). La liste des
marchands **reste côté microservice** : la recopier côté Laravel produirait deux réponses possibles
à la même question. `ClientPaiementGeniusPay::estConfigure()` interroge et **met la réponse en
cache** quelques minutes — un cache, pas une copie : il se périme seul et n'est jamais la source.

Assumé et dit : sans interrupteur, un défaut du canal se verra immédiatement par les patients ; la
contrepartie est que rien n'est proposé qui ne puisse aboutir, et que le règlement d'aujourd'hui
reste intact pour un établissement non configuré.

### 3.5 Quatrième implémentation du principal signé

`SigneurPrincipalSortant` (PHP) mint `X-Principal`/`X-Principal-Sig` — Laravel devient **émetteur**
pour la première fois (jusqu'ici seul `VerificateurPrincipalSigne` existait, côté vérification).
Mêmes claims, même encodage, jamais un sous-ensemble, que les trois implémentations existantes
(Java vérifie, Node et Python mintent). Garde anti-divergence dédiée
(`PrincipalSigneSourceUniqueTest`), motif `PermissionsSourceUniqueTest`/`NisVecteursPartagesTest`.
Aucune dépendance nouvelle (`hash_hmac`, `base64`, `Str::uuid()` natifs ; client HTTP `Illuminate\Support\Facades\Http`).

## 4. Découpage

**B4-a** le canal, prouvé seul, sans toucher aucun écran ; **B4-b** le rendez-vous (temporalité du
règlement, différée) ; **puis B3-d** s'y branche. Le canal doit être prouvé seul : le mêler au
rendez-vous mêlerait deux causes de panne sur un module (B1) déjà validé G5.

## 5. Ce qui a été prouvé

**G3** — Java : suite complète verte (un échec confirmé être un flake de contention réseau,
`AdaptateurGeniusPayTest`, vert isolément, sans rapport avec B4). PHP : suite complète **1702/1702,
17 882 assertions, 0 échec**. **Campagne de mutation PHP : 7 tueuses + 1 témoin volontairement
vert** sur les gardes canal/statut/résolution/frais-inconnus/idempotence/pays/secret-manquant,
chaque mutation assertée appliquée, arbre restauré et vérifié par `diff`.

**Défaut de méthode trouvé par le premier run Java** : le harnais de test (`evenementPret()`)
construisait un `Paiement` resté à `INITIATED` — `INITIATED → SUCCESS` n'est pas une transition
permise par `MachineEtatsPaiement` (seul `PENDING` y mène, posé par `ServiceGeniusPay::executer()`
à l'ouverture réelle d'un checkout). Le harnais ne le reproduisait pas : `appliquer()` refusait
silencieusement la transition du paiement tout en terminant l'événement webhook en `TRAITE`, et
aucun événement de domaine ne partait jamais — invisible aux vecteurs plus anciens, qui ne
vérifient que le statut de traitement du webhook, jamais l'historique du `Paiement` lui-même.

**G2 — live, réel, sur MySQL de développement.** Java réellement démarré (Docker, Postgres+Redis
réels), `php artisan serve` réel. Un établissement réel créé, un marchand GeniusPay réellement
enregistré (compte sandbox réutilisé du lot 7), un secret webhook réellement déposé.

- `GET /marchands/{ref}` : `configure:false` avant dépôt du secret, `configure:true` après — en
  réel.
- `ClientPaiementGeniusPay::estConfigure()` : premier appel réseau réel (28,5 s sous la charge de ce
  poste), second appel servi par le cache (4 ms) — le contraste **est** la preuve.
- **Deux checkouts GeniusPay réellement ouverts en bac à sable** (vraie `checkoutUrl`, vraie
  `referencePasserelle`). Un troisième essai a expérimenté `INITIEE_INCERTAINE` (délai réseau réel
  sous charge) — comportement de sécurité correct et prévu (§7.5.3 de P5.6b), pas un défaut.
- **Deux webhooks `payment.success` signés avec le vrai secret déposé**, envoyés réellement,
  vérifiés réellement par Java, transition `INITIEE_INCERTAINE → REUSSIE` réelle (autorisée par
  `MachineEtatsGeniusPay`, prévue pour ce cas exact).
- **Notification réellement relayée par le scheduler automatique Java**
  (`PlanificateurNotifications`) — appel HTTP réel, signé par `SigneurPrincipalSortant` (Java),
  vérifié par `VerificateurPrincipalSigne` (PHP) : preuve croisée du principal signé dans le sens
  Java→PHP, jamais exercée en réel avant B4.
- **Deux défauts réels trouvés PAR LE G2, invisibles aux tests** (les deux bases de test partent
  toujours neuves) : (1) `baremes_commission` vide sur la base de dev réelle — refus bruyant réel,
  journalisé, rien écrit, corrigé par `BaremesCommissionSeeder` ; (2) **la migration
  `frais_connus` n'avait jamais été rejouée sur la vraie base MySQL**, seulement sur SQLite via les
  tests — `SQLSTATE[42S22]` réelle, rien écrit, corrigée par `artisan migrate --force`.
- Après correction, un cycle complet a produit une **commission réelle** :
  `structure_sanitaire_id=18`, `montant_brut=18000`, `frais_passerelle=200` (extrait du webhook),
  `frais_connus=true`, `taux_bps_applique=250`, `montant_commission=450` (exact),
  `montant_net_structure=17350` (exact).
- **Trois refus/garanties prouvés en direct**, en appelant l'endpoint Laravel réel avec un principal
  réellement signé : `canal=carte` (même établissement réel) → 0 commission ; `etablissementRef`
  inconnu → 0 commission ; **rejeu exact** de la notification qui avait créé la commission (montant
  et frais falsifiés) → 0 seconde commission, montant inchangé.

**G4** — le propriétaire a réalisé son propre test réel le 2026-09-04 et l'a validé.

**G5** — le propriétaire a écrit « c'est bon pour le G5 » le 2026-09-04.

## 6. Limites annoncées

1. **Les frais ne sont pas complétés** (dette P5.6b, désormais portée par `frais_connus`) : le net
   dû au partenaire peut être surestimé.
2. **Aucun remboursement.**
3. **Le bac à sable GeniusPay renvoie `fees = 0`** dans le cas nominal (dette R4 de P5.6b) — le
   chemin « frais connus » n'a été prouvé en direct que lorsque le webhook les portait
   explicitement (2ᵉ et 3ᵉ cycles du G2), jamais garanti pour tout webhook réel futur.
4. **Aucun paiement partiel, aucun échéancier.**
5. `plans_tarifaires`/`abonnements_structure` vides en base réelle : l'exonération par plan
   (`commission_incluse`) n'a été exercée que par un vecteur, jamais en réel.
6. **Aucun écran de rapprochement des commissions** (l'écran de facturation du lot 8 n'est pas
   retouché).
7. **Aucun interrupteur pour éteindre le paiement en ligne** (arbitrage C, §3.4) : le seul recours
   d'exploitation est de retirer le compte marchand d'un établissement chez GeniusPay.
8. **Aucun écran d'enregistrement du compte marchand** : `POST /marchands` s'appelle en direct sur
   le microservice.
9. **B4-b (le rendez-vous) et B3-d (la pharmacie) ne sont pas branchés sur ce canal** — c'est
   l'objet des incréments suivants, qui n'ont plus à réinventer le canal, seulement à l'appeler.
