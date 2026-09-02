# Index des guides de test — MASANTÉ

Point d'entrée unique pour retrouver **quel guide teste quel module**.

> **Règle de méthode (CDC_01 §2.4)** : à partir de P6.1, **tout module a son propre guide de test**,
> écrit avant le G4 et conservé après le G5 comme procédure de non-régression. Un module sans guide
> ne peut pas être déclaré validé.

---

## Convention de nommage

```
GUIDE_TEST_<SUJET>.md        à la racine du dépôt
```

Le `<SUJET>` nomme le **domaine**, pas le numéro d'incrément — un domaine peut couvrir plusieurs
incréments successifs, ajoutés en **parties** dans le même fichier plutôt qu'en fichiers nouveaux
(c'est ce qui est fait pour le paiement et la fraude, et qui évite l'éparpillement).

**Structure attendue de chaque guide**, dans cet ordre :
1. Périmètre, et **ce que le module ne fait pas** (les limites, dites honnêtement) ;
2. Prérequis (backend, base, comptes de test, tunnel) ;
3. Scénarios **front** (libellés exacts affichés, jamais d'icônes inventées) ;
4. Scénarios **backend** (curl reproductibles, réponses attendues littérales) ;
5. Invariants **base de données** ;
6. Commandes de qualité (G3) ;
7. **Checklist de clôture** cochable ;
8. **Pièges rencontrés** — ce qui a réellement fait échouer le test la première fois.

---

## Guides existants

| Module | Domaine | Guide | Sections |
|--------|---------|-------|----------|
| **P1** | Identité — RBAC + MFA TOTP | [GUIDE_TEST_G4_P1.md](GUIDE_TEST_G4_P1.md) | A mobile · B web · C checklist |
| **P5** | Paiement (microservice Java) | [GUIDE_TEST_Paiement_G4_P5.md](GUIDE_TEST_Paiement_G4_P5.md) | voir table ci-dessous |
| **CDC_05** | Fraude IA (microservice Python) | [GUIDE_TEST_Fraude_G4.md](GUIDE_TEST_Fraude_G4.md) | §0–6 socle · §7 extraction réelle (A) · §8 routage (B1) · §9 écran admin (B2) |
| **P6.1** | Identifiant National de Santé | [GUIDE_TEST_NIS.md](GUIDE_TEST_NIS.md) | A mobile · B backend · C base · D qualité · E checklist · F pièges |
| **P6.3 / P6.4** | Référentiels nationaux (CDC_09) | [GUIDE_TEST_REFERENTIELS.md](GUIDE_TEST_REFERENTIELS.md) | **Partie 1** socle (§1–8) · **Partie 2** établissements (§2.1–2.8) · **Partie 3** villes et géolocalisation (§3.1–3.8) · **Partie 4** images (§4.1–4.8) · **Partie 5** formulaires du portail (§5.1–5.8) · **Partie 6** bascule des seuils de mesure, L1+L2 (§6.1–6.8) |
| **P6.5** | Professionnels de santé + PKI (CDC_09 §5) | [GUIDE_TEST_PROFESSIONNELS.md](GUIDE_TEST_PROFESSIONNELS.md) | **Partie 1** référentiel professionnel (§1–8) · **Partie 2** PKI et signature électronique (§2.1–2.8) |
| **P6.6** | Référentiel des médicaments (CDC_09 §6) | [GUIDE_TEST_MEDICAMENTS.md](GUIDE_TEST_MEDICAMENTS.md) | **Partie 1** le référentiel (§1.1–1.8) · **Partie 2** lien ordonnance + interactions (§2.1–2.8) |
| **P6.7** | Laboratoires + catalogue des analyses (CDC_09 §7) | [GUIDE_TEST_LABORATOIRES.md](GUIDE_TEST_LABORATOIRES.md) | **Partie 0** ce que contient un référentiel biologique réel · **Partie 1** le catalogue (§1.1–1.8) · **Partie 2** laboratoires et liens d'un résultat (§2.1–2.8) |
| **P6.8** | Référentiels transverses (CDC_09 §8) | [GUIDE_TEST_TRANSVERSES.md](GUIDE_TEST_TRANSVERSES.md) | **Partie 1** spécialités médicales (§1.1–1.9) · **Partie 2** vaccins + calendrier vaccinal (V1–V36, écran mobile) · **Partie 3** maladies (CIM) : gardes du moteur, gouvernance, trois miroirs, antécédent, alerte, portail, mobile · **Partie 4** assurances et organismes agréés (§4.1–4.13) : couvertures cumulables, provenance déclarée, gardes du moteur, gouvernance, deux miroirs, carte CMU dérivée, portail, mobile · **Partie 5** numéros d'urgence nationaux : chaîne à trois niveaux, repli visible, gardes du moteur, gouvernance, portail |
| **P10** | Triage et orientation | [GUIDE_TEST_TRIAGE.md](GUIDE_TEST_TRIAGE.md) | **Partie 1** (P10a) orientation rangée, bascule sur la version publiée, estampille, fiche §5.4 (réponses, hôpitaux proches, QR, mention obligatoire), QR à logo · **Partie 2** (P10b-1) registre des protocoles CDC_08, cycle §6 et quatre validations §7, niveau de triage rendu par le protocole publié, marqueur `{urgence:samu}`, aucun protocole thérapeutique applicable · **Partie 3** (P10b-2) sélecteur par contexte, ordre de priorité §3 et départage des conflits §8, cumul des recommandations non exclusives, interdiction de publier une version que seule la date départagerait, journal d'exécution chaîné §10 · **Partie 4** (P10b-3-i) questionnaire adaptatif §4.3b — les questions deviennent un protocole, arborescence conditionnelle, bornes publiées **opposables** (on refuse, on n'écrête pas), score indépendant du nombre de tours, `triage_reponses` §115 à libellé figé · **Partie 5** (P10b-3-ii) la borne des antécédents quitte le code pour un protocole signé, assemblage des faits à source unique, et **écran §7 de lecture et signature** (règles en français, validation caduque marquée comme telle, quatre-yeux prouvé par son motif) · **Partie 6** (P10c-1) les constantes cliniques du §5.2 entrent dans le triage — **le §1.2 retourné à l'endroit** (`if temperature > 39` existe en donnée signée par les quatre validateurs), aucun fait `constante.*_statut`, le carnet propose et le patient confirme (fenêtre de fraîcheur **par type**), origine décidée par le serveur, et la **cinquième mise en vigueur qui passe en premier** (`seuils_mesure` avant `TRIAGE-NIVEAU`) · **Partie 7** (P10c-2-i) le retour du soignant sur une orientation (lien déclaré jamais déduit, nouvelle entrée du journal append-only) et le socle IA — `services/triage-service` **sans aucune dépendance ML** (503 honnête, aucun repli « règles seules »), disjoncteur à trois états gaté OFF par défaut, jeu d'apprentissage **pseudonymisé** (`jeux_donnees_entrainement`+`validations_medecins`), et un **bug réel de désérialisation `Carbon` inter-processus trouvé par le G2 live**, invisible aux tests (`CACHE_STORE=array` en test masque toute sérialisation) · **Partie 8** (P10c-3-i) l'export anonymisant rend l'anonymisation **effective** (`triage_id` retiré, âge généralisé en bande, date réduite au mois, `k_estime` calculé jamais bloquant), le modèle devient **réel** (XGBoost+SHAP+MLflow, `rappel_sous_triage` suivi à part, refus sous 30 lignes), registre de gouvernance `versions_modeles`/`metriques_modeles` à **quatre-yeux**, et la frontière tenue — `/api/v1/triage/score` répond **toujours 503 même avec un modèle `valide` en base**, vérifié en direct · **Partie 9** (P10c-3-ii) le modèle est **allumé pour de vrai** mais n'influence rien — mode **`observation`**, jamais `hybride` (CDC_08 §3 place l'IA au dernier rang) ; le **registre** décide quel modèle répond et le service refuse d'en servir un autre ; `predictions_ia` **devient une chaîne d'audit** ; les trois faits du §5.5.4 (diagnostic, spécialité, niveau réel) sont **captés**, relus au référentiel et **figés**, avec refus de toute contradiction verdict/niveau ; **frontière vérifiée en direct — rien de l'IA n'atteint le patient** ; puis (lot B) la **comparaison prédiction ⇄ verdict** sur la seule surface administrateur — la montrer au soignant avant son verdict **contaminerait l'étiquette**, et le défaut serait invisible dans les métriques — matrice de confusion §8, rappel `sous_triage` test contre production, et `alertes_drift` (PSI + chute de rappel, **jamais fondus**) en **détection seule** : aucune dérive ne retire un modèle du service |
| **P11** | Applications métier (CDC_11) | [GUIDE_TEST_APPLICATIONS_METIER.md](GUIDE_TEST_APPLICATIONS_METIER.md) | **Partie 1** (P11.0) les portes du portail — **onze rôles, un nom par métier** après réconciliation de trois paires de doublons dont une que le G1 n'avait pas vue (`agent_garde` *est* le personnel d'accueil), les **sept rôles muets depuis P1** reçoivent enfin les permissions de ce qui EXISTE (et rien pour un écran absent — `assurance` en garde zéro, délibérément), `/me` **expose les permissions** sans déplacer l'autorité, et un **registre de zones** remplace la porte unique « tout ce qui n'est pas patient atteint tout » : la garde serveur et la navigation lisent la même ligne, donc un lien ne peut plus mener à une page qui refuse. **Défaut réel corrigé** : depuis P6.5a, le rôle `medecin` portait `qr.scan` et **ne pouvait pas scanner** — une garde par nom de rôle annulait sa permission, sans que rien ne le signale · **Partie 2** (P11.1) l'onboarding **méthode 2** — CDC_11 §3 affirmait que « les deux méthodes sont implémentées », c'était faux depuis P6.4a (limite **M1**, reportée deux fois) : un établissement dépose sa candidature **sans compte ni contact préalable**, la plateforme vérifie son numéro d'autorisation et approuve. **Une candidature n'est pas un établissement** — rien de ce qui est déposé n'atteint l'annuaire que lit un patient avant qu'un humain habilité n'ait approuvé ; l'approbation emprunte **le même chemin de création que la méthode 1** (service extrait avant d'être partagé) ; **l'agent vérifie, il ne ressaisit pas** (seule la catégorie est rectifiable — nom et numéro d'autorisation viennent de la candidature quoi qu'il envoie) ; le suivi public rend **l'état seul, jamais le dossier**. Première application branchée sur le registre de zones de P11.0 : **une ligne ajoutée**, la garde et la navigation ont suivi · **Partie 3** (P11.2) l'**API d'ingestion partenaire** — CDC_11 §7.7 promet que « le pharmacien n'a rien à ressaisir » : son logiciel pousse stock et prix, MaSanté les reçoit. **Troisième population d'authentification** (clé par établissement + signature HMAC sur le **corps brut**, amendement à ADR-030 qui nommait OAuth2), et surtout **le serveur ne devine JAMAIS** une référence produit — le partenaire parle SES codes, l'équivalence se **déclare une fois** et se retient, une référence inconnue est **refusée et nommée** jamais rapprochée par ressemblance. L'API n'est **pas un second chemin d'écriture** : elle appelle le service que le pharmacien utilise au portail, et hérite de ses bornes. Acceptation **partielle** avec rapport nominatif, idempotence, journal append-only. **Deux défauts d'une même racine, invisibles en test** : SQLite contraint bien les ENUM (contrairement à ce que j'avais écrit) et ne plafonne pas les noms d'index à 64 caractères · **Partie 4** (B1-a) le **vrai** workflow RDV à deux étapes (CDC_11 §9.1 littéral : « le médecin fait la validation finale ») — nouveau statut `prevalide`, ENUM élargi jamais réécrit, deux permissions distinctes (`rdv.prevalider` à l'accueil, `rdv.validate` réservée au médecin) là où les deux rôles partageaient la même avant ; le **tarif se déplace vers le service** avec sa source toujours tracée sur la facture (`tarif_source`), jamais un repli muet ; l'enum mort `RendezVousStatut` (`PREVALIDE_SECRETAIRE`, zéro import dans tout le monorepo) est retiré et remplacé par le vrai contrat, enfin consommé par le web ET le mobile au lieu d'être dupliqué trois fois à la main. **G2 live réel** (curl contre un serveur démarré, base MySQL réelle restaurée) : 409 avant prévalidation, 403 pour l'accueil qui tente de confirmer directement, 200 à la prévalidation, 409 en cas de double prévalidation, 200 à la confirmation par le médecin, et le montant facturé **vérifié en base** venant bien du service (`tarif_source='service'`) · **Partie 5** (B1-b) la **fiche RDV enrichie** — photo du médecin (patron allégé de P6.4c, une seule photo, habilitation non revérifiée par le service), numéro professionnel exposé pour la première fois au patient, référent lu via `ReferentService::actif()` (aucun nouveau mécanisme), tarif rendu visible SANS naviguer (même méthode `RecuRdvService::tarifPour()` que le paiement, jamais un second calcul) et statut « réglé » qui remplace le bouton générique « Reçu / paiement » par « Payer »/« Voir le reçu », association d'un triage **après coup** (mêmes vérifications anti-IDOR que la création). **Défaut réel trouvé au G0, dans la propre livraison de B1-a** : la vue Blade `show.blade.php` n'avait jamais suivi le passage au workflow à deux étapes — elle proposait toujours la confirmation dès `en_attente`, un accueil qui la suivait recevait un 409 muet ; corrigé, avec des vecteurs qui exercent cette fois le RENDU et non seulement les actions · **Partie 6** (B1-c) **partage temporaire d'accès (30 min) + présence temps réel** — première utilisation de **Reverb** dans le projet. C'est le compte du **médecin** qui ouvre son propre accès (comme un référent), l'accueil ayant déjà joué son rôle en amenant le RDV à `confirme` et en **enregistrant le patient à son arrivée** (check-in Module 4, réutilisé tel quel comme « désignation »). Quatre refus indépendants (permission, anti-IDOR **404**, statut, check-in). Trois événements diffusés (`ouvert`/`écriture`/`fermé`), **aucun contenu médical**, et une garantie centrale : `DiffusionPresence` **ne casse jamais l'appelant** si Reverb est injoignable (précédent P7-D1, transposé au synchrone). **Défaut réel amont trouvé et contourné sans toucher `vendor/`** : `laravel/reverb` casse toute commande Artisan contre Laravel ^13.8 (nouvelle garde `DevCommands` qui refuse tout enregistrement venu de code `vendor/`) — contournement en répétant l'appel depuis un fournisseur applicatif. Trouvé en chemin : la suite dépassant 1500 tests épuisait le memory_limit CLI par défaut à un point aléatoire — corrigé par une directive `ini` dans `phpunit.xml` · **Partie 7** (B1-d) **clôture du rendez-vous, prévalidateur tracé, notification de fin — dernier sous-incrément, B1 COMPLET (a→d)**. Le G0 a trouvé que **deux des six décisions du plan ne tenaient plus** : depuis B1-c le paiement précède toujours le check-in, donc **la facture n'est plus « générée » à la clôture — elle existe déjà** ; ce qui restait réellement à faire était `honore`, **clé morte depuis B1-a** (déclarée, jamais atteinte). `terminer()` la referme sous **trois gardes** (statut, **check-in — ajoutée en écrivant les tests, absente du plan initial**, règlement), vérifiées contre notre seule source de vérité. **Le pont GeniusPay (D14) est DÉFÉRÉ, sans cible réelle** : le webhook existe, la méthode qu'il pourrait appeler a **zéro appelant dans tout le dépôt**. **Le multi-intervenants (D13) est DÉFÉRÉ pour une raison trouvée en implémentant** : le groupe de routes RDV est gardé par une permission qu'aucun rôle de soin ne porte — un infirmier ne peut même pas ouvrir la fiche, avant toute question de bouton « rejoindre ». `prevalide_par_agent_id` capturé pour la première fois, **distinct** du check-in (deux agents réels dans le scénario G2 live). `rendez_vous_verifie` sur la fiche de parcours, **`null` jamais `false`** hors voie RDV. Notification `RENDEZ_VOUS_TERMINE` — **le nom du plan corrigé** : la facture n'étant plus nouvelle à cet instant, la réutiliser sous le type « facture émise » aurait menti. **Mutation : 9ᵉ occurrence de « le vecteur prouve autre chose »** — élargir le statut accepté laissait un vecteur 409 quand même, mais par la garde de règlement ; corrigé en isolant chaque garde et en vérifiant le message exact |
| **Transverse** | Chaînes d'audit (origine déclarée) | [GUIDE_TEST_CHAINES_AUDIT.md](GUIDE_TEST_CHAINES_AUDIT.md) | Les chaînes de hachage du cœur Laravel — **quatre à l'origine** (référentiels, protocoles, signatures PKI, exécutions §10), **six depuis P10c-3-ii** (prédictions IA, retours cliniques) : une chaîne déclare son origine et **ancre sa tête**, les identifiants de compte cessent d'être des clés étrangères (supprimer un compte est un droit, pas une falsification), et un recommencement se **déclare** au lieu de s'effacer. *Le succès se mesure au fait que deux voyants passent au rouge : ils mentaient.* |
| **P7** | Carnet familial partagé | [GUIDE_TEST_CARNET_FAMILIAL.md](GUIDE_TEST_CARNET_FAMILIAL.md) | **A** partage en lecture · B revendication · C brouillon · D notifications |

