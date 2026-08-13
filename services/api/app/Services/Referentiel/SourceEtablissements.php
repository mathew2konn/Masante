<?php

namespace App\Services\Referentiel;

use App\Models\StructureSanitaire;
use App\Services\Etablissement\GenerateurIdentifiantEtablissement;

/**
 * Référentiel national des établissements de santé (CDC_09 §4) — **projection d'identité
 * administrative**, et non la table `structures_sanitaires` entière (décision G1 D1).
 *
 * ═══ POURQUOI UNE PROJECTION — la décision centrale de P6.4 ═══
 *
 * `structures_sanitaires` mélange deux natures de données que le socle P6.3 ne peut pas traiter
 * de la même façon :
 *
 *   · l'IDENTITÉ ADMINISTRATIVE — identifiant national, statut juridique, niveau de soins,
 *     district, agréments. Elle change rarement, et toujours sur décision d'une autorité.
 *   · l'ÉTAT OPÉRATIONNEL — `note_moyenne`, `nb_avis`, horaires, tarifs, disponibilités,
 *     coordonnées de contact. Il change en continu, et souvent AUTOMATIQUEMENT.
 *
 * `note_moyenne` et `nb_avis` sont recalculés par `NoteStructureService` **à chaque avis déposé
 * par un citoyen**. Versionner la table entière rendrait donc l'instantané divergent en
 * permanence : l'anti-substitution de P6.3 refuserait toute publication dès qu'un avis arrive, et
 * la commande de contrôle signalerait « DIVERGENTE » sans discontinuer. Le mécanisme deviendrait
 * inexploitable — et surtout MENSONGER, puisqu'il affirmerait qu'une note d'étoiles est une
 * donnée de référence nationale.
 *
 * ═══ CE QUI EST EXCLU, ET POURQUOI ═══
 *
 * Téléphone, e-mail, site web, coordonnées GPS, adresse, horaires, tarifs, spécialités,
 * description, note et nombre d'avis. Toutes ces données sont réelles et utiles — elles servent
 * la carte, la recherche et la fiche (P3) — mais **elles n'engagent aucune autorité**. On les
 * corrige au fil de l'eau ; les soumettre à un cycle de proposition et de double validation
 * transformerait la correction d'un numéro de téléphone en acte de gouvernance nationale.
 *
 * Ce que la projection répond, c'est la question du §4.4 : « quels établissements existent, avec
 * quel statut, quel niveau de soins et dans quel district ? » — la matière des statistiques
 * nationales et de la planification sanitaire. Pas « lequel est ouvert cet après-midi ».
 *
 * ═══ CONSÉQUENCE À CONNAÎTRE ═══
 *
 * Un établissement dont le téléphone change ne fait PAS diverger le référentiel publié. Un
 * établissement dont le statut juridique change, si. C'est exactement le comportement voulu, et
 * c'est le vecteur qui le prouve au G2.
 */
final class SourceEtablissements implements SourceReferentiel
{
    public const CODE = 'etablissements';

    /** Catégories pour lesquelles l'absence de niveau de soins est une anomalie. */
    private const CATEGORIES_HOSPITALIERES = ['chu', 'chr', 'hopital_general'];

    public function code(): string
    {
        return self::CODE;
    }

    public function libelle(): string
    {
        return 'Établissements de santé';
    }

    public function roleResponsable(): string
    {
        return 'ministere';
    }

    public function extraire(): array
    {
        return StructureSanitaire::query()
            ->with(['region:id,code', 'district:id,code,region_id', 'images'])
            // Ordre stable : `identifiant_national` est UNIQUE par pays, mais reste NULL tant
            // qu'un établissement n'a pas été servi par le backfill — d'où `id` en second, qui
            // rend l'ordre total dans tous les cas.
            ->orderBy('identifiant_national')
            ->orderBy('id')
            ->get()
            ->map(fn (StructureSanitaire $e): array => [
                'identifiant_national' => $e->identifiant_national,
                'pays_code'            => $e->pays_code,
                // Le nom officiel prime ; à défaut, le nom d'usage de l'annuaire. On ne laisse
                // jamais l'entrée sans nom : un référentiel d'établissements anonymes ne sert à rien.
                'nom_officiel'         => $e->nom_officiel ?? $e->nom,
                'categorie'            => $e->type,
                'statut_juridique'     => $e->statut_juridique,
                // P6.4d — qui possède (`statut_juridique`) et sous quelle forme de droit
                // (`forme_juridique`) sont deux axes distincts : une clinique privée peut être
                // une SARL ou une SA. Les deux engagent une autorité, donc les deux sont ici.
                'forme_juridique'      => $e->forme_juridique,
                'niveau_soins'         => $e->niveau_soins,
                'region_code'          => $e->region?->code,
                'district_code'        => $e->district?->code,
                'commune'              => $e->commune,
                'capacite_accueil'     => $e->capacite_accueil,
                'nombre_lits'          => $e->nombre_lits,
                'numero_autorisation'  => $e->numero_autorisation,
                'autorite_tutelle'     => $e->autorite_tutelle,
                'agrements'            => $e->agrements_json,
                'certifications'       => $e->certifications_json,
                'actif'                => (bool) $e->actif,
                'images'               => $this->projeterImages($e),
                // Rattachement conservé pour la cohérence hiérarchique contrôlée plus bas :
                // sans lui, on ne saurait pas dire qu'un district est hors de sa région.
                'region_du_district'   => $e->district?->region?->code,
            ])
            ->all();
    }

