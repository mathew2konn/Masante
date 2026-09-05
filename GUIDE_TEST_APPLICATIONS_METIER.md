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

---

## Partie 8 — B2-a : la consultation, un acte de soin distinct du journal d'accès (CDC_11 §5.2)

> **Périmètre** : le médecin ouvre une **consultation** pendant qu'un dossier lui est ouvert,
> y consigne des **observations**, puis la clôture. C'est le premier morceau de l'étape 5 de
> CDC_11 §12 (« Consultation + diagnostic + prescription électronique »), et il referme un trou
> que **trois modules validés G5 nommaient comme leur propre verrou**.
> **✅ VALIDÉ (G5, 2026-09-03, suite complète 1567/1567) — G4 propriétaire OK.**

### Ce qu'il faut savoir avant de commencer

**Une consultation n'est PAS un accès au dossier, et les deux existent côte à côte.** Le bandeau
du haut (« Dossier ouvert · accès journalisé ») parle de la **fenêtre de lecture** : qui a ouvert
ce dossier, quand, par quelle voie, et combien de temps il reste. La carte « Consultation » parle
de **l'acte de soin** : ce que le médecin fait pendant que la fenêtre est ouverte. Un accès peut
exister sans consultation — une lecture familiale, un accès d'urgence, ou simplement un médecin
qui ouvre puis referme sans rien faire.

**Une session d'accès porte au plus UNE consultation.** Si vous écrivez une ordonnance ET un
antécédent pendant la même fenêtre, ce sont deux actes de la même consultation, pas deux
consultations.

**Le bris de glace ne permet pas de mener une consultation.** Cette voie ouvre le vital minimal
sans le consentement du patient ; y autoriser un acte de soin ferait d'un accès d'exception un
droit de soigner. C'est une décision, pas un oubli.

### Prérequis

1. `php artisan migrate` (la migration `2026_09_02_000004_consultations`).
2. Un compte portail avec le rôle `medecin` **et** la permission `dossier.ecrire`
   (`PortailRolesSeeder` la donne au rôle `medecin`).
3. Ce compte doit être relié à une **fiche de l'annuaire** (`medecins.user_id`) pour que la
   consultation porte son nom professionnel et son établissement — sinon elle portera le nom du
   compte, ce qui est le repli prévu.
4. Un patient dont ce médecin est **référent** (le plus simple pour ouvrir un dossier sans QR).

### Cas à vérifier

| # | Ce que vous faites | Ce qui doit se produire |
|---|---|---|
| 1 | Ouvrir le dossier d'un de vos patients depuis « Mes patients » | La carte **« Consultation »** apparaît, avec un champ « Motif » et un bouton « Ouvrir la consultation » |
| 2 | Ouvrir la consultation avec un motif | La carte affiche « **En cours** », votre nom professionnel, votre établissement, l'heure d'ouverture et le motif |
| 3 | Consigner une observation | Elle apparaît sous la carte, horodatée |
| 4 | Consigner une observation **vide** (que des espaces) | Refus **visible à l'écran** : « Une observation ne peut pas être vide. » — et rien n'est enregistré |
| 5 | Clôturer la consultation | La carte passe à « **Clôturée** », l'heure de clôture s'affiche, le champ d'observation **disparaît** |
| 6 | Recharger la page | La consultation reste clôturée ; aucun bouton ne permet de la rouvrir |
| 7 | Fermer le dossier, le rouvrir (nouvel accès) | Une **nouvelle** carte « Consultation » est proposée : l'ancienne appartenait à l'ancien accès |
| 8 | Ouvrir un dossier par **bris de glace** | La carte « Consultation » **n'apparaît pas** |
| 9 | Se connecter avec un compte sans `dossier.ecrire` | Aucune carte « Consultation » ; et la route refuse (403) même appelée directement |

### Ce qui a été prouvé automatiquement (vous n'avez pas à le refaire)

- **21 vecteurs** dédiés, dont un par garde ; **mutation 6/6 conforme**, avec un témoin
  volontairement vert.
- **G2 live MySQL** : les quatre gardes du moteur (`1644` sur une clôture sans heure, `1644` sur
  une consultation en cours qui en porte une, `1062` sur deux consultations pour un même accès),
  puis le parcours réel au portail par la voie référent.
- Un client qui envoie `membre_id` ou `soignant_nom` en plus du motif : **les deux sont ignorés**,
  la consultation porte le patient de la session et votre nom.
- Le contenu des observations est **chiffré en base** (vérifié en SQL direct).

### Deux défauts réels trouvés pendant ce lot, et ce qu'ils changent pour vous

1. **Le refus d'une observation vide était muet.** Le serveur refusait correctement — la base le
   confirmait — mais l'écran ne disait rien : vous auriez vu votre page recharger sans votre texte
   et sans explication. Corrigé ; c'est le **cas n° 4** ci-dessus qui le vérifie.
2. **Le message s'affichait en anglais** (« The contenu field is required. ») sur un portail
   francophone. Corrigé pour cet écran. **Le défaut est plus large que ce lot** : ce projet n'a pas
   de fichiers de traduction, donc d'autres écrans peuvent encore afficher des messages de
   validation en anglais. C'est signalé, pas corrigé ici.

### Ce que B2-a NE fait PAS — à ne pas chercher

1. **Aucun diagnostic.** Poser un diagnostic est B2-b. La consultation enregistre le motif et les
   observations, elle ne nomme aucune maladie.
2. **Aucune prescription rattachée.** Une ordonnance écrite pendant la consultation est enregistrée
   comme avant, mais elle ne pointe pas encore vers l'acte — c'est B2-c.
3. **Aucune vérification d'allergies ni de contre-indications** (CDC_11 §5.4). Les allergies sont
   aujourd'hui du texte libre : une vérification ne couvrirait que celles saisies après ce lot et
   afficherait « aucune allergie signalée » sur un patient qui en a une, écrite en prose. C'est
   plus dangereux que pas de vérification du tout — donc ce n'est pas fait, et c'est dit.
4. **Aucune transmission en pharmacie** (lot pharmacie).
5. **Aucune aide au diagnostic IA** (§5.3) : CDC_05, et une IA ne décide jamais seule (CDC_00 §4).
6. **Aucun écran mobile** : la consultation est un acte professionnel, elle vit au portail.

