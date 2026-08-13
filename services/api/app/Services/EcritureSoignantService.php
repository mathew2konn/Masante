<?php

namespace App\Services;

use App\Models\Medecin;
use App\Models\MembreFamille;
use App\Models\User;
use App\Support\RegistreSectionsCarnet;
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
     * `qr_scan` : il a présenté son QR. `referent` : il a désigné ce médecin.
     *
     * `bris_de_glace` en est exclu, et c'est une décision, pas un oubli (G1, choix propriétaire) :
     * cette voie ouvre le vital minimal, 15 minutes, SANS le consentement du patient (voie 4,
     * Sécurité §4.4). Son périmètre est défini en lecture. Y autoriser l'écriture ferait d'un accès
     * d'exception non consenti un droit de modifier le dossier.
     *
     * `admin` en est exclu pour la même raison : c'est une voie de dépannage, pas de soin.
     */
    public const VOIES_ECRITURE = ['qr_scan', 'referent'];

    public function __construct(private readonly ServiceNotification $notifications)
    {
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

            // L'établissement suit la même logique. Repli sur la saisie quand la fiche n'en porte
            // pas : mieux vaut l'information de l'agent qu'un champ vide.
            if (array_key_exists('structure_sanitaire', $valide) && $fiche->structure?->nom !== null) {
                $valide['structure_sanitaire'] = $fiche->structure->nom;
            }
        }

        return $membre->{$controleur->nomRelation()}()->create($valide);
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
