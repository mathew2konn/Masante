<?php

namespace App\Services\Medicament;

use App\Models\Medicament;
use App\Support\Medicaments;
use Illuminate\Validation\ValidationException;

/**
 * P6.6b — Le lien entre une ligne d'ordonnance et le référentiel national (CDC_09 §6.1).
 *
 * ═══ LE DÉFAUT QUE CE SERVICE REFERME ═══
 *
 * `medicaments_json.*.nom` est un texte libre. Une ordonnance pouvait donc nommer n'importe quelle
 * molécule, sous n'importe quelle orthographe — exactement ce que §6.1 dit vouloir supprimer
 * (« éviter les incohérences de nommage et garantir une prescription fiable »), et le miroir du
 * défaut `medecin_nom` refermé en P6.5.
 *
 * ═══ LE LIEN EST FACULTATIF, ET C'EST UN CHOIX ═══
 *
 * Un patient qui photographie une vieille ordonnance ne choisit pas dans une liste ; et le
 * référentiel est incomplet. Rendre `medicament_id` obligatoire ferait de ses lacunes un blocage
 * clinique. Le `nom` libre reste donc accepté et conservé tel quel.
 *
 * ═══ MAIS QUAND IL EST FOURNI, LE SERVEUR NE CROIT RIEN DU CLIENT ═══
 *
 * Le code national, la DCI et le dosage sont RELUS au référentiel et écrasent ce que la requête
 * contenait. Même mouvement qu'en P6.5a, où `nom` et `prenom` sont refusés du client et repris du
 * compte : ce que le serveur sait n'a pas à être redemandé à celui qu'on contrôle.
 *
 * ═══ ET ILS SONT FIGÉS ═══
 *
 * Le nom commercial d'un produit change, un laboratoire cède une marque. Une ordonnance — surtout
 * SIGNÉE (P6.5b) — doit continuer de dire ce qui a été prescrit ce jour-là. On recopie donc les
 * valeurs au moment de la prescription plutôt que de les rejouer par jointure, exactement comme
 * P7-D2 recopie l'établissement à l'écriture pour qu'un agent muté ne déplace pas ses visites
 * passées.
 */
final class ServiceLienMedicament
{
    /**
     * Résout les entrées d'un `medicaments_json` validé.
     *
     * @param  array<int, array<string, mixed>>  $lignes
     * @return array<int, array<string, mixed>>
     *
     * @throws ValidationException si un `medicament_id` ne désigne aucun produit du référentiel
     */
    public function resoudre(array $lignes): array
    {
        $ids = array_values(array_filter(array_map(
            static fn (array $l): ?int => isset($l['medicament_id']) ? (int) $l['medicament_id'] : null,
            $lignes,
        )));

        $produits = $ids === []
            ? collect()
            : Medicament::whereIn('id', $ids)->get()->keyBy('id');

        return array_values(array_map(function (array $ligne) use ($produits): array {
            // Ce que le client a écrit reste son texte : on ne réécrit pas le nom qu'il a saisi.
            // Les clés dérivées, elles, ne viennent jamais de lui — on les efface avant de les
            // reposer, sinon un client pourrait faire porter à sa ligne un code national qu'il a
            // choisi et que rien n'aurait vérifié.
            unset($ligne['code_national'], $ligne['dci'], $ligne['dosage_referentiel']);

            if (! isset($ligne['medicament_id'])) {
                return $ligne;
            }

            $id = (int) $ligne['medicament_id'];
            $produit = $produits->get($id);

            if ($produit === null) {
                throw ValidationException::withMessages([
                    'medicaments_json' => "Le médicament n°{$id} n'existe pas au référentiel national.",
                ]);
            }

            $ligne['medicament_id']      = $id;
            $ligne['code_national']      = $produit->code;
            $ligne['dci']                = $produit->nom_generique;
            $ligne['dosage_referentiel'] = $produit->dosage;

            return $ligne;
        }, $lignes));
    }

    /**
     * Ce que le prescripteur doit savoir, sans que rien ne soit refusé.
     *
     * SEULEMENT LE STATUT DE COMMERCIALISATION. Les interactions n'y figurent PAS : le propriétaire
     * a choisi « donnée du référentiel + consultation explicite » et non « signalement au moment de
     * prescrire » — les calculer ici rapprocherait P6.6 d'une aide à la décision, terrain de CDC_05
     * et de CDC_08. Elles se demandent, elles ne s'imposent pas.
     *
     * @param  array<int, array<string, mixed>>  $lignes
     * @return array<int, array<string, mixed>>
     */
    public function avertissements(array $lignes): array
    {
        $ids = array_values(array_filter(array_map(
            static fn (array $l): ?int => isset($l['medicament_id']) ? (int) $l['medicament_id'] : null,
            $lignes,
        )));

        if ($ids === []) {
            return [];
        }

        return Medicament::whereIn('id', $ids)
            ->whereIn('statut_marche', ['suspendu', 'retire'])
            ->get()
            ->map(static fn (Medicament $m): array => [
                'type'          => 'statut_marche',
                'medicament_id' => $m->id,
                'code_national' => $m->code,
                'libelle'       => $m->libelle,
                'statut'        => $m->statut_marche,
                'message'       => $m->statut_marche === 'retire'
                    ? "« {$m->libelle} » est retiré du marché au référentiel national."
                    : "« {$m->libelle} » est suspendu au référentiel national.",
            ])
            ->values()
            ->all();
    }

    /**
     * Les interactions déclarées entre les produits d'une liste, résolues PAR MOLÉCULE.
     *
     * POURQUOI L'ÉQUIVALENCE PAR DCI. Une interaction est déclarée entre deux lignes du référentiel,
     * mais elle vaut cliniquement entre deux MOLÉCULES : une interaction posée sur l'aspirine
     * générique concerne aussi la marque qui contient la même aspirine. Chercher les seuls
     * identifiants prescrits manquerait donc les couples déclarés sur une autre présentation du même
     * principe actif — un silence qui ressemblerait à « aucune interaction ».
     *
     * Ce n'est pas une règle médicale : c'est une résolution d'IDENTITÉ, et c'est précisément le
     * rôle que §6.2 donne à la DCI. Le jugement clinique, lui, reste au `interaction-service`
     * (CDC_05 §2).
     *
     * @param  array<int, int>  $medicamentIds
     * @return array<int, int> les identifiants à confronter, équivalents par DCI inclus
     */
    public function etendreParMolecule(array $medicamentIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $medicamentIds)));

        if ($ids === []) {
            return [];
        }

        $dci = Medicament::whereIn('id', $ids)->pluck('nom_generique')->unique()->all();

        return Medicament::whereIn('nom_generique', $dci)->pluck('id')->map('intval')->all();
    }

    /** Les niveaux d'interaction, dans l'ordre où un humain veut les lire (jamais pour décider). */
    public function ordreGravite(): array
    {
        return Medicaments::ORDRE_GRAVITE;
    }
}
