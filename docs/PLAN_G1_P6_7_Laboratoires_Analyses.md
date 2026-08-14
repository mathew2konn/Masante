# Plan G1 — P6.7 Laboratoires et catalogue des analyses (CDC_09 §7)

Étape **7** de l'ordre CDC_09 §14. Suit P6.6 (médicaments), précède le module « Documents médicaux
signés » qui l'attend explicitement.

**Trois décisions du propriétaire (2026-08-14)** :

1. **La traçabilité des prélèvements (§7.4) est un module séparé** — c'est un workflow, pas un
   référentiel, et elle suppose la *prescription biologique*, entité qui n'existe pas.
2. **Les valeurs de référence sont AFFICHÉES, jamais conclusives** — et **stratifiées** dès
   maintenant, remplies d'un jeu de démonstration honnêtement étiqueté, remplaçable par un
   référentiel biologique réel sans migration.
3. **P6.7 referme la seconde porte du prescripteur** : `resultats_analyses.medecin_prescripteur`.

---

## 1. G0 — ce que le code dit réellement

### B1 — Un résultat d'analyse ne désigne rien de normalisé

`ResultatAnalyseController` valide `resultats_json` en **`nullable|array`**, sans aucune structure.
Le mobile y envoie des couples `{parametre, valeur}` en texte libre. Ni unité, ni valeurs de
référence, ni méthode, ni milieu prélevé.

§7.3 dit l'inverse mot pour mot : la standardisation doit garantir que « les résultats sont
interprétés de manière cohérente, **quel que soit le laboratoire** ». Aujourd'hui, deux laboratoires
peuvent rendre la même analyse sous deux noms et deux unités, et rien ne les rapproche.

**Troisième instance de la même famille de défauts**, après `ordonnances.medecin_nom` (P6.5) et
`medicaments_json.*.nom` (P6.6).

### B2 — P6.5 a refermé UNE porte du prescripteur ; il y en avait DEUX *(constat non attendu)*

`EcritureSoignantService` réécrit le prescripteur côté serveur — mais il teste la clé `medecin_nom` :

```php
if (array_key_exists('medecin_nom', $valide)) { $valide['medecin_nom'] = $fiche->nom_complet; }
```

`resultats_analyses` porte **`medecin_prescripteur`**, une autre clé. Et cette section **est ouverte
au soignant** (`RegistreSectionsCarnet::SECTIONS_SOIGNANT`). Un résultat d'analyse consigné par un
soignant peut donc encore nommer n'importe quel prescripteur.

### B3 — `laboratoire` est un texte libre alors que le référentiel existe

`resultats_analyses.laboratoire` est `nullable|string|max:200`, et `TypesEtablissement` porte
`laboratoire` parmi ses 13 catégories depuis P6.4a. Le référentiel des établissements est là ; le
résultat ne le cite pas.

### B4 — Les données propres aux laboratoires du §7.2 manquent

`structures_sanitaires` couvre déjà l'identité administrative (P6.4a), y compris `certifications_json`
et `agrements_json`. Manquent, spécifiques au laboratoire : **responsable scientifique** (rôle légal
distinct du directeur), **équipements principaux**, **délais moyens de rendu**, **analyses
disponibles**, connexion au SI national.

### B5 — §7.4 : rien du tout

Aucun identifiant de prélèvement, aucune des huit étapes. Traité en module séparé (décision 1).

### B6 — Le module « Documents médicaux signés » attend ce catalogue

`RegistreDocumentsSignables` le dit noir sur blanc pour `prescription_biologique` : « *ce n'est pas
un document mais une DEMANDE* », et sans le catalogue elle prescrirait en texte libre.

---

## 2. Découpage — deux incréments

**P6.7a — le catalogue des analyses** : code national, identité normalisée, **intervalles de
référence stratifiés**, gouvernance §10, lien résultat → catalogue, et la fermeture de la seconde
porte du prescripteur.

**P6.7b — le référentiel des laboratoires** : enrichissement additif du §7.2, analyses disponibles,
délais de rendu, et lien résultat → laboratoire.

**Pourquoi le catalogue d'abord** : c'est lui qui donne un sens au reste. Un laboratoire qui déclare
« analyses disponibles » sans catalogue les déclarerait en texte libre — le défaut qu'on répare.

---

## 3. Décisions de conception

