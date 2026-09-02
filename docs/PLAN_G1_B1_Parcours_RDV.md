# PLAN G1 — B1 : refonte du parcours Rendez-vous (CDC_11 §9)

- **Statut** : G1 — en attente de validation écrite du propriétaire.
- **Date** : 2026-09-01
- **Module** : suite de P11 (Applications métier), premier lot de fond après P11.0/P11.1/P11.2 (G5)
- **Corpus** : CDC_11 §9 (RDV), §9.1 (« le médecin fait la validation finale »), CDC_03 §8 (Outbox), CDC_08 (triage — lien déjà posé), ADR-047 (registre de zones), ADR-042 (identifiants de journal)

---

## 0. Ce que le G0 a établi (audit factuel, aucune supposition)

- `RendezVousValidationService` (`services/api/app/Services/RendezVousValidationService.php`) ne code **qu'une seule transition** : `en_attente → confirme|refuse`. Malgré le libellé « workflow deux étapes complet » du P4 validé G5 (2026-08-01), **aucune pré-validation n'existe dans le code**.
- `personnel_accueil` et `medecin` portent aujourd'hui **exactement la même permission** `rdv.validate` et **le même service** — rien ne distingue leurs rôles dans le circuit.
- Le tarif est porté par `medecins.tarif_consultation` (principal) avec repli sur `structures_sanitaires.tarif_min_cfa` (`RecuRdvService::montantPour()`). **`services_etablissement` n'a jamais eu de colonne tarif.**
- `@masante/shared::RendezVousStatut` (7 valeurs, dont `PREVALIDE_SECRETAIRE`) est une **clé totalement morte** — zéro import dans tout le monorepo. Le vrai contrat (5 valeurs) est dupliqué **indépendamment trois fois** : PHP (`RendezVousValidationService::STATUTS`), web (`apps/web/src/lib/rdv-types.ts`), mobile (`apps/mobile/src/types/structure.ts`).
- `rendez_vous.triage_id` **existe déjà** depuis la création de la table (2026-06-20) et est déjà écrit à la réservation, déjà lu par le staff. Rien à créer sur ce point.
- Le mécanisme le plus proche d'un « partage temporaire d'accès à un médecin désigné » est **`ReferentService::ouvrir()` + `SessionDossierService`** (voie 2, Module 5) : session de 30 minutes, deux lignes de journal `acces_dossier` (ouverture/clôture), déjà exactement la durée demandée. Mais ce patron est conçu pour un référent **permanent** qui s'auto-ouvre une session — pas pour un partage **ponctuel, initié par l'accueil, vers un médecin désigné pour CE RDV**.
- Le **médecin référent** (nom, numéro, structure) existe déjà en base, table `referents` (Module 5), avec révocation tracée et **un seul actif par membre**. Ce n'est pas à réinventer.
- **Trois systèmes de facturation coexistent** : `paiements`+`recus_rdv` (legacy simulé), `FacturePatient` (source de vérité actuelle, immuable une fois `PAYEE`), et le microservice paiement Java (isolé — le webhook retour est **inerte** pour la commission RDV car son payload ne porte pas `structure_sanitaire_id`).
- **Aucun pont n'existe** entre `acces_dossier`/`ServiceFicheParcours` (journal d'accès, fiche de parcours P7-D2) et `rendez_vous`/`recus_rdv` — « RDV vérifiés » est à construire entièrement, pas à retrouver.
- **Reverb n'est installé nulle part** (composer.json, config, package.json web/mobile) — confirmé par recherche exhaustive.
- `medecins` n'a **aucune colonne photo**. `numero_professionnel` est aujourd'hui exposé **seulement au portail Blade**, jamais à l'API mobile/patient.

---

## 1. Découpage (quatre sous-incréments, dépendances imposées par l'ordre)

**B1-a** Workflow à deux étapes + tarif au service + enum partagé enfin branché
**B1-b** Fiche RDV enrichie (profil médecin complet, référent, triage, tarif)
**B1-c** Partage temporaire d'accès (30 min) vers un médecin désigné + présence temps réel (Reverb)
**B1-d** Facture, vérification, « RDV vérifiés », pont GeniusPay, notification de clôture

L'ordre est contraint : b affiche le tarif que a vient de poser ; c a besoin de la fiche enrichie de b pour désigner un médecin ; d clôture ce que c ouvre.

---

## 2. B1-a — Workflow à deux étapes + tarif au service + enum partagé

### D1 — Nouveau statut `prevalide`, ENUM élargi jamais réécrit
Précédent : élargissement d'ENUM sans jamais convertir les lignes historiques (P6.4a, P10b-1 `niveau`). `rendez_vous.statut` gagne `prevalide` entre `en_attente` et `confirme`. Les RDV déjà `confirme`/`refuse`/etc. ne bougent pas.

