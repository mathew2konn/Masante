# GUIDE DE TEST — Applications métier (CDC_11)

> Guide de non-régression du module **P11 — Applications métier**. Une partie par incrément
> (règle propriétaire du 2026-08-11, `GUIDE_TEST_INDEX.md`).

---

## Partie 1 — P11.0 : les portes du portail

> **Périmètre** : rôles réconciliés, permissions exposées par `/me`, registre de zones, garde par
> zone dans le portail Next. **Aucune application métier, aucune zone Blade migrée.**
> **VALIDÉ G5 le 2026-09-01** (G2 live + G3 le 2026-08-30, G4 propriétaire le 2026-09-01).

### Ce qu'il faut savoir avant de commencer

Cet incrément livre **la porte, la serrure, les clés et le couloir** — pas les pièces. Le portail
Next contient trois zones (Rendez-vous, Alertes de fraude, Sécurité du compte) ; le portail Blade
n'a pas bougé d'une ligne et continue de servir ses vingt-neuf zones.

Le point à éprouver n'est donc pas « que peut faire un pharmacien ? » (rien encore) mais **« un
compte ne voit-il que ce qu'il peut atteindre, et le backend refuse-t-il vraiment le reste ? »**

### Préparation

> **Condition de run qui a changé.** La suite Laravel a franchi le plafond de **128 Mo** de PHP :
> elle rend `Allowed memory size exhausted` avec le `memory_limit` par défaut. Lancez-la avec
> `php -d memory_limit=1G vendor/bin/phpunit`. Ce n'est pas un défaut de cet incrément — la suite
> était à la limite —, mais c'est une condition à connaître avant de conclure qu'elle « échoue ».

```bash
cd services/api
XDEBUG_MODE=off php artisan migrate
XDEBUG_MODE=off php artisan db:seed --class=RoleSeeder
XDEBUG_MODE=off php artisan db:seed --class=PortailRolesSeeder
XDEBUG_MODE=off php artisan serve --host=0.0.0.0 --port=8000
```

```bash
pnpm --filter @masante/web dev     # portail Next, http://localhost:3000
```

Créez un compte par rôle à éprouver (portail Blade → Comptes, ou en base).

---

### A — Les rôles

- [ ] **A1.** `SELECT name FROM roles ORDER BY name;` rend **exactement onze** lignes :
      `admin_ivoirsante`, `assurance`, `gestionnaire_etablissement`, `infirmier`, `laborantin`,
      `medecin`, `ministere`, `patient`, `personnel_accueil`, `pharmacien`, `radiologue`.
- [ ] **A2.** Aucun de ces quatre noms ne subsiste : `secretaire`, `admin_etablissement`,
      `super_admin`, `agent_garde`.
- [ ] **A3.** Sur une base qui portait l'un de ces noms **avant** la migration, le compte qui le
      portait a reçu son survivant — il n'est resté sans rôle dans aucun cas.
- [ ] **A4.** `personnel_accueil` porte exactement les **cinq** permissions de l'ex-`agent_garde` :
      `disponibilite.manage`, `rdv.validate`, `qr.scan`, `triage.view`, `dossier.referent`.

### B — Les permissions, et ce qui n'est délibérément pas donné

- [ ] **B1.** `infirmier` et `laborantin` portent `dossier.ecrire` et `qr.scan`.
- [ ] **B2.** `radiologue` porte `qr.scan` et `triage.view`, et **pas** `dossier.ecrire` — il
      n'existe ni imagerie ni compte rendu radiologique ; l'écriture lui ouvrirait les ordonnances.
- [ ] **B3.** `pharmacien` porte `medicament.manage` et **pas** `medicament.referentiel`.
- [ ] **B4.** `ministere` porte `stats.global` et `sante_publique.manage`, et **pas**
      `stats.etablissement`.
- [ ] **B5.** `assurance` porte **zéro** permission. *C'est la garantie, pas un oubli.*
- [ ] **B6.** `medecin` porte désormais `rdv.validate` (CDC_11 §9.1), et toujours pas
      `disponibilite.manage` ni `medecin.manage`.

### C — La porte du portail Blade

- [ ] **C1.** Chacun des dix rôles professionnels se connecte au portail Blade et arrive au
      tableau de bord.
- [ ] **C2.** Un compte `patient` est refusé avec « Ce compte n'a pas accès au portail ».
- [ ] **C3.** Un compte `radiologue` connecté ne voit **aucune** entrée d'établissement ni de
      référentiel : chaque route reste gardée par sa permission.

### D — Le défaut corrigé : le scan

- [ ] **D1.** Un compte `medecin` **rattaché à un établissement** ouvre `/portail/scan` → **200**.
      *Avant P11.0 il recevait un 403 « réservé aux agents de garde », alors qu'il portait
      `qr.scan` depuis P6.5a.*
- [ ] **D2.** Le même compte **sans** `structure_id` → **403**. La garde restante est réelle.
- [ ] **D3.** Un compte `infirmier` rattaché ouvre également `/portail/scan` → 200.

### E — `/me` expose les permissions

- [ ] **E1.** `GET /api/v1/auth/me` (Bearer Sanctum) rend un tableau `user.permissions`.
- [ ] **E2.** Il contient les permissions **du rôle** (ex. `dossier.ecrire` pour un médecin).
- [ ] **E3.** Il contient aussi les permissions **nominatives** : accordez
      `urgence.bris_de_glace` à un compte, elle apparaît. *C'est le point — quatorze permissions
      de ce projet n'appartiennent à aucun rôle.*
- [ ] **E4.** Il ne contient pas une permission que le compte n'a pas (`etablissement.manage`).
- [ ] **E5.** Un compte `assurance` rend `"permissions": []` — un tableau **vide**, pas une clé
      absente. Les deux ne veulent pas dire la même chose côté portail.

### F — Le portail Next : on ne voit que ce qu'on peut atteindre

- [ ] **F1.** Connectez-vous avec un compte portant `rdv.validate` : la navigation affiche
      **Rendez-vous**, et le tableau de bord la propose en carte.
- [ ] **F2.** Connectez-vous avec un compte `radiologue` : **aucune** entrée « Rendez-vous », ni
      dans la navigation, ni en carte. *Avant P11.0, la carte s'affichait pour tout le monde et le
      clic menait à « accès restreint ».*
- [ ] **F3.** Ce même compte tape `/rendez-vous` **à la main** dans la barre d'adresse → il est
      **renvoyé au tableau de bord**, pas à la page de connexion. Le compte est connecté ; l'envoyer
      se reconnecter lui ferait croire à une session expirée.
- [ ] **F4.** Un compte `admin_ivoirsante` ou `ministere` voit **Alertes de fraude** ; un compte
      `gestionnaire_etablissement` ne la voit pas, même s'il porte beaucoup de permissions —
      l'indépendance du contrôleur est une propriété du rôle (ADR-017 §7).
- [ ] **F5.** Un compte `assurance` arrive sur un tableau de bord qui **dit** qu'aucun espace n'est
      encore ouvert pour son profil, et précise que ce n'est pas un problème de droits. La
      navigation latérale est absente, pas vide.
- [ ] **F6.** **Sécurité du compte** est visible pour tous : elle n'exige aucune permission.

### G — La garde anti-divergence

