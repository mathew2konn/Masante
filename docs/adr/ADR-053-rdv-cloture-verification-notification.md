# ADR-053 — Clôture du rendez-vous, prévalidateur tracé, notification de fin (B1-d)

- **Statut** : **Accepté — B1-d VALIDÉ (G5, 2026-09-02, suite complète 1546/1546).** G4
  propriétaire OK. **Dernier sous-incrément de B1 → le lot RDV COMPLET (a→d) est VALIDÉ G5.**
- **Date** : 2026-09-02
- **Module** : B1-d — quatrième et dernier sous-incrément du lot RDV (suite de B1-a/ADR-050,
  B1-b/ADR-051, B1-c/ADR-052)
- **Corpus** : CDC_11 §9 (RDV) · D10→D15 du plan `docs/PLAN_G1_B1_Parcours_RDV.md`

---

## 1. Contexte, et la correction que le G0 a imposée avant d'écrire une ligne

Le plan (§5) prévoyait six décisions (D10→D15) : clôture+facture, prévalidateur/reçu vérifié,
« RDV vérifiés » dans le journal, multi-intervenants, pont GeniusPay, notification. Le G0 de B1-d
— fait en relisant le code réel de B1-a/b/c, pas le plan écrit la veille — a trouvé que **deux de
ces six ne tenaient plus**, pour une raison structurelle posée par B1-c lui-même : depuis
`RecuRdvService::payer()`, le règlement est **la condition d'existence du reçu**, et le check-in
(`ScanController::checkIn()`) **exige ce reçu**. Le paiement précède donc toujours le check-in, qui
précède toujours l'ouverture d'un accès partagé. Deux conséquences, dites ici plutôt que devinées
en codant :

- **D10 ne peut plus « générer » une facture à la clôture** : elle existe déjà, `PAYEE`, avant
  même que la méthode ne soit atteignable. Ce qui restait réellement à faire — et qui n'existait
  nulle part — c'est `honore` lui-même : **clé morte depuis B1-a** (`RendezVousValidationService::STATUTS`
  la déclare, aucune transition ne l'atteint), précédent exact de l'enum mort `RendezVousStatut`
  qu'ADR-050 avait déjà refermé une fois.
- **D14 (pont GeniusPay) n'a AUCUNE cible réelle** : `PaiementNotificationController` (le webhook
  Java→Laravel) existe, mais `CommissionService::calculerEtEnregistrer()` — la seule méthode qu'il
  pourrait appeler — a **zéro appelant dans tout le dépôt**, y compris son propre TODO qui l'annonce
  depuis le lot 7 GeniusPay. Ce webhook sert un domaine entièrement différent (commission de
  plateforme sur un paiement réel via GeniusPay, jamais branché à `factures_patient`) : le RDV,
  lui, reste payé en **simulation pure** (`RecuRdvService`, `SIM-` préfixe) et ne touche jamais ce
  webhook. Construire une table de correspondance pour un lien qui n'a aujourd'hui aucun émetteur
  serait le « socle à vide » que ce projet refuse depuis P6.3-D3.

**D13 (multi-intervenants) a été examinée, conçue, puis DÉFÉRÉE pour une raison trouvée en
implémentant, pas en lisant le plan** : `Route::middleware('permission:rdv.prevalider|rdv.validate')`
garde **tout** le groupe `rendez-vous/*`, y compris `rdv.show`. Un infirmier ou un laborantin — qui
ne portent que `dossier.ecrire`+`qr.scan` depuis P11.0 — ne peut donc **même pas ouvrir la fiche**
d'un RDV pour y trouver un bouton « rejoindre ». L'ouvrir à `dossier.ecrire` élargirait la visite de
la file d'attente entière (motif, tarif, patient) à un périmètre plus large que celui que D13
demandait, sans que le plan tranche cette question. C'est exactement le type d'ambiguïté que ce
projet renvoie à une décision explicite plutôt que de deviner — nommée en §5, pas laissée dans
l'angle mort.

Le lot se resserre donc sur **D10 (revu), D11 (revu), D12, D15** — quatre décisions concrètes,
sûres, construites sur ce qui existe réellement.

## 2. Décisions

### D10 (revu) — `terminer()` : `confirme → honore`, cinq refus indépendants

`RendezVousValidationService::terminer(User $user, RendezVous $rdv)`. **Cinq refus, aucun ne
rattrape les autres** (même style que `PartageRdvService::ouvrir()`, ADR-052) :

1. permission `rdv.validate` (le médecin, ou le gestionnaire en supervision — même répartition que
   `confirmer()`) → 403.
