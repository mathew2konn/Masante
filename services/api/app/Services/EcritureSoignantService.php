<?php

namespace App\Services;

use App\Events\PartageRdvEcriture;
use App\Models\Consultation;
use App\Models\Medecin;
use App\Models\MembreFamille;
use App\Models\User;
use App\Support\DiffusionPresence;
use App\Support\RegistreSectionsCarnet;
use App\Support\TypeAccesDossier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Écriture d'un soignant au carnet, pendant une session ouverte (incrément D0).
 *
 * CE QUE CET INCRÉMENT DÉBLOQUE. Jusqu'ici, `Portail\DossierController` était en LECTURE SEULE et
 * le chemin d'écriture médecin était annoncé « futur » dans les tests. La fiche de parcours promise
 * en D2 — « la trace du médecin, l'hôpital, l'ordonnance » — aurait donc affiché une section vide.
 *
 * LA GARANTIE QUI DONNE SA VALEUR À LA FICHE DE PARCOURS : `source` et `added_by` sont RÉÉCRITS
 * ici, jamais lus du client. C'est le miroir exact de {@see ContributionCarnetService}, dans
 * l'autre sens :
 *
 *   délégué (contribution)  →  source = patient   (il ne peut pas se faire passer pour un soignant)
 *   soignant (ce service)   →  source = medecin   (il ne peut pas se faire passer pour le patient)
 *
 * « Ceci vient d'un soignant » sera donc une garantie du serveur, pas une déclaration.
 *
 * TROIS GARDES CUMULATIVES, et aucune n'est redondante :
 *   1. la permission `dossier.ecrire` — qui a le droit d'écrire ;
 *   2. la VOIE d'accès — par quel consentement le dossier est ouvert ;
 *   3. la liste blanche des sections — quoi.
 *
 * FRONTIÈRE : tout le jugement est ici. Le contrôleur traduit en HTTP, la vue Blade affiche un
 * formulaire. Aucune règle médicale n'est calculée — on enregistre ce que le soignant a saisi.
 */
class EcritureSoignantService
{
    /**
     * Voies d'accès autorisées à écrire — celles où le patient a CONSENTI.
     *
     * `qr_scan` : il a présenté son QR. `referent` : il a désigné ce médecin. `rdv_partage`
     * (B1-c) : il s'est présenté à un rendez-vous CONFIRMÉ et enregistré à l'accueil — le
     * consentement est celui donné en prenant et en honorant ce rendez-vous précis.
     *
     * `bris_de_glace` en est exclu, et c'est une décision, pas un oubli (G1, choix propriétaire) :
     * cette voie ouvre le vital minimal, 15 minutes, SANS le consentement du patient (voie 4,
     * Sécurité §4.4). Son périmètre est défini en lecture. Y autoriser l'écriture ferait d'un accès
     * d'exception non consenti un droit de modifier le dossier.
     *
     * `admin` en est exclu pour la même raison : c'est une voie de dépannage, pas de soin.
     */
    public const VOIES_ECRITURE = ['qr_scan', 'referent', 'rdv_partage'];

    public function __construct(
        private readonly ServiceNotification $notifications,
        private readonly SessionDossierService $session,
    ) {
    }

