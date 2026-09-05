# ADR-058 — Le circuit du laboratoire : le prélèvement (B5-b)

**Statut : Accepté — B5-b VALIDÉ (G5, 2026-09-05).** G4 propriétaire OK (« G4 validé, c'est pour
le G5 »). Suite complète **1826/1826**, 18 263 assertions.
Contexte : CDC_11 §8.1 (étape 9 de §12), CDC_09 §7.4, CDC_04 §109 · Plan G1 : [`plan.md`
PLAN 4 §12](../../plan.md) · Suite de [ADR-057](ADR-057-circuit-laboratoire.md) (B5-a).

---

## 1. Contexte

B5-a a refermé K5/K11 et livré la demande d'examen, avec son jeton posé mais **rien pour le
consommer**. B5-b livre ce lecteur : le laboratoire, le prélèvement, son étiquette imprimable et
son cycle. Trois des seize décisions du plan G1 restent partiellement ou entièrement hors de ce
lot : L7 n'est livrée qu'à moitié (`analyse.executer`, pas `analyse.valider`), L10/L14/L15
(automates, notification, saisie-et-import des résultats) appartiennent en totalité à B5-c, qui
seul construit un résultat.

---

## 2. Décisions (B5-b)

### D1 — Le laboratoire n'ouvre JAMAIS de session de dossier (L3)

Trois voies étaient possibles ; deux écartées avec leur raison : une **septième voie** d'accès au
carnet (l'élargissement durable que B3-a a déjà refusé pour le pharmacien) et le scan du QR
patient (le plus court, le plus disproportionné). Retenu : le laboratoire lit la **demande** par
son jeton ({@see `DemandeAnalyse::$hidden`}), et son écriture est bornée **par construction** à un
prélèvement rattaché à cette demande. *La porte n'est pas gardée, elle n'existe pas.*

**Vecteur central du lot, comme en B3-a** : consulter une demande ou enregistrer un prélèvement ne
crée **aucune** ligne dans `acces_dossier` — vérifié en SQL direct au G2 live, à chaque étape du
cycle, pas seulement à l'enregistrement.

### D2 — Six états déclarés, quatre atteignables (L6)

`preleve → [expedie] → recu → en_analyse → valide → publie`. Les huit étapes du §7.4 ne sont pas
huit états : la première est la création de la demande (B5-a), les deuxième et troisième sont
**un seul acte** — l'identifiant du prélèvement *est* l'étiquette. `expedie` est **facultatif**
(sauté quand le prélèvement est fait au laboratoire même — prétendre le contraire ferait saisir un
transport qui n'a pas eu lieu) ; `recu` ne l'est **pas** — l'accession formelle au laboratoire a
lieu même pour un prélèvement sur place. `valide`/`publie` restent **déclarés dans l'ENUM et
inatteignables** : ils supposent un résultat, que B5-c seul construit ({@see
`ServiceValidationBiologique`}, à venir) — même motif que `predictions_ia.mode` portant `hybride`
avant qu'il le soit (P10c-3-ii).

### D3 — `analyse.executer` est créée, `analyse.valider` ne l'est PAS ENCORE (L7, partiel)

Le laborantin exécute le cycle mécanique : `analyse.executer`, donnée au **seul** rôle
`laborantin`, qui **garde** `qr.scan`/`dossier.ecrire`/`triage.view` (précédent B3-a : une
permission neuve n'en retire jamais une existante). `analyse.valider` (verdict du biologiste,
§7.4 étape 7) reste une chaîne **nommée dans les commentaires du code**, sans permission créée :
l'écrire ici, sans le contrôleur de validation qui lui donnerait un sens, aurait été une clé sans
serrure (refus déjà opposé par P11.1-D5). Elle sera, comme prévu par le plan G1, portée par
**aucun rôle métier** — un résultat biologique validé engage la responsabilité d'un biologiste
nommé, pas d'un métier.

### D4 — Jeton et étiquette : deux objets, prouvé et pas seulement affirmé (L5)

Le jeton (`demandes_analyses.jeton_partage`, posé en B5-a) est un **secret d'accès** : 64
caractères, temps constant (`hash_equals`), 404 jamais 403. L'étiquette (`prelevements.identifiant`,
`PRE-`+10 caractères aléatoires, patron `DEM-` de P11.1) est un **identifiant imprimé**, qui
circule sur un tube et n'ouvre rien : elle ne figure dans aucune règle de validation temps-constant,
et sa génération (`GenerateurIdentifiantPrelevement`) est une classe séparée de tout mécanisme de
jeton. *Mettre un secret d'accès sur une étiquette reviendrait à distribuer la clé du dossier avec
l'échantillon.*

### D5 — `journal_laboratoire` : même exigence que le journal du médecin, mécanisme différent (L13)

Le propriétaire demande le même niveau d'exigence que l'audit du médecin au carnet
(`acces_dossier`) ; le mécanisme ne peut pas être le même puisqu'aucune fenêtre n'est ouverte
(D1). **Vérifié plutôt que supposé** : `ServiceFicheParcours` apparie ouvertures et clôtures sur
`duree_minutes !== null`, donc une ligne de dépôt isolée y afficherait « consultation non
clôturée » — une phrase fausse dans le document qui existe pour dire au patient ce qui s'est
passé. D'où un journal **séparé**, append-only à deux niveaux (modèle : `booted()` lève sur
`updating`/`deleting` ; moteur : déclencheurs `trg_journal_labo_update`/`_delete` dans les deux
dialectes), tous les identifiants **sans contrainte** (ADR-042 D1 : `demande_id`, `prelevement_id`,
`acteur_user_id` ne sont jamais des relations vivantes), **aucune valeur clinique**. Trace TOUS les
actes, y compris la simple consultation par jeton qui ne crée rien d'autre.