---

## Partie 9 — B2-b : le diagnostic posé en consultation (CDC_11 §5.2)

> **Périmètre** : pendant une consultation ouverte, le médecin **pose un diagnostic** — en toutes
> lettres, et facultativement rattaché au référentiel national des maladies — puis peut décider de
> **l'inscrire aux antécédents** du patient. Deuxième sous-incrément du lot B2.
> **✅ VALIDÉ (G5, 2026-09-03, suite complète 1584/1584) — G4 propriétaire OK.**

### Ce qu'il faut savoir avant de commencer

**Un diagnostic n'est PAS un antécédent, et la différence a des conséquences réelles.** Un
antécédent SUIT le patient : il pèse sur le score de ses triages futurs. Un diagnostic DATE de
cette consultation. Si chaque diagnostic devenait un antécédent, une simple grippe pèserait à vie
sur toutes les orientations du patient — on dégraderait l'orientation qu'on cherche à améliorer.

C'est pourquoi **poser un diagnostic ne crée rien dans les antécédents**. L'inscription est un
geste séparé, et **c'est vous qui choisissez le type** (maladie chronique, allergie, chirurgie,
hospitalisation, autre) : décider qu'un diagnostic est « chronique » est un jugement clinique, la
machine ne le pose pas à votre place.

**Le rattachement au référentiel est facultatif, et jamais deviné.** Si vous écrivez « Paludisme »
alors que le référentiel contient « Paludisme », **rien n'est rattaché automatiquement** :
rapprocher un texte d'une entrée serait un diagnostic posé par une machine. Vous choisissez dans la
liste, ou vous laissez « non rattaché ».

### Prérequis

1. `php artisan migrate` (les migrations `consultations` **et** `diagnostics`).
2. Un compte médecin habilité et relié à une fiche de l'annuaire (comme en partie 8).
3. **Pour tester le rattachement** : le référentiel des maladies doit être **en vigueur**. Cela
   suppose `MaladieSeeder`, **puis** `php artisan masante:maladies:backfill` (le seeder seul laisse
   les codes nationaux nuls et la publication est alors refusée), puis une publication par le cycle
   de gouvernance — qui exige **deux agents distincts** (quatre-yeux).
   *Sans cette publication, la liste sera vide : c'est normal, et le cas n° 2 ci-dessous le teste.*

### Cas à vérifier

| # | Ce que vous faites | Ce qui doit se produire |
|---|---|---|
| 1 | Ouvrir une consultation | Un champ « Diagnostic » apparaît, avec une liste « Référentiel national (facultatif) » |
| 2 | **Sans référentiel publié** : poser un diagnostic en laissant « non rattaché » | Il est **accepté**, et s'affiche avec le badge **« hors référentiel »** |
| 3 | Poser un diagnostic **vide** | Refus visible : « Un diagnostic ne peut pas être vide. » |
| 4 | **Référentiel publié** : choisir une maladie dans la liste | Le diagnostic s'affiche avec le **libellé et le code** du référentiel à côté de vos mots |
| 5 | Écrire un libellé **identique** à une entrée du référentiel, sans rien choisir | **Aucun rattachement** — badge « hors référentiel ». Le serveur ne devine pas |
| 6 | Vérifier les antécédents du patient après avoir posé un diagnostic | **Rien n'y a été ajouté** |
| 7 | Cliquer « Inscrire aux antécédents » en choisissant un type | L'antécédent est créé ; le diagnostic affiche « inscrit aux antécédents » |
| 8 | Réessayer l'inscription sur le même diagnostic | Refus : « Ce diagnostic est déjà inscrit aux antécédents. » |
| 9 | Clôturer la consultation | Le champ « Diagnostic » disparaît ; les diagnostics restent affichés |

### Ce qui a été prouvé automatiquement

- Suite complète **1584/1584**, 17 636 assertions ; **17 vecteurs** dédiés (38 avec la partie 8) ; **mutation 6/6**, dont un témoin volontairement vert.
- **G2 live en deux temps** : avant publication du référentiel, zéro option et le diagnostic passe
  quand même en texte libre ; après publication par un quatre-yeux réel, 21 options, rattachement
  effectif, code et libellé figés en base.
- Vérifié en base : le libellé est **chiffré**, la provenance de l'antécédent promu est **réécrite
  par le serveur** (`source = medecin`), et **2 diagnostics n'ont produit qu'1 antécédent**.

### Un défaut réel corrigé au passage — il datait de P6.8c

**Le rattachement d'un antécédent au référentiel ne fonctionnait pas depuis le portail.** Le
formulaire construisait ses options avec un identifiant que la liste publiée ne fournit pas : toutes
les options valaient une valeur vide. Rien ne cassait, rien ne s'affichait en erreur — la
fonctionnalité ne marchait simplement pas. Corrigé à la source, donc **le formulaire d'antécédent
en bénéficie aussi** : si vous testez la section « Antécédents », le rattachement y fonctionne
désormais.

### Ce que B2-b NE fait PAS

1. **Aucune prescription rattachée à la consultation** — c'est B2-c.
2. **Ni « diagnostic principal », ni « certitude »** : le corpus ne les demande pas, et inventer
   une hiérarchie clinique serait une affirmation non sourcée.
3. **Les codes CIM restent vides.** Le diagnostic est codé au sens du référentiel national, pas au
   sens de la CIM — les charger est de la donnée, pas du code.
4. **Le diagnostic de consultation et celui du retour de triage restent deux saisies.** Ce sont deux
   faits différents : l'un dit ce qu'a le patient, l'autre juge une orientation pour l'apprentissage
   de l'IA. Les fondre reviendrait à déduire un jugement.
5. **Aucun écran mobile.**

---

## Partie 10 — B2-c : l'ordonnance désigne son prescripteur (CDC_11 §5.4)

> **Périmètre** : une ordonnance écrite par un soignant **désigne** désormais sa fiche
> professionnelle, son établissement et la consultation qui l'a produite — là où il n'y avait
> qu'un nom en toutes lettres. Dernier sous-incrément du lot B2.
> **✅ VALIDÉ (G5, 2026-09-03, suite complète 1595/1595) — G4 propriétaire OK. Le lot B2 est COMPLET (a, b, c).**

