<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Module 4 / 4.1 — Rôles et permissions du portail administratif (Sécurité §4.1, CdC §5.4.2).
 *
 * Trois profils RBAC (spatie/laravel-permission), guard `web` (portail à sessions). Idempotent.
 * Crée aussi un compte ADMIN de bootstrap : sans lui, personne ne pourrait se connecter au portail
 * pour créer le premier établissement (le workflow 5.4.1 part de l'admin IVOIRSANTÉ).
 */
class PortailRolesSeeder extends Seeder
{
    /** Toutes les permissions du portail. */
    private const PERMISSIONS = [
        // Admin IVOIRSANTÉ
        'etablissement.manage',   // créer / modifier / supprimer des établissements
        'compte.manage',          // gérer tous les comptes
        'moderation.manage',      // modérer avis + signalements
        'stats.global',           // statistiques globales
        'sante_publique.manage',  // publier / gérer les alertes épidémiques (FN3)
        // Gestionnaire d'établissement
        'service.manage',         // CRUD de SES services
        'agent.manage',           // créer / gérer SES agents
        'medecin.manage',         // annuaire des praticiens de SON établissement (RDV F3.5 + référent 5.6)
        'don_sang.manage',        // publier les besoins en sang de SON établissement (FN6)
        'medicament.manage',      // prix et ruptures de SA pharmacie (FN7/FN8, modèle freemium)
        // B3-a — servir une ordonnance (CDC_11 §7.1). PERMISSION DISTINCTE de `medicament.manage`,
        // et ce n'est pas de la prudence décorative : celle-ci appartient AUSSI au gestionnaire
        // d'établissement (P6.6a), donc la réutiliser laisserait un gestionnaire de CHU délivrer des
        // ordonnances. Servir une prescription est un acte de dispensation, pas la tenue d'un prix.
        'ordonnance.delivrer',
        // B3-d — traiter une commande de médicaments (CDC_11 §9.5). PERMISSION DISTINCTE, encore :
        // accepter une commande est un acte de relation client, dispenser un acte pharmaceutique
        // (même critère que `ordonnance.delivrer` ci-dessus). C'est la remise qui donne son sens à
        // la séparation — remettre une commande PORTANT UNE ORDONNANCE exige les DEUX permissions.
        'commande.traiter',
        // B5-b — enregistrer et faire avancer un prélèvement (CDC_09 §7.4). PERMISSION DISTINCTE
        // de `medicament.manage`/`ordonnance.delivrer` : exécuter un prélèvement biologique n'est
        // ni tenir un prix ni dispenser un médicament. `analyse.valider` (le VERDICT du biologiste,
        // B5-c) sera volontairement orpheline — celle-ci ne l'est pas : exécuter un prélèvement est
        // le métier même du laborantin, comme délivrer l'est du pharmacien.
        'analyse.executer',
        'stats.etablissement',    // statistiques de SON établissement
        // Agent de garde
        'disponibilite.manage',   // mettre à jour la dispo de SON service
        // B1-a — DEUX permissions distinctes là où il n'y en avait qu'une (CDC_11 §9.1 littéral :
        // « le médecin fait la validation finale »). `rdv.prevalider` porte l'étape 1 (accueil,
        // en_attente→prevalide) ; `rdv.validate` porte désormais SEULEMENT l'étape 2 (médecin,
        // prevalide→confirme). Avant B1-a les deux rôles partageaient `rdv.validate` et rien ne
        // les distinguait dans le code — voir le commentaire du rôle `medecin` plus bas.
        'rdv.prevalider',         // pré-valider une demande de RDV (étape 1, accueil)
        'rdv.validate',           // valider (finale) ou refuser un RDV pré-validé
        'qr.scan',                // scanner le QR patient (carnet / RDV)
        'triage.view',            // consulter la fiche de triage reçue
        'dossier.referent',       // ouvrir sans QR le dossier des patients qui vous ont désigné (5.6)
        // Urgences — VOLONTAIREMENT ATTRIBUÉE À AUCUN RÔLE (Note_Continuite §5.3) : le gestionnaire
        // l'accorde individuellement, et seulement aux agents d'un service d'urgences.
        'urgence.bris_de_glace',  // ouvrir le vital minimal d'un patient hors d'état de consentir
        // Écriture au carnet (D0) — VOLONTAIREMENT ATTRIBUÉE À AUCUN RÔLE, même précédent :
        // `agent_garde` porte `qr.scan` et sert l'accueil ; un agent d'accueil ne rédige pas une
        // ordonnance. Le gestionnaire l'accorde individuellement aux soignants habilités.
        'dossier.ecrire',         // consigner un acte dans le carnet, pendant une session ouverte
        // Retour clinique sur une orientation (P10c-2-i, CDC_05 §5.5.4 / §9.1) — ATTRIBUÉE, ELLE,
        // AU RÔLE `medecin`, ET LA DIFFÉRENCE AVEC LES TREIZE PRÉCÉDENTES DOIT ÊTRE DITE.
        //
        // Les permissions volontairement orphelines de ce projet gouvernent toutes des
        // RÉFÉRENTIELS NATIONAUX : elles décident de ce qui fait autorité pour tout le pays, et les
        // laisser sans rôle empêche qu'un acteur devienne juge et partie sur ses propres données.
        //
        // Celle-ci est d'une autre nature : c'est un acte de soin ordinaire, posé au chevet, sur un
        // seul patient. La laisser orpheline n'aurait protégé personne et aurait garanti l'inverse
        // du but recherché — **la boucle d'apprentissage du §5.5.4 ne démarrerait jamais**, faute
        // de quelqu'un pour donner le premier retour.
        //
        // Elle n'est pas donnée à `agent_garde` pour autant : juger si une orientation était
        // adaptée est un jugement clinique, et un agent d'accueil n'a pas à le porter. Même
        // raisonnement que `dossier.ecrire` ci-dessus, dont l'exclusion visait un rôle d'accueil.
        'triage.retour',          // dire si l'orientation rendue par le triage était adaptée
        // Référentiels nationaux (P6.3, CDC_09 §10 « accès en écriture strictement réservé aux
        // rôles habilités ») — VOLONTAIREMENT ATTRIBUÉES À AUCUN RÔLE MÉTIER, troisième occurrence
        // du même précédent. Deux permissions distinctes et non une seule : le quatre-yeux du §10
        // suppose que proposer et décider puissent être portés par des personnes différentes.
        //
        // À DIRE HONNÊTEMENT : `admin_ivoirsante` les reçoit quand même, comme toutes les autres,
        // par le `syncPermissions(Permission::all())` ci-dessous. Cela n'affaiblit pas le
        // quatre-yeux — qui sépare deux UTILISATEURS, pas deux rôles — mais cela veut dire que le
        // filtrage réel se joue à l'attribution nominative, pas dans cette liste.
        'referentiel.proposer',   // soumettre un changement de référentiel national à décision
        'referentiel.publier',    // décider du sort d'une proposition : la publier ou la rejeter
        // Habilitation professionnelle (P6.5a, CDC_09 §5.2) — CINQUIÈME occurrence du précédent :
        // VOLONTAIREMENT ATTRIBUÉE À AUCUN RÔLE MÉTIER, et ici la raison est plus forte qu'ailleurs.
        //
        // `medecin.manage` laisse un gestionnaire décrire les praticiens de SON établissement :
        // nom, spécialité, tarif, service. Mais l'AUTORISATION D'EXERCER n'est pas une donnée
        // d'établissement — elle est délivrée et retirée par un ordre professionnel. Laisser un
        // hôpital déclarer ses propres médecins autorisés reviendrait à lui faire signer le
        // contrôle qui le vise : c'est le §5.4 qui s'appuiera dessus pour laisser signer une
        // ordonnance, et il serait alors adossé à une déclaration de l'intéressé.
        'professionnel.habiliter', // déclarer / modifier l'autorisation d'exercer et le n° d'ordre
        // Référentiel national des médicaments (P6.6a, CDC_09 §6.2) — ATTRIBUÉE À AUCUN RÔLE, même
        // motif. `medicament.manage` ci-dessus appartient au gestionnaire d'établissement pour les
        // prix et les ruptures de SA pharmacie ; l'étendre au catalogue national laisserait une
        // officine écrire les indications, les contre-indications et les INTERACTIONS, et un
        // laboratoire fabricant serait juge et partie sur son propre produit.
        'medicament.referentiel', // éditer le contenu de travail du référentiel des médicaments
        // Catalogue des analyses (P6.7a, CDC_09 §7.3) — ATTRIBUÉE À AUCUN RÔLE, septième occurrence,
        // et la raison est ici plus nette encore : un LABORATOIRE ne peut pas fixer les valeurs de
        // référence nationales, il serait juge et partie sur les résultats qu'il rend lui-même.
        'analyse.referentiel',    // éditer le catalogue des analyses et ses valeurs de référence
        // Vocabulaire des spécialités (P6.8a, CDC_09 §8) — ATTRIBUÉE À AUCUN RÔLE, huitième
        // occurrence. `service.manage` appartient au gestionnaire pour décrire les services de SON
        // établissement ; l'étendre au vocabulaire national laisserait chaque hôpital ajouter le
        // terme qui l'arrange, et la liste nationale deviendrait la somme des ambitions de chacun.
        // C'est aussi ce qui rendrait impossible la question du §4.4 : « combien de services de
        // cardiologie dans ce district ? » — si « cardio » et « cardiologie » coexistent, aucune.
        'specialite.referentiel', // éditer le vocabulaire des spécialités et activités de service
        // Vaccins et calendrier vaccinal (P6.8b, CDC_09 §8) — ATTRIBUÉE À AUCUN RÔLE, NEUVIÈME
        // occurrence. Un centre de vaccination ne peut pas décider à quel âge une dose est due ni
        // si elle est obligatoire : il serait juge et partie sur ce qu'il administre. Et un
        // calendrier vaccinal n'est pas une donnée d'exploitation — c'est une politique de santé
        // publique, dont le caractère obligatoire engage l'État.
        'vaccin.referentiel',     // éditer le référentiel des vaccins et le calendrier vaccinal
        // Référentiel des maladies (P6.8c, CDC_09 §8) — ATTRIBUÉE À AUCUN RÔLE, DIXIÈME occurrence,
        // et la raison lui est propre. `sante_publique.manage` existe déjà et sert à PUBLIER LES
        // ALERTES épidémiques ; l'étendre au vocabulaire ferait de l'auteur d'une alerte celui qui
        // décide de ce qu'est une maladie — et de sa liste de surveillance nationale. Une
        // classification des maladies engage une autorité sanitaire, pas le rédacteur d'un bulletin.
        'maladie.referentiel',    // éditer le référentiel des maladies, leurs libellés et la surveillance
        // Référentiel des organismes d'assurance (P6.8d, CDC_09 §8) — ATTRIBUÉE À AUCUN RÔLE,
        // ONZIÈME occurrence, et sa raison est la plus littérale de toutes : le rôle `assurance`
        // existe depuis P1 et désigne PRÉCISÉMENT les organismes que ce registre recense. La lui
        // donner ferait décider de la liste des organismes agréés par un assureur — juge et partie
        // sur son propre agrément. `gestionnaire_etablissement` non plus : il gère les conventions
        // de SON établissement, et la liste nationale deviendrait la somme des conventions de
        // chacun. Un agrément est délivré par un État.
        'assurance.referentiel',  // éditer le registre national des organismes d'assurance agréés
        // Facturation partenaire (lot 8, post-facturation) — enregistrer un règlement reçu d'un
        // établissement est une action de back-office : un établissement ne peut jamais déclarer
        // lui-même « j'ai payé » (risque de fraude direct sur son propre recouvrement). Rattachée
        // à aucun rôle métier explicitement : elle atteint `admin_ivoirsante` par le
        // `syncPermissions(Permission::all())` ci-dessous, comme les permissions référentielles.
        'recouvrement.manage',    // consulter/gérer la facturation partenaire côté back-office
        // Numéros d'urgence nationaux (P6.8e, CDC_09 §8) — ATTRIBUÉE À AUCUN RÔLE, DOUZIÈME
        // occurrence, et celle-ci porte l'enjeu le plus direct du projet. Un numéro d'urgence est
        // attribué par un plan national de numérotation : aucun établissement, aucun opérateur,
        // aucune caisse n'a qualité pour en décider. Et l'erreur ne se rattrape pas — un numéro
        // faux ne produit pas une liste vide ou un filtre inopérant, il produit un appel qui
        // n'aboutit nulle part, composé par quelqu'un devant un blessé.
        'urgence.referentiel',    // éditer le référentiel national des numéros d'urgence
        // Validation du jeu d'apprentissage IA (P10c-2-i F4, CDC_05 §7.2) — ATTRIBUÉE À AUCUN
        // RÔLE, TREIZIÈME occurrence, mais pour une raison DIFFÉRENTE des douze précédentes :
        // celles-ci gouvernent des référentiels NATIONAUX (ce qui fait autorité pour tout le
        // pays) ; celle-ci gouverne ce qui entraînera un MODÈLE national. Ni un acte de soin au
        // chevet (ce n'est pas `triage.retour` : le médecin juge un cas pseudonymisé, sans savoir
        // qui c'est), ni un référentiel administratif — mais le même risque de juge-et-partie
        // qu'ailleurs si elle était donnée sans discernement à qui produit ces lignes.
        'apprentissage.valider',  // valider ou rejeter une ligne du jeu d'apprentissage triage
        // Gouvernance des modèles IA de triage (P10c-3-i F17/F18/F19, CDC_05 §7.2/§8/§9) —
        // ATTRIBUÉE À AUCUN RÔLE, QUATORZIÈME occurrence, et sa raison rejoint celle
        // d'`apprentissage.valider` juste au-dessus : ni un acte de soin au chevet, ni un
        // référentiel administratif, mais le même risque de juge-et-partie si elle était donnée
        // sans discernement à qui produit les lignes qu'elle finit par entraîner. Garde la surface
        // entière (voir/exporter/entraîner/valider), motif `apprentissage.valider` qui garde de la
        // même façon un contrôleur entier — la fractionner n'a été demandé par aucune décision.
        'ia_triage.valider',      // produire un export, déclencher un entraînement, valider un candidat
        // Signature électronique (P6.5b, CDC_09 §5.3). Portée par le rôle `medecin` : signer ses
        // propres prescriptions relève du soin, pas d'une habilitation exceptionnelle. Elle ne
        // donne à elle seule AUCUN pouvoir — les cinq contrôles du §5.4 restent devant, et sans
        // certificat ni autorisation d'exercer valide, elle n'ouvre rien.
        'document.signer',        // demander son certificat et signer les documents qu'on rédige

        // ═══════════════════════════════════════════════════════════════════════════════════════
        // Protocoles médicaux (P10b-1, CDC_08 §10) — TREIZIÈME occurrence, et la seule qui se
        // décline en SIX permissions plutôt qu'une.
        // ═══════════════════════════════════════════════════════════════════════════════════════
        //
        // ATTRIBUÉES À AUCUN RÔLE MÉTIER. Le §10 réserve l'édition des protocoles au « comité
        // scientifique » et aux « autorités » — deux instances qui n'existent comme rôles nulle
        // part dans ce projet, et qu'on n'invente pas. Elles s'accordent nominativement.
        //
        // POURQUOI QUATRE PERMISSIONS DE VALIDATION ET NON UNE. Le §7 confie la validation
        // clinique à des médecins spécialistes et la réglementaire au Ministère de la Santé : ce
        // sont des instances différentes, et c'est tout l'objet d'une validation en quatre couches.
        // Une permission unique laisserait un technicien signer les quatre — le §7 serait
        // formellement respecté et matériellement vide.
        //
        // Ce qui n'est PAS ajouté : l'interdiction pour un même agent de porter plusieurs de ces
        // permissions. Le §7 ne l'exige pas, et un garde-fou plus strict que sa propre règle est un
        // défaut même quand il refuse par prudence (leçon de la collation, P6.8c). Le journal
        // NOMME qui a signé quoi — c'est la transparence, pas l'interdiction, qui est due ici.
        'protocole.rediger',      // créer un protocole et ouvrir/modifier un brouillon
        'protocole.valider.clinique',      // §7.1 — médecins spécialistes, experts hospitaliers
        'protocole.valider.reglementaire', // §7.2 — Ministère, programmes nationaux, autorités
        'protocole.valider.scientifique',  // §7.3 — publications, essais, méta-analyses
        'protocole.valider.technique',     // §7.4 — cohérence des règles, absence de conflits
        'protocole.publier',      // mettre une version en vigueur (§10, double validation)

        // P10b-2 — Évaluer des protocoles hors triage citoyen (CDC_08 §9.1).
        //
        // ATTRIBUÉE À AUCUN RÔLE MÉTIER, elle aussi, et pour une raison différente des six
        // ci-dessus : celles-là gardent l'ÉCRITURE des protocoles, celle-ci garde une LECTURE
        // — mais une lecture qui rend des recommandations de conduite à tenir, et qui inscrit
        // une ligne au journal médico-légal du §10 à chaque appel. L'ouvrir largement ferait
        // d'un moteur d'aide à la décision clinique une API publique, et remplirait le journal
        // d'évaluations qui ne concernent aucun patient.
        //
        // Le triage citoyen ne passe PAS par cette porte : il appelle le même service en
        // interne, avec son contexte à lui. Deux portes, un seul moteur — l'inverse aurait
        // laissé les deux chemins diverger.
        'protocole.evaluer',
    ];

    /** Permissions par rôle (moindre privilège). L'admin reçoit tout. */
    private const ROLES = [
        // `medicament.manage` est donnée à tous les gestionnaires, mais l'écran se referme sur ceux
        // dont l'établissement n'est pas une PHARMACIE (revérifié à chaque accès, comme le bris de
        // glace vérifie le service d'urgences) : la permission dit le rôle, pas la nature du lieu.
        'gestionnaire_etablissement' => [
            'service.manage', 'agent.manage', 'medecin.manage', 'don_sang.manage', 'medicament.manage',
            'stats.etablissement', 'rdv.prevalider', 'rdv.validate', 'disponibilite.manage',
        ],
        // La permission `dossier.referent` ne donne accès à RIEN à elle seule : encore faut-il que le
        // gestionnaire ait relié le compte à une fiche de l'annuaire, ET qu'un patient ait désigné ce
        // praticien. C'est le patient qui ouvre la porte, pas le rôle (Sécurité §4.4).
        //
        // P11.0 — CE RÔLE S'APPELAIT `agent_garde`, ET IL ABSORBE `secretaire`. Ce n'étaient pas
        // deux métiers mais un seul écrit deux fois : `secretaire` n'a jamais porté la moindre
        // permission, tandis que `agent_garde` faisait déjà le travail que CDC_11 §9.1 confie au
        // guichet. Le terme du propriétaire (décision B1) départage — « agent de garde » évoque
        // une astreinte, alors que ce rôle vérifie une fiche de rendez-vous à l'accueil.
        //
        // B1-a — `rdv.validate` REMPLACÉE PAR `rdv.prevalider`. Jusqu'ici ce rôle pouvait
        // confirmer un RDV de bout en bout (seul un `en_attente` existait) : c'était l'inverse de
        // ce que §9.1 demande. Il pré-valide désormais (étape 1), la validation finale (étape 2)
        // appartient au rôle `medecin` seul.
        'personnel_accueil' => [
            'disponibilite.manage', 'rdv.prevalider', 'qr.scan', 'triage.view', 'dossier.referent',
        ],
        // ═══ P6.5a — LE RÔLE `medecin` DEVIENT UTILISABLE (décision propriétaire P5) ═══
        //
        // Il existait depuis P1 et n'était accepté par aucun portail : un praticien qui écrivait
        // au carnet en P7-D0 le faisait sous un compte `agent_garde`, sous l'identité d'un agent
        // d'accueil. Une signature électronique n'aurait alors désigné personne.
        //
        // POURQUOI `dossier.ecrire` LUI EST DONNÉE ALORS QU'ELLE N'EST DONNÉE À AUCUN AUTRE RÔLE.
        // Le commentaire de P7-D0 disait : « `agent_garde` porte `qr.scan` et sert l'accueil ; un
        // agent d'accueil ne rédige pas une ordonnance — le gestionnaire l'accorde individuellement
        // aux soignants habilités. » L'exclusion visait un rôle d'accueil faute de rôle de soin.
        // Ce rôle de soin existe désormais : c'est exactement le destinataire que la phrase
        // annonçait. Les TROIS GARDES CUMULATIVES de D0 restent intégralement en place —
        // permission, voie consentie (`qr_scan` ou `referent`, jamais le bris de glace), liste
        // blanche des sections.
        //
        // CE QU'IL NE REÇOIT PAS, ET POURQUOI. `disponibilite.manage` et `rdv.validate` restent à
        // l'accueil et au secrétariat : CDC_11 §9 prévoit bien une validation finale par le
        // médecin, mais ce circuit est celui de P4, validé G5, et on ne le rouvre pas au détour
        // d'un incrément sur les référentiels. `medecin.manage` non plus — un praticien ne se
        // décrit pas lui-même dans l'annuaire national.
        // P10c-2-i — `triage.retour` s'ajoute ici, et nulle part ailleurs : c'est le rôle de SOIN.
        // Il lisait déjà la fiche de triage (`triage.view`) sans avoir aucun moyen d'en dire quoi
        // que ce soit ; le §9.1 attend précisément cette supervision humaine.
        // P11.0 — `rdv.validate` S'AJOUTE, ET C'EST UNE DETTE ANNONCÉE QU'ON SOLDE.
        // Le commentaire ci-dessus disait : « `disponibilite.manage` et `rdv.validate` restent à
        // l'accueil et au secrétariat : CDC_11 §9 prévoit bien une validation finale par le
        // médecin, mais ce circuit est celui de P4, validé G5, et on ne le rouvre pas au détour
        // d'un incrément sur les référentiels. » Cet incrément-ci EST celui qui le rouvre, et le
        // §9.1 est littéral : « **Le médecin fait la validation finale.** » Jusqu'ici l'accueil
        // pouvait confirmer un rendez-vous et le praticien concerné, non.
        // `disponibilite.manage` ne suit pas : ouvrir des créneaux est un acte d'organisation du
        // service, pas un acte de soin, et le §9.1 le confie explicitement à l'accueil.
        //
        // B1-a — LA DETTE EST RÉELLEMENT SOLDÉE, PAS SEULEMENT ACCORDÉE. Depuis P11.0 ce rôle
        // portait `rdv.validate` sans que le code distingue son usage de celui de l'accueil : les
        // deux appelaient la même transition (`en_attente→confirme`). Ce n'est plus le cas :
        // `personnel_accueil` porte désormais `rdv.prevalider` (étape 1) et non plus
        // `rdv.validate` — seul `medecin` (et `gestionnaire_etablissement`, en supervision) peut
        // confirmer, et seulement un RDV déjà `prevalide`.
        'medecin' => [
            'qr.scan', 'triage.view', 'triage.retour', 'dossier.referent', 'dossier.ecrire',
            'document.signer', 'rdv.validate',
        ],
        // ═══ P11.0 — LES SEPT RÔLES MUETS (CDC_11 §6, §7, §8) ═══
        //
        // Ils existaient depuis P1, traduits dans `@masante/shared`, soumis au MFA — et portaient
        // ZÉRO permission, sans qu'aucun portail ne les accepte. Huit modules durant, un
        // infirmier, un pharmacien ou un laborantin n'avait aucune porte.
        //
        // RÈGLE QUI GOUVERNE CE BLOC, ET QUI EXPLIQUE POURQUOI CERTAINES LISTES SONT COURTES :
        // **on n'invente aucune permission pour un écran qui n'existe pas.** Créer
        // `hospitalisation.manage` aujourd'hui donnerait une clé pour une porte qui n'est pas
        // encore percée — c'est le « socle à vide » refusé en P6.3-D3, et le contrôle toujours
        // vert refusé en P5.3b-4. Chaque rôle reçoit donc exactement les capacités qui EXISTENT
        // et qui lui reviennent ; les autres arriveront avec l'application qui les porte.

        // §6 — L'infirmier consigne les constantes et les traitements administrés. C'est
        // littéralement « consigner un acte dans le carnet », ce que `dossier.ecrire` nomme. Les
        // TROIS GARDES CUMULATIVES de P7-D0 restent intactes : permission, voie consentie
        // (`qr_scan`/`referent`, jamais le bris de glace), et liste blanche des sections.
        // Pas de `document.signer` : la « signature infirmier » du §6 n'a aucun type de document
        // correspondant dans le registre de P6.5b, et signer suppose d'abord quelque chose à
        // signer. Pas de `rdv.validate` non plus — ce n'est pas son circuit.
        'infirmier' => [
            'qr.scan', 'triage.view', 'dossier.ecrire',
        ],
        // §7 — Le pharmacien tient les prix et les ruptures de SON officine. `medicament.manage`
        // existe déjà et fait exactement cela ; l'écran se referme de lui-même sur les
        // établissements qui ne sont pas des pharmacies.
        // Il ne reçoit **pas** `medicament.referentiel` : le catalogue national, ses indications
        // et ses interactions ne se décident pas à l'officine (P6.6a, onzième occurrence du
        // précédent « juge et partie »).
        'pharmacien' => [
            // B3-a — `ordonnance.delivrer` lui est donnée : c'est SON acte, et le §7.1 le nomme.
            // Elle n'est donnée à aucun autre rôle — un gestionnaire d'établissement tient des prix,
            // il ne dispense pas.
            // B3-d — `commande.traiter` lui est donnée : c'est le pharmacien qui reçoit les
            // commandes de son officine (§9.5).
            'medicament.manage', 'qr.scan', 'ordonnance.delivrer', 'commande.traiter',
        ],
        // §8.1 — Le laborantin publie un résultat dans le carnet du patient :
        // `resultats-analyses` figure dans la liste blanche des sections ouvertes au soignant,
        // donc la capacité existe réellement.
        // Il ne reçoit **pas** `analyse.referentiel` : un laboratoire ne fixe pas les valeurs de
        // référence nationales contre lesquelles ses propres résultats seront lus (P6.7a).
        // B5-b — `analyse.executer` s'ajoute : c'est le VRAI circuit (jeton, sans session de
        // dossier, L3) que `qr.scan`/`dossier.ecrire` ci-dessus n'ont jamais couvert — ils restent
        // pour ne pas retirer une capacité déjà accordée (précédent B3-a, où `pharmacien` a gardé
        // `qr.scan` après l'ajout d'`ordonnance.delivrer`), mais le circuit du prélèvement passe
        // désormais par cette permission-ci.
        'laborantin' => [
            'qr.scan', 'triage.view', 'dossier.ecrire', 'analyse.executer',
        ],
        // §8.2 — Le radiologue est le rôle le plus pauvre de ce bloc, et il faut le dire plutôt
        // que de le garnir : il n'existe dans ce projet NI imagerie, NI DICOM, NI compte rendu
        // radiologique — le registre des documents signables de P6.5b le déclare mot pour mot
        // « entité inexistante ». Aucune des quatre sections ouvertes au soignant n'est un
        // compte rendu d'imagerie. Lui donner `dossier.ecrire` lui ouvrirait donc les
        // ordonnances et les antécédents, ce qui n'est pas son métier.
        // Il reçoit ce qu'il peut réellement faire aujourd'hui : identifier le patient qui se
        // présente et lire l'orientation qui l'amène.
        'radiologue' => [
            'qr.scan', 'triage.view',
        ],
        // §8.5 — Le Ministère pilote (statistiques nationales) et surveille (alertes
        // épidémiques). Les deux permissions existent et étaient jusqu'ici réservées à
        // `admin_ivoirsante`, c'est-à-dire à l'exploitant de la plateforme : publier une alerte
        // sanitaire nationale est un acte d'autorité de santé, pas un acte d'exploitation.
        // `stats.etablissement` ne suit pas — elle est bornée à l'établissement du compte, ce
        // qui n'a aucun sens pour un ministère qui les regarde tous.
        'ministere' => [
            'stats.global', 'sante_publique.manage',
        ],
        // §8.6 — L'ASSURANCE NE REÇOIT AUCUNE PERMISSION, ET CE VIDE EST LA RÉPONSE HONNÊTE.
        //
        // Le §8.6 lui demande de vérifier une couverture, de valider une prise en charge et de
        // contrôler la fraude. Aucune de ces trois capacités n'existe : la vérification auprès
        // d'un organisme est la limite ouverte de P6.8d (« l'étape 2 du §8.1 — l'API CNAM —
        // n'existe pas »), et la prise en charge est calculée par le microservice de paiement,
        // qui n'expose aucune surface d'assureur.
        //
        // Lui fabriquer une permission maintenant lui donnerait une clé sans serrure, et
        // surtout ferait CROIRE que le portail assurance existe. La ligne est écrite ici, vide
        // et commentée, pour que l'absence se voie au lieu de se deviner.
        'assurance' => [],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $nom) {
            Permission::firstOrCreate(['name' => $nom, 'guard_name' => 'web']);
        }

        // Admin = toutes les permissions.
        $admin = Role::firstOrCreate(['name' => 'admin_ivoirsante', 'guard_name' => 'web']);
        $admin->syncPermissions(Permission::all());

        // Gestionnaire + agent = sous-ensembles.
        foreach (self::ROLES as $role => $perms) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web'])->syncPermissions($perms);
        }

        $this->creerAdminBootstrap();
    }

    /** Compte admin de démarrage (login portail par email + mot de passe). */
    private function creerAdminBootstrap(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'admin@masante.ci'],
            [
                'nom' => 'Admin',
                'prenom' => 'IVOIRSANTÉ',
                'password' => Hash::make('Admin@2026!'),
                'email_verified_at' => now(),
            ],
        );

        if (! $user->hasRole('admin_ivoirsante')) {
            $user->assignRole('admin_ivoirsante');
        }

        $this->command?->info('Admin portail : admin@masante.ci / Admin@2026!');
    }
}
