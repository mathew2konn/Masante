# Plan G1 — P7 / Incrément D : notifications en application + fiche de parcours

> Statut : **G1 VALIDÉ par le propriétaire le 2026-08-11**.
> Prérequis : incréments A, B, C validés G5 le 2026-08-11.
>
> **Trois décisions du propriétaire, au G1 :**
> 1. Transport = **Push Expo**.
> 2. `DOSSIER_CONSULTE` **dans le périmètre** — le scénario de l'accident est livré, et les trois
>    stubs hérités des modules 2 à 5 (scan QR, référent, bris de glace) sont levés.
> 3. Push = **adaptateur complet gaté OFF, « prêt à activer »** ; pas de development build EAS
>    maintenant, le G4 reste sur Expo Go.
> 4. Livraison **en deux temps** : **D1** notifications (celui-ci) → **D2** fiche de parcours.
>
> Le présent document couvre les deux ; la §6 (fiche de parcours) est **différée à D2**.

---

## 1. Ce que D doit réparer

L'incrément C fonctionne mais **personne n'est prévenu**. Le responsable doit penser à ouvrir
« Ajouts à valider ». Dans le scénario du propriétaire, les parents *reçoivent une notification*,
*cliquent*, et tombent sur une *fiche de parcours* qui leur montre le médecin, l'hôpital,
l'ordonnance et le journal d'audit — de quoi vérifier étape par étape avant d'appeler l'auteur et
de valider.

D est ce qui rend C utilisable au lieu de simplement correct.

---

## 2. G0 — ce que l'audit a réellement trouvé

### 2.1 Le « module Notifications » est attendu depuis le Module 2

Six endroits du code écrivent un `Log::info` en attendant, avec le même commentaire :
« *ni Firebase ni passerelle SMS dans le projet : trace applicative, à brancher au module
Notifications* ».

| # | Emplacement | Événement | Dans le périmètre P7 ? |
|---|---|---|---|
| 1 | `Portail\ScanController::scanner` | QR scanné à l'accueil d'un hôpital (CDC §4.3 étape 6, qui dit explicitement « notification push au patient ») | **oui** — c'est le scénario de l'accident |
| 2 | `BrisDeGlaceService` | accès d'urgence, notification décrite comme « IMMÉDIATE » (§5.3) | **oui** — même scénario |
| 3 | `ReferentService::notifierTitulaire` | le médecin référent a consulté le dossier | **oui** — même scénario |
| 4 | `DelegationController::inviter` | invitation de délégation reçue | **oui** — cœur de P7 |
| 5 | `QrController` | un délégué a généré un QR sur mon carnet | oui (peu coûteux, même mécanique) |
| 6 | `DonSangService` | alerte aux donneurs compatibles | **non** — hors P7 |

`AlerteEpidemiqueController` et `PasswordController` portent des stubs comparables : **hors
périmètre**, ils restent en `Log::info`.

### 2.2 Le journal d'audit contient bien de quoi bâtir la fiche de parcours

`acces_dossier` (Module 2, immuable, append-only) porte déjà : `membre_id`, `agent_id`,
`type_acces` (5 voies : `qr_scan` · `referent` · `delegation` · `bris_de_glace` · `admin`),
`motif_urgence`, `sections_consultees` (JSON), `donnees_ajoutees` (JSON), `ip_address`,
`duree_minutes`, `created_at`.

`agent_id` **est renseigné** (repris de la consommation du QR) et pointe un `users.id` qui porte
un `structure_id` → l'établissement est résoluble via `StructureSanitaire`. La fiche de parcours
est donc un **travail d'assemblage**, pas de collecte : rien de nouveau à journaliser.

> Nuance repérée : `agent_id` est `nullable` **sans clé étrangère** (« agents au Module 3 »). La
> résolution du profil devra être défensive — un agent supprimé ne doit pas faire tomber la fiche.

> Nuance repérée : la ligne `acces_dossier` est écrite à la **fermeture** de la session, pas à son
> ouverture. Les notifications d'accès seront donc émises à l'**ouverture**, là où sont déjà les
> stubs, et non depuis le journal.

### 2.3 Il n'existe aucune table de notifications, mais le trait est déjà là

`App\Models\User` utilise `Illuminate\Notifications\Notifiable` depuis P0 — sans que la migration
`notifications` de Laravel ait jamais été publiée. Le système natif est donc **déjà câblé et
inutilisé**.

### 2.4 Expo SDK 54 — vérifié sur la documentation versionnée

- **Le push distant est indisponible dans Expo Go sur Android depuis le SDK 53.** Un *development
  build* est requis. C'est écrit noir sur blanc dans la doc `v54.0.0/sdk/notifications`.
