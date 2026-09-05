# ADR-057 — Le circuit du laboratoire : la demande d'examen (B5-a)

**Statut : Accepté — B5-a VALIDÉ (G5, 2026-09-05).** G4 propriétaire OK. **Lot B5 EN COURS**
(a fait, b et c restent). Suite complète **1792/1792**, 18 096 assertions.
Contexte : CDC_11 §8.1 (étape 9 de §12), CDC_09 §7.4, CDC_04 §109 · Plan G1 : [`plan.md`
PLAN 4](../../plan.md) · Suite de [ADR-056](ADR-056-paiement-en-ligne-geniuspay.md) (lot B4).

---

## 1. Contexte

Étape 9 de l'ordre que CDC_11 §12 fixe lui-même (« Laboratoire, puis Radiologie »), les huit
précédentes étant closes. Trois modules validés G5 en avaient déjà nommé le porteur :
P6.5b (« une prescription biologique est une DEMANDE, pas un document »), P6.7 (« §7.4 non
livré ») et B2-c (« les demandes d'examens seraient ouvrir un module dans un autre »).

**Périmètre intégral, arbitrage du propriétaire (2026-09-05)** : « on ne va rien abandonner, on
va ajouter tout ce qui manque ». Les quatre tables absentes du CDC_04 §109
(`demandes_analyses`, `prelevements`, `validations_biologiques`, `automates`) et les sept
éléments du corpus, plus deux exigences ajoutées — la traçabilité du laborantin et une
notification au patient après publication. Ma première proposition écartait les automates ; le
propriétaire l'a corrigée, et le résultat est meilleur (L10, réécrit).

Le G0 (onze constats K1→K11) a montré que le circuit n'existait à AUCUN degré : un résultat sans
émetteur ni circuit, et un défaut réel actif — `source = 'structure'` déclarable par le client et
lu par `ServiceFicheParcours::autresEntrees()` comme « un fait de professionnel » (K5+K11).

Découpage en trois : **B5-a** (cet ADR) referme K5/K11 et livre la demande d'examen ;
**B5-b** livrera le prélèvement, l'étiquette et le cycle ; **B5-c** livrera les résultats,
les automates, la validation biologique et la notification.

---

## 2. Décisions (B5-a)

### D1 — Le circuit transpose B2-c → B3-a, il ne s'invente pas (L1)

Ce projet a déjà construit ce parcours une fois : un praticien produit une pièce signée
(B2-c, `ordonnances`), un professionnel d'un autre métier la lit **par un jeton, sans ouvrir le
dossier** (B3-a, `delivrances`). `demandes_analyses` est l'analogue exact d'`ordonnances`, et en
reprend le schéma trait pour trait — y compris une divergence assumée : `consultation_id` y est
un **identifiant sans clé étrangère** (ADR-042 D1), quand `ordonnances.consultation_id` est une
vraie FK. Une demande d'examen a une valeur médico-légale propre, indépendante d'une action
référentielle sur `consultations`.

### D2 — `demandes-analyses` est une SIXIÈME section du registre du carnet

Ajoutée à `RegistreSectionsCarnet::SECTIONS`, `SECTIONS_AUTEUR_EST_PRESCRIPTEUR` (rédiger la
demande EST l'acte de prescrire, comme pour une ordonnance) et `SECTIONS_SOIGNANT`. Conséquence :
tout le mécanisme générique existant (`EcritureSoignantService`, `ContributionCarnetService`,
`CarnetSectionController`, la route `dossier/{section}` du portail) s'applique **sans une ligne
de code neuve** — seuls un contrôleur et ses règles de validation sont écrits.

**AUCUNE PERMISSION NEUVE** : `dossier.ecrire` suffit, comme pour B2-a/B2-b. En inventer une
aurait donné deux clés pour une seule porte (refus déjà opposé par P11.1-D5).

### D3 — K5/K11 : la porte `source` se ferme AVANT de s'appuyer sur le signal (L4)

`source` sort des règles de validation d'`AntecedentController`, `OrdonnanceController` et
`ResultatAnalyseController` — un client ne déclare plus la provenance de ce qu'il écrit.
Quatrième application d'une règle déjà tenue pour `source` (P7-C), `obligatoire` (P6.8b),
`provenance` (P6.8d) et `origine` (P10c-1). `DemandeAnalyseController`, neuf, ne l'a jamais
acceptée. **Prouvé en G2 live** : un client postant `source: 'structure'` sur une demande écrite
par un soignant voit la valeur `medecin` s'imposer, jamais la sienne.

### D4 — Le lien au catalogue : facultatif, relu, figé, réutilisé et non réécrit (L2)

`ServiceLienAnalyse` (P6.7a) est **généralisé** (paramètre `$champ`) plutôt que dupliqué pour
`demandes_analyses` — même catalogue, même garantie, une seule classe qui l'assure. Un examen
hors catalogue est **accepté**, jamais bloqué : refuser de prescrire un examen absent d'un jeu de
démonstration de huit analyses serait une décision médicale prise par une machine (CDC_00 §4).

### D5 — Ce qui est en clair, ce qui est chiffré : la question REPOSÉE, pas recopiée (L9)

B3-a a tranché pour les médicaments : identité en clair, traitement chiffré. La transposition
littérale serait fausse ici — une valeur biologique EST elle-même une donnée de santé. B5-a ne
touche donc PAS `resultats_json` (K3, hors périmètre) ; il construit seulement la demande, où
l'identité de l'examen (`libelle`, `analyse_id`, `code_national`, `unite`) est en clair et
`renseignements_cliniques`/`conditions_prelevement` restent chiffrés.

### D6 — Le jeton et l'étiquette n'ont, dès maintenant, pas le même nom (L5, partiel)

`demandes_analyses.jeton_partage` (64 caractères, hors `$fillable`, `$hidden`, patron B3-a/P10a)
est posé dès B5-a. C'est le secret d'accès à LA DEMANDE. L'**étiquette** du prélèvement — objet
distinct, imprimée, qui circule sur un tube — n'existe qu'en B5-b : les deux ne doivent jamais
être confondues, sous peine de distribuer la clé du dossier avec l'échantillon.

### D7 — La demande est signable : troisième entité branchée du registre PKI (L8, K2)

`DocumentPrescriptionBiologique implements DocumentSignable` referme la condition posée par
`RegistreDocumentsSignables` depuis P6.5b (« sans le catalogue national, elle prescrirait en
texte libre ») — l'étape 7 est faite. Le contenu canonique signe le patient, le prescripteur, la
structure, la date et les examens en clair — **jamais `consultation_id`, `jeton_partage` ou
`statut`** : une transition du circuit (émise → servie, en B5-b) ne doit jamais casser une
signature déjà posée. **Prouvé en G2 live** : signature posée, `statut` basculé à `servie` en
base, signature toujours intègre.

---

## 3. Deux défauts réels trouvés par les tests, aucun par la relecture

1. **`structure_nom` au lieu de `structure_sanitaire`** — `EcritureSoignantService::ecrire()`
   vérifie le nom de colonne **littéral** `structure_sanitaire` (celui d'`antecedents` et
   `ordonnances`) pour la réécrire depuis la fiche du soignant. Un nom différent laissait le
   mécanisme générique muet, sans erreur : `medecin_nom` se posait, `structure_sanitaire` restait
   vide. Renommé pour correspondre au nom que le mécanisme attend réellement.
2. **Comparaison enum-à-chaîne dans `ProjecteurLignesDemande::projeter()`** —
   `$demande->statut !== StatutDemandeAnalyse::EMISE->value` comparait un ENUM (le cast) à une
   CHAÎNE, donc était **toujours vraie** : aucune ligne n'était jamais projetée, sur aucune
   demande. Corrigé en comparant l'enum à l'enum. Sans le second défaut, le premier aurait
   probablement encore fui la revue : les deux se sont révélés en même temps, sur les mêmes
   vecteurs.

Aucun des deux n'était visible en relecture du code écrit — les deux ont été trouvés par le
premier passage de la suite de tests dédiée, avant tout G2.

---

## 4. Preuves

**G3** : 20 vecteurs dédiés (`DemandeAnalyseTest`) ; suite complète **1792/1792, 18 096
assertions, 0 échec** ; **campagne de mutation manuelle, 5 tueuses + 1 témoin volontairement
vert** (fermeture de `source`, garde de projection, garde du catalogue, garde du prescripteur,
génération du jeton — chacune assertée appliquée, chacune restaurée et vérifiée par `diff`) ;
Pint propre sur les fichiers neufs, **baseline établie AVANT tout formatage** sur les dix
fichiers existants touchés (identique à HEAD, aucune nouvelle violation introduite).

**G2 live MySQL réel** (base sauvegardée par `mysqldump --routines --triggers`, restaurée
compte pour compte) : schéma des deux tables conforme ; parcours réel au portail par la voie
**référent**, session HTTP réelle avec cookies et CSRF ; `medecin_id`/`structure_id`/
`medecin_nom`/`structure_sanitaire`/`source` envoyés par le client (`999999`, noms inventés,
`structure`) tous **ignorés**, les vraies valeurs de la fiche posées à la place ; examen lié au
catalogue (`ANA000003`) → code et unité figés ; examen hors catalogue → accepté ; examen
désignant un `analyse_id` inconnu → **refusé, rien créé** ; jeton généré et absent de la
sérialisation JSON, présent en base (48 caractères) ; signature RSA-SHA256 réelle posée et
vérifiée intègre, **restant intègre après une transition de statut simulée** ; un seul
`acces_dossier` pour toute la session (aucune fuite de session neuve à l'écriture). Base et
`.env` restaurés, vérifiés par comptage.

**Prérequis de déploiement exécuté** (K6) : `CatalogueAnalysesSeeder` puis
`masante:analyses:backfill` — catalogue à 0 ligne avant, 8 analyses toutes codées après ;
persiste au travers de la restauration du G2 (donnée de déploiement, pas donnée de test).

---

## 5. Limites annoncées (B5-a seul)

- **Aucun prélèvement, aucun laboratoire ne peut encore lire une demande** (B5-b) — le jeton
  existe, rien ne le consomme.
- **Aucun résultat, aucun automate, aucune validation biologique** (B5-c).
- **Aucune traçabilité du laborantin ni notification au patient** — les deux exigences ajoutées
  par le propriétaire sont portées par B5-b (`journal_laboratoire`) et B5-c
  (`RESULTAT_ANALYSE_PUBLIE`), pas par B5-a.
- **Catalogue = jeu de démonstration de huit analyses**, honnêtement étiqueté depuis P6.7a.
- **Aucun écran mobile** — la demande est exposée par l'API générique du carnet, aucune UI
  mobile dédiée n'est construite (précédent B2-a/B2-b).
- **Écran Blade seul** (décision K1 de P6.4d, tenue), le registre de zones Next reste inchangé.

---

## 6. Suite

B5-b : `prelevements`, `ReglesCode128`, le scan, le cycle à six états et ses gardes du moteur,
`journal_laboratoire`, l'écran laboratoire. Puis B5-c : résultats (saisie et import), `automates`,
validation biologique et son verrou, publication en `source = 'structure'`, notification
`RESULTAT_ANALYSE_PUBLIE`.
