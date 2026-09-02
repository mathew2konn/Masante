# Moteur de protocoles MaSanté — prototype

Moteur de règles minimal pour le CDC_08, avec le protocole paludisme Côte d'Ivoire (PNLP, mars 2022)
et une batterie de 12 cas cliniques.

```bash
npm install
npm test          # exécute la validation technique + les 12 cas cliniques
npm run typecheck
```

## Ce qui est implémenté

| Exigence CDC_08 | Où |
|---|---|
| Évaluation de conditions sans `eval` | `src/conditions.ts` — tokeniseur + parseur + évaluateur d'arbre |
| Chaînage avant jusqu'au point fixe | `src/moteur.ts` |
| Résolution de conflits entre règles (`remplace`) | `src/moteur.ts` |
| Sélection de posologie par tranche de poids | `src/posologie.ts` |
| Arbres de décision (échec thérapeutique, diagnostic par niveau) | `src/moteur.ts` → `resoudreArbre` |
| Validation technique à la publication (§7.4) | `MoteurProtocoles.validerProtocole()` |
| Détection des trous et chevauchements de tranches (§12) | `src/posologie.ts` → `validerTables` |
| Trace opposable : `trace_id`, version du protocole, règles déclenchées | `ResultatEvaluation` |
| Performance < 100 ms P95 (§11) | mesuré : **0,8 ms** |

## Trois décisions de conception à retenir

**Aucune condition n'est du code.** Un protocole est une donnée éditée par un comité scientifique.
Si le moteur faisait `eval("signes_gravite.length > 0")`, éditer un protocole reviendrait à exécuter
du code arbitraire sur le serveur. Le fichier `conditions.ts` tokenise, parse et évalue un arbre —
la grammaire ne peut rien exprimer d'autre que des comparaisons et des `ET`/`OU`.

**Les priorités ne séquencent pas le raisonnement.** La règle R05 (grossesse T1 → quinine) dépend
d'une classification produite par R03, mais R05 a une priorité plus forte. Une exécution en un seul
passage trié par priorité ne déclencherait jamais R05. Le moteur boucle donc jusqu'au point fixe :
les priorités servent uniquement à ordonner l'affichage et à départager les conflits.

**Un blocage de prescription bloque vraiment.** Quand une règle émet `BLOCAGE_PRESCRIPTION`,
le moteur ne calcule aucune posologie — même si un traitement est par ailleurs recommandé.

## Ce que la première exécution a trouvé

Cinq défauts réels dans les données du protocole, tous corrigés :

1. **Trous dans les tranches de poids** — un enfant de 14,5 kg ne recevait aucune dose d'artéméther-
   luméfantrine (tranches « 7–14 kg » puis « 15–24 kg »). Converti en intervalles semi-ouverts
   `[7, 15[`, `[15, 25[`, `[25, 35[`, `[35, ∞[`.
2. **Nourrisson de 4 kg dosé comme un enfant d'un an** — la borne basse de l'AS+AQ était à 0 kg.
   Ajout d'un garde-fou (`garde_fous.poids_minimum_dosage_automatique_kg`) et de la règle R11 qui
   bloque le dosage automatique et exige un avis médical. La valeur de 4,5 kg est un garde-fou
   technique, **pas une recommandation du guide national** — elle doit être confirmée.
3. **Échec thérapeutique sans effet** — R10 signalait l'échec mais R03 continuait à recommander la
   1ère intention. R10 annule désormais R03 et l'arbre de décision est résolu (« Quinine orale »).
4. **Six variables non déclarées** — `semaines_amenorrhee`, `observance`, `jours_depuis_debut`,
   `microscopie`, `parasitemie_persistante_j3`, `reapparition_parasites` étaient utilisées par des
   règles sans figurer dans `variables_entree`.
5. **Fausse alerte de posologie** — le traitement pré-transfert est structuré par population et non
   par poids ; le moteur réclamait à tort une tranche de poids.

## Avertissements qui restent, et c'est normal

Les tables ne couvrent pas les poids les plus bas (AS+AQ sous 4,5 kg, AL sous 7 kg, quinine sous
7 kg). Le guide national ne donne pas de posologie pour ces poids. Ce n'est pas un bug à corriger
dans le code : c'est une question à poser au comité scientifique, et R11 empêche entre-temps toute
prescription automatique.

## Limites du prototype

- Un seul protocole chargé — pas encore de registre, de sélection multi-protocoles ni de résolution
  de conflits *entre* protocoles (CDC_08 §8).
- Pas de cache Redis ni de compilation des règles : inutile à 0,8 ms sur 11 règles, indispensable
  quand il y en aura des centaines.
- Le journal d'audit est produit en mémoire, il n'est pas encore persisté dans le journal immuable
  du CDC_10.
- Les 12 cas cliniques sont écrits à partir du guide. **Ils doivent être relus par un médecin** —
  un cas de test faux valide un protocole faux.

## Étape suivante

Faire relire le fichier `protocoles/PROT-CI-PALU-2022.json` et les 12 cas par un médecin, poser la
question du plancher de poids, puis passer l'état de `BROUILLON` à `ACTIF`.