### Ce qu'il faut savoir avant de commencer

**Ce qui change n'est pas visible à l'écran.** `medecin_nom` s'affichait déjà, et il reste affiché
à l'identique. Ce qui change est en dessous : l'ordonnance **pointe** maintenant vers la fiche du
praticien, son établissement et la consultation. C'est ce qui rendra possible, plus tard,
« toutes les ordonnances du D<sup>r</sup> X » ou « ce prescripteur exerce-t-il encore ? ».

**Le point le plus sensible est ce qui ne devait PAS bouger : les signatures.** Une ordonnance
signée porte une empreinte de son contenu. Si les nouveaux liens y entraient, **toute ordonnance
signée avant aujourd'hui deviendrait « altérée »** alors que personne n'y a touché. Ils n'y entrent
pas — et c'est le cas n° 5 ci-dessous qui le vérifie.

### Prérequis

1. `php artisan migrate` (les trois migrations du lot B2).
2. Un compte médecin habilité et **relié à une fiche de l'annuaire** — sans fiche, aucun lien n'est
   posé, et c'est le comportement attendu (cas n° 3).

### Cas à vérifier

| # | Ce que vous faites | Ce qui doit se produire |
|---|---|---|
| 1 | Ouvrir une consultation, puis écrire une ordonnance depuis la section « Ordonnances » | Elle est enregistrée normalement — **rien ne change à l'écran** |
| 2 | Regarder la fiche du patient côté mobile ou API | L'ordonnance s'affiche comme avant, avec le nom du prescripteur |
| 3 | Écrire une ordonnance avec un compte **sans fiche** de l'annuaire | Elle passe quand même. Aucun lien n'est posé, et **rien n'est inventé** |
| 4 | Écrire une ordonnance **hors** consultation (dossier ouvert, aucune consultation) | Elle passe ; le rattachement à la consultation reste vide — une ordonnance vit dans le carnet, pas dans la consultation |
| 5 | **Le cas qui compte** : vérifier une ordonnance **déjà signée** avant aujourd'hui | Elle doit rester **intègre**. Les liens ne font pas partie de ce qui est signé |
| 6 | Modifier un dosage sur une ordonnance signée | Elle doit devenir **altérée** — la signature protège toujours ce qu'elle doit protéger |

### Ce qui a été prouvé automatiquement

- Suite complète **1595/1595**, 17 668 assertions ; **11 vecteurs** dédiés ; **mutation : 4 tueuses + 1 témoin volontairement vert**.
- **G2 live** : ordonnance écrite par le **vrai portail** pendant une consultation réelle, avec des
  identifiants de liens envoyés à `999999` par le client — **tous ignorés**. Les liens posés
  désignent la bonne fiche, le bon établissement, la bonne consultation.
- Vérifié en base : `medecin_nom` porte le nom réécrit par le serveur, jamais celui envoyé.

### Trois défauts de méthode trouvés, et ce qu'ils disent

1. **Un cycle de dépendances évité** : le service de consultation dépend déjà du service
   d'écriture (depuis B2-b) ; l'inverse aurait bouclé.
2. **Un test qui prouvait autre chose** : il vérifiait que le service « repose » les liens face à un
   client malveillant — mais la validation les écarte bien avant, donc ce code n'était jamais
   atteint. Le commentaire qui promettait « deux couches » a été corrigé : il n'y en a qu'une, et
   c'est la validation.
3. **Le test le plus important ne testait pas son propre cas** : il partait d'une ordonnance qui
   portait déjà les liens, donc les reposer ne changeait rien. Une ordonnance d'avant B2-c n'en a
   aucun. Corrigé — et c'est seulement là que la vérification est devenue réelle.

### Ce que B2-c NE fait PAS

1. **Aucune demande d'examen.** Le plan les annonçait ; le G0 a établi qu'une demande d'examen
   ouvre un circuit médecin → laboratoire → résultat (§7.4), qui est un module à part. Les livrer
   ici reviendrait à ouvrir un module dans un autre.
2. **Aucune ligne d'ordonnance structurée** (`ordonnance_lignes`) : elles n'ont de sens qu'avec la
   délivrance en pharmacie, qui n'existe pas.
3. **Les ordonnances anciennes gardent leurs liens vides.** Aucun rattrapage automatique : les
   rattacher supposerait de deviner quel praticien a écrit quoi à partir d'un nom, c'est-à-dire
   d'inventer une donnée.
4. **Rien ne relie encore une ordonnance à sa délivrance.** Le §5.4 décrit
   `Médecin → Patient → Pharmacie` ; seul le premier maillon existe.

---

## Partie 11 — B3-a : servir une ordonnance en officine (CDC_11 §7.1)

> **Périmètre** : un pharmacien reçoit l'ordonnance que le patient lui présente, la sert — en
> totalité ou en partie — et le patient peut repasser chercher le reste. Premier sous-incrément du
> lot **B3 (Pharmacie)**, et il referme le maillon manquant du §5.4 : `Médecin → Patient →
> Pharmacie`.
> **✅ VALIDÉ (G5, 2026-09-03, suite complète 1616/1616) — G4 propriétaire OK.**

### Ce qu'il faut savoir avant de commencer

**Le pharmacien ne voit QUE l'ordonnance.** C'est la décision centrale du lot. Le mécanisme qui
existait (le scan du QR patient) ouvre **tout le carnet** — antécédents, vaccinations, résultats
d'analyses. *Un pharmacien n'a pas à lire les antécédents pour servir une boîte de paracétamol.*

Il accède donc à l'ordonnance par un **code** que le patient lui présente, et **rien d'autre n'est
joignable depuis cet écran**. Ce n'est pas une case qu'on a pensé à cocher : il n'y a pas de porte.

**Une délivrance partielle est le cas normal.** Si la pharmacie n'a que deux médicaments sur trois,
elle sert ce qu'elle a ; le patient repassera chercher le reste, et le système saura ce qui manque.

### Prérequis

1. `php artisan migrate` puis `php artisan db:seed --class=PortailRolesSeeder`
   (la permission `ordonnance.delivrer` est neuve).