### Détail du guide Paiement (13 parties)

| Incrément | Partie | Cas |
|-----------|--------|-----|
| P5.1 Socle + prise en charge CNAM/assurance | A | 1–12 |
| P5.2a Facturation | B | 13–18 |
| P5.2b Avoir, versionnage, signature | C | 19–27 |
| P5.3a Wallet + double écriture | D | 28–34 |
| P5.3b-1 Sécurité wallet (PIN, OTP, limites) | E | — |
| P5.3b-2 Détection de fraude + gel | F | — |
| P5.3b-3 Cashback + bonus | G | — |
| P5.3b-4 Contrôle d'intégrité interne | H | — |
| P5.4a Cartes bancaires | I | 57–67 |
| P5.5a Reversements — socle et relevé | J | 68–74 |
| P5.5b-1 Destinations chiffrées + quatre-yeux | K | 75–81 |
| P5.5b-2 Décaissement (simulé) | L | 82–89 |
| P5.5c Rapprochement factures ↔ reversements | M | 90–93 |
| P5.4b Mandats récurrents + P5.4c notifications | — | 94–104 |

> **Lot 7 — GeniusPay** : plan de test à part, `services/payment/docs/PLAN_TEST_WEBHOOK.md` (W1→W16).
> Il vit dans le dépôt du microservice parce qu'il ne se joue pas comme les autres : il exige un
> tunnel Ngrok, des clés de bac à sable et un webhook enregistré chez un tiers, à recréer à chaque
> session. Le rapprocher des parties A–M aurait laissé croire qu'il se rejoue d'un simple `curl`.