- [ ] **G1.** Ajoutez une permission fictive dans `packages/shared/src/enums/permissions.ts` →
      `phpunit --filter PermissionsSourceUniqueTest` **échoue**, en disant que le portail
      afficherait une porte qui rendra 403. Retirez-la, le test repasse.
- [ ] **G2.** Retirez une permission de la liste partagée → le test échoue dans l'autre sens.
- [ ] **G3.** Cassez la forme du tableau (renommez `PERMISSIONS`) → le test échoue en disant que
      c'est **l'extraction** qui a échoué, et non que la liste a rétréci. *Sans ce garde-fou, il
      comparerait deux listes vides et passerait.*

### H — Ce qui ne doit PAS avoir bougé

- [ ] **H1.** Le portail Blade sert toujours ses vingt-neuf zones à l'identique.
- [ ] **H2.** Le module fraude de Next fonctionne : liste, détail, scan, marquage « revue ».
- [ ] **H3.** La file d'attente RDV de Next fonctionne : liste, détail, confirmer, refuser.
- [ ] **H4.** L'application mobile se connecte et fonctionne normalement (le rôle `patient` n'a pas
      changé).

---

### Ce que le G2 live a effectivement montré (2026-08-30, base MySQL réelle)

Sauvegarde préalable des **trois seules tables que la migration peut toucher** (`roles`,
`model_has_roles`, `role_has_permissions` — 14 rôles, 6 attributions, 59 liens) dans
`C:/tmp/g2p11_avant.json`. *`mysqldump` refuse la connexion sur ce poste ; le périmètre sauvegardé
est celui de la migration, entièrement.*

| | Constaté |
|---|---|
| **Avant** | 14 rôles. `super_admin` : **1 compte**, 0 permission. `agent_garde` : 1 compte, 5 permissions. Sept rôles à **0 permission**. |
| **Migration** | `2026_08_30_000002_p11_reconciliation_roles` — 336 ms |
| **Transfert** | le compte `+2250709090909` (contrôleur de fraude) est passé de `super_admin` à **`admin_ivoirsante`** — **pas orphelin**. Le compte `+2250700000009` a suivi `agent_garde` → `personnel_accueil`. |
| **Suppressions** | `secretaire`, `admin_etablissement`, `super_admin`, `agent_garde` : **disparus** |
| **Après seeders** | **11 rôles**. `infirmier` 3 · `laborantin` 3 · `pharmacien` 2 · `radiologue` 2 · `ministere` 2 · `medecin` **7** (était 6) · `personnel_accueil` 5 (inchangé) · `assurance` **0** |
| **`/me` en direct** | `radiologue` → `['qr.scan','triage.view']` · `pharmacien` → `['medicament.manage','qr.scan']` · `infirmier` → `['qr.scan','triage.view','dossier.ecrire']` · `ministere` → `['stats.global','sante_publique.manage']` · **`assurance` → `[]`** |
| **Permission nominative** | `urgence.bris_de_glace` accordée au radiologue → **apparaît** dans `/me` ; `etablissement.manage` reste absente |
| **Portail Blade** | les cinq rôles neufs se connectent (302 → `/portail`) — ils étaient refusés avant |
| **Le défaut corrigé** | médecin rattaché → `/portail/scan` **200**. *Avant P11.0 : 403 « réservé aux agents de garde ».* |
| **§9.1** | médecin rattaché à un service → `/portail/rendez-vous` **200** |
| **Confinement** | radiologue → **403** sur rendez-vous, établissements, médecins, statistiques |

**Trouvé au passage, et ce n'est pas un défaut** : la permission `rdv.validate` ne suffit pas, le
compte doit aussi être **rattaché à un service** (`RendezVousValidationService::serviceIds()` rend
403 « aucun service à gérer »). C'est le périmètre préexistant, et il est juste : la permission dit
ce qu'on peut faire, le rattachement dit sur quoi.

Les six comptes créés pour le G2 ont été supprimés ; **la réconciliation des rôles est conservée**,
c'est l'état de déploiement voulu.

---

### État des gates

- [x] **G0** — audit en base et dans le code (129 tables, 145 routes portail, 29 permissions)
- [x] **G1** — plan validé par le propriétaire le 2026-08-30
- [x] **G2** — vérifié en direct : rôles, permissions, portes, `/me`, portail Next
- [x] **G3** — 16 vecteurs dédiés, suite complète, mutation 8/8, Pint, typecheck ×3, lint, build
- [x] **G4 propriétaire — validé le 2026-09-01**

### Limites annoncées

1. Le registre ne contient que **trois zones**, parce qu'il n'y en a que trois. Un compte
   `radiologue` entre et n'a rien à ouvrir — son tableau de bord le lui dit.
2. **Aucune zone Blade n'est migrée** : vingt-neuf zones, soixante-dix-sept vues, chacune ayant
   besoin de son API JSON. C'est le lot suivant.
3. **Shadcn UI et `laravel/reverb` ne sont pas installés**, bien qu'annoncés au G1 : les installer
   ici les laisserait dormir. Ils arrivent avec le premier écran d'application métier et avec le
   lot RDV. *Écart au plan, signalé et non silencieux.*
4. **Le workflow RDV à deux étapes du §9.1 n'est pas implémenté.** `medecin` peut valider, mais
   l'état `PREVALIDE_SECRETAIRE` que `@masante/shared` déclare depuis P0 reste une **clé morte** et
   la table MySQL porte cinq valeurs sans rapport. C'est le lot RDV qui le porte.

---

## Partie 2 — P11.1 : l'onboarding méthode 2 (l'établissement demande, la plateforme valide)

> **Périmètre** : formulaire public de candidature, file de traitement dans le portail Next,
> approbation qui crée l'établissement par le même chemin que la méthode 1.
> **Referme la limite M1**, ouverte depuis P6.4a et reportée deux fois.
> **VALIDÉ G5 le 2026-09-01** (G2 live + G3 le 2026-08-30, G4 propriétaire le 2026-09-01).

### Ce qu'il faut savoir avant de commencer