2. Un compte au rôle **pharmacien**, rattaché à une structure **de type pharmacie**.
3. Une ordonnance écrite **après** ce lot (voir le cas n° 7 pour les anciennes).

### Cas à vérifier

| # | Ce que vous faites | Ce qui doit se produire |
|---|---|---|
| 1 | Ouvrir « Servir une ordonnance » et saisir un code **inventé** | **404** — page introuvable. Jamais « accès refusé » : un refus confirmerait qu'une ordonnance existe |
| 2 | Saisir le vrai code | L'ordonnance s'affiche avec ses médicaments, et **la mention** que le reste du dossier ne vous est pas accessible |
| 3 | Chercher un lien vers le dossier du patient depuis cet écran | **Il n'y en a aucun** |
| 4 | Servir 8 sur 20 d'un médicament | Enregistré. La colonne « déjà servi » affiche 8, le reste 12 |
| 5 | Essayer d'en servir 13 de plus | Refus **qui nomme le médicament et le reste** : « il ne reste que 12 à servir » |
| 6 | Servir les 12 restants | Accepté. La ligne passe à « servi » et ne propose plus de champ |
| 7 | Ouvrir une ordonnance écrite **avant** ce lot | Elle s'affiche, mais **ne peut pas être servie** — et l'écran l'explique |
| 8 | Se connecter avec un compte d'un **laboratoire** ayant la permission | Refus : « une ordonnance ne se sert que dans une pharmacie » |

### Ce qui a été prouvé automatiquement

- Suite complète **1616/1616**, 17 717 assertions ; **21 vecteurs** dédiés ; **mutation : 6 tueuses + 1 témoin volontairement vert**.
- **G2 live** : le parcours complet ci-dessus contre un serveur réel — 404 sur code inventé,
  délivrance partielle, refus du dépassement, complément accepté, **total exact en base** (20 sur
  20, deux délivrances et non trois).
- **Le vecteur central vérifié en réel** : après tout le parcours, **zéro ligne** dans le journal
  d'accès au dossier. Aucun dossier n'a été ouvert, parce qu'aucun ne pouvait l'être.

### Ce que B3-a NE fait PAS — et le premier point compte

1. **Aucune vérification de stock.** Rien n'empêche aujourd'hui d'enregistrer la délivrance d'un
   médicament que l'officine n'a pas : le système note ce que le pharmacien **déclare** avoir servi,
   il ne le confronte à aucun inventaire. C'est **B3-b**.
2. **Aucune vérification de contre-indications.** Les allergies sont du texte libre : une
   vérification partielle afficherait « aucune contre-indication » sur un patient qui en a une.
3. **Les interactions sont consultables, pas calculées.** Elles s'affichent si le référentiel en
   déclare entre les médicaments prescrits — c'est une information, pas une décision.
4. **Les ordonnances antérieures ne sont pas servables** (cas n° 7). On ne fabrique pas
   rétroactivement des lignes que personne n'a vérifiées.
5. **Aucune traçabilité nationale** (§7.6) : la trace de délivrance suit l'ordonnance, et le patient
   reste maître de son carnet. Le registre qui doit survivre est **B3-c**.
6. **Aucun écran mobile** : le patient présente son code, il ne sert pas lui-même.

---

## Partie 12 — B3-b : le stock réel de l'officine (CDC_11 §7.3 et §7.5)

> **Périmètre** : une pharmacie tient enfin un vrai inventaire — entrées, sorties, péremptions,
> seuil d'alerte — et une délivrance en sort automatiquement. L'écran qui s'appelait « stock » sans
> en gérer un est renommé. Deuxième sous-incrément du lot **B3**.
> **✅ VALIDÉ G5 le 2026-09-03 — G4 propriétaire OK.**

### Ce qu'il faut savoir avant de commencer

**Deux écrans différents, et c'est tout l'objet du renommage.**

| Écran | Ce qu'il fait |
|---|---|
| **Mes prix** (avant : « Prix & stock ») | déclare un **prix** ou une **rupture** au comparateur public |
| **Mon stock** (nouveau) | tient l'**inventaire** : entrées, sorties, péremptions, seuils |

Le premier existait déjà et **n'a pas changé de comportement** — seul son nom a changé, parce qu'il
faisait chercher la gestion de stock au mauvais endroit.

**Le stock est une somme, pas une case à corriger.** Vous n'écrivez jamais « j'ai 40 boîtes » : vous
enregistrez une entrée de 40. Si vous constatez un écart, vous faites un **ajustement**, qui reste
visible dans l'historique. C'est ce qui rend l'inventaire vérifiable.

**Le signe est déduit de la nature du mouvement.** Vous saisissez toujours une quantité positive :
une entrée ajoute, une sortie retire. Seul l'ajustement accepte un nombre négatif.

### Prérequis

`php artisan migrate`. Un compte pharmacien rattaché à une structure de type **pharmacie**.

### Cas à vérifier

| # | Ce que vous faites | Ce qui doit se produire |
|---|---|---|
| 1 | Ouvrir « Mon stock » et ajouter un produit | Il apparaît avec **0 en rayon** |
| 2 | Enregistrer une entrée de 40 | Le stock passe à 40 |
| 3 | Enregistrer une sortie de 10 | Le stock passe à 30 — et l'historique garde les **deux** mouvements |
| 4 | Tenter une sortie de 100 | Refus **qui nomme le produit et le stock réel** |
| 5 | Fixer un prix et un seuil de 20, puis descendre sous 20 | Un bandeau **« sous le seuil d'alerte »** apparaît |
| 6 | Fixer un prix, puis regarder le comparateur public | Le prix y figure — l'inventaire **alimente** le relevé |
| 7 | Vider le stock à 0 | Le comparateur passe en **rupture**, sans que vous ayez à le déclarer |
| 8 | Enregistrer une entrée avec un lot et une date de péremption proche | Elle apparaît dans **« lots proches de la péremption »** |
| 9 | Servir une ordonnance (partie 11) pour un produit que vous tenez | Le stock **diminue tout seul** |
| 10 | Servir une ordonnance pour un produit **absent** de votre inventaire | La délivrance passe quand même — elle ne se heurte pas à un stock que vous ne tenez pas |