Transitions redéfinies dans `RendezVousValidationService` :
- `previsalider()` : `en_attente → prevalide`, permission **`rdv.prevalider`** (neuve).
- `confirmer()` : accepté **seulement** depuis `prevalide` (plus depuis `en_attente`), permission `rdv.validate` (inchangée dans son nom, réservée à l'étape finale).
- `refuser()` : accepté depuis `en_attente` **ou** `prevalide` (l'accueil peut refuser d'emblée, le médecin peut refuser au dernier moment).
- Annulation patient (`RendezVousController::annuler`) : ajoute `prevalide` à la liste des statuts annulables (déjà `en_attente`/`confirme`).

### D2 — Répartition RBAC (referme réellement la dette P6.5a/P11.0)
- `personnel_accueil` : **perd `rdv.validate`**, reçoit **`rdv.prevalider`**. C'est la correction littérale de CDC_11 §9.1 (« le médecin fait la validation finale ») — jusqu'ici c'était l'inverse qui était vrai en pratique (l'accueil confirmait, pas le médecin).
- `medecin` : garde `rdv.validate` seul (déjà accordé en P11.0, désormais réellement significatif).
- `gestionnaire_etablissement` : garde **les deux** permissions (supervision/remplacement, cohérent avec son rôle transversal existant).

### D3 — Tarif porté par le service, sans repli bruyant destructeur
`services_etablissement.tarif_consultation_cfa` (nullable, migration additive) devient la **source première**. `RecuRdvService::montantPour()` :
```
tarif = service.tarif_consultation_cfa ?? medecin.tarif_consultation ?? structure.tarif_min_cfa
```
Le repli reste (un refus bruyant casserait tous les établissements dont le service n'a pas encore de tarif configuré — aucune donnée de migration ne le permettrait honnêtement). Mais **la source est désormais enregistrée** (`tarif_source` : `service`|`medecin`|`structure`) sur le reçu/la facture — précédent `delai_source` (P6.7b), `provenance` (P6.8d) : *un montant ne doit jamais mentir sur d'où il vient*.

### D4 — L'enum partagé mort est retiré et remplacé par le vrai contrat
`RendezVousStatut` (7 valeurs, `PREVALIDE_SECRETAIRE`) sort de `@masante/shared`. Il est remplacé par le contrat réel à **6 valeurs** (`en_attente|prevalide|confirme|refuse|annule|honore`), consommé cette fois par les trois sites qui le dupliquaient (PHP via une constante dérivée pour éviter la divergence, `apps/web/src/lib/rdv-types.ts`, `apps/mobile/src/types/structure.ts`). Garde anti-divergence dans l'esprit de `PermissionsSourceUniqueTest`/`NisVecteursPartagesTest` (P11.0/P6.1) : un test casse le build si un des trois consommateurs diverge.

---

## 3. B1-b — Fiche RDV enrichie

### D5 — Médecin attribué / médecin choisi, profil complet
Distinction déjà amorcée côté mobile (`mode_attribution`) → généralisée avec :
- **Photo** : `medecins` gagne `photo_uuid`/`photo_mime`/`photo_empreinte_sha256` (nullable) — patron **allégé** de P6.4c (empreinte SHA-256, `finfo`+`getimagesize` réel, UUID sur disque, URL relative + ETag, liste blanche sans HEIC) mais **sans table séparée** ni quota : un médecin n'a qu'**une** photo, pas une galerie. **Hors projection gouvernée P6.5a** (même famille que biographie/tarif/contacts — n'engage aucune autorité).
- **Numéro professionnel** : exposé pour la première fois en **lecture seule** à l'API mobile/web (jusqu'ici Blade uniquement). Aucune règle du corpus ne l'interdit ; c'est de la transparence patient, pas une donnée sensible au sens CDC_00 §4.

### D6 — Préfixe « Patient », référent, triage
- Préfixe « Patient » devant le nom sur la fiche staff — affichage seul.
- **Médecin référent** : lu via `ReferentService::actif($membre)` (table `referents`, existante) — **aucun nouveau mécanisme de référent**. `rendez_vous` gagne deux colonnes facultatives et propres au RDV : `motif_orientation`/`message_orientation` (nullable, saisies par le patient à la réservation s'il le souhaite) — distinctes du référent, qui reste porté par sa table dédiée.
- Bouton « associer un triage » : le lien existe déjà en base (`triage_id`) ; ajoute un `PATCH` dédié pour l'associer après coup, avec les mêmes vérifications anti-IDOR que `store()`.

### D7 — Tarif affiché, bouton « reçu » retiré
Affichage direct du tarif (avec sa source) sur la fiche ; le bouton « reçu » séparé disparaît (le tarif et la facture deviennent visibles directement sur la fiche RDV).

---

## 4. B1-c — Partage temporaire d'accès (30 min) + présence temps réel

### D8 — Nouvelle voie d'accès `rdv_partage`
`TypeAccesDossier` (backend) et `packages/shared/src/enums/index.ts` (déjà consommé, contrairement à `RendezVousStatut`) gagnent une sixième voie : `rdv_partage`, aux côtés de `qr_scan`/`referent`/`delegation`/`bris_de_glace`/`admin`. Miroir exact du patron `ReferentService::ouvrir()` + `SessionDossierService` (durée déjà **30 minutes**, réutilisée telle quelle — aucune nouvelle constante), mais :
- initiée par `personnel_accueil` qui désigne/scanne le médecin pour **ce RDV précis** (jamais permanente, contrairement au référent) ;
- l'écriture au dossier passe par le chemin **existant** `EcritureSoignantService`/`dossier.ecrire` — **aucun second chemin d'écriture** (principe absolu du projet, rappelé à chaque incrément touchant le carnet depuis P7-D0) ; le médecin partagé doit porter `dossier.ecrire` (déjà accordé au rôle `medecin` depuis P6.5a) ;
- clôture explicite par le médecin (« terminer ») → `SessionDossierService::fermer()` réutilisé tel quel, ou expiration automatique à 30 minutes.

### D9 — Présence temps réel via Reverb
Première utilisation de Reverb dans le projet (approuvé, jamais installé) : canal privé scopé au RDV (`private-rdv.{id}.presence`), autorisation stricte au seul titulaire/patient concerné. Un événement est diffusé à chaque écriture réussie côté `EcritureSoignantService` pendant une session `rdv_partage` — **jamais de contenu médical dans l'événement** (règle inviolable posée en P7-D1 pour les notifications, transposée ici à un canal encore plus exposé qu'un push).

---

## 5. B1-d — Facture, vérification, journal, paiement

### D10 — Clôture et facture
À la fermeture de la session `rdv_partage` (ou à la clôture globale du RDV), complète/génère la `FacturePatient` déjà liée au RDV (source de vérité confirmée au G0), avec le tarif et sa source posés en B1-a.

### D11 — Agent d'accueil, reçu vérifié
`rendez_vous.checked_in_by_agent_id` capture le check-in physique (flux **délibérément cloisonné**, confirmé au G0) — distinct du prévalidateur. Ajout d'un champ dédié `prevalide_par_agent_id` pour ne pas confondre les deux. `RecuRdv`/`FacturePatient` gagnent `verifie_le`/`verifie_par` — posés automatiquement quand le paiement provient d'un canal fiable (webhook GeniusPay signé, précédent P5.6b), jamais déclaratifs.

### D12 — « RDV vérifiés » dans le journal d'accès
Le G0 confirme qu'aucun pont n'existe. Nouveau bloc dans `ServiceFicheParcours` (ou onglet dédié) listant les RDV dont le reçu est vérifié, relié à la visite correspondante par `acces_ouverture_id` quand l'accès est de type `rdv_partage`.

### D13 — Multi-intervenants (« autre »)
Bouton « autre » pour scanner un profil supplémentaire (ex. infirmier après le médecin) : réutilise B1-c avec un `type_acces` par intervenant, toutes les sessions rattachées au même RDV.

### D14 — Pont vers GeniusPay (Java) — recommandation
Le webhook retour Java est aujourd'hui **inerte** pour la commission RDV (aucun `structure_sanitaire_id` dans son payload). Deux options :
1. Enrichir l'appel sortant Laravel→Java pour transporter l'identifiant en métadonnée (touche un contrat Java stable, P5.6b validé G5).
2. Table de correspondance côté Laravel (paiement Java ↔ RDV), motif `correspondances_partenaire` (P11.2) — n'exige aucune modification du microservice Java.
**Recommandation : option 2**, moins invasive, ne rouvre aucun module Java déjà G5.

### D15 — Notification de clôture
À « terminer » (RDV totalement clos), notification `FACTURE_RDV_DISPONIBLE` via le canal Outbox existant (P5.4c/D1), montant et lien vers la facture — **sans contenu médical** (règle P7-D1).

---

## 6. Ce qui n'est PAS dans ce lot (limites assumées, pour ne rien laisser deviner)

- Le calcul automatique §5.4 (interactions médicamenteuses, etc.) reste hors périmètre — dépend du moteur CDC_05 non construit (B2, discuté séparément).
- Wallet citoyen : abandonné (B3, décision propriétaire actée).
- Aucune zone Blade migrée par ce lot au-delà de ce que B1 touche directement.

---

## 7. Preuve attendue (G2/G3/G4)

Même standard que tout le reste du projet : vecteurs dédiés par décision (D1→D15), mutation testing sur chaque garde neuve, G2 live MySQL avec scénario bout-en-bout (réservation → prévalidation → partage 30 min avec présence Reverb réelle → écriture → clôture → facture vérifiée → notification), suite complète verte, Pint/typecheck/lint/build.
