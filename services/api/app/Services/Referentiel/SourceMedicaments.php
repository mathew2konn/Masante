<?php

namespace App\Services\Referentiel;

use App\Models\InteractionMedicamenteuse;
use App\Models\Medicament;
use App\Services\Medicament\GenerateurCodeMedicament;
use App\Support\Medicaments;

/**
 * Référentiel national des médicaments (CDC_09 §6).
 *
 * ═══ ICI LA PROJECTION PREND LA LIGNE ENTIÈRE, ET CE N'EST PAS UNE FACILITÉ ═══
 *
 * P6.4a a dû exclure `note_moyenne` de la projection des établissements : recalculée à chaque avis
 * citoyen, elle aurait rendu l'instantané divergent en permanence et l'anti-substitution aurait
 * refusé toute publication. P6.5a a refait le tri sur un autre critère, faute de valeur recalculée.
 *
 * La question a été reposée ici plutôt que la conclusion recopiée, et **la vérification a donné une
 * réponse opposée** : rien n'écrit automatiquement dans `medicaments`. Les prix relevés par les
 * citoyens et les ruptures signalées vivent dans `prix_pharmacie`, une table séparée. Le prix
 * homologué, lui, est une donnée d'autorité — c'est le §6.2 qui l'exige, et il ne bouge que si
 * l'autorité le change.
 *
 * ═══ LES INTERACTIONS SONT DANS LE MÊME INSTANTANÉ, DÉLIBÉRÉMENT ═══
 *
 * Publier les médicaments et leurs interactions séparément permettrait qu'une version d'interactions
 * désigne un produit absent de la version de médicaments en vigueur : la référence serait
 * **irrésoluble**, et le référentiel affirmerait quelque chose d'invérifiable. Un seul référentiel,
 * un seul instantané, une seule version citable par une décision clinique.
 *
 * Chaque interaction est portée par les **codes nationaux** des deux produits, jamais par leurs
 * identifiants techniques : un instantané doit rester lisible et rejouable sans la base qui l'a
 * produit (précédent P6.4c, où l'empreinte porte le contenu et non le chemin de stockage).
 */
final class SourceMedicaments implements SourceReferentiel
{
    public const CODE = 'medicaments';

    public function code(): string
    {
        return self::CODE;
    }

    public function libelle(): string
    {
        return 'Médicaments et interactions';
    }

    /**
     * §10 : « chaque référentiel a un responsable désigné ». Le ministère — les autorisations de
     * mise sur le marché, les retraits et la pharmacovigilance (§6.5) relèvent de l'autorité
     * sanitaire nationale, jamais d'une pharmacie ni d'un laboratoire fabricant, qui serait juge
     * et partie sur son propre produit.
     */
    public function roleResponsable(): string
    {
        return 'ministere';
    }

    public function extraire(): array
    {
        $medicaments = Medicament::query()
            // Ordre stable : `code` est UNIQUE par pays mais reste NULL tant qu'une fiche n'a pas
            // été servie par le backfill — d'où `id` en second, qui rend l'ordre total dans tous
            // les cas (précédent P6.4a).
            ->orderBy('code')
            ->orderBy('id')
            ->get();

        $lignes = $medicaments->map(fn (Medicament $m): array => [
            'code'                 => $m->code,
            'pays_code'            => $m->pays_code,
            'dci'                  => $m->nom_generique,
            'nom_commercial'       => $m->nom_commercial,
            'laboratoire'          => $m->laboratoire,
            'forme'                => $m->forme,
            'dosage'               => $m->dosage,
            'voie_administration'  => $m->voie_administration,
            'classe_therapeutique' => $m->categorie,
            'indications'          => $m->indications,
            'contre_indications'   => $m->contre_indications,
            'effets_secondaires'   => $m->effets_secondaires,
            'prix_homologue_cfa'   => $m->prix_reference_cfa === null ? null : (int) $m->prix_reference_cfa,
            'ordonnance_requise'   => (bool) $m->ordonnance_requise,
            'statut_marche'        => $m->statut_marche,
            'statut_generique'     => $m->statut_generique,
            'cename_reference'     => $m->cename_reference,
        ])->all();

        return [
            ...$lignes,
            ...$this->interactions($medicaments->pluck('code', 'id')->all()),
        ];
    }

    /**
     * Les interactions, portées par les codes nationaux et triées de façon totale.
     *
     * @param  array<int, string|null>  $codesParId
     * @return array<int, array<string, mixed>>
     */
    private function interactions(array $codesParId): array
    {
        return InteractionMedicamenteuse::query()
            ->orderBy('medicament_a_id')
            ->orderBy('medicament_b_id')
            ->get()
            ->map(fn (InteractionMedicamenteuse $i): array => [
                'type'             => 'interaction',
                'medicament_a'     => $codesParId[$i->medicament_a_id] ?? null,
                'medicament_b'     => $codesParId[$i->medicament_b_id] ?? null,
                'niveau'           => $i->niveau,
                'description'      => $i->description,
                'conduite_a_tenir' => $i->conduite_a_tenir,
                'source'           => $i->source,
            ])
            ->all();
    }