### Ce qui a été prouvé automatiquement

- **22 vecteurs** dédiés ; **mutation : 6 tueuses + 1 témoin volontairement vert**.
- Un mouvement **ne se modifie ni ne s'efface** — refusé par le code **et** par la base.
- L'article d'un confrère répond **404**, jamais « accès refusé » : un refus dirait ce qu'une autre
  officine tient en rayon.

### Ce qui a été rejoué en direct sur la vraie base (G2)

Sur la base MySQL de développement, avec le vrai portail et trois comptes réels — un pharmacien, un
confrère d'une autre officine, et un agent d'accueil **rattaché à la même officine mais non
habilité**. La base a été **restaurée à l'identique** ensuite.

- Les quatre refus de la base : entrée négative, quantité nulle, modification, suppression.
- Le parcours entier des cas 1 à 8, y compris la sortie saisie **`10`** qui s'enregistre **`-10`**.
- **Le comparateur suit tout seul** : à zéro il passe en rupture, à la réapprovisionnement il
  redevient disponible avec son prix — sans que le pharmacien ait rien déclaré.
- L'article d'un **confrère réel** (pas un numéro inventé) répond **404**, son stock ne bouge pas, et
  il n'apparaît pas dans l'inventaire.
- L'agent d'accueil non habilité reçoit **403**, sur l'inventaire comme sur la délivrance.
- Une ordonnance de deux produits servie en une fois : celui qui est **en rayon** sort tout seul
  (25 → 19), celui qui **n'est pas tenu** est servi quand même — et aucun article fantôme n'est créé.

### Un défaut que seul le G2 pouvait montrer

Le message affiché quand la base refuse d'effacer un mouvement disait « ne se **efface** pas ». En
corrigeant cette faute de français, la migration a cessé de pouvoir se rejouer : l'apostrophe de
« s'efface » refermait la chaîne SQL dans laquelle ce texte est écrit. Elle est désormais doublée.

Aucun des 22 vecteurs ne pouvait le voir — ils vérifient qu'un refus se produit, pas le texte que la
base emploie pour le dire.

### Deux erreurs de ma part, dites plutôt que tues

**Le G0 affirmait qu'aucun test ne couvrait l'écran renommé. C'était faux.** Un test existait et
l'appelait par son **adresse écrite en toutes lettres**, que ma recherche — portant sur le nom de la
route — ne pouvait pas trouver. Le renommage l'a cassé, et c'est la suite complète qui l'a rattrapé.
Le test a été mis à jour pour suivre la nouvelle adresse, sans rien perdre de ce qu'il vérifiait.

### Une erreur de diagnostic, dite plutôt que tue

Après avoir branché la délivrance sur le stock, un test est passé de 11 secondes à plus de 4
minutes, et j'ai d'abord accusé ce branchement. Après vérification, **le code n'était pas en
cause** : plusieurs exécutions de tests tournaient en parallèle et se gênaient. Le même test, seul,
prend 3 secondes.

### Ce que B3-b NE fait PAS

1. **Aucun code-barres, aucune traçabilité nationale** (§7.6) — c'est B3-c.
2. **Ni photo, ni TVA** sur l'article : le corpus les nomme, mais la TVA n'a aucun usage (il n'y a
   pas de facturation en officine) et la photo appartiendrait au produit national.
3. **Le stock n'est pas suivi lot par lot.** Les lots servent aux alertes de péremption ; le stock
   courant reste global. Un vrai « premier périmé, premier sorti » supposerait d'imputer chaque
   sortie à un lot — non fait.
4. **Aucun panier ni commande** — c'est B3-d.

---

## Partie 13 — B3-c : code-barres et traçabilité nationale (CDC_11 §7.6)

> **Périmètre** : chaque médicament peut porter un code-barres, un pharmacien peut vérifier une
> boîte au comptoir, et chaque délivrance alimente un **registre national** qui survit même si le
> patient supprime l'ordonnance de son carnet. Troisième sous-incrément du lot **B3 (Pharmacie)**,
> qui achève le **§7 (Application Pharmacien)** — le §9.5 « achat d'un médicament », côté patient,
> reste à **B3-d**.
> **✅ VALIDÉ G5 le 2026-09-04 — G4 propriétaire OK.**

### Ce qu'il faut savoir avant de commencer

**Le §7.6 du corpus tient en une phrase** : lutte contre les médicaments falsifiés, suivi de
consommation, statistiques nationales. Il fallait donc **concevoir**, pas seulement transcrire — et
une règle a guidé tout le lot : chaque élément livré doit servir l'une de ces trois finalités.

**Le registre national ne contient AUCUNE information sur le patient.** Ni nom, ni dossier, ni
ordonnance, ni posologie. C'est délibéré : ce registre doit **survivre** à la suppression de
l'ordonnance qui l'a produit (le patient reste maître de son carnet), et une trace qui survit ne
peut donc jamais avoir été un dossier médical déguisé. Il dit seulement : quel produit, combien,
quand, dans quelle officine.

**Un code-barres reconnu n'est pas une preuve d'authenticité.** Un faussaire recopie un code-barres
sans effort. Le scan dit « ce code est connu du référentiel » — jamais « cette boîte est
authentique ». Un code inconnu **n'empêche jamais** de servir le patient.

**Le champ de scan EST le lecteur de comptoir.** Un lecteur de codes-barres branché en USB se
comporte comme un clavier : il tape le code puis appuie sur Entrée. Un simple champ de texte le
reçoit — pas de caméra, pas de connexion internet nécessaire.

### Prérequis

1. `php artisan migrate` (colonne `code_barres` sur les médicaments + nouvelle table du registre).
2. Un compte porteur de la permission **référentiel des médicaments** pour saisir un code-barres.
3. Un compte **pharmacien** pour servir une ordonnance et scanner au comptoir (partie 11).
4. Un compte porteur des **statistiques globales** pour voir l'écran de consommation.

### Cas à vérifier