    /**
     * Projection des images (P6.4c, décision propriétaire I3 du 2026-08-13).
     *
     * ═══ POURQUOI LES IMAGES ENTRENT DANS LE RÉFÉRENTIEL ═══
     *
     * Le propriétaire les y a placées contre la recommandation initiale, et la décision se tient :
     * une photo est déposée **délibérément par un humain identifié**, comme un numéro d'autorisation
     * — pas recalculée automatiquement comme une note d'étoiles. La ligne d'exclusion posée
     * ci-dessus n'est donc pas franchie. La conséquence, elle, est réelle et assumée : **déposer
     * une image fait diverger le référentiel publié** jusqu'à la version suivante.
     *
     * ═══ CE QUI ENTRE N'EST PAS LE FICHIER ═══
     *
     * Ni les octets (l'instantané deviendrait énorme — c'est la limite L6 d'ADR-025, qu'on
     * aggraverait au lieu de la traiter), ni le chemin de stockage (un UUID : redéposer exactement
     * la même image ferait diverger le référentiel alors que RIEN n'a changé, et l'instantané
     * publierait des détails d'implémentation).
     *
     * Entrent la CATÉGORIE et l'EMPREINTE du contenu. L'instantané dit alors : « cet établissement
     * publie un logo dont le contenu vaut a3f… ». Changer le logo diverge ; redéposer le même
     * octet pour octet ne diverge pas. C'est exactement la sensibilité qu'on veut.
     *
     * Le TRI est indispensable : sans lui, deux ensembles d'images identiques produiraient deux
     * empreintes différentes selon l'ordre d'insertion, et le référentiel divergerait sans raison.
     *
     * @return list<array{categorie:string, empreinte:string}>
     */
    private function projeterImages(StructureSanitaire $etablissement): array
    {
        return $etablissement->images
            ->map(fn ($image): array => [
                'categorie' => $image->categorie_code,
                'empreinte' => $image->empreinte,
            ])
            ->sortBy([['categorie', 'asc'], ['empreinte', 'asc']])
            ->values()
            ->all();
    }

    public function controlerQualite(array $contenu): array
    {
        $erreurs = [];

        if ($contenu === []) {
            return ['Le référentiel est vide : aucun établissement à publier.'];
        }

        $identifiantsVus = [];
        $nomsParDistrict = [];

        foreach ($contenu as $ligne) {
            $nom = trim((string) ($ligne['nom_officiel'] ?? ''));
            $etiquette = $ligne['identifiant_national'] ?? ('« '.($nom !== '' ? $nom : 'sans nom').' »');

            // — Identifiant national (§4.3) —
            if ($ligne['identifiant_national'] === null) {
                $erreurs[] = "{$etiquette} : aucun identifiant national "
                    .'(php artisan masante:etablissement:backfill).';
            } elseif (! GenerateurIdentifiantEtablissement::formeValide($ligne['identifiant_national'])) {
                $erreurs[] = "{$etiquette} : identifiant national mal formé (attendu ETS + 6 chiffres).";
            } elseif (isset($identifiantsVus[$ligne['identifiant_national']])) {
                $erreurs[] = "Doublon : l'identifiant {$ligne['identifiant_national']} est porté "
                    .'par plusieurs établissements.';
            } else {
                $identifiantsVus[$ligne['identifiant_national']] = true;
            }

            // — Format —
            if ($nom === '') {
                $erreurs[] = "{$etiquette} : nom officiel absent.";
            }

            // — Cohérence hiérarchique. L'anomalie la plus sournoise : les deux références sont
            //   valides prises séparément, et c'est leur COMBINAISON qui est fausse. Une
            //   statistique par région la propagerait sans que rien ne signale l'erreur.
            if ($ligne['district_code'] !== null && $ligne['region_code'] !== null
                && $ligne['region_du_district'] !== $ligne['region_code']) {
                $erreurs[] = "{$etiquette} : le district {$ligne['district_code']} n'appartient pas "
                    ."à la région déclarée {$ligne['region_code']}.";
            }

            if ($ligne['district_code'] !== null && $ligne['region_code'] === null) {
                $erreurs[] = "{$etiquette} : district renseigné sans région.";
            }

            // — Complétude exigée par §4.2 pour l'usage « planification sanitaire » (§4.4) —
            if ($ligne['statut_juridique'] === null) {
                $erreurs[] = "{$etiquette} : statut juridique absent — on ne peut pas dire si "
                    .'l\'établissement est public ou privé.';
            }

            if ($ligne['niveau_soins'] === null
                && in_array($ligne['categorie'], self::CATEGORIES_HOSPITALIERES, true)) {
                $erreurs[] = "{$etiquette} : niveau de soins absent alors que la catégorie "
                    ."« {$ligne['categorie']} » est hospitalière.";
            }

            // — Valeurs aberrantes (§10) —
            if ($ligne['nombre_lits'] !== null && $ligne['capacite_accueil'] !== null
                && $ligne['nombre_lits'] > $ligne['capacite_accueil']) {
                $erreurs[] = "{$etiquette} : {$ligne['nombre_lits']} lits déclarés pour une "
                    ."capacité d'accueil de {$ligne['capacite_accueil']}.";
            }

            // — Doublons métier : deux établissements de même nom dans un même district.
            //   Ce n'est pas nécessairement une faute (deux pharmacies homonymes existent), d'où
            //   un signalement qui nomme les deux plutôt qu'un refus muet.
            if ($nom !== '' && $ligne['district_code'] !== null) {
                $cle = mb_strtolower($nom).'@'.$ligne['district_code'];
                if (isset($nomsParDistrict[$cle])) {
                    $erreurs[] = "Doublon probable : « {$nom} » apparaît deux fois dans le district "
                        ."{$ligne['district_code']} ({$nomsParDistrict[$cle]} et {$etiquette}).";
                }
                $nomsParDistrict[$cle] = $etiquette;
            }
        }

        return $erreurs;
    }
}