2. périmètre (`assertPerimetre()`, dans le CONTRÔLEUR — voir le défaut trouvé ci-dessous) : le RDV
   doit relever d'un service géré par l'appelant → 404 (anti-énumération, même famille que
   `previsalider()`/`confirmer()`/`refuser()`).
3. `assertStatut($rdv, ['confirme'], …)` — pas encore confirmé, ou déjà clos → 409.
4. `$rdv->estEnregistre()` — le patient doit être physiquement arrivé (check-in Module 4) → 409.
   **Cette garde n'était PAS dans le plan initial** : ajoutée en écrivant les tests, en réalisant
   que `estRegle()` seul ne suffit pas — un patient peut payer via l'application des jours avant de
   se présenter, et `estEnregistre()` reste `false` jusqu'au scan à l'accueil. Payer et être présent
   sont deux faits distincts ; aucun des deux ne prouve l'autre.
5. `RecuRdvService::estRegle($rdv)` — vérifié contre notre **seule source de vérité aujourd'hui**,
   jamais une passerelle externe qui n'existe pas (D14) → 409. Aucun chemin du portail ne peut
   aujourd'hui produire un `confirme` enregistré-mais-non-réglé (le check-in exige déjà un reçu
   payé) — la garde reste structurelle, pas supposée : un RDV construit hors du flux normal (comme
   les vecteurs qui l'éprouvent) doit rester refusé.

`rdv->update(['statut' => 'honore', 'termine_le' => now(), 'termine_par_agent_id' => $user->id])` —
même style de traçabilité que `checked_in_at`/`checked_in_by_agent_id` (Module 4) : chaque
transition d'état de ce module trace qui, quand.

**DÉFAUT RÉEL TROUVÉ EN RELECTURE, AVANT LE G2 LIVE** : `RendezVousValidationService::terminer()`
ne vérifie PAS le périmètre (services gérés) — c'est délibérément le rôle du **contrôleur**, qui
appelle `assertPerimetre()` avant `previsalider()`/`confirmer()`/`refuser()` (motif établi depuis
B1-a). Le contrôleur Blade de `terminer()`, écrit dans la foulée, avait **omis cet appel** — un
médecin habilité (`rdv.validate`) mais d'un AUTRE service aurait pu clore n'importe quel RDV du
système, pas seulement ceux de son service. Trouvé en relisant le contrôleur contre le patron des
trois autres actions, pas par un test qui aurait échoué — aucun des vecteurs initiaux n'exerçait la
route HTTP, seulement le service directement. Corrigé (`assertPerimetre()` ajouté), et couvert par
un vecteur HTTP réel (`$this->actingAs(…, 'web')->patch(...)`, la seule façon de le prouver puisque
la garde vit dans le contrôleur et non dans le service) — mutation confirmée : retirer l'appel fait
passer le refus de 404 à un 302 de succès.

### D11 (revu) — `prevalide_par_agent_id`, `verifie_le`/`verifie_par` DÉFÉRÉ

`previsalider()` capture désormais `prevalide_par_agent_id` — colonne neuve, **jamais écrite avant
B1-d** : personne ne savait qui avait pré-validé un RDV, seul le check-in (Module 4) était tracé.
Délibérément **distincte** de `checked_in_by_agent_id` : le vecteur G2 live le prouve avec deux
agents différents (l'un pré-valide le matin, l'autre enregistre le soir) et les deux colonnes
restent chacune fidèle à son fait.

`verifie_le`/`verifie_par` sur `FacturePatient`/`RecuRdv` — posés « automatiquement quand le
paiement provient d'un canal fiable » selon le plan — **ne sont pas construits** : voir D14 ci-
dessus, aucun canal fiable n'existe aujourd'hui pour ces tables. Des colonnes que rien n'écrirait
jamais seraient exactement le « socle à vide » que ce projet refuse — nommées ici comme limite,
pas construites en silence pour faire joli sur un schéma.

### D12 — « RDV vérifiés » dans la fiche de parcours

`ServiceFicheParcours::composer()` gagne `rendez_vous_verifie` (`bool|null`) sur chaque visite.
**`null`, jamais `false`, sur les cinq voies qui ne désignent pas un rendez-vous** (précédent
P10c-3-ii, « null n'est pas 0 ») : affirmer `false` dirait « non réglé » là où la question ne se
pose simplement pas. `null` aussi quand le RDV a disparu depuis (`rendez_vous_id` **sans clé
étrangère**, ADR-042 D1 : le journal survit à la suppression du RDV, mais ne peut plus répondre à
sa place). La **même méthode** que le paiement (`RecuRdvService::estRegle()`) tranche — jamais une
seconde façon de répondre à « ce rendez-vous est-il réglé ? ».

### D15 — Notification de clôture, et pourquoi ce n'est pas `facturePatientEmise()` rejouée

`ServiceNotification::rendezVousTermine(RendezVous $rdv, ?FacturePatient $facture)`, type neuf
`RENDEZ_VOUS_TERMINE`. **Le plan nommait `FACTURE_RDV_DISPONIBLE` — corrigé, le mot avant le
mécanisme** (précédent P10c-3-ii) : la facture n'est PAS nouvelle à cet instant, elle existe depuis
le paiement (D10). La réutiliser sous le type `FACTURE_PATIENT_EMISE` mentirait sur ce qui vient de
se passer. `rendezVousTermine()` confirme la fin de la consultation et rappelle le montant déjà
réglé — même garde-fou de contenu que la facturation (§2.7, `verifierContenuFacturation()`
réutilisée, jamais réécrite). Mêmes destinataires que `carnetEnrichi()`/`dossierConsulte()` :
titulaire + délégués en lecture. `$facture` nullable : un très ancien RDV réglé par le seul chemin
legacy (`Paiement` sans `FacturePatient`) reste couvert par le repli documenté d'`estRegle()`, et le
corps reste générique plutôt que d'inventer un montant.

## 3. Ce que la mutation a trouvé — deux fois « le vecteur prouve autre chose »

**Mutation B** : élargir `assertStatut()` à `['confirme', 'prevalide']` a d'abord **survécu**. Le
vecteur créait un RDV `prevalide` (sans le régler), donc même la garde de statut neutralisée, le
même 409 arrivait — par la garde de règlement, pour une raison sans rapport avec ce que le test
prétendait vérifier. 9ᵉ occurrence de cette famille dans ce projet, trouvée cette fois **par la
mutation elle-même**. Corrigé en satisfaisant délibérément les deux AUTRES gardes (check-in +
règlement) pour isoler la garde visée, et en vérifiant le **message exact** plutôt que le seul code
409 — les trois gardes de `terminer()` partagent ce code, un vecteur qui ne lit que le nombre ne
prouve rien de spécifique.

**Mutation F** (D12) : retirer la vérification `type_acces === RDV_PARTAGE` de
`rendezVousVerifie()` a survécu au premier passage — aucune consultation `qr_scan` du fichier de
test ne portait jamais de `rendez_vous_id`, donc la garde `rendez_vous_id === null` seule suffisait
à tous les vecteurs existants. Corrigé par un vecteur qui force un `qr_scan` à porter malgré tout un
`rendez_vous_id` réglé (rien ne l'interdit en base) — seule la voie `rdv_partage` doit répondre à
la question.

## 3bis. Un défaut trouvé dans B1-c en faisant tourner la suite complète, pas dans B1-d

Le premier run complet lancé après restauration a échoué sur
`PartageRdvTest::test_l_evenement_d_ecriture_ne_porte_aucun_contenu_clinique` (B1-c) — un vecteur
qui existait déjà, sans rapport avec le code de B1-d. Le test cherchait « 42 » (l'id de RDV choisi
pour le vecteur) comme sous-chaîne du JSON **entier** de la charge diffusée, **y compris**
`'a' => now()->toIso8601String()` — un horodatage RÉEL. À chaque minute ou seconde `:42`
(~1 exécution sur 60), l'horodatage contenait « 42 » et le test échouait pour une raison **sans
aucun rapport** avec une fuite d'identifiant : un vecteur qui ment selon l'heure à laquelle il
tourne. Trouvé en pratique — l'échec réel est survenu à 20:42. Corrigé en vérifiant les **clés** de
la charge (`array_keys($charge) === ['a']`) plutôt qu'une recherche de sous-chaîne sur un
horodatage vivant — garantie plus forte que l'originale, et non flaky.

## 4. Preuve

**G3** : suite Laravel complète **1546/1546, 17 469 assertions, 0 échec** ; Pint propre sur tous
les fichiers touchés (fixé par le fixer lui-même, diff vérifié cosmétique — `ordered_imports`,
`fully_qualified_strict_types`, espacements) ; `tsc --noEmit` propre pour `@masante/shared` et
`@masante/mobile` ; `expo-doctor` 18/18. **Mutation : 7/7 gardes tuées** (permission, statut,
check-in, règlement et périmètre de `terminer()` ; capture `prevalide_par_agent_id` ; garde
`type_acces` de D12 — 8 vecteurs au total, dont 2 pour la seule capture `prevalide_par_agent_id`),
chaque mutation confirmée appliquée (le test rouge) puis fichier restauré et vérifié **ligne pour
ligne** (`Compare-Object`) contre sa copie pré-mutation.

**G2 live** (base MySQL dev réelle sauvegardée par `mysqldump --routines --triggers`, migrations
B1 rejouées — **conséquence de déploiement retrouvée une quatrième fois** : chaque G2 live restaure
sa propre base, qui annule les migrations et le RBAC des incréments précédents — contre un
`php artisan serve` réel, deux comptes portail réels (médecin, accueil) et un patient au jeton
Sanctum réel) :

- **Parcours complet réel** : réservation (patient) → prévalidation (accueil) → confirmation
  (médecin) → paiement réel (7500 FCFA, `tarif_source=service`) → check-in réel (scan du code du
  reçu) → ouverture ET fermeture d'un accès partagé (B1-c, réutilisé tel quel) → **clôture réelle**
  (`terminer`) → **302**.
- **Base vérifiée directement** : `statut=honore`, `prevalide_par_agent_id` = l'accueil,
  `checked_in_by_agent_id` = le même agent mais sur SA propre colonne, `termine_le` posé,
  `termine_par_agent_id` = le médecin, `FacturePatient.statut=PAYEE` inchangée depuis le paiement.
- **Notification réelle** : `RENDEZ_VOUS_TERMINE` reçue par le patient, corps exact
  « Votre rendez-vous est terminé · 7500 FCFA réglés. », `facture_patient_id` correct.
- **D12 vérifié en direct** : `GET /api/v1/membres/{id}/parcours` (jeton Sanctum réel du patient)
  rend `rendez_vous_verifie: true` sur la visite réelle `rdv_partage`.
- **Re-clôture** → **409** (« déjà honoré »).
- **Quatre refus vérifiés séparément en direct, chacun sur un RDV construit pour isoler SA garde** :
  permission (accueil tente `terminer` malgré `rdv.prevalider` seul) → **403** ; statut (RDV
  `prevalide`, jamais confirmé) → **409** ; check-in (RDV confirmé ET payé, jamais enregistré à
  l'accueil) → **409**. **Le cinquième refus (périmètre) a été trouvé APRÈS cette première session
  de G2 live**, en relisant le contrôleur — dit honnêtement plutôt que glissé sous le tapis, et
  refermé par une **seconde session G2 live ciblée**, tenue le même jour, dès le correctif écrit :
  base sauvegardée une seconde fois, deux services distincts et deux médecins réels créés, un RDV
  confirmé+réglé+enregistré attribué au médecin du service B, et le médecin du service A —
  authentifié réellement, session cookie réelle, CSRF réel — refusé **404** sur
  `PATCH /portail/rendez-vous/{id}/terminer`, RDV vérifié inchangé en base (`confirme`,
  `termine_le` NULL) ; base restaurée, zéro résidu.
- Base restaurée : migrations B1 revenues à `Pending`, **zéro** compte/structure de test résiduel,
  vérifié par requête directe sur les identifiants exacts utilisés (pas seulement un motif large,
  qui aurait accroché deux comptes de seed préexistants sans rapport).

## 5. Ce qui n'est pas dans ce lot, et pourquoi c'est dit plutôt que deviné

- **D13 (multi-intervenants)** : le groupe de routes `rendez-vous/*` est gardé par
  `rdv.prevalider|rdv.validate`, qu'aucun rôle de soin (infirmier, laborantin) ne porte — un
  infirmier ne peut aujourd'hui même pas OUVRIR la fiche d'un RDV, avant toute question de bouton
  « rejoindre ». L'élargir engagerait une décision RBAC (la file entière, ou un chemin d'entrée
  séparé ?) que le plan ne tranche pas ; ce projet ne devine pas ce genre de décision.
- **D14 (pont GeniusPay)** : aucune cible réelle. `CommissionService::calculerEtEnregistrer()` a
  zéro appelant dans tout le dépôt ; le RDV reste payé en simulation pure et ne touche jamais le
  webhook Java. Construire un pont vers un flux qui n'émet jamais rien serait spéculatif.
- **`verifie_le`/`verifie_par`** (moitié de D11) : même raison que D14 — aucun canal fiable
  n'existe pour les poser autrement que déclarativement, ce que le plan interdisait explicitement.
- Wallet citoyen, calcul automatique §5.4 (interactions médicamenteuses) : hors périmètre de B1
  depuis le G1 initial, non rouverts ici.

Voir `docs/PLAN_G1_B1_Parcours_RDV.md` ; guide `GUIDE_TEST_APPLICATIONS_METIER.md` **partie 7**.