- Les notifications **locales** restent pleinement supportées dans Expo Go.
- `getExpoPushTokenAsync` exige un `projectId` (EAS).
- **Côté serveur, aucune dépendance** : envoyer un push Expo est un `POST` JSON sur
  `https://exp.host/--/api/v2/push/send`, jusqu'à 100 messages par requête, avec des accusés
  (`push tickets` puis `receipts`) et des codes d'erreur exploitables — dont `DeviceNotRegistered`,
  qui commande d'arrêter d'écrire à ce jeton.

**Conséquence directe et non négociable : le push réel ne pourra pas être prouvé au G4 sur Expo Go.**
Voir §7.

---

## 3. Décisions d'architecture

### D1 — Le canal, c'est `via()` de Laravel, pas une table maison

Le système natif de notifications de Laravel *est* le patron « port + adaptateurs » demandé :

```php
public function via(object $notifiable): array
{
    return ['database', CanalPushExpo::class];   // demain : + CanalSms::class
}
```

Gains : `read_at`, `unreadNotifications`, `markAsRead()` gratuits et éprouvés ; **zéro dépendance,
zéro table inventée** ; ajouter le SMS plus tard = une ligne. C'est l'équivalent Laravel de l'Outbox
+ port `EnvoiNotification` validé en P5.4c côté paiement.

### D2 — Une notification ne porte AUCUN contenu médical

Règle posée ici et à ne jamais lever : le corps d'une notification dit **qui, quoi, où cliquer** —
jamais ce qu'il y a dans le dossier.

> ✅ « Aya a proposé un ajout au carnet de Koffi Eli. »
> ❌ « Aya a ajouté : fièvre 39, vue aux urgences. »

Raison : un push s'affiche sur un **écran verrouillé**, visible de n'importe qui dans la pièce, et
son contenu transite par le service Expo. Le fait médical reste dans le dossier, derrière
l'authentification. Cette règle vaut aussi pour la ligne en base : elle sera relue en soutenance.

### D3 — La ligne en base est écrite dans la transaction du fait ; le push part APRÈS le commit

Le canal `database` s'écrit dans la même transaction que la contribution (si elle échoue, aucune
notification fantôme). L'appel HTTP à `exp.host`, lui, part **après commit** : un `exp.host` lent
tiendrait sinon un verrou MySQL ouvert et pourrait faire échouer l'acte métier. Un service externe
n'a jamais le droit de mettre en péril l'écriture du dossier.

### D4 — Le push est gaté OFF par défaut

`config('notifications.push.enabled')`, `false` par défaut. Tant qu'aucun *development build*
n'existe, l'activer n'enverrait rien d'utile. Même vocabulaire que le reste du projet : **« prêt à
activer »**, comme MFA, Keycloak et PostgreSQL.

### D5 — Frontière

Qui est destinataire, quel événement produit quelle notification, quelle fenêtre couvre la fiche de
parcours : **backend seul**. Le mobile affiche une liste, une pastille, et poste « lu ».
Test de fin de module — « quelles règles métier ce module calcule-t-il côté front ? » → **aucune**.

---

## 4. Modèle de données (2 migrations additives)

| Table | Rôle |
|---|---|
| `notifications` | table **native Laravel** (uuid, type, notifiable, `data` JSON, `read_at`) — publiée, pas inventée |
| `appareils_push` | `user_id`, `jeton_expo` (unique), `plateforme`, `derniere_vue_le`, `revoque_le` — un compte peut avoir plusieurs téléphones |
| `notification_envois` | trace du relais push : `notification_id`, `appareil_id`, `statut` (`EN_ATTENTE`→`ENVOYEE`\|`ECHOUEE`), `ticket_id`, `erreur`, `tentatives` |

`notification_envois` n'est pas du zèle : c'est ce qui permettra de répondre « le père a-t-il été
prévenu, oui ou non ? » — et de désactiver un jeton sur `DeviceNotRegistered`.

Aucune table existante n'est modifiée. Aucune des 8 tables du carnet n'est touchée.

---

## 5. Les événements branchés

| Code | Déclencheur | Destinataires |
|---|---|---|
| `CONTRIBUTION_DEPOSEE` | dépôt au brouillon (C) | les décideurs (propriétaire + responsables désignés), **sauf l'auteur** |
| `CONTRIBUTION_VALIDEE` | validation (C) | l'auteur **et les autres décideurs** — c'est le « *Tel responsable a validé l'ajout du carnet de X par Y* » demandé |
| `CONTRIBUTION_REJETEE` | rejet (C) | l'auteur (avec le motif) et les autres décideurs |
| `DELEGATION_RECUE` | invitation (A) | le délégué invité — **remplace le `Log::info`** |
| `RESPONSABLE_DESIGNE` | désignation (C) | le second responsable |
| `DOSSIER_CONSULTE` | scan QR · référent · bris de glace | le propriétaire **et les délégués en lecture** du membre |

`DOSSIER_CONSULTE` est le scénario de l'accident, mot pour mot : *« si un membre fait un accident et
qu'on consulte sa carte vitale, tous les autres le sauront sans même qu'on les appelle »*. Il branche
d'un coup trois stubs vieux de trois modules. Le bris de glace portera un niveau d'urgence distinct.

