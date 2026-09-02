# MODÈLE ÉCONOMIQUE ET STRATÉGIE DE PARTENARIAT — MaSanté

**Projet MaSanté (ex-IVOIRSANTÉ)** — Plateforme numérique de santé de Côte d'Ivoire
Version 2.0 — Août 2026 · **Document soumis à validation avant implémentation**
> v2.0 : intègre la tarification réelle des passerelles, la commission dès le premier jour,
> le seuil d'encaissement en ligne et l'offre de lancement limitée.

> Remplace et complète le §« Modèle de revenus » de `claude_Business_Plan_MaSante_Hypotheses.md` (v1.0).
> Les hypothèses financières de ce document restent valides ; c'est la **structure de facturation** qui change.

---

## PARTIE 1 — CE QUE DIT LE MARCHÉ IVOIRIEN

### 1.1 Le vrai problème n'est pas la couverture, c'est l'usage

C'est le constat central de toute cette étude, et il réoriente entièrement la proposition de valeur.

| Indicateur | Valeur | Lecture |
|---|---|---|
| Ivoiriens enrôlés à la CMU | 23 000 000 | La couverture est quasi universelle |
| Enrôlés ayant utilisé leur carte en 2025 | **moins de 4 %** | **Le système est financé mais pas consommé** |
| Consultations CMU réalisées en 2025 | 1 797 518 | Faible au regard de 23 M d'enrôlés |
| Moyenne mensuelle avant / après mesure de mai 2025 | 28 700 → 210 000+ | La demande existe : elle réagit très vite à une levée de friction |
| Établissements publics opérationnels CMU | 3 097 | L'offre est équipée et sous-utilisée |
| Pharmacies privées servant les assurés CMU | 6 000+ | Le réseau de distribution est en place |
| Cartes distribuées / enrôlés (juillet 2025) | 8,6 M / 20,3 M | Beaucoup d'assurés n'ont pas leur carte en main |

**Conclusion.** L'infrastructure sanitaire et l'assurance existent. Ce qui manque, c'est la **couche d'information** entre le citoyen et cette infrastructure : *où ma carte est-elle acceptée, pour quel acte, à quel prix, et est-ce ouvert maintenant ?* Aucun acteur ne répond aujourd'hui à cette question.

Le bond de 28 700 à 210 000 consultations mensuelles après une simple mesure gouvernementale prouve que **la demande n'est pas le facteur limitant : la friction l'est**. MaSanté est une machine à retirer de la friction.

### 1.2 Ce qui pèse sur le portefeuille des ménages

Les paiements directs des ménages représentaient **39 % du financement de la santé en 2017**, très au-dessus de la norme OMS de 25 %, et environ **30,7 % du financement des soins de santé primaires sur 2020-2021**. La dépense directe moyenne par personne concernée était de l'ordre de **16 000 FCFA** (enquête ménages 2015).

**Conséquence pour le modèle :** toute friction tarifaire supplémentaire imposée au patient est disqualifiante. Le patient paie déjà trop. L'application doit être gratuite, et le prix affiché sur MaSanté doit être **exactement** celui du guichet.

### 1.3 Ce qui fonctionne déjà en Côte d'Ivoire

| Levier | État | Exploitation par MaSanté |
|---|---|---|
| Monnaie électronique | 26,88 M de comptes | Rail de paiement natif — pas d'éducation à faire |
| Internet mobile | 40,9 M d'abonnements | Le canal existe |
| Enrôlement CMU | Obligatoire depuis 2023 | La base d'identification existe |
| Prix des médicaments essentiels | Homologués et réglementés | **Voir §3.4 — supprime l'objection des pharmacies** |

### 1.4 Ce qui fait échouer les plateformes — les quatre causes

Ces quatre causes sont celles qui reviennent systématiquement, et le modèle proposé est construit pour les neutraliser une par une.

1. **Facturer avant d'avoir produit de la valeur.** Le partenaire paie un abonnement, ne voit aucun patient arriver, et résilie en se sentant volé. → Neutralisé par le Palier 0 gratuit et les 3 mois offerts (§2.2).
2. **L'opacité des frais.** Le partenaire ne comprend pas pourquoi il reçoit 14 225 FCFA sur un acte à 15 000. Il soupçonne. → Neutralisé par le reçu détaillé (§2.4).
3. **La captivité.** Engagement long, données non exportables, exclusivité. Le partenaire signe, puis se sent piégé, et le dit autour de lui. → Neutralisé par la Charte (§4.1).
4. **La perte de contrôle sur le prix et la relation client.** Le partenaire craint que la plateforme finisse par lui dicter ses tarifs ou lui revendre ses propres patients. → Neutralisé par la parité tarifaire et l'interdiction du classement payant.

---

## PARTIE 2 — LE MODÈLE RETENU

### 2.1 La réponse à la question « abonnement ou commission ? »

**Les deux — mais chacun paie une chose différente, et jamais avant que la valeur soit livrée.**

| | Ce que ça paie | Pourquoi ce mode |
|---|---|---|
| **Abonnement** | L'**outil** : portail, agenda, dossiers, facturation, statistiques | Coût fixe pour MaSanté (serveurs, support) → revenu prévisible, indépendant du volume |
| **Commission** | Le **flux** : encaissement en ligne, rapprochement, relances | Coût variable → le partenaire ne paie que proportionnellement à ce qu'il encaisse |

Un abonnement seul (modèle Doctolib) suppose que le partenaire a les moyens de payer d'avance et une force de vente pour le convaincre — MaSanté n'a ni l'un ni l'autre en année 1. Une commission seule ne finance pas les coûts fixes et rend le revenu trop volatil.

### 2.2 L'escalier de confiance — trois paliers

**PALIER 0 — VISIBILITÉ · Gratuit, à vie, sans condition**

