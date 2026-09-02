# PROMPT CLAUDE CODE — NOTIFICATIONS DE FACTURATION ET CONFIDENTIALITÉ
## Backend Laravel — Lot 9 de la séquence post-facturation

> Applique `claude/00_Conventions_Transversales_MaSante.md`, non recopiées ici.

---

## CONDITIONS D'EXÉCUTION

Lots 3 (génération des factures) et 8 (API) exécutés. Ce lot déclenche des notifications à partir d'événements déjà produits par les lots précédents ; il n'invente aucun nouvel événement métier.

---

## COPIER À PARTIR D'ICI

---

## INTERDICTIONS ABSOLUES

1. **Aucun champ médical, de service, de spécialité ou d'établissement dans une notification patient.** Seuls le montant et un libellé générique sont autorisés (§2.7 : « Notification interdite : tout libellé d'acte, de service, de spécialité **ou d'établissement** »).
2. **Une seule relance par facture, jamais deux** (R18) — ce lot implémente l'envoi effectif, la colonne `relance_envoyee_le` existe déjà.
3. **Aucun ton anxiogène, aucun marquage d'impayé agressif** dans le contenu envoyé au patient.
4. **N'invente pas de nouveau canal de notification.** Réutilise le mécanisme déjà présent dans le projet (`TypeNotification` dans `app/Support/`), ne construis pas un second système parallèle.
5. **Aucune nouvelle migration** au-delà d'un éventuel ajout de valeur à `TypeNotification`.

## PHASE 0 — AUDIT CIBLÉ

1. Confirme le mécanisme de notification déjà en place (Firebase Cloud Messaging ou autre), son point d'appel unique dans le code, et la liste actuelle de `TypeNotification`.
2. Confirme si un mécanisme de notification interne (alerte back-office, distincte de la notification patient) existe déjà, pour l'alerte de bascule Palier 0.
3. Confirme la structure du payload de notification existant, pour vérifier qu'un contrôle automatisé de contenu est possible.

**Arrête-toi et rapporte.**

## PHASE 1 — Déclencheurs

| Événement | Destinataire | Contenu autorisé |
|---|---|---|
| `facture_patient` créée (statut `A_REGLER`) | Patient (ou titulaire du compte si bénéficiaire) | « Vous avez une nouvelle facture · {montant} FCFA ». Si bénéficiaire ≠ titulaire : « Facture pour {prénom bénéficiaire} · {montant} FCFA ». |
| `facture_patient.relance_envoyee_le` encore nul et échéance dépassée | Idem, une seule fois | Même contenu, pas de ton différent. Après envoi, `relance_envoyee_le` est posé — vérifié par un test qui interdit un second envoi. |
| `abonnements_structure` bascule à `SUSPENDU` (lot 1) | Back-office MaSanté uniquement, jamais le patient | Alerte interne : structure, montant dû, date de bascule. |
| `facture_partenaire.statut = PAYEE` après période de suspension | Back-office | Confirmation de réactivation. |

## PHASE 2 — Garde-fou de contenu

Ajoute une vérification centralisée (pas un test seul — un point de code) : avant tout envoi d'une notification de type facturation vers un patient, le corps du message est comparé à une liste de motifs interdits (codes d'acte, noms de spécialité connus, noms d'établissements) et l'envoi est bloqué avec une exception explicite si l'un d'eux apparaît. Ce n'est pas une confiance aveugle dans le code appelant : c'est un filet de sécurité au dernier point avant l'envoi.

## PHASE 3 — TESTS (`tests/Feature/`)

1. `test_notification_facture_contenu_minimal` — vérifie l'absence de tout champ interdit.
2. `test_notification_beneficiaire_different_du_titulaire`.
3. `test_relance_unique_seconde_tentative_bloquee`.
4. `test_bascule_palier0_notifie_le_backoffice_pas_le_patient`.
5. `test_garde_fou_contenu_bloque_un_libelle_interdit` — injecte volontairement un libellé d'acte dans un test pour vérifier que l'envoi est refusé.
6. `test_reactivation_notifie_le_backoffice`.

## CHECKLIST FINALE

- [ ] Le garde-fou de contenu est un point de code unique, pas une vérification dupliquée à chaque appel
- [ ] Les 6 tests passent
- [ ] Aucun nouveau système de notification parallèle créé
- [ ] `relance_envoyee_le` empêche bien un second envoi

## HORS PÉRIMÈTRE

Le choix du son de notification (distinct du son SOS) — c'est un choix d'asset côté application mobile, hors backend, à traiter avec les écrans (voir note de séquencement). Les écrans d'affichage des notifications.

## FIN DU PROMPT