### D6 — Étiquette en Code 128, SVG pur, zéro dépendance (L16)

Vérifié : aucune bibliothèque de code-barres ni de QR dans ce dépôt (`composer.json`, `vendor/`).
Un QR exige un encodage Reed-Solomon qu'on n'écrit pas à la main — une dépendance (§2.6). Le §7.4
dit « code-barres **ou** QR » : Code 128 Set B s'écrit en classe **pure**, prouvable par vecteurs
(alphabet ASCII 32-126, clé de contrôle modulo 103 — même famille que le mod-97 du NIS), et
**c'est aussi le choix juste métier** : une étiquette de tube est un code-barres linéaire dans tous
les laboratoires réels, un QR sur un tube de 13 mm se lit mal. `ReglesCode128::svg()` rend des
`<rect>` pour les barres seules, avec zone de silence. **Limite dite, pas cachée** : le tableau des
107 motifs est reproduit de mémoire d'après la norme ISO/IEC 15417 — vérifié par auto-cohérence
(chaque motif somme à 11 ou 13 modules, aucun motif dupliqué), jamais confronté à une douchette
physique.

### D7 — Quatre gardes du moteur, dual-dialecte, aucune ne rattrape les autres

`prelevements` : `valide` exige `valide_par_user_id`+`valide_le` ; `publie` exige
`resultat_analyse_id`+`publie_le` ; `identifiant` non vide ; le rang du statut ne peut pas reculer
(`StatutPrelevement::rang()`, dupliqué en `CASE` SQL — un déclencheur ne peut pas appeler PHP).
`journal_laboratoire` : append-only inconditionnel. Toutes en `SIGNAL SQLSTATE '45000'` (MySQL) /
`RAISE(ABORT)` (SQLite) — motif établi depuis P6.3.

---

## 3. Un défaut réel de B5-a, trouvé en construisant B5-b, pas au G0 de B5-b

`ProjecteurLignesDemande::projeter()` comparait `$demande->statut !== StatutDemandeAnalyse::EMISE`
— correction déjà faite en B5-a pour l'enum-à-chaîne — **mais manquait toute vérification
relationnelle**. `demandes_analyses.statut` ne passe à `servie` qu'à la publication d'un résultat
(B5-c), pas à l'enregistrement d'un prélèvement (B5-b) : un `statut` encore `emise` n'empêchait
donc pas de reprojeter les lignes d'une demande dont un laboratoire avait **déjà prélevé**
l'échantillon sur l'ancienne liste d'examens — un médecin éditant sa demande après coup aurait
silencieusement désynchronisé ce que le tube contient de ce que le carnet affiche. Corrigé par une
seconde garde, relationnelle : `if ($demande->prelevements()->exists()) { return -1; }` — motif
déjà tenu par `ProjecteurLignesOrdonnance` (B3-a, `delivrances()->exists()`) ; B5-a s'en était
écarté sans qu'aucun `prelevements` ne pût encore exister pour le prouver. **B5-a ne pouvait pas
trouver ce défaut** : il n'avait littéralement rien à reprojeter contre.

