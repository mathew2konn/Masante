<?php

namespace App\Services\Assurance;

use App\Models\CouvertureMembre;
use App\Models\MembreFamille;
use App\Models\OrganismeAssurance;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * P6.8d — L'écriture d'une couverture santé déclarée (CDC_09 §8, CDC_06 §8).
 *
 * ═══ CE QUE LE CLIENT NE DÉCLARE PAS ═══
 *
 * `provenance` et `verifiee_le` sont effacés du tableau validé avant toute écriture, sur **tous** les
 * chemins. C'est la SECONDE couche : la première est `$fillable`, la deuxième est ici, et chacune a
 * son propre vecteur — leçon des mutations de P6.6b, où des vecteurs restés verts ne prouvaient que
 * le validateur.
 *
 * Pourquoi cette garde compte : laisser écrire `provenance = verifie` transformerait une
 * auto-déclaration en **attestation**, par un simple envoi HTTP. C'est précisément ce que P6.8d
 * corrige au niveau du mot — la carte F2.3 disait « il confirme votre statut CMU » d'une case remplie
 * par l'intéressé lui-même.
 *
 * ═══ QUAND UN ORGANISME EST DÉSIGNÉ, LE SERVEUR NE CROIT RIEN DU CLIENT ═══
 *
 * Le lien est relu à la **version publiée** du référentiel (§10), jamais à la table : lire la table
 * rendrait la gouvernance décorative — un `UPDATE` direct changerait ce qu'un guichet lit, sans
 * relecture ni quatre-yeux (défaut refermé par L1+L2 pour `seuils_mesure`).
 *
 * ═══ MAIS LE NOM N'EST PAS FIGÉ, À L'INVERSE DE P6.6b / P6.7b / P6.8c ═══
 *
 * Question reposée, réponse inverse, et la raison est de nature : ces trois-là inscrivaient un fait
 * **historique** dans un carnet — une ordonnance signée, un résultat rendu, un antécédent daté. Une
 * couverture est un **état courant** : « je suis assuré chez X aujourd'hui ». Si X est renommé, la
 * phrase reste vraie sous le nouveau nom, et afficher l'ancien ferait porter à l'assuré un nom que le
 * guichet ne reconnaît plus. Le nom vient donc du référentiel **à la lecture**.
 */
final class ServiceCouvertures
{
    public function __construct(private readonly ServiceAssurances $assurances) {}

    /**
     * Nettoie et complète le tableau validé avant écriture (création comme modification).
     *
     * @param  array<string, mixed>  $valide
     * @return array<string, mixed>
     *
     * @throws ValidationException si l'organisme désigné ne figure pas dans la version en vigueur
     */
    public function preparer(array $valide): array
    {
        // JAMAIS du client, sur aucun chemin — voir l'en-tête.
        unset($valide['provenance'], $valide['verifiee_le']);

        if (($valide['organisme_assurance_id'] ?? null) === null) {
            // ═══ LE REPLI HORS RÉFÉRENTIEL (3ᵉ application du motif E4) ═══
            //
            // Le registre livré est un jeu de démonstration : refuser une couverture dont
            // l'organisme n'y figure pas ferait payer NOS lacunes à un assuré réel. L'écart est
            // COMPTÉ et AFFICHÉ, jamais bloqué.
            $valide['organisme_assurance_id'] = null;

            return $valide;
        }

        $publie = $this->exigerPublie((int) $valide['organisme_assurance_id']);

        // UN LIEN ET UN LIBELLÉ LIBRE SERAIENT DEUX VÉRITÉS sur la même ligne, et le lecteur
        // choisirait laquelle croire (motif P6.7b). Le nom vient du référentiel, à la lecture.
        $valide['organisme_libelle'] = null;

        // Rien d'autre n'est recopié : ni le nom, ni le type, ni l'état d'agrément. Les figer ferait
        // porter à la couverture un état d'agrément périmé — or c'est justement ce qu'un guichet doit
        // lire à jour.
        unset($publie);

        return $valide;
    }

    /**
     * Enregistre une couverture pour ce membre.
     *
     * ═══ UNE SEULE COUVERTURE VIVANTE PAR ORGANISME — GARDE APPLICATIVE, ET C'EST DIT ═══
     *
     * MySQL 8 n'a pas d'index unique partiel : « une seule ligne vivante par (membre, organisme) » ne
     * peut pas être tenu par le moteur sans une colonne générée et un jeu de contraintes qui
     * coûterait plus qu'il ne garantit. Elle est donc tenue ici — **annoncée comme applicative,
     * jamais déguisée en garantie du moteur** (précédent du quota d'images de P6.4c).
     *
     * Sous verrou pessimiste : deux envois simultanés du même écran ne peuvent pas créer deux fois
     * la même couverture.
     *
     * @param  array<string, mixed>  $donnees
     */
    public function enregistrer(MembreFamille $membre, array $donnees): CouvertureMembre
    {
        return DB::transaction(function () use ($membre, $donnees): CouvertureMembre {
            $this->refuserLeDoublonVivant($membre, $donnees, null);

            $couverture = new CouvertureMembre($donnees);
            $couverture->membre_id = $membre->id;
            // Explicite plutôt que par défaut de colonne : la valeur est posée PAR LE SERVEUR, et
            // aucune autre n'est atteignable tant qu'aucune vérification n'existe (décision F2).
            $couverture->provenance = 'declare';
            $couverture->save();

            return $couverture;
        });
    }