    /**
     * Contrôles qualité §10 — bloquants à la publication.
     *
     * Ce qui est vérifié tient en une phrase : **un référentiel national ne doit pas pouvoir
     * affirmer quelque chose d'invérifiable ou de contradictoire.**
     */
    public function controlerQualite(array $contenu): array
    {
        $erreurs = [];

        [$produits, $interactions] = $this->separer($contenu);

        if ($produits === []) {
            return ['Le référentiel est vide : aucun médicament à publier.'];
        }

        $codesVus = [];
        $produitsVus = [];

        foreach ($produits as $ligne) {
            $code = $ligne['code'];
            $dci  = trim((string) $ligne['dci']);
            $nom  = $code ?? ($dci !== '' ? $dci : '(sans code ni DCI)');

            if ($code === null) {
                $erreurs[] = "« {$nom} » : aucun code national attribué — lancez `masante:medicaments:backfill`.";
            } elseif (! GenerateurCodeMedicament::formeValide($code)) {
                $erreurs[] = "« {$nom} » : code national mal formé (attendu MED + 6 chiffres).";
            } else {
                // LE PAYS FAIT PARTIE DE LA CLÉ. Comparer les codes seuls signalerait un doublon
                // entre CI et SN, que l'index autorise pourtant : le contrôle serait plus strict
                // que le moteur, et le référentiel deviendrait impubliable dès le second pays.
                // C'est exactement le défaut trouvé au G2 live de P6.5a.
                $cle = $ligne['pays_code'].'-'.$code;

                if (isset($codesVus[$cle])) {
                    $erreurs[] = "Doublon : le code « {$code} » apparaît plusieurs fois pour {$ligne['pays_code']}.";
                }
                $codesVus[$cle] = true;
            }

            if ($dci === '') {
                $erreurs[] = "« {$nom} » : DCI absente — c'est elle qui identifie la molécule (§6.1).";
            }

            // §6.1 « éviter les incohérences de nommage » — le même PRODUIT saisi deux fois.
            //
            // LA CLÉ N'EST SURTOUT PAS LA SEULE DCI. Le jeu de développement contient
            // « Amoxicilline 500 mg » deux fois : une ligne générique portant sa référence CENAME,
            // une ligne « Clamoxyl ». Ce sont deux produits distincts, et c'est le fonctionnement
            // normal d'un référentiel — un contrôle sur la DCI seule les aurait signalés comme
            // doublons et aurait rendu le référentiel impubliable. La clé est donc le produit
            // complet : molécule, dosage, marque, fabricant.
            $produit = strtolower(trim($dci).'|'.trim((string) $ligne['dosage'])
                .'|'.trim((string) $ligne['nom_commercial']).'|'.trim((string) $ligne['laboratoire']));

            if (isset($produitsVus[$produit])) {
                $erreurs[] = "Doublon : « {$dci} » apparaît deux fois avec le même dosage, la même "
                    .'marque et le même fabricant.';
            }
            $produitsVus[$produit] = true;

            // Un dosage sans forme est inexploitable : « 500 mg » de quoi ? L'inverse est tolérable
            // (une crème peut n'avoir aucun dosage utile à afficher).
            if (trim((string) $ligne['dosage']) !== '' && $ligne['forme'] === null) {
                $erreurs[] = "« {$nom} » : un dosage est renseigné sans forme pharmaceutique.";
            }

            if ($ligne['forme'] !== null && ! in_array($ligne['forme'], Medicaments::formes(), true)) {
                $erreurs[] = "« {$nom} » : forme pharmaceutique inconnue ({$ligne['forme']}).";
            }

            if ($ligne['voie_administration'] !== null
                && ! in_array($ligne['voie_administration'], Medicaments::voies(), true)) {
                $erreurs[] = "« {$nom} » : voie d'administration inconnue ({$ligne['voie_administration']}).";
            }

            if (! in_array($ligne['statut_marche'], Medicaments::statutsMarche(), true)) {
                $erreurs[] = "« {$nom} » : statut de commercialisation inconnu ({$ligne['statut_marche']}).";
            }

            if ($ligne['prix_homologue_cfa'] !== null && $ligne['prix_homologue_cfa'] < 0) {
                $erreurs[] = "« {$nom} » : prix homologué négatif.";
            }
        }

        foreach ($interactions as $i) {
            $couple = ($i['medicament_a'] ?? '?').' / '.($i['medicament_b'] ?? '?');

            // Une interaction qui désigne un produit absent de CE contenu est irrésoluble : la
            // publier ferait affirmer au référentiel quelque chose que personne ne peut vérifier.
            foreach (['medicament_a', 'medicament_b'] as $cote) {
                if ($i[$cote] === null) {
                    $erreurs[] = "Interaction {$couple} : elle désigne un médicament sans code national.";
                }
            }

            if ($i['medicament_a'] !== null && $i['medicament_a'] === $i['medicament_b']) {
                $erreurs[] = "Interaction {$couple} : un médicament ne peut pas interagir avec lui-même.";
            }

            if (! in_array($i['niveau'], Medicaments::niveauxInteraction(), true)) {
                $erreurs[] = "Interaction {$couple} : niveau inconnu ({$i['niveau']}).";
            }

            if (trim((string) $i['description']) === '') {
                $erreurs[] = "Interaction {$couple} : description absente — une interaction sans énoncé "
                    .'ne dit pas au prescripteur ce qui est en cause.';
            }

            // Une interaction sans source est une rumeur. On la signale sans la bloquer serait
            // tentant, mais §10 fait des contrôles qualité une barrière : un référentiel national
            // qui affirme une contre-indication doit pouvoir dire d'où elle vient.
            if (trim((string) $i['source']) === '') {
                $erreurs[] = "Interaction {$couple} : source absente — le référentiel ne peut pas "
                    .'affirmer une interaction sans dire d\'où elle vient.';
            }
        }

        return $erreurs;
    }

    /**
     * Sépare les produits des interactions dans un instantané.
     *
     * @param  array<int, array<string, mixed>>  $contenu
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private function separer(array $contenu): array
    {
        $produits = [];
        $interactions = [];

        foreach ($contenu as $ligne) {
            if (($ligne['type'] ?? null) === 'interaction') {
                $interactions[] = $ligne;
            } else {
                $produits[] = $ligne;
            }
        }

        return [$produits, $interactions];
    }
}