---

## Modules validés sans guide dédié

Ces modules ont été validés G5 **avant** l'instauration de la règle. Leur procédure de test est
consignée dans les fiches mémoire et les ADR, mais pas dans un guide cochable.

| Module | Domaine | Validé | Guide |
|--------|---------|--------|-------|
| **P0** | Socle monorepo, `@masante/shared`, design system | 2026-08-01 | à produire sur demande |
| **P2** | Profil + dossier médical, cache chiffré hors ligne | 2026-08-01 | à produire sur demande |
| **P3** | Annuaire établissements/médecins + carte OSM | 2026-08-01 | à produire sur demande |
| **P4** | Rendez-vous, workflow de validation en deux étapes | 2026-08-01 | à produire sur demande |

> Les scénarios critiques de P2/P3 (mode avion, cache chiffré, dégradation de la carte) sont
> partiellement rejoués par **GUIDE_TEST_NIS.md §A.6** en non-régression.

---

## À venir

| Module | Domaine | Guide prévu |
|--------|---------|-------------|
| **Migration du portail Blade → Next** | Module identifié par ADR-029 : dix-sept zones, où le design moderne se fera **une fois** sur le design system partagé | guide propre |
| **Documents médicaux signés** | Les 5 entités de CDC_10 §4.5, après P6.7 | guide propre |
| **Pharmacovigilance (CDC_09 §6.5)** | Propagation d'un retrait aux pharmacies et aux prescripteurs | **partie 3** de `GUIDE_TEST_MEDICAMENTS.md` |