---

## 4. Preuves

**G3** : 34 vecteurs dédiés (`CircuitPrelevementTest`, 167 assertions) ; suite complète
**1826/1826, 18 263 assertions, 0 échec** ; **campagne de mutation manuelle : 4 tueuses + 1 témoin
volontairement vert**, chacune assertée appliquée avant interprétation, chaque fichier restauré et
vérifié par `diff` :

- anti-IDOR (`assertAppartientAuLaboratoire`, `abort_if(false, 404)`) → tue 2 vecteurs (direct-call
  **et** HTTP réel) ;
- garde SQLite de non-régression du rang → tue 1 vecteur ;
- garde relationnelle neuve de `ProjecteurLignesDemande` (§3 ci-dessus) → tue 1 vecteur ;
- habilitation (`assertHabilite`, `if (false)`) → tue 1 vecteur ;
- témoin : réordonnancement de six affectations indépendantes dans `enregistrer()` → **34/34
  restent verts**.

Pint propre sur les fichiers neufs, baseline établie avant tout formatage.

**G2 live MySQL réel** (base sauvegardée `mysqldump --routines --triggers`, stderr redirigé
séparément, puis migration réelle) : deux laboratoires réels, un médecin réel désigné référent
d'un membre réel, une demande réelle créée par HTTP (falsifications du client toutes ignorées) ;
jeton faux → **404** ; vrai jeton → demande affichée, `acces_dossier` **à 1** (inchangé) ;
enregistrement, réception directe (transport sauté, L6 en réel), mise en analyse → `acces_dossier`
**toujours à 1** au terme du cycle complet ; étiquette SVG réelle récupérée
(`image/svg+xml`) ; **anti-IDOR réel** : le laborantin d'un second laboratoire reçoit 404 sur le
détail, l'étiquette et `recevoir`, statut inchangé, zéro ligne journalisée pour lui ; **les quatre
gardes du moteur refusent chacune par leur motif exact** en SQL direct (`ERROR 1644`) ;
`journal_laboratoire` refuse `UPDATE`/`DELETE` en direct ; la garde relationnelle du §3 vérifiée en
direct sur la vraie demande. Base restaurée : un **`DROP TABLE` explicite** a été nécessaire en
plus de la réimportation du dump (pris avant la migration, il ne connaît pas les deux tables
neuves et ne les supprime donc pas) pour revenir à 145 tables et une migration `Pending`. `.env`
inchangé.

**Prérequis de déploiement retrouvé une fois de plus** : `PortailRolesSeeder` n'avait pas encore
été rejoué depuis le seeder de B5-a — `analyse.executer` absente du rôle `laborantin` jusqu'au
rejeu.

---

## 5. Limites annoncées (B5-b seul)

- **Aucun résultat, aucun automate, aucune validation biologique** — `valide`/`publie` restent
  inatteignables (B5-c).
- **`analyse.valider` n'existe toujours pas** — la porte biologiste reste à construire.
- **Aucune notification au patient** — le dépôt n'est pas encore visible dans son carnet, faute de
  `source='structure'` réellement écrite par ce circuit (B5-c).
- **`ReglesCode128` non confrontée à un scanner physique** — auto-cohérence seule (D6).
- **`expedie` est une déclaration, pas un suivi géolocalisé.**
- **`journal_laboratoire` n'est PAS une chaîne de hachage** — append-only sans empreinte chaînée ;
  ADR-042 a montré ce que coûte une chaîne (déclaration d'origine, ancrage, scellement, piège des
  identifiants dans l'empreinte), et *on ne durcit pas par symétrie décorative* (précédent B3-c).
- **Aucun écran mobile** (précédent B2-a/B2-b/B5-a) — écran Blade seul.
- **Catalogue toujours un jeu de démonstration de huit analyses** (hérité P6.7a).

---

## 6. Suite

B5-c : résultats (saisie **et** import par un seul service, `origine` décidée par le serveur),
`automates` (clé + HMAC sur le corps brut, un automate ne valide jamais), validation biologiste et
son verrou (`analyse.valider`), publication en `source = 'structure'` (referme K5/K11 pour de
bon), notification `RESULTAT_ANALYSE_PUBLIE` (l'événement seul, jamais le résultat).