- Fiche établissement complète : services, horaires, tarifs affichés, photos, GPS, bouton « S'y rendre »
- Apparition dans la recherche et sur la carte
- Réception de demandes de rendez-vous (limitées à 30 par mois)
- Affichage « Carte CMU acceptée ici » et actes couverts

**0 FCFA. Pour toujours.** Ce palier n'est pas une offre commerciale, c'est la résolution du problème de démarrage : sans établissements sur la carte, aucun patient ne vient ; sans patients, aucun établissement n'accepte de payer. Le Palier 0 casse ce cercle.

**PALIER 1 — GESTION · Abonnement mensuel, avec période d'essai**

Tout le Palier 0, plus : rendez-vous illimités, agenda synchronisé, dossier patient partagé par QR code, rappels automatiques, encaissement en ligne, tableau de bord et statistiques, export comptable.

| Type d'établissement | Abonnement mensuel | Commission sur encaissements |
|---|---:|---:|
| Cabinet médical, centre de santé (1–3 praticiens) | 15 000 FCFA | Barème §2.3 |
| Clinique, laboratoire, centre d'imagerie (4–15) | 30 000 FCFA | Barème §2.3 |
| Hôpital, polyclinique (plus de 15) | 50 000 FCFA | Barème §2.3 |
| **Pharmacie** | 15 000 FCFA | **0 % — voir §3.4** |

**Règles de la période d'essai**

| | Durée offerte | Commission pendant l'essai |
|---|---|---|
| **Offre de lancement** — 20 premiers partenaires signés | 3 mois | **2,5 % dès le 1er jour** |
| Tous les partenaires suivants | 1 mois | **2,5 % dès le 1er jour** |

Seul l'**abonnement** est offert. La commission sur les encaissements court dès la première transaction et doit figurer explicitement au contrat pour éviter tout malentendu.

L'offre de lancement à 3 mois est réservée aux vingt premiers partenaires : ce sont eux qui prennent le risque d'une plateforme sans référence. Passé ce cap, la plateforme a des preuves à montrer et un mois suffit. Cette limitation n'est pas cosmétique — voir §7.2, elle conditionne l'atteinte de l'équilibre.

*Repère international : Doctolib facture environ 135 € par mois **et par praticien**, soit ≈ 88 500 FCFA. MaSanté facture 30 000 FCFA pour une clinique entière — environ le tiers du prix d'un seul praticien français.*

**PALIER 2 — INTÉGRATION · Sur devis**

Connexion API au logiciel existant de l'établissement (caisse, stock, ERP hospitalier), reprise d'historique, formation sur site. Facturé au projet.

### 2.3 Barème de commission dégressif

Appliqué au **volume mensuel encaissé via MaSanté**, tous actes confondus :

| Volume mensuel encaissé via MaSanté | Commission MaSanté |
|---|---:|
| 0 – 250 000 FCFA | 2,5 % |
| 250 001 – 1 000 000 FCFA | 2,0 % |
| 1 000 001 – 3 000 000 FCFA | 1,5 % |
| Au-delà de 3 000 000 FCFA | 1,0 % |

Ces seuils portent sur le **volume réellement encaissé via la plateforme**, pas sur le chiffre d'affaires de l'établissement. Ils ont été calibrés pour qu'une clinique de taille moyenne franchisse le premier palier au cours de sa deuxième année : la dégressivité doit être vécue, pas seulement promise.

La dégressivité n'est pas une remise commerciale : c'est le signal que **la croissance du partenaire lui profite à lui d'abord**. Elle est appliquée automatiquement, sans demande de sa part.

### 2.4 Le reçu transparent — le dispositif de confiance central

Les frais du prestataire de paiement sont refacturés **à prix coûtant**. MaSanté ne prend aucune marge dessus, et le prouve sur chaque transaction :

```
┌──────────────────────────────────────────────┐
│  Consultation — 12 mars 2026                 │
├──────────────────────────────────────────────┤
│  Montant réglé par le patient    15 000 FCFA │
│                                              │
│  Frais de paiement Wave             −225 FCFA│
│  Frais GeniusPay (1 % + 100)        −250 FCFA│
│    → refacturés à prix coûtant, marge 0      │
│  Commission MaSanté (2 %)           −300 FCFA│
│                                              │
│  VOUS RECEVEZ                    14 225 FCFA │
└──────────────────────────────────────────────┘
```

Un partenaire qui peut refaire l'addition ne se sent jamais volé. C'est le dispositif de confiance le plus puissant du modèle, et il ne coûte rien à produire.

### 2.5 Le coût réel du paiement — et ce qu'il impose

Tarification GeniusPay observée : **100 FCFA fixes + 1 %**, plus les frais de la passerelle (Wave 1,5 %, PawaPay ≈ 3,5 %).

**Deux points souvent mal compris, et qui changent le calcul :**

- **Le paiement marchand Wave n'est pas gratuit.** Il est gratuit *pour le patient* ; c'est l'établissement qui supporte la commission. Le tableau de bord GeniusPay applique 1,5 % sur la passerelle Wave. Toute modélisation qui omet cette couche sous-estime les frais d'environ 1,5 point.
- **Il n'existe pas de compte marchand par opérateur.** L'établissement ouvre **un seul compte marchand GeniusPay** ; l'agrégateur route ensuite vers Wave, Orange Money, MTN, Moov, PawaPay et les cartes. C'est précisément ce que financent les 100 FCFA + 1 %.
- **Wave et PawaPay ne peuvent pas être désactivés** dans le tableau de bord GeniusPay. Une stratégie « Wave uniquement » est donc techniquement impossible — et commercialement dommageable, Wave représentant environ 40 % des transactions de monnaie électronique du pays.

**Règle d'affichage retenue :** tous les moyens de paiement restent proposés, Wave présenté en premier avec la mention « le moins de frais pour votre établissement ». Le patient choisit ; l'établissement voit sur son reçu quel opérateur a coûté quoi.