    /**
     * Consigne un acte dans le carnet.
     *
     * @param  string  $voie      voie par laquelle la session a été ouverte (`type_acces`)
     * @param  array<string, mixed>  $donnees
     *
     * @throws \RuntimeException     conditions non réunies (message destiné à l'interface)
     * @throws ValidationException   la saisie ne respecte pas les règles de la section
     */
    public function ecrire(
        User $soignant,
        MembreFamille $membre,
        string $voie,
        string $section,
        array $donnees,
    ): Model {
        if (! $soignant->can('dossier.ecrire')) {
            throw new \RuntimeException("Vous n'êtes pas habilité à écrire dans un carnet.");
        }

        if (! in_array($voie, self::VOIES_ECRITURE, true)) {
            throw new \RuntimeException(
                'Cet accès est en lecture seule : le patient n\'a pas consenti à une écriture.'
            );
        }

        if (! RegistreSectionsCarnet::ouverteAuSoignant($section)) {
            throw new \RuntimeException("Cette section n'accepte pas d'écriture.");
        }

        // Les règles de validation ne sont pas réécrites : elles viennent du contrôleur d'API de la
        // section. Une ordonnance obéit donc aux mêmes règles pour le patient, pour un délégué qui
        // la propose, et pour le soignant qui la consigne — écrites une seule fois.
        $controleur = RegistreSectionsCarnet::controleur($section);
        $valide     = Validator::make($donnees, $controleur->reglesDeCreation())->validate();

        // Réécriture serveur, quoi qu'ait envoyé le client. Appliquée APRÈS la validation : la
        // règle `in:patient,medecin` du contrôleur porte sur ce que le client propose, pas sur ce
        // que le serveur impose.
        //
        // POURQUOI PAS LE NOM DU SOIGNANT ICI. `added_by` est un ENUM('patient','medecin') sur les
        // quatre tables : il dit une CATÉGORIE d'auteur, pas une identité. Y écrire « Dr Aka — CHU
        // de Cocody » serait rejeté par MySQL — et serait de toute façon une faute de conception :
        // l'identité du soignant, son établissement et l'heure sont déjà dans `acces_dossier`,
        // journal IMMUABLE. La dupliquer dans une table modifiable créerait deux vérités.
        //
        // C'est `donnees_ajoutees` (renseignée à la clôture de session) qui relie cette entrée à
        // l'agent qui l'a écrite. La fiche de parcours (D2) fera la jointure.
        $valide['source']   = 'medecin';
        $valide['added_by'] = 'medecin';

        // ═══ P6.5b — LE PRESCRIPTEUR N'EST PLUS DÉCLARÉ PAR LE CLIENT ═══
        //
        // C'est le constat qui a ouvert P6.5 : `medecin_nom` était `required|string|max:200`, saisi
        // au formulaire, y compris quand c'était le soignant lui-même qui écrivait. Une ordonnance
        // pouvait donc porter le nom de n'importe quel médecin.
        //
        // Même mouvement que `source` et `added_by` juste au-dessus, et pour la même raison : ce
        // que le serveur SAIT n'a pas à être redemandé à celui qu'on contrôle. La fiche est reliée
        // au compte par un gestionnaire (P6.5a) ; c'est un acte humain, pas une déclaration de
        // l'intéressé.
        //
        // LE CHEMIN DU PATIENT N'EST PAS TOUCHÉ : un patient qui recopie une ordonnance papier
        // continue de saisir le nom du médecin qui la lui a remise — il serait absurde de le lui
        // imposer depuis un compte qu'il n'a pas. La réécriture n'a lieu que sur CE chemin.
        $fiche = Medecin::with('structure:id,nom')->where('user_id', $soignant->id)->first();

        if ($fiche !== null) {
            if (array_key_exists('medecin_nom', $valide)) {
                $valide['medecin_nom'] = $fiche->nom_complet;
            }

            // ═══ CE QU'ON NE RÉÉCRIT PAS, ET POURQUOI (correction de P6.7a, décidée en P6.7b) ═══
            //
            // P6.7a réécrivait aussi `resultats_analyses.medecin_prescripteur`, en le présentant
            // comme le miroir de `medecin_nom`. **C'était faux.**
            //
            // Pour une ordonnance, celui qui écrit EST le prescripteur : rédiger l'ordonnance est
            // l'acte de prescrire. Pour un résultat d'analyse, celui qui consigne est souvent
            // QUELQU'UN D'AUTRE — un biologiste, ou un médecin hospitalier qui classe un résultat
            // prescrit par un généraliste de ville. L'écraser inscrivait alors le nom du mauvais
            // médecin, et une affirmation fausse écrite par le SERVEUR est plus difficile à
            // contester qu'une saisie humaine non vérifiée.
            //
            // Le prescripteur d'un résultat est une déclaration sur un TIERS, au même titre que le
            // laboratoire qui a réalisé l'analyse. On ne la devine pas : on la fait VÉRIFIER quand
            // elle est faite, par un lien au référentiel des professionnels
            // ({@see App\Services\Analyse\ServiceLienResultat}) — même forme que le lien
            // médicament (P6.6b) et le lien analyse (P6.7a).

            // L'établissement suit la même logique. Repli sur la saisie quand la fiche n'en porte
            // pas : mieux vaut l'information de l'agent qu'un champ vide.
            if (array_key_exists('structure_sanitaire', $valide) && $fiche->structure?->nom !== null) {
                $valide['structure_sanitaire'] = $fiche->structure->nom;
            }

            // ═══ B2-c — LE LIEN, LÀ OÙ IL Y AVAIT UNE CHAÎNE (constat Y2) ═══
            //
            // `medecin_nom` est fiable depuis P6.5a, mais une valeur fiable n'est pas un lien :
            // « toutes les ordonnances du Dr X » et « ce prescripteur exerce-t-il encore ? »
            // restaient insolubles. Geste identique à celui de P6.7b sur `resultats_analyses`.
            //
            // SEULEMENT SUR LES SECTIONS OÙ L'AUTEUR EST LE PRESCRIPTEUR, et la liste est DÉCLARÉE
            // au registre plutôt que codée ici : sur un résultat d'analyse, celui qui consigne est
            // souvent quelqu'un d'autre, et poser son identifiant y inscrirait le mauvais médecin —
            // c'est précisément la faute que P6.7b a corrigée pour le nom.
            if (RegistreSectionsCarnet::auteurEstPrescripteur($section)) {
                $valide['medecin_id'] = $fiche->id;
                $valide['structure_id'] = $fiche->structure?->id;
            }
        }

        // Le rattachement à l'acte de soin en cours, s'il y en a un (B2-a). Repris de la SESSION,
        // jamais déclaré : c'est le serveur qui sait quelle consultation est ouverte. Une écriture
        // hors consultation reste possible et laisse simplement ce lien nul — l'ordonnance vit dans
        // le carnet du patient, pas dans la consultation.
        if (RegistreSectionsCarnet::auteurEstPrescripteur($section)) {
            // POURQUOI UNE REQUÊTE ET NON `ServiceConsultation::enCoursPourLaSession()` : ce
            // service-là injecte `EcritureSoignantService` depuis B2-b (la promotion d'un
            // diagnostic passe par le chemin d'écriture soignant, plutôt que d'accéder au modèle
            // directement). L'injecter en retour ferait un CYCLE de dépendances. On lit donc la
            // consultation depuis la session que ce service porte déjà — c'est une requête, pas un
            // jugement dupliqué : la décision « une consultation par accès » reste en B2-a, et
            // c'est son index unique qui la garantit.
            $accesId = $this->session->accesId();

            $valide['consultation_id'] = $accesId === null
                ? null
                : Consultation::where('acces_dossier_id', $accesId)->value('id');
        }

        // P6.6b — dérivations serveur de la section (pour une ordonnance : résolution du lien au
        // référentiel des médicaments). Appelée ici ET sur les deux autres chemins d'écriture :
        // une garantie qui ne vaudrait que sur celui du patient n'en serait pas une.
        $valide = $controleur->preparerDonnees($valide, $membre);

        $entree = $membre->{$controleur->nomRelation()}()->create($valide);

        // B1-c (D9) — présence temps réel, UNIQUEMENT pendant une session `rdv_partage` : les cinq
        // autres voies n'ont pas de canal ouvert pour ce membre, et un patient qui consulte son
        // carnet en `qr_scan` ne doit voir aucune diffusion. `rdvDeclare()` reste NULL sur toute
        // autre voie ({@see SessionDossierService}). Posé ICI et non dans le contrôleur : la
        // frontière de cette classe promet que « tout le jugement est ici ».
        if ($voie === TypeAccesDossier::RDV_PARTAGE->value) {
            $rdvId = $this->session->rdvDeclare();
            if ($rdvId !== null) {
                DiffusionPresence::diffuser(new PartageRdvEcriture($rdvId));
            }
        }

        return $entree;
    }

    /**
     * Prévient la famille — le propriétaire ET les délégués en lecture.
     *
     * Séparé de {@see ecrire()} pour que le contrôleur puisse d'abord noter l'écriture dans la
     * session d'audit : une notification partie sans trace serait invérifiable.
     */
    public function notifier(MembreFamille $membre, User $soignant, string $section): void
    {
        $this->notifications->carnetEnrichi($membre, $soignant, $section);
    }
}
