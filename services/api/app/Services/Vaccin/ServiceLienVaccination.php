<?php

namespace App\Services\Vaccin;

use App\Models\MembreFamille;
use App\Models\Vaccin;
use App\Support\ReglesCalendrierVaccinal;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * P6.8b — Le lien d'une ligne de carnet vers le référentiel national des vaccins (CDC_09 §8).
 *
 * ═══ CE QUE CE SERVICE FERME ═══
 *
 * Deux colonnes de `vaccinations` étaient déclarées librement par le client — `obligatoire` et
 * `statut` — et ce sont exactement, et uniquement, les deux que lisait `FicheVitaleService` pour
 * composer le bloc « Vaccinations essentielles » montré à un secouriste SANS authentification.
 *
 *  - `statut` n'est plus accepté du tout : il est CALCULÉ ({@see App\Models\Vaccination::statut}).
 *  - `obligatoire` cesse d'être une case cochée dès que la ligne est rattachée : c'est un fait de
 *    politique nationale attaché à une dose précise, il se LIT dans le calendrier.
 *
 * ═══ LE LIEN RESTE FACULTATIF ═══
 *
 * Un patient qui recopie un carnet papier n'a pas la liste sous les yeux, et le référentiel est
 * incomplet : l'imposer ferait de ses LACUNES un blocage. Raison identique à P6.6b (médicaments),
 * P6.7a (analyses) et P6.7b (laboratoires).
 *
 * **Mais quand il est fourni, le serveur ne croit rien du client** : le code national, le libellé et
 * le caractère obligatoire sont relus au référentiel et FIGÉS — figés comme en P6.6b, pour qu'une
 * correction ultérieure du référentiel ne réécrive pas ce qui a été inscrit au carnet.
 *
 * ═══ LA GARANTIE VAUT SUR LES TROIS CHEMINS D'ÉCRITURE ═══
 *
 * Ce service est appelé depuis `preparerDonnees()`, donc par le patient, par le délégué
 * (contribution, résolue AU DÉPÔT) et par le soignant. *Une garantie qui ne vaudrait que sur l'un
 * des trois n'en serait pas une.*
 */
final class ServiceLienVaccination
{
    public function __construct(private readonly ServiceCalendrierVaccinal $calendrier) {}

    /**
     * Résout le lien et pose les valeurs que le serveur sait.
     *
     * @param  array<string, mixed>  $valide
     * @return array<string, mixed>
     *
     * @throws ValidationException si le vaccin ou la dose ne désigne rien au référentiel
     */
    public function resoudre(array $valide, ?MembreFamille $membre = null): array
    {
        // JAMAIS du client, sur aucun chemin : on les efface avant de les reposer nous-mêmes.
        // `statut` n'est même plus dans les règles de validation — l'effacer ici est la seconde
        // couche, celle qui tient face à un import ou à un appel direct au service (leçon de la
        // mutation de P6.6b : un vecteur qui ne teste que le validateur ne teste pas le service).
        unset($valide['statut'], $valide['vaccin_code']);

        if (! isset($valide['vaccin_id'])) {
            return $valide;
        }

        $id = (int) $valide['vaccin_id'];

        // ═══ DEUX LECTURES, ET CHACUNE RÉPOND À UNE QUESTION DIFFÉRENTE ═══
        //
        // La TABLE répond de l'intégrité référentielle : `vaccinations.vaccin_id` est une clé
        // étrangère, elle doit désigner une ligne réelle.
        //
        // La VERSION PUBLIÉE fait autorité sur le CONTENU — libellé, doses, caractère obligatoire.
        // Lire le contenu dans la table aurait rendu la gouvernance décorative : un `UPDATE` direct
        // aurait changé ce qui s'inscrit dans les carnets sans relecture ni quatre-yeux, ce qui est
        // exactement le défaut que L1+L2 ont dû refermer pour `seuils_mesure` (ADR-025 §5/§6).
        $vaccin = Vaccin::find($id);

        if ($vaccin === null) {
            throw ValidationException::withMessages([
                'vaccin_id' => "Le vaccin n°{$id} n'existe pas au référentiel national.",
            ]);
        }

        $publie = $vaccin->code !== null ? $this->calendrier->vaccinPublie($vaccin->code) : null;

        if ($publie === null) {
            // Le refus est BRUYANT et attribué au champ que l'utilisateur a rempli. Accepter le
            // lien en lisant la table laisserait inscrire au carnet un vaccin que personne n'a mis
            // en vigueur ; l'ignorer en silence ferait croire à un rattachement qui n'a pas eu lieu.
            throw ValidationException::withMessages([
                'vaccin_id' => sprintf(
                    '« %s » ne figure pas dans la version en vigueur du calendrier vaccinal '
                    .'national : la vaccination peut être enregistrée sans être rattachée.',
                    $vaccin->libelle,
                ),
            ]);
        }

        $valide['vaccin_id']   = $vaccin->id;
        $valide['vaccin_code'] = $vaccin->code;

        // Le texte libre est ALIGNÉ sur la version publiée quand le lien existe : garder deux noms
        // différents dans la même ligne laisserait le lecteur choisir lequel croire (motif P6.7b).
        $valide['vaccin_nom'] = $publie['libelle'];

        return $this->resoudreLaDose($valide, $publie, $membre);
    }