| Montant de l'acte | Frais totaux (via Wave) | Part |
|---:|---:|---:|
| 3 000 FCFA | 175 | **5,83 %** |
| 5 000 FCFA | 225 | 4,50 % |
| 10 000 FCFA | 350 | 3,50 % |
| 15 000 FCFA | 475 | 3,17 % |
| 25 000 FCFA | 725 | 2,90 % |
| 50 000 FCFA | 1 350 | 2,70 % |
| 100 000 FCFA | 2 600 | 2,60 % |

**Trois règles produit non négociables en découlent :**

1. **Une seule transaction par panier**, jamais ligne par ligne. Une ordonnance de 5 médicaments réglée en 5 fois coûte 500 FCFA de frais fixes au lieu de 100.
2. **Seuil de pertinence de l'encaissement en ligne : environ 10 000 FCFA.** En dessous, les frais mangent la marge de l'établissement. L'application propose alors « payer sur place » par défaut, tout en gardant la réservation en ligne.
3. **Négocier une grille aggregateur avec GeniusPay** dès 20 établissements actifs — voir §5.

### 2.6 Le patient ne paie jamais

Aucun abonnement patient, aucune commission patient, aucune publicité, aucune revente de données.

**Garantie de parité tarifaire** : le prix affiché sur MaSanté est identique au prix du guichet. Cette clause figure au contrat partenaire. Sans elle, le patient soupçonne un surcoût, et l'adoption s'effondre.

### 2.7 Le déclencheur d'encaissement — la facture patient

Tout acte générant une facture dans un hôpital, une clinique, un laboratoire ou un centre de santé (consultation, analyse, imagerie, intervention, hospitalisation) produit une **facture émise depuis le portail de l'établissement** et poussée dans l'application du patient.

**Pourquoi c'est le pivot du modèle.** Tant que le paiement est une action que le patient doit initier, la caisse peut le rediriger vers le QR maison (§7.1). Lorsque la facture existe déjà dans MaSanté avant l'arrivée au guichet, le règlement suit son support naturel. La capture n'est plus obtenue par persuasion mais par construction — c'est la mesure la plus efficace du dispositif anti-désintermédiation.

**Pourquoi c'est le pivot de l'argument CMU.** La facture est le seul endroit où la couverture devient lisible pour le patient :

```
Consultation générale              5 000 FCFA
Prise en charge CMU (70 %)        −3 500 FCFA
Reste à votre charge               1 500 FCFA
```

Rapporté au constat du §1.1 — moins de 4 % des enrôlés utilisant leur carte — cette seule ligne d'affichage a plus d'effet sur l'usage réel de la CMU qu'une campagne de sensibilisation.

**Moment du paiement — au choix de l'établissement.** Chaque type d'acte porte un champ `moment_paiement` :

| Valeur | Qui porte le risque | Usage typique |
|---|---|---|
| `AVANT_ACTE` | Personne — pas de soin sans règlement | Consultation, analyse, imagerie |
| `APRES_ACTE` | **L'établissement**, en cas d'impayé | Hospitalisation, urgence, suivi chronique |

MaSanté ne décide jamais à la place de l'établissement : c'est lui qui choisit le risque qu'il accepte de porter. Sans ce paramétrage, la plateforme lui créerait un poste d'impayés qu'il n'avait pas avant — objection partenaire rédhibitoire.

**Règles de confidentialité de la notification — non négociables.**

La notification temps réel ne doit **jamais** révéler le motif médical. Un intitulé affiché sur un écran verrouillé est visible par l'entourage ; le nom seul de certains établissements est déjà révélateur. Conformité loi n°2013-450.

| | |
|---|---|
| Notification autorisée | « Vous avez une nouvelle facture · 15 000 FCFA » |
| Notification interdite | Tout libellé d'acte, de service, de spécialité ou d'établissement |
| Détail | Accessible uniquement après ouverture authentifiée de l'application |

La sonnerie personnalisée est retenue, mais **discrète et non identifiable comme médicale**. La sonorité la plus distinctive de l'application reste réservée aux alertes d'urgence : une confusion entre le son « facture » et le son « SOS » serait un défaut de sécurité, pas un défaut de confort.

**Portée familiale.** Le carnet étant familial (module 2), une facture peut concerner un ayant droit. La liste affiche systématiquement le bénéficiaire — « Facture pour Aya Konan, 6 ans » — faute de quoi un parent gérant plusieurs enfants ne s'y retrouve pas.

**Interface.** Icône seule en page d'accueil, face au nom du titulaire, conformément au parti pris minimaliste. La découvrabilité est assurée par une **pastille indiquant le nombre de factures en attente** : c'est elle qui attire l'œil, non l'icône. Libellé « Factures » affiché à la première ouverture uniquement. Deux onglets : *À régler* et *Historique*.

**Ton de l'interface.** Aucune relance agressive, aucun marquage anxiogène d'impayé. Une personne sortant d'hospitalisation et en difficulté de paiement ne doit pas être mise sous pression par l'application. Rappel unique à l'échéance, puis silence.

**Responsabilité juridique.** La facture est **émise par l'établissement**, jamais par MaSanté. La plateforme n'est qu'un canal d'affichage et d'encaissement — cohérent avec le montage A du §5.3 et avec la position hors agrément BCEAO.

---

## PARTIE 3 — ADAPTATION PAR TYPE DE PARTENAIRE

Le même modèle appliqué uniformément échouerait : la structure de marge d'une pharmacie n'a rien à voir avec celle d'une clinique.

### 3.1 Hôpitaux, cliniques, cabinets

**Ce qu'ils gagnent :** remplissage des créneaux vides, réduction des rendez-vous non honorés grâce aux rappels automatiques, conversion des porteurs de carte CMU dormants, facturation et dossiers de remboursement CNAM préparés automatiquement, moins d'espèces manipulées à la caisse.

