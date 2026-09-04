<?php

namespace App\Services\Medicament;

use App\Models\Delivrance;
use App\Models\DelivranceLigne;
use App\Models\Ordonnance;
use App\Models\StructureSanitaire;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * B3-a — servir une ordonnance en officine (CDC_11 §7.1).
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * LE PHARMACIEN NE VOIT QUE L'ORDONNANCE, ET C'EST LA DÉCISION CENTRALE DU LOT
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * Le seul mécanisme existant (`qr.scan`) ouvre une SESSION DE DOSSIER : antécédents, vaccinations,
 * résultats d'analyses. *Un pharmacien n'a pas à lire les antécédents pour servir une boîte de
 * paracétamol* — minimisation (loi 2013-450), que ce projet applique déjà explicitement (P7-D2).
 *
 * L'accès passe donc par un JETON porté par l'ordonnance, patron repris de la fiche de triage
 * (P10a) : non devinable, comparé en TEMPS CONSTANT, et **404 jamais 403** — un 403 confirmerait
 * qu'une ordonnance existe là. Aucune session de dossier n'est ouverte, aucune autre section n'est
 * atteignable : ce n'est pas une garde qu'on vérifie, c'est une porte qui n'existe pas.
 *
 * ═══ LES GARDES DE LA DÉLIVRANCE, ET AUCUNE NE RATTRAPE LES AUTRES ═══
 *
 *  1. HABILITATION — `ordonnance.delivrer`, PERMISSION NEUVE, et c'est justifié : `medicament.manage`
 *     appartient aussi au **gestionnaire d'établissement** (P6.6a le dit pour « les prix et les
 *     ruptures de SA pharmacie »). La réutiliser laisserait un gestionnaire de CHU servir des
 *     ordonnances. Ce n'est pas la même porte — à la différence de P11.1-D5, où « approuver une
 *     candidature, c'est créer un établissement » désignait bien le même acte.
 *  2. UNE PHARMACIE — on ne sert pas une ordonnance dans un laboratoire.
 *  3. UNE ORDONNANCE DÉLIVRABLE — celles d'avant B3-a n'ont pas de lignes ; elles restent
 *     consultables, pas servables, et le message le dit plutôt que de rendre un refus opaque.
 *  4. DES LIGNES DE CETTE ORDONNANCE — vérifié ici pour un message utile, et garanti par le
 *     moteur (déclencheur dans les deux dialectes).
 *  5. DES QUANTITÉS QUI NE DÉPASSENT PAS LA PRESCRIPTION — quand elle est connue. Le cumul porte
 *     sur TOUTES les délivrances : le patient qui repasse chercher le manquant est le cas normal.
 *  6. AU MOINS UNE LIGNE SERVIE — une délivrance vide serait un acte qui n'a pas eu lieu.
 */
final class ServiceDelivrance
{
    public const PERMISSION = 'ordonnance.delivrer';

    /**
     * L'ordonnance désignée par ce jeton, ou `null`.
     *
     * COMPARAISON EN TEMPS CONSTANT : une comparaison ordinaire fuit, par sa durée, le nombre de
     * caractères devinés juste — c'est ce que `hash_equals` évite (patron P10a et P5.4a).
     */
    public function ordonnancePourJeton(?string $jeton): ?Ordonnance
    {
        $jeton = trim((string) $jeton);

        if ($jeton === '') {
            return null;
        }

        // On charge par index (l'unicité le permet) puis on RECOMPARE en temps constant : sans
        // cette seconde comparaison, la seule protection serait l'index, dont le temps de réponse
        // varie avec la donnée.
        $ordonnance = Ordonnance::with(['lignes.lignesDelivrees', 'membre:id,nom,prenom,date_naissance'])
            ->where('jeton_partage', $jeton)
            ->first();

        if ($ordonnance === null || ! hash_equals((string) $ordonnance->jeton_partage, $jeton)) {
            return null;
        }

        return $ordonnance;
    }