CDC_11 §3 affirme que « les deux méthodes sont implémentées ». Ce n'était pas vrai : seule la
méthode 1 existait (l'administrateur crée). Cet incrément livre la seconde — et c'est **l'étape 1
de l'ordre de construction** que CDC_11 §12 fixe lui-même.

La garantie centrale à éprouver : **une candidature n'est pas un établissement.** Rien de ce qui
est déposé ne doit atteindre l'annuaire que lit un patient, tant qu'un humain habilité n'a pas
approuvé.

### Préparation

```bash
cd services/api && XDEBUG_MODE=off php artisan migrate
XDEBUG_MODE=off php artisan serve --host=0.0.0.0 --port=8000
pnpm --filter @masante/web dev      # http://localhost:3000
```

Notez le nombre d'établissements avant de commencer :
`SELECT COUNT(*) FROM structures_sanitaires;`

---

### A — Le dépôt public

- [ ] **A1.** Ouvrez `http://localhost:3000/rejoindre` **sans être connecté**. La page s'affiche.
      *C'est tout le point de la méthode 2 : le demandeur n'a ni compte ni contact préalable.*
- [ ] **A2.** Envoyez une candidature complète — une **référence** `DEM-XXXXXXXXXX` s'affiche.
- [ ] **A3.** Le compte d'établissements **n'a pas bougé**. La candidature vit dans
      `demandes_inscription_etablissement`, pas dans l'annuaire.
- [ ] **A4.** Envoyez la même chose en ajoutant `"statut":"approuvee"` et
      `"reference":"DEM-AUTOACCORD"` (par curl) — la demande est créée **en attente**, avec une
      référence générée par le serveur. *Un candidat ne s'auto-inscrit pas.*
- [ ] **A5.** Laissez le numéro d'autorisation vide — **422**. C'est la pièce qui rend la demande
      vérifiable ; sans elle il n'y a rien à confronter à l'autorité de tutelle.
- [ ] **A6.** Redéposez avec la **même adresse de demandeur** — refus qui **rappelle la référence
      en cours**. Une seule demande en attente par adresse.

### B — Le suivi public

- [ ] **B1.** `GET /api/v1/etablissements/demandes/{reference}` rend **quatre champs** :
      `reference`, `statut`, `decide_le`, `motif_rejet`.
- [ ] **B2.** Rien du dossier déposé n'en ressort — ni le nom, ni le numéro d'autorisation, ni
      l'adresse, ni les coordonnées. *Une référence peut être interceptée ; elle ne doit pas
      devenir un moyen de relire les données d'un candidat.*
- [ ] **B3.** Une référence inconnue — **404, jamais 403**. Un 403 confirmerait qu'une demande
      existe là.

### C — La zone du portail, et ce qu'elle prouve du socle de P11.0

- [ ] **C1.** Connectez-vous avec un compte portant `etablissement.manage` : **Demandes
      d'inscription** apparaît dans la navigation *et* en carte sur le tableau de bord.
- [ ] **C2.** Connectez-vous avec un compte `medecin` : l'entrée **n'apparaît nulle part**, et
      taper `/demandes-inscription` à la main renvoie au tableau de bord.
      *Une seule ligne a été ajoutée au registre de zones ; la garde et la navigation ont suivi.*
- [ ] **C3.** `GET /api/v1/portail/demandes-inscription` avec un jeton `medecin` — **403**.
      Le backend refuse de son côté, indépendamment de ce que le portail affiche.

### D — L'approbation

- [ ] **D1.** Ouvrez une candidature en attente : le numéro d'autorisation est mis en avant, avec
      la mention qu'il est à confronter à l'autorité de tutelle.
- [ ] **D2.** Approuvez en complétant latitude, longitude et le compte gestionnaire — un lien
      d'activation s'affiche, et l'établissement apparaît dans l'annuaire (**+1**).
- [ ] **D3.** Le compte gestionnaire est créé **sans mot de passe**, avec le rôle
      `gestionnaire_etablissement`, rattaché au nouvel établissement.
- [ ] **D4.** **Le vecteur qui compte** : envoyez par curl un `nom` et un `numero_autorisation`
      différents de la candidature. La base porte **ceux de la candidature**. L'agent vérifie, il
      ne ressaisit pas.
- [ ] **D5.** En revanche, `type` **est** rectifiable — c'est le champ qu'un demandeur se trompe le
      plus souvent, et le laisser faux fausserait les statistiques du §4.4.
- [ ] **D6.** La demande porte désormais `structure_id`, le nom du décideur et la date.
- [ ] **D7.** Le suivi public de la référence dit maintenant `approuvee`.

### E — Le rejet et le conflit

- [ ] **E1.** Rejetez sans motif — **422**. Le demandeur lira ce motif ; un refus sans raison ne
      lui dit pas quoi corriger.
- [ ] **E2.** Rejetez avec un motif — la décision est enregistrée, et le motif apparaît **au suivi
      public**.
- [ ] **E3.** Re-décidez une demande déjà traitée (approuver **ou** rejeter) — **409, jamais 403**.
      *L'agent a le droit de décider ; c'est cette demande qui ne l'est plus.*
- [ ] **E4.** Approuvez avec une adresse de gestionnaire **déjà prise** — 422, et **rien n'est
      créé** : pas d'établissement orphelin sans gestionnaire.

### F — Les gardes du moteur

- [ ] **F1.** En SQL direct : `UPDATE ... SET statut='rejetee', motif_rejet=NULL` — **`ERROR 1644`**.
- [ ] **F2.** En SQL direct : `UPDATE ... SET statut='approuvee', structure_id=NULL` — **`ERROR 1644`**.
      *C'est en base qu'on relira ces lignes dans dix ans.*

### G — Ce qui ne doit PAS avoir bougé

- [ ] **G1.** La méthode 1 fonctionne à l'identique : portail Blade, Établissements, Créer — le
      lien d'activation s'affiche. *Les deux méthodes passent désormais par le même service ; si
      l'une casse, l'autre le dirait.*
- [ ] **G2.** Régénérer un lien d'activation depuis la fiche d'un établissement fonctionne, et
      reste refusé sur un compte déjà activé.
- [ ] **G3.** Les trois zones de P11.0 fonctionnent toujours.

---

### Ce que le G2 live a effectivement montré (2026-08-30, base MySQL réelle)

| | Constaté |
|---|---|
| Schéma | 24 colonnes, **2 déclencheurs** posés |
| Dépôt public | `DEM-KOS1YUMTGQ` créée sans jeton ; `statut` et `reference` envoyés par le client **ignorés** |
| Annuaire | **12 avant, 12 après** le dépôt |
| Second dépôt | refusé en **rappelant la référence en cours** |
| Suivi | 4 champs, **aucune fuite** sur les 5 cherchées ; référence inconnue — **404** |
| Habilitation | `medecin` — **403**, `admin_ivoirsante` — **200** |
| Approbation | annuaire **12 vers 13** ; nom `Clinique Saint Joseph` et autorisation `AUT-2026-00417` **de la candidature** malgré `NOM REECRIT PAR AGENT` et `AUT-FALSIFIE` envoyés ; `type` rectifié en `cabinet` ; gestionnaire **sans mot de passe**, rôle `gestionnaire_etablissement`, structure 17 ; demande `approuvee`, liée, nommant son décideur |
| Re-décision | **409** sur approuver **et** sur rejeter |
| Moteur | `ERROR 1644` sur rejet sans motif **et** sur approbation orpheline |

Base restaurée compte pour compte (12 structures, 0 demande, 16 comptes, 11 rôles).

---

### État des gates

- [x] **G0** — audit : méthode 1 vérifiée présente, méthode 2 absente, aucune route publique
- [x] **G1** — périmètre annoncé et tenu
- [x] **G2** — vérifié en direct de bout en bout
- [x] **G3** — 18 vecteurs dédiés, mutation **10/10**, Pint, typecheck x3, lint, build
- [x] **G4 propriétaire — validé le 2026-09-01**

### Limites annoncées

1. **La vérification du numéro d'autorisation est un acte humain.** Aucune API d'autorité de
   tutelle n'existe ; prétendre l'automatiser donnerait à une machine l'apparence d'une
   habilitation qu'elle n'a pas.
2. **Aucun courriel n'est envoyé.** Le lien d'activation s'affiche à l'agent, qui le transmet ; le
   demandeur n'est pas notifié de sa décision et doit consulter le suivi par référence. Il n'y a
   pas de passerelle de courriel dans cet environnement, et le prétendre serait pire que de le dire.
3. **L'anti-abus n'est pas une protection forte** : limiteur plus une demande en attente par
   adresse, pas de captcha (ce serait une dépendance externe sur un formulaire public).
4. **L'intégration par API (ADR-030) n'est pas là.** C'est un autre axe — la méthode 2 fait entrer
   un établissement *dans* la plateforme, l'API fait circuler les données d'un établissement qui a
   *déjà* son logiciel. Étape 9 de CDC_11 §12, elle suppose une troisième population
   d'authentification et un partenaire réel.

---

## Partie 3 — P11.2 : l'API d'ingestion partenaire

> **Périmètre** : la troisième population d'authentification, les correspondances déclarées, le
> journal d'ingestion, et **un flux réel** — le stock d'une officine.
> **VALIDÉ G5 le 2026-08-31** — cette partie est conservée comme procédure de non-régression
> (règle propriétaire, CDC_01 §2.4).

### Ce qu'il faut savoir avant de commencer

CDC_11 §7.7 promet : « si la pharmacie possède déjà un logiciel, ce logiciel envoie automatiquement
stock, prix, disponibilité. **Le pharmacien n'a rien à ressaisir.** » Cet incrément rend cette
phrase vraie pour le stock.

Deux garanties à éprouver plus que les autres : **le serveur ne devine jamais** une référence
produit, et **l'API n'est pas un second chemin d'écriture** — elle passe par le service que le
pharmacien utilise au portail.

### Préparation — et le prérequis qui n'en est pas un détail

```bash
cd services/api && XDEBUG_MODE=off php artisan migrate

# LE PIVOT. Sans lui, aucune correspondance n'est déclarable : ADR-030 disait
# « le référentiel est le pivot, sans lui rien vers quoi mapper », et sur cette base
# `medicaments.code` était renseigné 0 fois sur 18.
XDEBUG_MODE=off php artisan masante:medicaments:backfill

# Émettre une clé pour le logiciel d'une officine (le secret n'est affiché qu'une fois)
XDEBUG_MODE=off php artisan masante:integration:emettre <structure_id> "Caisse Sage Officine v4"

XDEBUG_MODE=off php artisan serve --host=0.0.0.0 --port=8000
```

Le partenaire signe ainsi :
`base64(hmac_sha256(timestamp + "." + corps_brut, secret))`, dans `X-MaSante-Signature`, avec
`X-MaSante-Client` et `X-MaSante-Timestamp`. Un script d'exemple existe dans le scratchpad du G2.

---

### A — Qui peut écrire

- [ ] **A1.** Un envoi correctement signé est accepté (200) et le rapport dit combien de lignes
      ont été écrites.
- [ ] **A2.** Une **signature fausse** est refusée en **401**, avec un message qui ne dit **pas**
      que c'est la signature. *Distinguer « client inconnu » de « signature fausse » dirait à un
      attaquant quels identifiants existent.*
- [ ] **A3.** Un **identifiant inconnu** rend exactement le même refus que A2.
- [ ] **A4.** Un horodatage vieux d'une heure est refusé. Sans fenêtre de fraîcheur, l'anti-rejeu
      devrait mémoriser indéfiniment.
- [ ] **A5.** **Le même envoi présenté deux fois** (mêmes octets, même signature) est refusé au
      second passage : c'est le rejeu.
- [ ] **A6.** Une clé **révoquée** (`--revoquer <identifiant> --motif "..."`) ne peut plus écrire.
- [ ] **A7.** Révoquer **sans motif** est refusé. *Une clé révoquée sans raison ne dit à personne,
      dans six mois, s'il faut la réémettre.*

### B — Le serveur ne devine jamais

- [ ] **B1.** Envoyez `{"reference": "PARA500", "code_masante": "MED000001", ...}` → accepté, et
      **la correspondance est retenue**.
- [ ] **B2.** Renvoyez `{"reference": "PARA500", ...}` **sans le code** → accepté. *C'est la
      promesse du §7.7 : le pharmacien n'a rien à ressaisir.*
- [ ] **B3.** **Le vecteur central** : envoyez `{"reference": "PARACETAMOL-500-COMPRIME"}`, dont
      le libellé ressemble à s'y méprendre à un produit du référentiel → **refusé et nommé**, avec
      « le serveur ne la devine pas ». Aucun relevé, aucune correspondance.
- [ ] **B4.** Un `code_masante` inexistant est refusé **en le citant**, et **ne crée aucune
      correspondance**.

### C — Ce qui est écrit

- [ ] **C1.** Le relevé porte `source = logiciel_officine`, et **aucune** ligne ne porte
      `pharmacie_portail` ni `crowdsource_patient`. *Un relevé ne doit jamais mentir sur d'où il
      vient.*
- [ ] **C2.** Une `quantite` de 0 produit une **rupture** — indisponible, sans prix — et non un
      troisième état.
- [ ] **C3.** Un produit déclaré disponible **sans prix** est refusé.
- [ ] **C4.** Un prix aberrant est refusé **par les bornes du service existant**, que l'ingestion
      n'a pas réécrites. *L'API n'est pas un second chemin d'écriture.*

### D — Ce qui est dit de ce qui a échoué

- [ ] **D1.** Un lot de 3 lignes dont 1 fautive → **2 écrites**, et la fautive **nommée par son
      index et sa référence**. Perdre les 2 valides rendrait l'intégration inutilisable ; les
      accepter en silence la rendrait indigne de confiance.
- [ ] **D2.** `journal_ingestion` porte une ligne par envoi, avec le détail des refus.
- [ ] **D3.** Deux envois portant la **même `Idempotency-Key`** : le second rend `rejeu: true` et
      **n'écrit pas une seconde fois**. Un seul envoi au journal.

### E — Ce qui ne doit PAS avoir bougé

- [ ] **E1.** Le pharmacien saisit toujours ses prix au portail Blade, et ses relevés portent
      `pharmacie_portail`.
- [ ] **E2.** La comparaison de prix citoyenne fonctionne et mélange les provenances comme avant.
- [ ] **E3.** Les zones de P11.0 et P11.1 fonctionnent.

---

### Ce que le G2 live a effectivement montré (2026-08-31, base MySQL réelle)

| | Constaté |
|---|---|
| Pivot | `masante:medicaments:backfill` → **18/18 codes**, `MED000001` = Paracétamol |
| Clé émise | `API-ZM9PRFCSXNDSMLORBW65`, secret affiché **une seule fois** |
| W4 | 2 équivalences déclarées → **2 acceptées** |
| W5 | même référence **sans le code** → **1 acceptée** (promesse du §7.7 tenue) |
| W6 | `PARACETAMOL-500-COMPRIME` → **refusée et nommée**, « le serveur ne la devine pas » |
| W7 | lot de 3 dont 1 fautive → **2 acceptées, 1 refusée nommée par son index** |
| W8/W9/W10 | signature fausse, client inconnu, horodatage d'une heure → **401 au message identique** |
| W11 | rejeu avec la même clé → `rejeu: true`, **6 relevés et non 7**, **5 envois journalisés** |
| W12 | 6 relevés, **tous** en `logiciel_officine` ; quantité 0 → indisponible, sans prix |

Base restaurée ; **les codes nationaux sont conservés** — c'est le prérequis de déploiement.

> **Note de lecture** : en développement, `APP_DEBUG=true` fait accompagner le 401 d'une trace.
> Seul le **message** est générique, et c'est lui qui compte ; en production `APP_DEBUG=false`
> retire la trace.

---

### État des gates

- [x] **G0** — audit : ni Passport ni `league/oauth2`, deux populations d'auth, **pivot vide**
- [x] **G1** — périmètre annoncé (socle + un flux) et tenu
- [x] **G2** — vérifié en direct contre un vrai serveur et un vrai client signant
- [x] **G3** — 19 vecteurs dédiés, mutation **13/13**, Pint, suite complète **1464/1464**
- [x] **G4 propriétaire — validé le 2026-08-31**

### Limites annoncées

1. **Un seul flux** : le stock d'officine. Résultats, ordonnances, commandes sont chacun une
   classe et une ligne de route — mais ils ne sont pas écrits.
2. **Aucun partenaire réel consulté** (ADR-030 le disait déjà). Le contrat est raisonnable, il
   n'est pas éprouvé contre un logiciel de caisse ivoirien.
3. **Pas de flux sortant** : MaSanté reçoit, elle ne notifie pas. Le webhook signé est une
   conception prouvée en P5.4a, mais son code est en Java.
4. **Le secret est recouvrable** (vérifier un HMAC l'exige) : une fuite de la base **et**
   d'`APP_KEY` l'exposerait. La parade est la révocation.
5. **L'établissement ne gère pas ses propres clés** — l'émission est une commande d'exploitation.
6. **Sans le backfill du pivot, l'API refuse tout** — et le dit.

---

## Partie 4 — B1-a : le vrai workflow RDV à deux étapes (CDC_11 §9.1)

> **Périmètre** : nouveau statut `prevalide`, deux permissions distinctes (`rdv.prevalider` à
> l'accueil, `rdv.validate` réservée au médecin), tarif basculé sur le service avec source
> tracée, enum partagé `RendezVousStatut` enfin branché (web + mobile + garde PHP).
> **✅ VALIDÉ (G5, 2026-09-02) — G4 propriétaire OK.**

### Ce qu'il faut savoir avant de commencer

`RendezVousValidationService` ne codait jusqu'ici qu'une seule transition
(`en_attente → confirme|refuse`), et `personnel_accueil`/`medecin` partageaient la même
permission `rdv.validate` pour l'appeler — rien ne distinguait leurs rôles dans le code, malgré
CDC_11 §9.1 (« le médecin fait la validation finale »). B1-a fait entrer une vraie étape
intermédiaire : l'accueil **pré-valide** (`en_attente → prevalide`), le médecin **confirme**
(`prevalide → confirme`). Le refus reste ouvert aux deux, à n'importe laquelle des deux étapes.

Le tarif se déplace du médecin vers **le service** (`services_etablissement.tarif_consultation_cfa`) ;
le repli médecin→structure reste pour les établissements qui n'ont pas encore configuré de tarif
de service, mais la source retenue est désormais tracée sur la facture (`tarif_source`).

### Scénario de bout en bout (portail Next, comptes réels)

1. Connectez-vous avec un compte `personnel_accueil`. Ouvrez un RDV `en_attente` de votre
   service — vous ne voyez qu'un bouton **« Pré-valider »** (et « Refuser »), plus de bouton
   « Confirmer ».
2. Cliquez « Pré-valider », avec un message optionnel pour le médecin. Le RDV passe à
   **« Pré-validé »**. Rechargez la page : le bouton disponible est maintenant « Refuser »
   uniquement (vous n'avez pas `rdv.validate`).
3. Déconnectez-vous, reconnectez-vous avec un compte `medecin` du même service. Ouvrez le même
   RDV : vous voyez **« Confirmer »** (avec date définitive + médecin optionnel + message) et
   « Refuser ».
4. Confirmez. Le RDV passe à **« Confirmé »**.
5. Essayez de rouvrir la fiche avec le compte `personnel_accueil` : plus aucune action n'est
   proposée (« Ce rendez-vous a déjà été traité »).

### Vecteur de régression obligatoire

Un compte `personnel_accueil` qui tente `PATCH /confirmer` directement sur un RDV `en_attente`
doit recevoir **403** (pas 409 — c'est la permission qui manque, pas l'état). Un compte
`medecin` qui tente `PATCH /confirmer` sur un RDV encore `en_attente` (jamais prévalidé) doit
recevoir **409** nommant l'étape manquante.

### Tarif — vérification du montant facturé

Configurez un tarif sur le **service** (portail gestionnaire, écran service). Réservez un RDV
sur ce service sans choisir de médecin. Payez (mobile). Le montant facturé doit être celui du
**service**, pas celui d'un médecin ni le plancher de la structure — vérifiable en base sur
`factures_patient.tarif_source = 'service'`.

### État des gates

- [x] **G0** — audit : aucune pré-validation dans le code malgré le libellé P4 ; permission
      unique partagée par les deux rôles ; enum `@masante/shared` mort (zéro import)
- [x] **G1** — plan validé par le propriétaire le 2026-09-01 (`docs/PLAN_G1_B1_Parcours_RDV.md`)
- [x] **G2** — vérifié en direct (curl contre un serveur dédié, base MySQL dev réelle
      sauvegardée puis restaurée) : 409/403/200/409/200 dans l'ordre attendu, montant du service
      vérifié en base
- [x] **G3** — suite complète **1477/1477**, mutation 3/3 gardes tuées, Pint, typecheck ×3,
      `next lint`, build Next vert, `expo-doctor` 18/18
- [x] **G4 propriétaire — OK (2026-09-02).** ✅ **VALIDÉ G5.**

### Limites annoncées

1. **Fiche RDV enrichie** (photo médecin, référent affiché, triage associable a posteriori) →
   B1-b, pas dans ce lot.
2. **Partage temporaire 30 min + présence temps réel** (Reverb) → B1-c.
3. **Facture/vérification/pont GeniusPay/notification de clôture** → B1-d.
4. Le repli médecin→structure sur le tarif reste actif : un établissement qui n'a configuré
   aucun tarif de service continue de facturer sur l'ancien mécanisme, silencieusement dans son
   comportement (mais tracé dans sa source).

---

## Partie 5 — B1-b : fiche RDV enrichie (photo, référent, tarif visible, triage après coup)

> **Périmètre** : photo du médecin (patron allégé de P6.4c), numéro professionnel exposé au
> patient pour la première fois, médecin référent affiché sur la fiche staff, tarif visible sans
> naviguer + statut « réglé » sur la liste patient, association d'un triage après coup.
> **✅ VALIDÉ (G5, 2026-09-02) — G4 propriétaire OK.**

### Ce qu'il faut savoir avant de commencer

**Défaut trouvé au G0 de B1-b, dans la propre livraison de B1-a** : la vue Blade
`resources/views/portail/rdv/show.blade.php` n'avait jamais été mise à jour pour le workflow à
deux étapes — elle proposait TOUJOURS le formulaire de confirmation dès `en_attente`, alors que
`confirmer()` exige désormais `prevalide`. Un accueil qui suivait l'écran Blade tel quel aurait
reçu un **409** que rien n'expliquait à l'écran. Corrigée dans ce lot, avec des vecteurs qui
exercent cette fois le RENDU de la page (aucun des 46 vecteurs de B1-a ne le faisait).

Le tarif (`RecuRdvService::tarifPour()`) devient public : c'est la MÊME méthode qui sert le
paiement (B1-a) et l'aperçu affiché maintenant sur la fiche — jamais un second calcul.

### Scénario de bout en bout — portail Blade (le défaut corrigé)

1. Connectez-vous avec un compte `personnel_accueil`. Ouvrez un RDV `en_attente`. La fiche montre
   désormais **« Patient »** devant le nom (préfixe), le tarif configuré avec sa source, et le
   référent du patient s'il en a un. Le bloc d'action montre **« Pré-valider (accueil) »**, jamais
   « Confirmer ».
2. Pré-validez. Reconnectez-vous en `medecin`. La fiche montre maintenant **« Confirmer
   (médecin) »**. Sur la liste (`/portail/rendez-vous?statut=prevalide`), le statut affiché est
   **« Pré-validé »** — jamais le mot technique brut `prevalide`.

### Scénario — photo du médecin (portail Blade gestionnaire)

1. Connectez-vous en `gestionnaire_etablissement`. Ouvrez une fiche médecin de votre
   établissement, déposez une photo (JPEG/PNG/WebP réel).
2. Ouvrez `GET /api/v1/medecins/{id}/photo` sans jeton : l'image se charge (diffusion publique).
3. Sur la fiche RDV du patient (mobile, « Mes rendez-vous »), la photo du médecin apparaît à côté
   de son nom, avec son numéro professionnel — deux informations jamais montrées au patient
   avant B1-b.

### Scénario — tarif visible + triage après coup (mobile)

1. Sur « Mes rendez-vous », un RDV réglable affiche désormais le **tarif directement sur la
   carte**, et le bouton « Reçu / paiement » est devenu **« Payer »** (non réglé) ou **« Voir le
   reçu »** (déjà réglé) selon l'état réel.
2. Sur un RDV sans triage associé, un bouton **« Associer un triage »** ouvre la liste des
   triages du membre concerné ; en choisir un l'attache sans recommencer la demande.

### Vecteur de régression obligatoire

Une image de zéro pixel (en-tête PNG valide, dimensions à zéro) doit être refusée par
`PhotoMedecin` — même second crible que P6.4c, qui y avait trouvé un vrai trou. `PATCH
/v1/rendez-vous/{id}/triage` doit refuser (403) un triage n'appartenant pas au compte, même si le
RDV appartient bien au compte qui appelle.

### État des gates

- [x] **G0** — audit : `show.blade.php` jamais mise à jour pour B1-a (défaut réel), `medecins` sans
      photo, `numero_professionnel` non sélectionné par la fiche patient, aucun pont vers
      `ReferentService` depuis le détail RDV, aucun moyen d'associer un triage après coup
- [x] **G1** — couvert par le plan validé le 2026-09-01 (`docs/PLAN_G1_B1_Parcours_RDV.md`, D5/D6/D7)
- [x] **G2** — vérifié en direct (curl/PowerShell contre un `php artisan serve` dédié, base MySQL
      dev réelle sauvegardée puis restaurée compte pour compte) : le défaut de B1-a reproduit puis
      refermé en direct (fiche `en_attente` → « Pré-valider » seul, fiche `prevalide` → « Confirmer »
      seul, onglet → « Pré-validé ») ; photo réelle déposée + diffusée publiquement (200/304/404) +
      fichier truqué refusé (422, photo réelle inchangée) ; référent + tarif exposés par l'API Next ;
      triage associé avec succès puis refusé (403) pour un triage d'un autre compte, vérifié en SQL
- [x] **G3** — suite complète **1510/1510**, 17 400 assertions ; mutation **4/4 gardes tuées**
      (anti-IDOR membre + triage d'`associerTriage()`, blocage RDV clos, second crible photo) ;
      Pint propre (baseline `HEAD` inchangée) ; typecheck ×3 ; `next lint` ; build Next vert ;
      `expo-doctor` 18/18
- [x] **G4 propriétaire — OK (2026-09-02).** ✅ **VALIDÉ G5.**

### Limites annoncées

1. Aucune vérification humaine du contenu de la photo (pas d'antivirus, symétrique de
   `PhotoMembreService`/`ImagesEtablissement`).
2. `motif_orientation`/`message_orientation` sont de l'affichage staff seul : aucune règle métier
   n'en dépend, et rien ne les relie formellement au référent (qui reste une table à part).
3. Partage temporaire 30 min + présence temps réel Reverb → B1-c.
4. Facture/vérification/pont GeniusPay/notification de clôture → B1-d.

## Partie 6 — B1-c : partage temporaire d'accès (30 min) + présence temps réel (Reverb)

> **Périmètre** : le médecin d'un rendez-vous confirmé et enregistré à l'accueil ouvre un accès
> de 30 minutes à son dossier (D8) ; le patient suit l'accès en direct sur son téléphone (D9,
> première utilisation de Reverb dans le projet).
> **✅ VALIDÉ (G5, 2026-09-02) — G4 propriétaire OK.**

### Ce qu'il faut savoir avant de commencer

**« L'accueil désigne le médecin » ne veut PAS dire que l'accueil clique un bouton d'ouverture** :
c'est le compte du **médecin** qui ouvre son propre accès (comme un référent), parce que
`SessionDossierService` porte l'état dans SA session — c'est cette session-là qui doit habiliter
l'écriture ensuite. Le rôle de l'accueil est **antérieur** : mener le RDV à `confirme` (B1-a) et
**enregistrer le patient à son arrivée** (scan du reçu, `checked_in_at` — déjà existant depuis le
Module 4). Sans ce check-in, le bouton d'ouverture est **désactivé** côté médecin.

**Reverb doit tourner** pour tester la présence réellement : `php artisan reverb:start` (port 8085
par défaut, voir `.env`), en plus de `php artisan serve`. Sans lui, l'ouverture/écriture/clôture
fonctionnent quand même (`DiffusionPresence` avale l'échec de diffusion silencieusement — c'est la
garantie testée), seul le suivi en direct ne montre rien.

### Scénario de bout en bout — portail Blade + mobile

1. Menez un RDV jusqu'à `confirme` (B1-a) puis **enregistrez le patient à l'accueil**
   (`/scan/rendez-vous`, scan du QR du reçu — Module 4, inchangé).
2. Reconnectez-vous en `medecin` (celui attribué au RDV). Sur la fiche du RDV, un nouveau bloc
   **« Accès partagé au dossier »** propose **« Ouvrir mon accès (30 min) »**. Avant le check-in,
   ce même bouton est visible mais **désactivé**, avec le message qui l'explique.
3. Ouvrez l'accès : redirection vers le dossier (comme un scan QR classique), badge **« Accès
   ouvert — expire dans 30 min »** sur la fiche RDV au retour.
4. Sur le téléphone du patient (compte titulaire du membre), ouvrez « Mes rendez-vous » → **« Suivre
   en direct »** sur ce RDV. L'écran affiche **« [Nom du médecin] consulte votre dossier »**
   (si Reverb tourne) — jamais un contenu médical.
5. Le médecin écrit un antécédent au carnet (voie `rdv_partage`, désormais autorisée). Le
   téléphone du patient affiche brièvement **« Votre dossier est en cours de mise à jour »** —
   sans nommer la section ni le contenu.
6. Le médecin clique **« Terminer »** (ou attend 30 min). Le téléphone affiche **« Consultation
   terminée »**.

### Vecteurs de régression obligatoires

- Un médecin habilité (`rdv.validate`) mais qui n'est **pas** celui attribué à ce RDV → **404**
  (anti-énumération, pas 403).
- Ouvrir avant le check-in → **409**, message nommant le check-in.
- Une session `rdv_partage` d'un RDV n'affecte JAMAIS la clôture d'un AUTRE RDV.
- Une écriture dont la voie prétend `qr_scan` alors qu'une session `rdv_partage` est réellement
  active ne diffuse **rien** (le mensonge du voie, pas seulement l'absence de session, doit être
  ce qui bloque — vecteur trouvé nécessaire pendant la campagne de mutation).
- `DiffusionPresence` ne doit **jamais** laisser une exception de diffusion remonter jusqu'à
  l'ouverture, l'écriture ou la clôture.

### État des gates

- [x] **G0** — audit : aucun mécanisme de partage RDV-scopé n'existait ; Reverb absent du projet
      (composer.json, config, aucun canal) ; `TypeAccesDossier` à cinq voies des deux côtés
- [x] **G1** — couvert par le plan validé le 2026-09-01 (`docs/PLAN_G1_B1_Parcours_RDV.md`, D8/D9)
- [x] **G2** — vérifié en direct : base MySQL dev réelle sauvegardée, `php artisan reverb:start` +
      `php artisan serve` réels, un abonné WebSocket réel (Node natif, sans bibliothèque Pusher).
      Deux migrations RBAC/schéma rejouées (la base était revenue à son état pré-B1) ; quatre
      refus vérifiés (403 permission, 404 anti-IDOR, 409×2 statut/check-in) ; ouverture réelle
      créant un `AccesDossier` vérifié en base ; les trois événements (`ouvert`/`ecriture`/`ferme`)
      **reçus en direct** par le compte titulaire, sans aucun contenu médical ; un autre compte
      **refusé 403** par le vrai `PusherBroadcaster` sur `/broadcasting/auth` ; Reverb **coupé
      volontairement** pendant qu'une nouvelle ouverture et une nouvelle écriture continuaient de
      réussir, avec les deux `warning` correspondants dans `laravel.log`. **Deux défauts réels
      trouvés uniquement à ce stade** (voir ADR-052 §3/§5) — un second fournisseur Reverb manquant
      (`reverb:start` inutilisable) et l'absence de réponse au `pusher:ping` (déconnexion à 30 s),
      tous deux corrigés avant de rejouer le scénario avec succès. Base restaurée, zéro compte de
      test résiduel.
- [x] **G3** — suite Laravel complète **1529/1529, 17 437 assertions, 0 échec** ; Pint propre ;
      typecheck ×2 (`@masante/shared`, `@masante/mobile`) ; `expo-doctor` 18/18 ; **mutation 8/8
      gardes tuées**, arbre restauré et vérifié octet pour octet (voir ADR-052 §6)
- [x] **G4 propriétaire — OK (2026-09-02).** ✅ **VALIDÉ G5.**

### Limites annoncées

1. Aucune reconnexion automatique du client mobile après un décrochage réseau — le patient rouvre
   l'écran ; la fiche de parcours (P7-D2) reste la source de vérité après coup.
2. Test réel sur téléphone via Ngrok exige un **second tunnel** (Reverb écoute un port différent
   de l'API HTTP) — non automatisé dans ce lot.
3. Le canal ne diffuse rien aux délégués en lecture du carnet familial partagé (P7-A) — décision,
   pas oubli : la présence en direct d'un soignant est plus sensible qu'une lecture de dossier.
4. Multi-intervenants (« autre », un second professionnel scanné pendant le même RDV), facture,
   vérification, pont GeniusPay, notification de clôture → B1-d.
5. **Conséquence de déploiement confirmée en direct** : après une restauration de base (comme à la
   fin de chaque G2 live), les migrations B1 redeviennent `Pending` et le RBAC de B1-a redevient
   l'ancien — `php artisan migrate` puis `PortailRolesSeeder` sont désormais des étapes
   obligatoires avant tout nouveau test réel de ce lot, pas seulement à la première installation.

## Partie 7 — B1-d : clôture du rendez-vous, prévalidateur tracé, notification de fin

> **Périmètre** : le médecin clôt un rendez-vous honoré (D10, `confirme → honore`) sous cinq
> refus indépendants — permission, service géré, statut, patient enregistré à l'accueil, règlement
> acquis ; qui a pré-validé un RDV est désormais tracé, distinct du check-in (D11) ; la fiche de
> parcours d'un membre signale les RDV réglés (D12) ; le patient et sa famille reçoivent une
> notification de clôture (D15). **Dernier sous-incrément de B1 → le lot RDV est complet (a→d).**
> **✅ VALIDÉ (G5, 2026-09-02, suite complète 1546/1546) — G4 propriétaire OK.**

### Ce qu'il faut savoir avant de commencer

**Le plan initial parlait de « générer la facture à la clôture » — ce n'est plus le cas.** Depuis
B1-c, le check-in à l'accueil (`/scan/rendez-vous`) exige un reçu, qui n'existe que si le patient a
déjà payé (`RecuRdvService::payer()`). Le paiement précède donc TOUJOURS le check-in : par le
temps qu'un médecin peut clore un RDV, la facture est déjà `PAYEE`. « Clore » ne génère rien — il
**vérifie** (patient enregistré, règlement acquis) puis marque `honore`.

**Deux boutons distincts sur la fiche RDV, ne pas les confondre** : « Terminer » (bloc « Accès
partagé au dossier », B1-c) referme SEULEMENT l'accès de 30 min du médecin — le RDV reste
`confirme`. « Clore le rendez-vous » (nouveau bloc, en bas de la fiche) marque le RDV `honore` —
action définitive, indépendante de l'accès partagé (un médecin peut refermer son accès sans clore
la consultation, et inversement).

**Multi-intervenants (D13) et pont GeniusPay (D14) ne sont PAS dans ce lot** — voir Limites
ci-dessous pour la raison de chacun, trouvée en implémentant, pas devinée au plan.

### Scénario de bout en bout — portail Blade

1. Menez un RDV jusqu'à `confirme` en repassant par le workflow B1-a (`previsalider` par l'accueil,
   `confirmer` par le médecin) — sur l'écran de pré-validation, remarquez que rien n'affiche encore
   qui a pré-validé : c'est visible en base (`prevalide_par_agent_id`), pas encore à l'écran.
2. Le patient paie (`Payer`, tarif venant du service, B1-b) puis présente son reçu à l'accueil, qui
   l'enregistre (scan, Module 4).
3. Optionnel : le médecin ouvre puis referme son accès partagé (B1-c) — cela alimente la fiche de
   parcours du membre en D12.
4. Reconnecté en médecin, sur la fiche du RDV, faites défiler jusqu'au bloc **« Clore le
   rendez-vous »**. Il propose **« Clore le rendez-vous »** si le règlement est vérifié ; sinon il
   affiche le motif exact du blocage (patient non enregistré, ou règlement non vérifié).
5. Cliquez. Redirection vers la file d'attente avec le message **« Rendez-vous clos. »** ; le
   statut du RDV devient `honore`.
6. Reclique sur « Clore le rendez-vous » (rechargez la fiche) → **409**, message « déjà traité ».
7. Côté patient (mobile), une notification **« Rendez-vous terminé »** est reçue, avec le montant
   déjà réglé — jamais l'établissement ni la spécialité (§2.7).
8. Si vous avez fait l'étape 3, ouvrez la fiche de parcours du membre (portail ou
   `GET /api/v1/membres/{id}/parcours`) : la visite `rdv_partage` porte désormais
   `rendez_vous_verifie: true`.

### Vecteurs de régression obligatoires

- Un compte `personnel_accueil` (permission `rdv.prevalider` seule) qui tente de clore → **403**.
- Un médecin habilité (`rdv.validate`) mais d'un **autre service** que celui du RDV → **404**
  (anti-énumération, pas 403 — même famille que `previsalider()`/`confirmer()`/`refuser()`). **Ce
  défaut était réel** : le contrôleur Blade de `terminer()` avait d'abord omis l'appel à
  `assertPerimetre()` que les trois autres actions font systématiquement — trouvé en relisant le
  contrôleur, pas par un test qui aurait d'abord échoué.
- Un RDV encore `prevalide` (jamais confirmé) → **409**, message nommant la confirmation manquante.
- Un RDV `confirme` et payé mais **jamais enregistré à l'accueil** → **409**, message nommant le
  check-in — cette garde n'était pas prévue au plan initial, trouvée en écrivant les tests : payer
  et être physiquement présent sont deux faits distincts, aucun ne prouve l'autre.
- Une seconde clôture du même RDV → **409** (« déjà honoré »).
- `previsalider()` capture bien un agent **distinct** de celui qui fera le check-in plus tard (deux
  comptes différents dans le scénario réel) — les deux colonnes ne doivent jamais se confondre.
- `rendez_vous_verifie` reste `null` (jamais `false`) sur une visite qui n'est PAS de type
  `rdv_partage`, et sur une visite `rdv_partage` dont le RDV a été supprimé depuis.

### État des gates

- [x] **G0** — audit : `honore` déclaré dans `RendezVousValidationService::STATUTS` depuis B1-a
      mais **aucune transition ne l'atteignait** (clé morte) ; `prevalide_par_agent_id` n'existait
      pas ; `CommissionService::calculerEtEnregistrer()` (cible potentielle de D14) a zéro appelant
      dans tout le dépôt ; le groupe de routes `rendez-vous/*` est gardé par une permission
      qu'aucun rôle de soin ne porte (bloque D13)
- [x] **G1** — couvert par le plan validé le 2026-09-01 (`docs/PLAN_G1_B1_Parcours_RDV.md`,
      D10→D15), avec deux corrections de scope écrites dans ADR-053 §1 (D10/D14 ne tenaient plus
      tels quels) et un ajout (la garde check-in de `terminer()`, absente du plan initial)
- [x] **G2** — vérifié en direct : base MySQL dev réelle sauvegardée, `php artisan serve` réel,
      parcours complet (réservation → prévalidation → confirmation → paiement 7500 FCFA
      `tarif_source=service` → check-in → ouverture/fermeture d'un accès partagé → **clôture**)
      mené par deux comptes portail réels + un patient au jeton Sanctum réel. Base vérifiée
      directement : `statut=honore`, `prevalide_par_agent_id` et `termine_par_agent_id` portant
      chacun le BON agent (deux comptes distincts), `termine_le` posé, `FacturePatient` inchangée.
      Notification reçue avec le corps exact et le bon `facture_patient_id`. `rendez_vous_verifie:
      true` vérifié en direct sur la fiche de parcours du patient. Re-clôture → 409. **Quatre
      refus vérifiés séparément en direct, chacun sur un RDV construit pour isoler SA garde
      seule** : permission → 403, statut → 409, check-in → 409. Base restaurée, migrations B1
      revenues à `Pending` (précédent confirmé une quatrième fois), zéro compte/structure de test
      résiduel vérifié sur les identifiants exacts utilisés (pas un motif large, qui aurait
      accroché des comptes de seed préexistants sans rapport). **Le refus de périmètre (médecin
      d'un autre service) a été trouvé APRÈS cette première session** — dit plutôt que masqué — et
      refermé par une **seconde session G2 live ciblée le même jour** : base sauvegardée une
      seconde fois, deux services et deux médecins réels, RDV confirmé+réglé+enregistré attribué
      au médecin du service B, médecin du service A authentifié réellement refusé **404** sur la
      route réelle, RDV vérifié inchangé en base, base restaurée sans résidu.
- [x] **G3** — suite Laravel complète **1546/1546, 17 469 assertions, 0 échec** ; Pint propre (fixé
      par le fixer lui-même, diff cosmétique vérifié) ; typecheck ×2 (`@masante/shared`,
      `@masante/mobile`) ; `expo-doctor` 18/18 ; **mutation 7/7 gardes tuées**, dont deux survivantes
      corrigées en cours de campagne — élargir le statut accepté par `terminer()` laissait passer un
      vecteur qui 409-ait quand même, mais **pour la mauvaise raison** (la garde de règlement, pas
      celle de statut) ; corrigé en isolant chaque garde et en vérifiant le message exact plutôt
      que le seul code 409, partagé par les gardes de la méthode. **Défaut réel corrigé, trouvé en
      RELECTURE plutôt que par un test rouge** : le contrôleur Blade de `terminer()` omettait
      `assertPerimetre()`, que `previsalider()`/`confirmer()`/`refuser()` appellent systématiquement
      — un médecin d'un autre service aurait pu clore n'importe quel RDV du système. Arbre restauré
      et vérifié ligne pour ligne (voir ADR-053 §2/§3/§4). **Défaut de plus, trouvé dans B1-c (pas
      B1-d) en faisant tourner la suite complète** : `PartageRdvTest` cherchait un id de RDV comme
      sous-chaîne du JSON entier d'une charge diffusée — y compris son horodatage réel — et
      échouait selon la minute d'exécution (~1/60), sans rapport avec une fuite d'identifiant ;
      corrigé en vérifiant les clés de la charge plutôt qu'une sous-chaîne (ADR-053 §3bis).
- [x] **G4 propriétaire — OK (2026-09-02).** ✅ **VALIDÉ G5 — B1 (a→d) COMPLET ET VALIDÉ.**

### Limites annoncées

1. **D13 (multi-intervenants, « autre » professionnel pendant le même RDV) n'est PAS construit** :
   le groupe de routes `rendez-vous/*` est gardé par `rdv.prevalider|rdv.validate`, qu'aucun rôle
   de soin (infirmier, laborantin) ne porte — un infirmier ne peut aujourd'hui même pas OUVRIR la
   fiche d'un RDV, avant toute question de bouton « rejoindre ». L'élargir engagerait une décision
   RBAC (la file entière visible, ou un chemin d'entrée séparé ?) que le plan ne tranche pas.
2. **D14 (pont GeniusPay) n'est PAS construit** : aucune cible réelle. Le webhook Java
   (`PaiementNotificationController`) existe, mais la méthode qu'il pourrait appeler
   (`CommissionService::calculerEtEnregistrer()`) a zéro appelant dans tout le dépôt — le RDV reste
   payé en simulation pure et ne touche jamais ce webhook. Construire une table de correspondance
   pour un lien qui n'a aujourd'hui aucun émetteur serait spéculatif.
3. **`verifie_le`/`verifie_par` (moitié de D11) ne sont PAS construits** : même raison que D14,
   aucun canal fiable n'existe pour les poser autrement que déclarativement — ce que le plan
   interdisait explicitement.
4. Wallet citoyen, calcul automatique §5.4 (interactions médicamenteuses) : hors périmètre de B1
   depuis le G1 initial, non rouverts ici.
5. Aucune UI Next.js pour `terminer()` — la route API existe (parité avec Blade), non consommée
   par le portail Next (précédent B1-c : `rdv_partage` est resté Blade-seul).