| # | Décision | Pourquoi |
|---|---|---|
| **N1** | Code national **`ANA` + 6 chiffres, littéral, sans clé** | Quatrième application après `ETS`, `PRO`, `MED`. §3.2 impose un checksum au NIS et nulle part ailleurs. `UNIQUE(pays_code, code)`, hors `$fillable`. |
| **N2** | Colonne **`loinc` nullable**, documentée comme la cible | CDC_09 §9.1 recommande LOINC. On n'a pas le jeu LOINC : la colonne existe, vide, **et on dit qu'elle est vide** plutôt que d'inventer des codes qui auraient l'air vrais. |
| **N3** | L'analyse porte son **milieu prélevé** et sa **méthode** | « Glycémie » n'est pas une analyse : glycémie **à jeun sur plasma veineux** et glycémie **capillaire** sont deux entrées, avec des références différentes. Les fondre reproduirait l'incohérence que §7.3 combat. |
| **N4** | Intervalles de référence dans une **table stratifiée** : sexe, tranche d'âge (en jours), état physiologique | Un intervalle unique dirait à une femme enceinte que son hémoglobine est basse alors qu'elle est normale pour elle. La stratification est **la structure**, et c'est elle qui rend le remplacement possible sans migration. |
| **N5** | **`source` OBLIGATOIRE** sur chaque intervalle ; le contrôle qualité refuse la publication sans elle | Un intervalle sans provenance est une rumeur — même règle qu'en P6.6a pour les interactions. |
| **N6** | Le jeu livré est étiqueté **`demonstration`**, jamais attribué à une autorité | Je ne peux pas attribuer ces valeurs à une autorité que je n'ai pas consultée. *Un intervalle inventé qui porte un nom d'autorité est pire qu'un intervalle inventé qui l'avoue.* |
| **N7** | **Le serveur ne conclut pas** : il sert le résultat et la ou les références applicables | Décision du propriétaire. La plateforme donne de quoi juger, elle ne juge pas. |
| **N8** | Quand plusieurs strates s'appliquent (grossesse notamment), on les **affiche toutes**, on n'en choisit aucune | Le carnet connaît la grossesse (`suivi_grossesse`), mais choisir pour la patiente reviendrait à décider. On montre « Femme adulte » **et** « Grossesse T3 » — le lecteur voit ce qui le concerne. Motif des **trois silences** de P7-D2. |
| **N9** | Le lien résultat → catalogue est **additif et facultatif** ; le serveur fige code, libellé et unité | Exactement P6.6b. Un patient qui recopie un compte rendu papier n'a pas de liste ; le référentiel est incomplet. |
| **N10** | `medecin_prescripteur` réécrit sur le chemin du soignant | B2. La correction est celle de P6.5b, appliquée à la clé que le service ne testait pas. Le chemin du patient n'est pas touché. |

---

## 4. Ce qui est construit (P6.7a)

- Table `analyses` : `code`, `pays_code`, `loinc`, `libelle`, `description`, `categorie`,
  `milieu_preleve`, `unite`, `methode`, `conditions_prelevement`, `conservation`,
  `delai_rendu_heures` — tout nullable sauf le libellé et l'unité.
- Table `analyse_references` : `analyse_id`, `sexe`, `age_min_jours`, `age_max_jours`,
  `etat_physiologique`, `valeur_min`, `valeur_max`, `critique_bas`, `critique_haut`,
  **`source` non nulle**, `libelle_strate`.
- `ReglesIntervalleReference` — **classe pure** (motif `ReglesReversement`) : à partir d'un âge et
  d'un sexe, elle renvoie **les** strates applicables. Aucune base, aucune horloge, aucun verdict.
- `SourceAnalyses` (interface `SourceReferentiel`) + une ligne au registre : le catalogue **et** ses
  intervalles dans un seul instantané — les publier séparément laisserait un intervalle désigner une
  analyse absente de la version en vigueur (raison de P6.6a).
- Contrôles qualité : libellé ou unité absents, intervalle sans source, borne min > max, strates qui
  se chevauchent pour un même sexe et un même âge, intervalle orphelin, code mal formé.
- `GenerateurCodeAnalyse` + `masante:analyses:backfill --dry-run`.
- Lien `resultats_json.*.analyse_id` résolu et figé, **sur les trois chemins d'écriture**.
- `medecin_prescripteur` réécrit sur le chemin du soignant (N10).
- Écran portail sous une permission **`analyse.referentiel`**, portée par aucun rôle — un laboratoire
  n'écrit pas le catalogue national, pour la raison exacte qui a fait naître `medicament.referentiel`.

---

## 5. Preuves attendues

**G3** — vecteurs dédiés dont, en miroir : un résultat **sans lien** reste accepté · avec lien, le
code et l'unité viennent **du catalogue** · un `analyse_id` inconnu → 422 nommant l'analyse · les
valeurs figées ne bougent plus · **la résolution renvoie plusieurs strates quand plusieurs
s'appliquent, et n'en choisit aucune** · un intervalle **sans source** bloque la publication · deux
strates qui se chevauchent sont signalées · le prescripteur est réécrit sur le chemin du soignant et
**pas** sur celui du patient. Plus : suite complète, typecheck ×3, expo-doctor,
**vérification par mutation** de chaque garde — en éprouvant la bonne couche, leçon de P6.6b.

**G2 live MySQL** — backfill `ANA000001…`, unicité par pays, publication gouvernée à deux agents,
`UPDATE` direct sans effet sur le diffusé, résolution des strates sur des âges réels (nouveau-né,
enfant, adulte), résultat lié puis renommage de l'analyse **sans réécriture du résultat**, base
restaurée.

---

## 6. Limites qui seront annoncées

1. **§7.4 non livré** : aucun identifiant de prélèvement, aucune des huit étapes. Module séparé.
2. **Les intervalles sont un JEU DE DÉMONSTRATION**, étiquetés comme tels, **non validés
   cliniquement** et non attribués à une autorité. Les remplacer sera **de la donnée, zéro code**.
3. **Aucun code LOINC** : la colonne existe et attend le jeu réel.
4. **Le serveur ne conclut jamais** — pas de statut normal/anormal sur un résultat de laboratoire.
5. **Les laboratoires eux-mêmes sont en P6.7b** : `resultats_analyses.laboratoire` reste du texte
   libre jusque-là.
6. **La grossesse n'est pas lue** pour choisir une strate : on les affiche toutes (N8).
7. **Intervalles ivoiriens absents.** Les valeurs usuelles dépendent de la population — plusieurs
   paramètres hématologiques diffèrent en Afrique subsaharienne. Un référentiel national digne de ce
   nom s'appuie sur des intervalles établis **localement** ; la structure est prête à les recevoir,
   les valeurs livrées ne le sont pas.