    /**
     * Sert tout ou partie d'une ordonnance.
     *
     * @param  array<int, int>  $quantites  identifiant de ligne => quantité servie
     *
     * @throws ValidationException
     */
    public function delivrer(User $pharmacien, Ordonnance $ordonnance, array $quantites): Delivrance
    {
        $this->assertHabilite($pharmacien);
        $officine = $this->assertOfficine($pharmacien);

        if (! $ordonnance->estDelivrable()) {
            $this->refus(
                'Cette ordonnance a été écrite avant la délivrance électronique : elle est '
                .'consultable, mais ne peut pas être servie ici.'
            );
        }

        $servies = array_filter($quantites, static fn ($q): bool => is_numeric($q) && (int) $q > 0);

        if ($servies === []) {
            $this->refus('Indiquez au moins un médicament servi.');
        }

        return DB::transaction(function () use ($pharmacien, $ordonnance, $officine, $servies): Delivrance {
            // Verrou pessimiste sur les lignes : deux comptoirs qui servent la même ordonnance en
            // même temps ne doivent pas dépasser ensemble la quantité prescrite, chacun ayant lu un
            // cumul d'avant l'autre (motif P5.5b-2 sur le décaissement).
            $lignes = $ordonnance->lignes()->lockForUpdate()->get()->keyBy('id');

            $delivrance = new Delivrance;
            $delivrance->ordonnance_id = $ordonnance->id;
            $delivrance->structure_id = $officine->id;
            // Identifiant + nom FIGÉ : l'acte doit continuer de nommer son auteur même si le compte
            // est supprimé (ADR-042 D1). `nomLisible()` est la source unique depuis P10b-1.
            $delivrance->pharmacien_user_id = $pharmacien->id;
            $delivrance->pharmacien_nom = $pharmacien->nomLisible();
            $delivrance->delivree_le = now();
            $delivrance->save();

            foreach ($servies as $ligneId => $quantite) {
                $ligne = $lignes->get((int) $ligneId);

                if ($ligne === null) {
                    $this->refus('Un des médicaments servis n\'appartient pas à cette ordonnance.');
                }

                $quantite = (int) $quantite;
                $reste = $ligne->resteAServir();

                // `null` = le médecin n'a pas précisé de quantité : on ne borne pas ce qu'on ne
                // sait pas. Ce n'est pas la même chose que « zéro » (précédent P10c-3-ii).
                if ($reste !== null && $quantite > $reste) {
                    $this->refus(sprintf(
                        '« %s » : il ne reste que %d à servir sur cette ordonnance.',
                        $ligne->nom,
                        $reste,
                    ));
                }

                $servie = new DelivranceLigne;
                $servie->delivrance_id = $delivrance->id;
                $servie->ordonnance_ligne_id = $ligne->id;
                $servie->quantite = $quantite;
                $servie->save();
            }

            $delivrance = $delivrance->fresh(['lignes.ligne']);

            // B3-b — la delivrance SORT du stock, si l'officine tient son inventaire. Sinon elle
            // passe sans rien decrementer : refuser de servir parce qu'une pharmacie ne tient pas
            // son stock dans notre application priverait un patient de son traitement pour une
            // raison qui ne le concerne pas (meme esprit qu'en P7-D0).
            app(ServiceStockOfficine::class)->sortirPourDelivrance($pharmacien, $delivrance);

            // B3-c — le registre national (§7.6) : une trace DÉNOMINALISÉE par ligne servie, qui
            // survivra à la suppression de cette ordonnance. `inscrire()` ne décide rien, il
            // enregistre ce que cette délivrance vient d'établir (motif Q9 du G0 de B3-c).
            app(ServiceTracabiliteMedicament::class)->inscrire($delivrance);

            return $delivrance;
        });
    }

    /** L'officine du compte, après vérification que c'en est bien une. */
    private function assertOfficine(User $pharmacien): StructureSanitaire
    {
        $structure = $pharmacien->structure;

        if ($structure === null) {
            $this->refus('Votre compte n\'est rattaché à aucune officine.');
        }

        if (! $structure->estPharmacie()) {
            $this->refus('Une ordonnance ne se sert que dans une pharmacie.');
        }

        return $structure;
    }

    private function assertHabilite(User $pharmacien): void
    {
        // Vérifiée ICI et pas seulement par le middleware : les routes du portail sont sur le guard
        // `web`, et un `permission:` au mauvais guard laisse passer (piège de P4).
        if (! $pharmacien->can(self::PERMISSION)) {
            $this->refus('Vous n\'êtes pas habilité à servir une ordonnance.');
        }
    }

    /** @return never */
    private function refus(string $message, string $champ = 'delivrance'): void
    {
        throw ValidationException::withMessages([$champ => $message]);
    }
}