| # | Ce que vous faites | Ce qui doit se produire |
|---|---|---|
| 1 | Sur la fiche d'un médicament, saisir un code-barres **inventé au hasard** (ex. `123456789`) | Refus **qui nomme la raison** : ce n'est pas un code-barres valide |
| 2 | Saisir un vrai code-barres (ex. celui d'un produit que vous avez chez vous) | Accepté et enregistré |
| 3 | Servir une ordonnance (partie 11) et scanner ce code-barres au comptoir | **« Connu du référentiel »**, avec le nom du produit |
| 4 | Scanner un code-barres qui n'a jamais été saisi | **« Inconnu du référentiel »** — et vous pouvez servir l'ordonnance quand même |
| 5 | Servir une ordonnance de deux médicaments | Enregistré normalement, comme avant ce lot |
| 6 | Supprimer cette ordonnance depuis le carnet du patient | Elle disparaît, ainsi que la trace de délivrance — mais **rien ne change** dans les statistiques nationales |
| 7 | Ouvrir l'écran de statistiques globales | Un bloc **« Consommation de médicaments »** : par produit, un compteur des dispensations **non rattachées** au référentiel, et la couverture en codes-barres (« X / Y produits ») |
| 8 | Essayer de saisir un code-barres **sans** la permission dédiée | Refus |

### Ce qui a été prouvé automatiquement

- **38 vecteurs dédiés** (14 sur le calcul de la clé de contrôle en isolation, 24 sur le reste) ;
  **mutation : 7 tueuses + 1 témoin volontairement vert**.
- **Deux vecteurs centraux, aucun ne suffisant seul** : supprimer l'ordonnance laisse la trace
  intacte ; et la trace, cherchée dans toute sa charge, ne contient ni le nom du patient, ni son
  identifiant, ni la posologie.
- Le registre est **append-only** : ni modifiable ni effaçable, refusé par le code **et** par la
  base elle-même, y compris contre un accès qui contournerait l'application.
- Un même code-barres est accepté dans deux pays différents (deux pays, deux catalogues), refusé en
  doublon dans le même pays.

### Ce qui a été rejoué en direct sur la vraie base (G2)

Sur la base MySQL de développement, avec un serveur réel, de vrais comptes, une vraie session
connectée et un vrai jeton de sécurité de formulaire (CSRF) — **pas un raccourci technique**. La
base a été sauvegardée avant, puis **restaurée à l'identique** ensuite.

- Les trois refus de la base : modification, suppression, quantité nulle — chacun avec son message
  exact. Le doublon de code-barres dans le même pays, refusé lui aussi par la base.
- Saisie d'un code-barres invalide au vrai formulaire → refus affiché à l'écran, rien enregistré.
- Saisie d'un code-barres valide → enregistré, et **l'empreinte du référentiel national a changé** —
  la preuve que renseigner un code-barres compte comme une vraie mise à jour du référentiel.
- Une ordonnance réelle de deux médicaments (l'un rattaché au référentiel, l'autre non) scannée puis
  servie au vrai comptoir : le scan a reconnu le premier produit et signalé le second comme inconnu
  sans bloquer ; la délivrance a créé **deux traces**, vérifiées colonne par colonne en base.
- **Le vecteur central vérifié en réel** : zéro ligne créée dans le journal d'accès au dossier par
  cette délivrance.
- L'ordonnance supprimée pour de vrai : elle et sa délivrance ont disparu, **les deux traces sont
  restées identiques**, colonne par colonne.
- L'écran de statistiques, ouvert avec un vrai compte : la consommation du produit servi, le
  compteur des dispensations non rattachées, et la couverture en codes-barres du référentiel —
  tous les trois exacts.

### Un défaut trouvé par un vecteur, corrigé avant le G2

Le lien entre une trace et le médicament avait d'abord été prévu comme un lien strict vers la fiche
du produit — de sorte que supprimer un médicament du référentiel effacerait automatiquement ce lien
sur ses traces. Un vecteur a montré que cela **empêchait purement et simplement** de supprimer un
médicament : le registre national, qui refuse toute modification, refusait aussi celle que cette
suppression aurait déclenchée en arrière-plan. Corrigé : ce lien est désormais un simple numéro de
référence, comme celui qui relie une trace à l'officine — les informations qui doivent survivre
(nom, code, dosage) sont de toute façon déjà recopiées sur la trace elle-même.

### Ce que B3-c NE fait PAS — et le premier point compte

1. **La lutte contre les médicaments falsifiés n'est qu'à moitié servie.** Le scan détecte un code
   inconnu ; il ne prouve jamais qu'une boîte reconnue est authentique. Une vraie preuve
   d'authenticité supposerait un dispositif national de numérotation unitaire — hors périmètre.
2. **Aucun code-barres réel n'est préchargé.** La colonne naît vide, et l'écran le dit — tant que
   personne ne les saisit, le scan ne reconnaît rien.
3. **Aucun scan par caméra sur le mobile** — seul le champ de saisie existe, pensé pour un lecteur
   de comptoir.
4. **Pas de suivi par lot sur le registre national** : B3-b ne suit pas les lots un par un, donc un
   rappel de lot n'est pas réalisable à partir de ce registre.
5. **La lecture du code-barres au comptoir n'est pas soumise à la même gouvernance que sa saisie** :
   la saisie exige une permission et un formulaire dédiés, la lecture consulte directement la fiche
   du produit — pour qu'un code-barres tout juste saisi soit reconnaissable immédiatement, sans
   attendre une republication du référentiel.

---

## Partie 15 — B4-a : le canal de paiement en ligne réel (GeniusPay)

> **Périmètre** : ce lot ne touche **aucun écran**. Il branche Laravel sur le microservice de
> paiement Java pour qu'un paiement réel via GeniusPay (compte marchand de l'établissement) puisse
> déclencher une commission MaSanté correctement calculée. Les écrans qui utiliseront ce canal
> (rendez-vous, commande de médicaments) sont les incréments suivants (B4-b, B3-d).
> **✅ VALIDÉ G5 le 2026-09-04 — G4 propriétaire OK.**

### Ce qu'il faut savoir avant de commencer

**Ce lot referme un blocage qui datait du lot 6.** Depuis, une commission MaSanté sur un paiement en
ligne n'était jamais calculée, faute d'un identifiant d'établissement dans la notification que le
service de paiement envoie à Laravel. Le champ existait en réalité côté paiement, mais restait
vide faute d'émetteur — Laravel n'ouvrait encore aucun paiement chez GeniusPay. Ce lot fait de
Laravel cet émetteur.