    /**
     * @param  array<string, mixed>  $valide
     * @param  array<string, mixed>  $publie  le vaccin tel que publié
     * @return array<string, mixed>
     */
    private function resoudreLaDose(array $valide, array $publie, ?MembreFamille $membre): array
    {
        $dose = isset($valide['numero_dose']) ? (int) $valide['numero_dose'] : null;

        if ($dose === null) {
            return $valide;
        }

        $echeance = $this->calendrier->echeancePubliee((string) $publie['code'], $dose);

        if ($echeance === null) {
            // Le message NOMME ce qui est introuvable : « dose 4 » sur un vaccin qui en compte
            // trois est une erreur de saisie, pas un « champ invalide ». Précédent P6.6b.
            throw ValidationException::withMessages([
                'numero_dose' => sprintf(
                    'Le calendrier national ne prévoit pas de dose n°%d pour « %s » (%d dose%s).',
                    $dose,
                    $publie['libelle'],
                    (int) $publie['nb_doses'],
                    (int) $publie['nb_doses'] > 1 ? 's' : '',
                ),
            ]);
        }

        // ═══ `obligatoire` SE LIT, IL NE SE DÉCLARE PLUS ═══
        $valide['obligatoire'] = (bool) ($echeance['obligatoire'] ?? false);

        return $this->materialiserEcheance($valide, $echeance, $membre);
    }

    /**
     * Inscrit dans `date_rappel` la date à laquelle cette dose devient exigible pour CE membre.
     *
     * ═══ POURQUOI L'ÉCRIRE PLUTÔT QUE LA RECALCULER À CHAQUE LECTURE ═══
     *
     * L'accesseur `statut` ne lit que des colonnes de sa propre ligne, délibérément : s'il
     * consultait le calendrier et la date de naissance, sa réponse dépendrait de ce qui se trouve
     * chargé au moment de l'appel, et la même ligne répondrait `a_faire` dans un endpoint et
     * `en_retard` dans un autre. En posant l'échéance sur la ligne, il n'y a **qu'une vérité,
     * inscrite une fois, au moment où le serveur la connaît** — motif P6.6b.
     *
     * ═══ TROIS CONDITIONS, ET CHACUNE ÉVITE D'ÉCRIRE QUELQUE CHOSE DE FAUX ═══
     *
     *  - le client n'a pas fourni de date de rappel — **la sienne prime toujours** : elle vient d'un
     *    carnet papier ou d'un soignant qui a vu le patient, et tient compte de ce que le calendrier
     *    ignore (une dose reçue en retard décale les suivantes) ;
     *  - la dose n'est pas déjà administrée — sur une ligne faite, `date_rappel` désigne le rappel
     *    SUIVANT, que le calendrier de cette dose-ci ne connaît pas ;
     *  - la date de naissance du membre est connue — sans elle, aucune date ne peut être déduite, et
     *    en inventer une produirait un retard imaginaire.
     *
     * @param  array<string, mixed>  $valide
     * @param  array<string, mixed>  $echeance  l'échéance telle que publiée
     * @return array<string, mixed>
     */
    private function materialiserEcheance(
        array $valide,
        array $echeance,
        ?MembreFamille $membre,
    ): array {
        if (($valide['date_rappel'] ?? null) !== null) {
            return $valide;
        }

        if (($valide['date_administration'] ?? null) !== null) {
            return $valide;
        }

        $naissance = $membre?->date_naissance;

        if ($naissance === null) {
            return $valide;
        }

        $due = ReglesCalendrierVaccinal::echeanceDeLaLigne(
            null,
            CarbonImmutable::parse($naissance),
            (int) ($echeance['age_jours_du'] ?? 0),
        );

        if ($due !== null) {
            $valide['date_rappel'] = $due->toDateString();
        }

        return $valide;
    }

    /**
     * Avertissements NON BLOQUANTS joints à la réponse de création.
     *
     * UN VACCIN RETIRÉ EST SIGNALÉ, JAMAIS REFUSÉ. Refuser d'inscrire au carnet une dose réellement
     * administrée parce que le produit a été retiré depuis effacerait un fait médical — et refuser
     * serait une décision médicale prise par une machine (CDC_00 §4). Décision identique à celle de
     * P6.6a/P6.6b pour les médicaments retirés.
     *
     * @return array<int, array<string, mixed>>
     */
    public function avertissements(?string $vaccinCode): array
    {
        if ($vaccinCode === null) {
            return [];
        }

        // Depuis la VERSION PUBLIÉE, comme la résolution : un avertissement bâti sur la table
        // pourrait annoncer un retrait que le calendrier en vigueur ne dit pas encore.
        $publie = $this->calendrier->vaccinPublie($vaccinCode);

        if ($publie === null || ($publie['statut_marche'] ?? null) !== 'retire') {
            return [];
        }

        return [[
            'code'    => 'vaccin_retire',
            'message' => sprintf(
                '« %s » est retiré du référentiel national. La vaccination est enregistrée ; '
                .'signalez-le au professionnel qui suit ce dossier.',
                $publie['libelle'],
            ),
        ]];
    }
}