> **Correction (2026-08-14).** Cette table annonçait P6.5 et P6.6 en « parties de
> `GUIDE_TEST_REFERENTIELS.md` ». Ils ont en fait reçu **leur propre guide** — le domaine du
> référentiel des professionnels et celui des médicaments sont assez larges pour se tenir seuls, et
> les diluer dans le guide des référentiels aurait rendu chacun introuvable. La règle du domaine
> vaut toujours : P6.6b s'est ajouté en **partie 2** du guide des médicaments, pas en fichier neuf.

> **P6.2 (MPI — détection de doublons et fusion, ADR-022) est ABANDONNÉ** : remplacé par le module
> **P7 Carnet familial partagé** (décision propriétaire 2026-08-11). Le NIS rend la fusion largement
> inutile — elle ne réparerait que les doublons nés avant lui, et ce projet n'en a aucun.
> Aucun `GUIDE_TEST_MPI.md` ne sera produit.

**Ce qui a été réellement fait, et pourquoi** : les référentiels d'annuaire assez larges pour se
tenir seuls ont reçu **leur propre guide** (professionnels, médicaments, laboratoires) ; ceux qui
prolongent un guide existant s'y ajoutent en **partie** (établissements dans le guide des
référentiels, lien ordonnance dans celui des médicaments). La règle du domaine est respectée dans
les deux cas — ce qu'elle interdit, c'est un fichier neuf par incrément.