**Panier moyen :** 5 000 – 100 000 FCFA. Abonnement + commission dégressive.

### 3.2 Laboratoires et centres d'imagerie

**Ce qu'ils gagnent :** réception directe des demandes d'examen prescrites par les médecins de la plateforme, résultats transmis dans le dossier patient sans déplacement, traçabilité des prélèvements.

**Panier moyen :** 10 000 – 80 000 FCFA — la zone où l'encaissement en ligne est le plus rentable. Cible prioritaire année 1.

### 3.3 Ambulances et urgences

Gratuit intégralement. Le module SOS (**SAMU 185**) n'est jamais monétisé. C'est une position éthique, et c'est aussi le principal argument de légitimité auprès du Ministère.

### 3.4 Pharmacies — le cas particulier

**Marges faibles, paniers petits, fréquence élevée.** Règle retenue, choisie par l'officine elle-même :

| Parcours du patient | Ce que paie la pharmacie |
|---|---|
| Réservation ou commande seule, règlement au comptoir | **0 %** — abonnement uniquement |
| Commande **réglée en ligne** via MaSanté | Barème §2.3 + frais de paiement à prix coûtant |

La pharmacie n'est donc jamais prélevée sur une vente qu'elle aurait faite de toute façon.

**Plancher obligatoire : le paiement en ligne n'est proposé qu'au-dessus de 5 000 FCFA.** En dessous, la chaîne de frais devient confiscatoire sur des médicaments à prix homologué :

| Panier | Frais paiement | Commission 2,5 % | Total prélevé | Part |
|---:|---:|---:|---:|---:|
| 3 000 | 175 | 75 | 250 | **8,3 %** |
| 5 000 | 225 | 125 | 350 | 7,0 % |
| 10 000 | 350 | 250 | 600 | 6,0 % |
| 25 000 | 725 | 625 | 1 350 | 5,4 % |

*À vérifier auprès d'un pharmacien d'officine : la marge réglementaire réelle sur les médicaments de la LNME, afin de confirmer que le seuil de 5 000 FCFA est le bon.*

**L'objection attendue et sa réponse.** Une pharmacie redoute qu'un comparateur de prix déclenche une guerre des prix. Mais en Côte d'Ivoire, **les prix des médicaments essentiels sont homologués et réglementés** : ils sont identiques d'une officine à l'autre. Le module n'est donc pas un comparateur de prix mais un **localisateur de disponibilité** : *quelle officine proche a ce médicament en stock, maintenant, au prix officiel ?*

Cette reformulation transforme la menace en argument de vente : la pharmacie ne perd pas de marge, elle capte le patient qui, sinon, aurait fait trois officines avant de trouver.

Deux modes d'intégration au choix (déjà spécifiés au CDC_11 §7.7) : gestion directe du stock dans le portail, ou synchronisation API depuis le logiciel de caisse existant — **sans aucune ressaisie**.

### 3.5 Assurances et CNAM

Non monétisé en phase initiale. La valeur est la vérification de droits en temps réel et la réduction de fraude. Modèle de revenus à construire ultérieurement (frais par vérification, ou contrat de service).

---

## PARTIE 4 — L'OFFRE DE PARTENARIAT

### 4.1 La Charte MaSanté — sept engagements contractuels

Ces engagements figurent au contrat, pas dans une plaquette. Ils sont opposables.

1. **Zéro franc avant le premier résultat.** Palier 0 gratuit à vie, puis 3 mois d'abonnement offerts. Vous ne payez qu'après avoir constaté la valeur.
2. **Le prix est le même partout.** Ce que le patient voit sur MaSanté est ce qu'il paie au guichet. Nous n'ajoutons jamais de frais à votre tarif.
3. **Vous voyez chaque franc.** Reçu détaillé sur chaque transaction. Les frais de paiement vous sont refacturés à prix coûtant, sans marge.
4. **Nous ne touchons jamais votre argent.** Chaque paiement arrive directement sur votre compte marchand. MaSanté n'est ni dépositaire ni intermédiaire de fonds.
5. **Le classement ne s'achète pas.** L'ordre d'affichage dépend de la proximité, de la disponibilité et de la spécialité. Jamais du paiement. Aucune option de mise en avant payante n'existe ni n'existera.
6. **Vos données ne sont pas à vendre.** Aucune donnée de santé, nominative ou non, n'est cédée à un tiers commercial, un assureur ou un laboratoire.
7. **Vous partez quand vous voulez.** Sans engagement, résiliation au mois, et export intégral de vos données au format standard sous 7 jours.

L'engagement 7 est contre-intuitif — il facilite le départ. C'est précisément ce qui rend l'offre difficile à refuser : un partenaire qui sait qu'il peut partir n'a aucune raison de ne pas essayer.

### 4.2 Le parcours d'un partenaire, en cinq étapes

| Étape | Durée | Ce que fait le partenaire | Ce qu'il paie |
|---|---|---|---|
| 1. Référencement | 1 jour | Vérification des pièces légales, fiche publiée | 0 |
| 2. Découverte | 1 mois | Il reçoit des demandes de rendez-vous, observe | 0 |
| 3. Essai | 3 mois | Portail complet activé, formation incluse | 0 |
| 4. Régime normal | — | Abonnement + commission dégressive | Selon §2.2 |
| 5. Optimisation | — | Passage automatique au palier de commission inférieur | Moins qu'avant |

### 4.3 L'argumentaire en une phrase

> « Vous êtes visible gratuitement, vous testez trois mois sans payer, vous ne versez de commission que sur l'argent que vous avez réellement encaissé, vous voyez chaque franc prélevé, et vous partez quand vous voulez avec vos données. »

### 4.4 Ce que MaSanté s'interdit

- Vendre du positionnement ou de la mise en avant
- Imposer une clause d'exclusivité
- Retenir les données d'un partenaire sortant
- Monétiser le module d'urgence
- Facturer le patient sous quelque forme que ce soit
- Modifier les tarifs affichés par l'établissement