    /**
     * Met à jour une couverture existante.
     *
     * @param  array<string, mixed>  $donnees
     */
    public function mettreAJour(CouvertureMembre $couverture, array $donnees): CouvertureMembre
    {
        return DB::transaction(function () use ($couverture, $donnees): CouvertureMembre {
            $this->refuserLeDoublonVivant($couverture->membre, $donnees, $couverture->id);

            $couverture->fill($donnees);
            // Une modification ne peut pas non plus promouvoir la ligne en « vérifiée » : la garde
            // vaut sur le chemin de mise à jour comme sur celui de création — *une garantie qui ne
            // vaudrait que sur l'un des chemins n'en serait pas une* (leçon P6.8b, où le `update()`
            // avait été oublié).
            $couverture->provenance = 'declare';
            $couverture->save();

            return $couverture;
        });
    }

    /**
     * Avertissements NON BLOQUANTS joints à la réponse.
     *
     * UN AGRÉMENT SUSPENDU OU RETIRÉ EST SIGNALÉ, JAMAIS REFUSÉ — décision identique à celle des
     * médicaments retirés (P6.6a/b), des vaccins retirés (P6.8b) et des maladies désactivées
     * (P6.8c). Un assuré dont l'organisme vient d'être suspendu **l'est toujours** : refuser sa
     * déclaration effacerait un fait réel et le priverait de l'information au moment où elle lui est
     * le plus utile.
     *
     * @return array<int, array<string, mixed>>
     */
    public function avertissements(?int $organismeId): array
    {
        if ($organismeId === null) {
            return [[
                'code'    => 'organisme_hors_referentiel',
                'message' => 'Cet organisme ne figure pas au référentiel national : la couverture '
                    .'est enregistrée telle que vous l\'avez saisie, et MaSanté ne peut rien '
                    .'confirmer à son sujet.',
            ]];
        }

        $organisme = OrganismeAssurance::find($organismeId);
        $publie    = $organisme?->code !== null ? $this->assurances->organismePublie($organisme->code) : null;

        if ($publie === null) {
            return [];
        }

        $statut = $publie['agrement_statut'] ?? null;

        if ($statut !== 'suspendu' && $statut !== 'retire') {
            return [];
        }

        return [[
            'code'    => 'agrement_'.$statut,
            'message' => sprintf(
                'L\'agrément de « %s » est %s au référentiel national. Votre couverture est '
                .'enregistrée ; renseignez-vous auprès de l\'organisme avant de la présenter.',
                $publie['nom'],
                $statut === 'suspendu' ? 'suspendu' : 'retiré',
            ),
        ]];
    }

    /**
     * DEUX LECTURES, ET CHACUNE RÉPOND À UNE QUESTION DIFFÉRENTE (motif P6.8b/P6.8c).
     *
     * La TABLE répond de l'intégrité référentielle : `organisme_assurance_id` est une clé étrangère,
     * elle doit désigner une ligne réelle. La VERSION PUBLIÉE fait autorité sur le CONTENU.
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function exigerPublie(int $id): array
    {
        $organisme = OrganismeAssurance::find($id);

        if ($organisme === null) {
            throw ValidationException::withMessages([
                'organisme_assurance_id' => "L'organisme n°{$id} n'existe pas au référentiel national.",
            ]);
        }

        $publie = $organisme->code !== null
            ? $this->assurances->organismePublie($organisme->code, $organisme->pays_code)
            : null;

        if ($publie === null) {
            // Refus BRUYANT, attribué au champ rempli. Accepter le lien en lisant la table
            // laisserait rattacher un organisme que personne n'a mis en vigueur ; l'ignorer en
            // silence ferait croire à un rattachement qui n'a pas eu lieu.
            throw ValidationException::withMessages([
                'organisme_assurance_id' => sprintf(
                    '« %s » ne figure pas dans la version en vigueur du référentiel national des '
                    .'organismes d\'assurance : vous pouvez saisir son nom librement.',
                    $organisme->nom,
                ),
            ]);
        }

        return $publie;
    }

    /**
     * @param  array<string, mixed>  $donnees
     *
     * @throws ValidationException
     */
    private function refuserLeDoublonVivant(MembreFamille $membre, array $donnees, ?int $exclu): void
    {
        // Une ligne résiliée ou échue ne bloque rien : un assuré qui reprend un contrat chez le même
        // organisme doit pouvoir le déclarer, et son historique doit rester lisible.
        $vivantes = CouvertureMembre::query()
            ->where('membre_id', $membre->id)
            ->when($exclu !== null, fn ($q) => $q->whereKeyNot($exclu))
            ->vivante()
            ->lockForUpdate()
            ->get();

        $organismeId = $donnees['organisme_assurance_id'] ?? null;
        $libelle     = ServiceAssurances::normaliser((string) ($donnees['organisme_libelle'] ?? ''));

        foreach ($vivantes as $existante) {
            $memeOrganisme = $organismeId !== null
                ? $existante->organisme_assurance_id === (int) $organismeId
                : $existante->organisme_assurance_id === null
                    && ServiceAssurances::normaliser((string) $existante->organisme_libelle) === $libelle;

            if ($memeOrganisme) {
                throw ValidationException::withMessages([
                    'organisme_assurance_id' => 'Une couverture en cours existe déjà pour cet '
                        .'organisme. Modifiez-la, ou indiquez sa date de fin avant d\'en déclarer '
                        .'une nouvelle.',
                ]);
            }
        }
    }
}