---

## 6. La fiche de parcours

`GET /contributions/{contribution}/parcours` — assemblage en lecture seule, réservé aux décideurs et
à l'auteur :

1. **La proposition** : section, données proposées, auteur, date.
2. **Le passage à l'hôpital** : les lignes `acces_dossier` du membre dans une fenêtre autour du dépôt
   (**fenêtre = donnée de configuration**, défaut ±1 jour) → agent (nom, prénom, rôle), établissement
   (nom, commune), voie d'accès, motif d'urgence, sections consultées, données ajoutées, durée, IP.
3. **Ce que le médecin a écrit** : les entrées du carnet `source = medecin|structure` créées dans la
   fenêtre — l'ordonnance.
4. **L'historique de décision** : statut, qui a décidé, quand, motif de rejet.

### Une limite qu'il faut dire, pas cacher

**Si l'hôpital n'a jamais scanné le QR, la fiche de parcours sera vide.** Elle ne *prouve* donc
rien à elle seule : c'est un **support à l'appel téléphonique** que le propriétaire décrit, pas un
substitut. L'écran le dira explicitement plutôt que d'afficher un vide ambigu — un vide muet
laisserait croire à une fraude alors qu'il peut simplement s'agir d'un centre de santé sans lecteur.

---

## 7. Mobile

- `app/(app)/notifications.tsx` — liste, non lues distinguées, appui → écran cible.
- **Pastille de non-lues** sur l'Accueil et le Carnet (rafraîchie au focus d'écran, patron
  `useFocusEffect` déjà en place dans les écrans A/B/C).
- `app/(app)/parcours/[contribution].tsx` — la fiche de parcours, atteinte depuis la notification
  comme depuis la file « Ajouts à valider ».
- `src/push/enregistrement.ts` — obtention du jeton Expo, **tolérante à l'échec** : sous Expo Go
  Android elle échouera, l'application ne doit pas broncher.
- `expo-notifications` installé via `npx expo install` (**dépendance approuvée par le propriétaire —
  choix « Push Expo »**). Elle ne casse pas Expo Go : elle s'y dégrade. Distinction importante avec
  MMKV, qui est interdit parce qu'il *casse* Expo Go.
- Correction chirurgicale au passage : `app/(app)/_layout.tsx` ne déclare pas en `href: null` les
  écrans ajoutés en A/B/C (`contributions`, `partager-carnets`, `revendiquer-carnet`,
  `profil-titulaire`) ni `don-sang` — à vérifier au G4, ils risquent d'apparaître dans la barre
  d'onglets.

### Ce que le propriétaire verra vraiment au G4

| Situation | Sous Expo Go, avec ce que D livre |
|---|---|
| Application ouverte | pastille + liste à jour au changement d'écran |
| Application rouverte | à jour immédiatement |
| **Téléphone en poche, application fermée** | **rien** — c'est ce que le push lèvera, et le push exige un development build |

---

## 8. Dettes assumées (à inscrire au `DETTE_TECHNIQUE.md`)

1. **Push réel non prouvé** : impossible sous Expo Go (SDK 53+). Exige un *development build* EAS —
   compte EAS, `projectId`, identifiants de notification, APK installé sur le téléphone. Le canal et
   le relais sont livrés et testables par le serveur ; la livraison au téléphone ne l'est pas.
2. **Pas de temps réel application fermée** tant que le push est OFF.
3. Stubs hors P7 conservés : don du sang, alertes épidémiques, mot de passe.
4. Pas de préférences de notification par type (tout ou rien).
5. Pas de politique de rétention/purge des notifications.
6. Les accusés Expo (`getReceipts`, à relire 15 min après envoi) ne seront pas exploités dans cet
   incrément — seul le *ticket* d'envoi est enregistré.

---

## 9. Preuves prévues

- **G2 live (MySQL)** : chaque événement produit la bonne ligne pour les bons destinataires et pour
  eux seuls ; l'auteur n'est pas notifié de son propre dépôt ; le rejeu ne double pas ; « lu » est
  idempotent ; un compte tiers ne voit pas les notifications d'autrui (anti-IDOR) ; la fiche de
  parcours refuse un non-décideur ; **la ligne en base ne contient aucun contenu médical** (vérifié
  au `SELECT`) ; l'adaptateur push est appelé après commit et son échec ne perd pas la notification.
- **G3** : suite PHPUnit complète verte (362 tests aujourd'hui) + tests dédiés `NotificationTest` et
  `FicheParcoursTest`, écrits **dans les deux sens** ; `tsc --noEmit` sur les 3 workspaces ;
  `expo-doctor` doit rester **18/18** après l'ajout d'`expo-notifications`.
- **G4** : Expo Go SDK 54 via Ngrok, sur le téléphone du propriétaire.
- Guide `GUIDE_TEST_CARNET_FAMILIAL.md` **partie D**, écrit avant le G4.