---

## PARTIE 5 — LE TRIANGLE MaSanté / PARTENAIRES / GENIUSPAY

### 5.1 Ce que chaque partie apporte et reçoit

| | Apporte | Reçoit |
|---|---|---|
| **Établissement** | Son offre de soins, ses tarifs, sa disponibilité | Patients, outils de gestion, encaissement, données CMU |
| **Patient** | Son usage, ses données de parcours (avec consentement) | Accès gratuit, orientation, transparence des prix |
| **MaSanté** | La plateforme, l'intégration, le support | Abonnements et commissions |
| **GeniusPay** | Le rail de paiement, la conformité | **Volume marchand acquis sans coût d'acquisition** |

### 5.2 La négociation à mener avec GeniusPay

MaSanté n'est pas un marchand parmi d'autres : c'est un **apporteur de marchands**. Chaque établissement signé est un compte marchand que GeniusPay n'a pas eu à démarcher. À partir de 20 établissements actifs, cet argument justifie de demander :

1. **La suppression ou la réduction des 100 FCFA fixes** — c'est le poste qui rend l'encaissement des petits actes non viable, et donc ce qui limite le volume que MaSanté peut leur apporter. L'intérêt est partagé.
2. **Une grille dégressive au volume agrégé** sur l'ensemble des comptes apportés.
3. **Le paiement fractionné (split payment)**, indispensable si MaSanté veut prélever sa commission au fil de l'eau — voir §5.3.
4. **Un accompagnement à l'onboarding KYC** des établissements.

### 5.3 Point d'architecture bloquant — à trancher

Le business plan pose comme principe non négociable (CDC §3.4) que **MaSanté n'est jamais dépositaire des fonds**. Deux montages permettent de le respecter :