**Une commission n'est calculée QUE sur un vrai paiement GeniusPay réussi.** Un paiement par carte
ou par mobile money, même réussi, ne déclenche rien ici — seul le canal GeniusPay le fait. C'est
volontaire : aucune décision de facturer une commission sur les autres moyens de paiement n'a été
prise.

**La disponibilité du paiement en ligne dépend de l'établissement, pas d'un interrupteur général.**
Un établissement doit avoir un identifiant national **et** un compte marchand déclaré chez
GeniusPay pour que le paiement en ligne lui soit proposé. Il n'existe **aucun bouton** pour éteindre
le paiement en ligne globalement — le seul recours, en cas de problème, est de retirer le compte
marchand de l'établissement concerné chez GeniusPay.

**Ce test se fait sans écran, par des appels signés.** Ce lot ne livre aucune interface : il se
vérifie en appelant directement les points d'entrée techniques, avec un « principal signé »
(l'équivalent d'un jeton d'accès entre les deux services). Si vous n'êtes pas familier avec cette
mécanique, demandez la démonstration plutôt que de la reproduire vous-même.

### Prérequis

1. Le microservice de paiement démarré réellement (`docker compose up -d` dans
   `services/payment`).
2. Laravel démarré réellement (`artisan serve --host=0.0.0.0 --port=8000`), et non le serveur WAMP
   habituel — les deux services doivent pouvoir s'appeler l'un l'autre.
3. Un établissement portant un identifiant national (le backfill de P6.4a doit avoir été rejoué).
4. `BaremesCommissionSeeder` joué — sans lui, tout paiement en ligne réel échoue **bruyamment**
   (c'est le comportement voulu, mais il faut le savoir avant de s'inquiéter).

### Ce qui a été vérifié, et comment

Ce lot n'a pas de « cas à cliquer » comme les précédents. Ce qui suit **a été réellement fait** au
G2 et au G4, pas seulement décrit :

| # | Ce qui a été vérifié | Ce qui s'est produit |
|---|---|---|
| 1 | Interroger si un établissement peut encaisser en ligne, avant tout dépôt de secret | « Non configuré » |
| 2 | Déposer le secret webhook de l'établissement | Accepté, jamais renvoyé en clair |
| 3 | Réinterroger le même établissement | « Configuré » — et un second appel immédiat est **servi depuis un cache**, sans repartir vers le microservice |
| 4 | Ouvrir un vrai checkout GeniusPay, en bac à sable, pour une facture réelle | Une vraie page de paiement, avec sa propre adresse |
| 5 | Recevoir le signal (« webhook ») annonçant que ce paiement a réussi | Le microservice vérifie sa signature, accepte le paiement |
| 6 | Attendre que la notification parte automatiquement vers Laravel (elle n'est pas immédiate) | Laravel la reçoit et la vérifie |
| 7 | Vérifier la commission calculée | Le bon établissement, le bon montant, le bon taux, le net exact |
| 8 | Refaire le même essai avec un paiement par carte au lieu de GeniusPay | **Aucune commission** |
| 9 | Refaire le même essai avec un établissement inexistant | **Aucune commission**, refus journalisé nommant l'établissement |
| 10 | Renvoyer exactement la même notification une seconde fois, avec un montant différent | **Aucune seconde commission** ; le montant enregistré reste celui du premier envoi |

### Ce qui a été prouvé automatiquement

- Suite Java complète verte, suite Laravel complète verte (**1702 tests, 17 882 assertions, 0
  échec**).
- **Campagne de mutation : 7 tueuses + 1 témoin volontairement vert** sur les gardes qui décident
  quand une commission se calcule (le bon canal, le bon statut, l'établissement résolu, les frais
  inconnus honnêtement inscrits, le rejeu sans double calcul, le pays qui distingue deux
  établissements, le secret manquant qui refuse de signer).

### Ce qui a été rejoué en direct sur la vraie base (G2)

Sur la base MySQL de développement, avec les deux services **réellement démarrés** — pas de
simulation à l'intérieur du test.

- Un établissement réel, un compte marchand réellement enregistré, un secret webhook réellement
  déposé.
- **Deux paiements GeniusPay réellement ouverts en bac à sable**, avec une vraie adresse de
  paiement. Un troisième essai, sous une charge réseau inhabituelle de ce poste, est resté « en
  attente de confirmation » — le système a **refusé de réessayer tout seul** plutôt que de risquer
  un double débit : c'est le comportement voulu.
- Deux signaux de paiement réel envoyés, avec la vraie signature du prestataire, réellement
  vérifiés.
- La notification automatique du microservice vers Laravel réellement reçue et vérifiée — sans
  intervention manuelle pour la déclencher.
- **Une commission réelle enregistrée en base** : montant du paiement 18 000 FCFA, taux de 2,50 %,
  commission de 450 FCFA, net de 17 350 FCFA pour l'établissement — l'arithmétique exacte.
- Les trois refus (canal carte, établissement inconnu, renvoi en double) vérifiés en réel, chacun
  sans effet sur la base.

### Deux défauts trouvés par ce test, invisibles aux vecteurs automatiques

**Un barème de commission manquant.** La base de développement réelle n'avait aucun palier de
commission enregistré — le premier essai a donc échoué **bruyamment**, avec un message clair, et
rien n'a été écrit. Corrigé en jouant le semis des barèmes. C'est le comportement voulu : une
commission calculée à l'aveugle, sans barème, aurait été pire que ce refus.

**Une mise à jour de la base oubliée.** Une colonne neuve, ajoutée par ce lot pour dire si les frais
étaient connus ou non, n'avait jamais été appliquée à la vraie base de développement — seulement à
la base de test, qui repart toujours neuve. Le premier essai a donc échoué avec un message
technique clair (« colonne introuvable »), rien n'a été écrit, et la mise à jour manquante a été
appliquée. **C'est précisément pour attraper ce genre d'oubli que ce test se fait sur la vraie
base**, jamais seulement sur les tests automatisés.

### Ce que ce lot NE fait PAS

1. **Aucun écran ne consomme ce canal.** Ni le rendez-vous, ni la pharmacie ne l'utilisent encore —
   c'est l'objet des lots suivants (B4-b, B3-d).
2. **Les frais ne sont pas toujours connus.** Quand le microservice ne les connaît pas au moment du
   paiement, la commission est calculée avec des frais à zéro **explicitement inscrits comme
   inconnus** — jamais présentés comme une vraie valeur de zéro.
3. **Aucun remboursement.**
4. **Aucun interrupteur général pour éteindre le paiement en ligne.** Le seul recours est de
   retirer le compte marchand d'un établissement précis chez GeniusPay.
5. **Aucun écran pour enregistrer un compte marchand** : cela se fait en appelant directement le
   microservice.

---

## Partie 16 — B4-b : payer un rendez-vous en ligne (GeniusPay)

**Statut : G3 fait, G2 live fait et réel. En attente de votre propre test (G4).**

### Ce qu'il faut savoir avant de commencer

**Le paiement simulé n'a pas disparu.** Sur l'écran de paiement d'un rendez-vous, vous verrez
toujours les modes habituels (mobile money, espèces, carte) — ils fonctionnent exactement comme
avant. Un second bouton, « Payer en ligne (GeniusPay) », apparaît **en plus**, uniquement si
l'établissement du rendez-vous est réellement équipé pour encaisser en ligne. S'il ne l'est pas,
ce bouton n'apparaît simplement pas — rien ne casse, rien ne dit d'erreur.

**Le paiement en ligne prend deux temps, et c'est normal.** Appuyer sur « Payer en ligne » ouvre
votre navigateur sur une vraie page GeniusPay — vous y saisiriez normalement une carte ou un
numéro mobile money. Revenu dans l'application, le reçu n'apparaît **pas immédiatement** : il
faut appuyer sur « Actualiser ». Ce délai n'est pas un bug : le système attend la confirmation
réelle du prestataire avant de considérer quoi que ce soit comme payé — jamais votre simple retour
dans l'application.

**Aucune donnée de carte ne transite par MaSanté.** Vous la saisissez uniquement sur la page
GeniusPay, dans votre navigateur, où vous pouvez voir l'adresse et le cadenas — jamais dans une
fenêtre intégrée à l'application.

### Pour tester vous-même, avec un vrai téléphone

1. Prenez un rendez-vous (n'importe quel établissement).
2. Sur l'écran de paiement, si un second bouton « Payer en ligne (GeniusPay) » apparaît sous les
   modes habituels, l'établissement est équipé — sinon, seul le paiement habituel est proposé, et
   c'est le comportement attendu pour la plupart des établissements aujourd'hui.
3. Si le bouton est là : appuyez, complétez (ou annulez) le paiement dans le navigateur qui
   s'ouvre, revenez dans l'application, appuyez sur « Actualiser ».
4. Si le paiement a réellement abouti chez GeniusPay, le reçu apparaît avec le mode « geniuspay » —
   exactement comme pour les autres modes, avec le même QR de check-in.

### Ce qui a été rejoué en direct sur la vraie base (G2), avec un vrai paiement GeniusPay

Sur la base MySQL de développement, avec le microservice de paiement et Laravel **réellement
démarrés**, contre l'établissement déjà équipé lors du test précédent (partie 15) :

- Un vrai rendez-vous, un vrai patient, un vrai compte connecté.
- **« Payer en ligne » a réellement ouvert un checkout GeniusPay en bac à sable**, avec sa propre
  adresse de paiement.
- **Une vraie facture a été créée côté service de paiement** (ce que la partie 15 n'avait pas
  encore besoin de faire) — c'est cette facture précise que le service de paiement solde quand le
  paiement réussit.
- **Le signal annonçant le succès du paiement a été réellement envoyé, signé, et vérifié**, avec
  les vrais frais du prestataire (150 FCFA sur 12 000, pas un frais fictif à zéro).
- **La facture du service de paiement a réellement été soldée**, et **la notification vers
  Laravel est réellement partie et a été reçue**.
- **Le rendez-vous est passé « réglé »**, un reçu réel a été créé avec le mode « geniuspay », et
  la même commission déjà vérifiée en partie 15 s'est déclenchée automatiquement (elle se
  déclenche sur tout paiement GeniusPay réussi, y compris pour un rendez-vous — dit pour que ce ne
  soit pas une surprise).
- **Renvoyer deux fois le même signal de succès n'a rien dédoublé** : ni un second reçu, ni une
  seconde commission.
- **Retenter de payer un rendez-vous déjà réglé a été refusé**, avec un message clair.

### Ce qui a été prouvé automatiquement (G3)

- Suite Laravel complète verte (**1732 tests, 17 949 assertions, 0 échec**).
- **Campagne de mutation : 9 tueuses + 1 témoin volontairement vert** sur les gardes de ce lot (le
  rendez-vous déjà réglé, l'établissement non équipé, le refus du prestataire relayé tel quel, la
  réutilisation de la même facture au lieu d'en fabriquer une seconde, la confirmation qui ne
  double jamais un règlement, et — trouvaille de ce lot précisément — la réutilisation de la
  facture créée côté service de paiement).

### Un défaut trouvé en relisant le code, avant même ce test

En comparant ce lot au fonctionnement réel du service de paiement, il est apparu qu'annoncer un
succès sans qu'une vraie facture existe côté service de paiement aurait fait échouer, en silence,
tout le règlement du premier paiement réel — **avant même que la notification ne parte vers
Laravel**. Corrigé avant ce test : une vraie facture est désormais créée au moment où le paiement
en ligne s'ouvre, et réutilisée si vous retentez sans avoir terminé.

### Ce que ce lot NE fait PAS

1. **Aucune expiration automatique** d'un paiement en ligne commencé puis jamais terminé : il reste
   disponible indéfiniment, vous pouvez retenter.
2. **Aucun remboursement.**
3. **Le seuil minimal du prestataire (5 000 FCFA)** n'est pas dupliqué côté MaSanté : sous ce
   montant, le bouton reste affiché, et un message du prestataire lui-même l'explique.
4. **Le portail (personnel de l'établissement) affiche seulement** qu'un paiement en ligne est en
   attente — aucune action n'y est possible, le règlement ne devient réel que par la confirmation
   du prestataire.