| Montage | État | Conséquence |
|---|---|---|
| **A — Un compte marchand par établissement** | ✅ Disponible aujourd'hui | Chaque établissement encaisse directement. MaSanté facture sa commission mensuellement, hors flux. KYC à faire par établissement. |
| **B — Paiement fractionné** | ❌ **API Payouts non disponible** chez GeniusPay (liste d'attente bêta) | Serait plus fluide, mais techniquement impossible aujourd'hui. |

**Décision recommandée : montage A.** Au-delà d'être le seul disponible, il présente un avantage juridique majeur : MaSanté n'étant à aucun moment intermédiaire de fonds, elle **n'entre pas dans le champ de l'agrément BCEAO d'établissement de paiement**. C'est une protection réglementaire à faire figurer explicitement au mémoire.

*Contrepartie à documenter honnêtement : la commission est facturée hors flux, donc exposée à l'impayé. Prévoir un prélèvement mensuel automatisé et une suspension du Palier 1 après 30 jours d'impayé (retour au Palier 0, jamais de coupure brutale).*

### 5.4 Deux points à faire confirmer par écrit à GeniusPay

- L'**attestation PCI DSS (AoC)** — annoncée sur leur site, absente de leurs CGU
- Le **statut réglementaire** — leurs CGU indiquent qu'ils ne sont pas établissement de paiement agréé BCEAO mais intégrateur technique. À clarifier, car cela détermine qui porte la responsabilité en cas d'incident.

---

## PARTIE 6 — CE QUE FONT LES MEILLEURES PLATEFORMES

| Plateforme | Marché | Modèle | Leçon retenue pour MaSanté |
|---|---|---|---|
| **Doctolib** | France | Abonnement praticien seul (≈135 €/mois), patient 100 % gratuit, **aucune commission**, pas d'accès aux données de santé | La pureté du modèle crée la confiance. Mais suppose un pouvoir d'achat praticien et une force de vente. **Retenu : gratuité patient, refus de la monétisation des données.** |
| **Ping An Good Doctor** | Chine | Consultations, abonnements famille, **vente de médicaments**, partenariats assureurs, plus de 32 000 pharmacies partenaires | L'argent n'est pas dans la consultation, il est dans **ce qui vient après** : médicament, examen, assurance. **Retenu : le module pharmacie n'est pas un accessoire, c'est un pilier.** |
| **Helium Health** | Nigéria, Afrique de l'Ouest | SaaS par paliers selon la taille de l'établissement, **plus du crédit** (HeliumCredit) — le crédit pourrait dépasser le SaaS en revenus | Le cas le plus proche du contexte ivoirien. En Afrique, le SaaS seul monte lentement ; **la finance embarquée est là où se trouve la marge**. |
| **mPharma** | Ghana, Afrique | Abonnement + marge sur l'approvisionnement médicament | Modèle mixte abonnement/flux validé sur le continent. |

### 6.1 La perspective long terme — à documenter, pas à livrer

La leçon Helium Health mérite d'être écrite au mémoire comme **perspective** et non comme livrable L3 :

MaSanté verra passer le chiffre d'affaires réel de chaque établissement partenaire. Or les remboursements CNAM sont lents, ce qui pèse sur la trésorerie des structures. À terme, MaSanté pourrait proposer une **avance sur créances CMU** — un produit à très forte valeur pour le partenaire, adossé à une donnée que personne d'autre ne possède.

**Hors périmètre L3** : nécessite un agrément, un partenaire bancaire et un capital. À présenter en perspective d'évolution, jamais comme fonctionnalité du prototype.

---

## PARTIE 7 — MODÈLE FINANCIER RECALCULÉ

Les hypothèses de volume du business plan v1.0 sont conservées. Trois éléments nouveaux entrent dans le calcul.

### 7.1 Deux hypothèses nouvelles — et la distinction qui les sépare

Le business plan v1.0 supposait implicitement que **100 % des actes seraient réglés via la plateforme**. C'est irréaliste, et un jury le relèvera. Mais il faut décomposer, car deux mécanismes très différents sont en jeu.

**Taux de paiement électronique — le patient veut-il payer par téléphone ?**

Oui, et c'est un point terrain fort : le **problème de monnaie** est une friction quotidienne en caisse. Le patient présente un billet de 10 000 FCFA, la caisse n'a pas l'appoint, tout le monde perd du temps. Un paiement mobile est exact au franc près. La préférence est particulièrement marquée chez les **jeunes** et chez les **fonctionnaires**, ces derniers étant par ailleurs salariés, payés par virement et enrôlés à la CMU par retenue sur salaire — un segment homogène et facile à cibler en priorité.

Restent les actes couverts à 100 % par la CMU, qui ne donnent lieu à aucun encaissement, et le plancher de 5 000 FCFA.

**Taux de capture plateforme — ce paiement passe-t-il par MaSanté ?**

C'est une tout autre question, et c'est **le principal risque du modèle**. L'établissement dispose déjà de son propre QR Wave en caisse :

| Où le patient règle | Coût pour l'établissement |
|---|---:|
| QR Wave de l'établissement, à la caisse | ≈ 1 % |
| Via MaSanté | ≈ 2,5 % + commission MaSanté |

Un agent de caisse qui oriente le patient vers le QR maison fait économiser son employeur. Ce comportement apparaîtra, sans mauvaise foi particulière. **La désintermédiation en caisse est le risque n°1 à traiter au niveau produit, pas au niveau contractuel.**

**Hypothèses retenues**

| | Année 1 | Année 2 | Année 3 |
|---|---:|---:|---:|
| Patient règle par voie électronique | 75 % | 80 % | 85 % |
| Ce règlement passe par MaSanté | 80 % | 85 % | 90 % |
| **Taux d'encaissement MaSanté (produit des deux)** | **60 %** | **68 %** | **76 %** |

*Les valeurs de 60 / 68 / 76 % sont conservées dans les calculs du §7.3 sous la forme arrondie 60 / 70 / 75 %. L'écart est négligeable et le tableau reste comparable à la v1.0.*

### 7.1 bis Les trois mesures anti-désintermédiation

1. **Prépaiement à la réservation — mesure principale.** Si le patient règle au moment où il prend rendez-vous, il n'y a pas de caisse à contourner. Bénéfice partagé : un rendez-vous payé n'est presque jamais manqué, ce qui attaque directement le problème des créneaux perdus.
2. **Donner à la caisse ce que le QR seul ne donne pas** — reçu automatique dans le carnet de santé, dossier de remboursement CMU pré-rempli, dispense de file d'attente. Si le passage par MaSanté fait gagner du temps au patient et du travail administratif à l'établissement, personne n'a intérêt à contourner.
3. **Le forfait tout compris à 0 % de commission.** Un établissement qui refuse le principe de la commission peut souscrire un abonnement majoré, sans aucune commission quel que soit le volume. Au-delà d'environ **1 500 000 FCFA encaissés par mois**, c'est lui qui y gagne — et il n'a alors plus aucune raison de détourner un paiement. Le risque se transforme en revenu prévisible.

*Indicateur de supervision à implémenter : le rapport entre rendez-vous confirmés et paiements encaissés, par établissement. Un écart durable signale une désintermédiation et doit déclencher une conversation commerciale, jamais une sanction automatique.*

### 7.2 Ce qui change par rapport à la v1.0

| Élément | v1.0 | v2.0 |
|---|---|---|
| Abonnement | Dès la signature | Après 3 mois offerts (20 premiers) puis 1 mois |
| Commission | 2 % fixe, dès le 1er jour | 2,5 % → 1 % dégressif, **dès le 1er jour, y compris pendant l'essai** |
| Assiette de la commission | 100 % du volume d'actes | **Volume réellement encaissé en ligne** (60 → 75 %) |
| Frais de paiement | Non modélisés | Supportés par l'établissement, refacturés à prix coûtant |
| Palier gratuit | Inexistant | Palier 0 à vie |

### 7.3 Résultats

| Indicateur (FCFA) | Année 1 | Année 2 | Année 3 |
|---|---:|---:|---:|
| Établissements actifs en moyenne | 9 | 38 | 120 |
| Volume total des actes | 41 580 000 | 324 900 000 | 1 728 000 000 |
| Taux d'encaissement en ligne | 60 % | 70 % | 75 % |
| **Volume encaissé via MaSanté** | **24 948 000** | **227 430 000** | **1 296 000 000** |
| Volume mensuel moyen par établissement | 231 000 | 498 750 | 900 000 |
| Palier de commission atteint | 2,5 % | 2,0 % | 2,0 % |
| Commissions | 623 700 | 4 548 600 | 25 920 000 |
| Abonnements bruts | 3 240 000 | 14 592 000 | 50 400 000 |
| Mois offerts (nouveaux entrants) | −810 000 | −1 440 000 | −4 200 000 |
| Abonnements nets | 2 430 000 | 13 152 000 | 46 200 000 |
| **Chiffre d'affaires** | **3 053 700** | **17 700 600** | **72 120 000** |
| Charges d'exploitation | 6 000 000 | 22 400 000 | 66 000 000 |
| **Résultat d'exploitation** | **−2 946 300** | **−4 699 400** | **+6 120 000** |
| Résultat cumulé | −2 946 300 | −7 645 700 | **−1 525 700** |

Rappel v1.0 pour comparaison : CA de 4 071 600 / 21 090 000 / 84 960 000 et résultat cumulé de +15 721 600 en année 3.

**Lecture.** Le modèle v2.0 est nettement moins optimiste, et c'est voulu : il intègre des frais et des taux de conversion que la v1.0 ignorait. L'équilibre annuel est atteint en année 3, l'équilibre cumulé quelques mois plus tard. Le besoin de financement de **15 000 000 FCFA reste suffisant** — le point bas du cumul est de −7,6 M FCFA.

### 7.4 Le point qui décide de tout — la durée de l'essai

Le calcul fait apparaître un résultat contre-intuitif qu'il faut avoir en tête.

**Si les 3 mois offerts étaient accordés à tous les partenaires**, sans limitation, le coût atteindrait 4 320 000 FCFA en année 2 et 12 600 000 en année 3. Le résultat d'exploitation deviendrait **−7 579 400 en année 2 et −2 280 000 en année 3** : la plateforme n'atteindrait jamais l'équilibre sur l'horizon du plan.

Ce n'est pas l'offre qui est mauvaise, c'est son application indifférenciée. En limitant les 3 mois aux **20 premiers partenaires** et en passant à 1 mois ensuite, on conserve l'argument décisif là où il est réellement nécessaire — auprès des premiers signataires, quand la plateforme n'a encore rien à montrer — et on protège le modèle à l'échelle.

**Sensibilité au taux d'encaissement en ligne (commissions année 3) :**

| Taux | Volume encaissé | Commissions | Résultat d'exploitation A3 |
|---:|---:|---:|---:|
| 60 % | 1 036 800 000 | 20 736 000 | +936 000 |
| **75 % (retenu)** | **1 296 000 000** | **25 920 000** | **+6 120 000** |
| 90 % | 1 555 200 000 | 31 104 000 | +11 304 000 |

Chaque point de taux d'encaissement en ligne vaut environ **345 000 FCFA de commission en année 3**. C'est l'indicateur à suivre en priorité une fois la plateforme lancée — bien avant le nombre d'établissements signés.

### 7.5 Point mort par établissement

Un établissement au Palier 1 devient rentable pour MaSanté dès lors qu'il couvre son coût de support et d'hébergement. Avec un abonnement de 30 000 FCFA et une commission à 2,5 %, un établissement encaissant 231 000 FCFA par mois rapporte **35 775 FCFA par mois**. À charges d'exploitation constantes rapportées au nombre d'établissements actifs, le seuil se situe autour de **55 000 FCFA de revenu mensuel par établissement**, soit environ 1 000 000 FCFA de volume encaissé.

**Conséquence stratégique : un partenaire à faible volume coûte de l'argent.** Le Palier 0 gratuit n'est donc pas seulement généreux, il est aussi le bon endroit où laisser les établissements qui n'ont pas encore de volume — sans support dédié, sans coût pour MaSanté.

---

## PARTIE 8 — SPÉCIFICATION POUR IMPLÉMENTATION

**À n'exécuter qu'après validation de l'encadreur.** Aucune de ces tables ne doit être créée avant arbitrage des points de la Partie 9.

### 8.1 Tables à créer

```
factures
  id, etablissement_id, patient_id, beneficiaire_id
  reference, date_emission, date_echeance
  moment_paiement (AVANT_ACTE | APRES_ACTE)
  montant_brut, montant_pris_en_charge_cmu, montant_reste_a_charge
  statut (A_REGLER | PAYEE | PRISE_EN_CHARGE_TOTALE | ANNULEE | EXPIREE)
  transaction_paiement_id, date_reglement

lignes_facture
  id, facture_id, libelle_acte, code_acte_national
  quantite, prix_unitaire, taux_cmu_applique, montant_ligne
  -- jamais transmis en notification (voir R14)

plans_tarifaires
  id, code (P0_VISIBILITE | P1_GESTION | P2_INTEGRATION)
  libelle, montant_mensuel_fcfa, actif, date_effet, date_fin

abonnements_etablissement
  id, etablissement_id, plan_id
  date_debut, date_fin_essai, date_fin
  statut (ESSAI | ACTIF | SUSPENDU | RESILIE)
  motif_suspension, date_prochaine_facturation

bareme_commission
  id, volume_min_fcfa, volume_max_fcfa, taux_pourcentage
  date_effet, date_fin

transactions_commission
  id, transaction_paiement_id, etablissement_id
  montant_brut, frais_passerelle, frais_geniuspay
  taux_commission_applique, montant_commission
  montant_net_etablissement
  volume_mensuel_cumule_au_moment_du_calcul
  reference_geniuspay, statut

factures_etablissement
  id, etablissement_id, periode_debut, periode_fin
  montant_abonnement, montant_commissions, montant_total
  statut (BROUILLON | EMISE | PAYEE | IMPAYEE)
  date_emission, date_echeance, date_paiement
```

### 8.2 Règles métier à implémenter

| # | Règle |
|---|---|
| R1 | Tout établissement validé démarre au Palier 0, sans action requise. |
| R2 | Le passage au Palier 1 ouvre un essai de **90 jours pour les 20 premiers partenaires, 30 jours ensuite**. Le compteur d'ordre de signature est figé et auditable. |
| R2b | Pendant l'essai, **seul l'abonnement est offert**. La commission est calculée et facturée normalement dès la première transaction. |
| R3 | Le taux de commission est recalculé **à chaque transaction** sur le volume mensuel cumulé de l'établissement. |
| R4 | Les frais de passerelle et GeniusPay sont enregistrés séparément de la commission MaSanté et ne peuvent porter aucune marge. |
| R5 | Pour une pharmacie, la commission ne s'applique **que si la commande est réglée en ligne**. Une commande réservée et payée au comptoir est facturée 0 %. |
| R5b | Le paiement en ligne n'est **pas proposé** en dessous de 5 000 FCFA. Seule la réservation l'est. Seuil paramétrable en base, jamais en dur. |
| R5c | Tous les moyens de paiement restent proposés. Wave est affiché en premier. Aucun moyen n'est masqué au patient. |
| R6 | Les actes du module urgence sont exclus de toute commission. |
| R7 | Après 30 jours d'impayé, l'établissement bascule au Palier 0 — sa fiche reste publiée, ses données restent accessibles. Jamais de suppression. |
| R8 | Toute demande d'export de données est honorée sous 7 jours, au format standard. |
| R9 | Le classement dans les résultats de recherche ne prend en compte aucun critère de facturation. **À vérifier par un test automatisé.** |
| R10 | Le tarif affiché au patient est celui saisi par l'établissement, sans transformation. |
| R11 | Le prépaiement à la réservation est proposé par défaut dès que le tarif de l'acte est connu et supérieur au plancher. |
| R12 | Un indicateur `taux_capture` est calculé mensuellement par établissement : paiements encaissés / rendez-vous confirmés. Consultable par MaSanté uniquement. |
| R13 | Toute facture est émise par un établissement. MaSanté ne peut en créer aucune. |
| R14 | Le contenu de la notification de facture est limité au montant et à un libellé générique. Aucun champ médical, de service ou de spécialité n'y transite. **À couvrir par un test automatisé.** |
| R15 | Le type d'acte porte `moment_paiement` ∈ {AVANT_ACTE, APRES_ACTE}, valeur choisie par l'établissement. |
| R16 | Une facture affiche toujours le bénéficiaire, qui peut différer du titulaire du compte. |
| R17 | Une facture au-dessous du plancher de 5 000 FCFA s'affiche mais n'ouvre pas le paiement en ligne. |
| R18 | Une seule relance par facture, à l'échéance. Aucune relance répétée, aucun marquage anxiogène. |

### 8.3 Écrans du portail établissement

- **Tableau de bord facturation** : volume du mois, palier de commission en cours, montant restant avant le palier inférieur
- **Détail des transactions** : le reçu du §2.4 pour chaque paiement
- **Factures** : historique, téléchargement PDF
- **Mon plan** : palier actuel, date de fin d'essai, bouton de résiliation accessible en deux clics maximum
- **Exporter mes données** : bouton visible, non enterré dans les paramètres

---

## PARTIE 9 — POINTS D'ARBITRAGE

À trancher avec l'encadreur avant toute écriture de code.

| # | Point | Recommandation |
|---|---|---|
| A1 | Durée de l'essai gratuit | **3 mois pour les 20 premiers partenaires, 1 mois ensuite** — §7.4 montre que l'application indifférenciée empêche l'atteinte de l'équilibre |
| A1b | Commission pendant l'essai | **Oui, 2,5 % dès le 1er jour** — décision prise. À écrire au contrat en toutes lettres. |
| A1c | Taux de paiement électronique (75/80/85 %) | À confirmer par l'enquête terrain prévue au mémoire (§1.5.2 du plan de rédaction) |
| A1d | Taux de capture plateforme (80/85/90 %) | À valider — dépend entièrement du déploiement du prépaiement à la réservation |
| A1e | Forfait tout compris à 0 % de commission | À trancher — neutralise le risque de désintermédiation mais complexifie la grille |
| A2 | Barème dégressif ou taux unique à 2 % | **Dégressif** — coût faible en année 1, effet de fidélisation fort |
| A3 | Traitement des pharmacies | **Commission uniquement sur commande réglée en ligne**, plancher à 5 000 FCFA. Marge officine réelle à confirmer auprès d'un pharmacien. |
| A4 | Montage A ou B pour le flux des fonds | **A** — seul disponible, et protège de l'agrément BCEAO |
| A5 | Le module pharmacie fait-il partie du périmètre L3 ? | **Oui — décision prise.** À séquencer après validation des modules 1 et 2. |
| A6 | L'avance sur créances CMU figure-t-elle au mémoire ? | **Oui, en perspective uniquement**, jamais présentée comme livrable |

---

## SOURCES

| Donnée | Source | Date |
|---|---|---|
| 23 M d'enrôlés CMU, 3 097 établissements publics, 1 797 518 consultations 2025, moyenne mensuelle 28 700 → 210 000 | Ministère de la Santé (MSHPCMU), bilan annuel du ministre Pierre Demba | Février 2026 |
| Moins de 4 % des enrôlés ayant utilisé leur carte en 2025 | Chiffres officiels CNAM relayés par l'AFP | Août 2025 |
| 20 263 001 enrôlés / 8 616 935 cartes distribuées ; 2 437 établissements servant la CMU | Portail de l'économie ivoirienne | Juillet 2025 |
| Plus de 6 000 pharmacies privées servant les assurés CMU | Portail officiel du Gouvernement | 2025 |
| Paiements directs des ménages : 39 % du financement de la santé (2017) | OCDE, *Mobilisation des recettes fiscales pour le financement de la santé en Côte d'Ivoire* | 2020 |
| 30,73 % du financement des soins de santé primaires (2020-2021) | MSHPCMU / DES, Analyse du budget 2021-2024 | 2024 |
| Dépense directe moyenne 16 034 FCFA | Attia-Konan et al., *BMC Research Notes* | 2019 |
| 60,16 M abonnements mobiles ; 40,9 M internet mobile ; 26,88 M comptes de monnaie électronique | ARTCI, statistiques T1 2026 | Mars 2026 |
| 3 450 établissements sanitaires ; 1,9 médecin / 10 000 hab. | Ministère de la Santé, déclaration publique | Avril 2026 |
| Doctolib : ≈135 €/mois par praticien, patient gratuit, modèle sans commission | Déclarations publiques du fondateur et relevés de tarifs praticiens | 2022-2026 |
| Ping An Good Doctor : segments de revenus, 32 000+ pharmacies partenaires | Rapports semestriels et annuels de la société | 2019-2026 |
| Helium Health : SaaS par paliers + HeliumCredit | TechCrunch, communiqués de la société | 2023 |
| Tarification GeniusPay : 100 FCFA + 1 % ; Wave 1,5 % ; PawaPay ≈ 3,5 % | Tableau de bord marchand GeniusPay, onglet Gateways | Août 2026 |

---

*Document préparé pour validation. Les projections financières reposent sur les hypothèses du business plan v1.0 et ne constituent ni une garantie de résultat ni un conseil en investissement.*
