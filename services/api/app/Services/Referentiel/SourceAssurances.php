<?php

namespace App\Services\Referentiel;

use App\Models\OrganismeAssurance;
use App\Support\TypesOrganismeAssurance;

/**
 * Référentiel national des organismes d'assurance agréés (CDC_09 §8), sous gouvernance §10.
 *
 * ═══ LA PROJECTION PREND LA LIGNE ENTIÈRE — question reposée pour la cinquième fois ═══
 *
 * P6.4a excluait la note d'avis parce qu'elle est RECALCULÉE à chaque avis déposé : l'inclure aurait
 * fait diverger l'instantané en permanence, et l'anti-substitution aurait refusé toute publication.
 * La question est reposée ici, comme en P6.6a, P6.8a, P6.8b et P6.8c, et la vérification donne la
 * même réponse : **rien n'écrit automatiquement dans `organismes_assurance`**. Les couvertures
 * citoyennes vivent dans `couvertures_membre`, comme les prix relevés vivent dans `prix_pharmacie`.
 *
 * Ce n'est pas un constat passif : la table a été construite pour que cette phrase reste vraie. Elle
 * ne porte **aucun compteur d'assurés** — il serait utile à l'écran, il rendrait la réponse fausse —
 * ni téléphone, ni adresse, qui feraient d'un changement de standard un acte soumis au quatre-yeux.
 *
 * DEUX VECTEURS EN MIROIR, aucun ne suffit seul : un citoyen déclare une couverture → l'empreinte NE
 * CHANGE PAS ; le statut d'agrément d'un organisme passe à `suspendu` → elle CHANGE.
 *
 * ═══ CE QUE LA GOUVERNANCE PROTÈGE ICI ═══
 *
 * Retirer l'agrément d'un organisme, ou le renommer, change le sens de **toutes les couvertures déjà
 * rattachées** et de ce qu'un agent d'accueil lit à un guichet. C'est un acte d'autorité, pas une
 * correction de saisie — d'où le quatre-yeux du §10.
 *
 * ═══ CE QUE LES CONTRÔLES N'EXIGENT PAS, ET POURQUOI ═══
 *
 * **Aucun numéro d'agrément n'est exigé.** L'exiger rendrait le référentiel impubliable dès le
 * premier jour, puisque ces numéros sont délivrés par une autorité et que ce projet n'en a chargé
 * aucun. L'absence est COMPTÉE et AFFICHÉE ({@see App\Http\Controllers\Portail\ReferentielAssuranceController}),
 * jamais transformée en blocage — troisième application du motif `analyses.loinc` (P6.7a) après
 * `code_cim10` (P6.8c). *Un contrôle qu'on ne peut pas satisfaire n'est pas une exigence, c'est un
 * mur.*
 */
final class SourceAssurances implements SourceReferentiel
{
    /** Les provenances admises — miroir de l'ENUM `source`. */
    private const SOURCES = ['demonstration', 'autorite_nationale', 'publication'];

    /** Les états d'agrément admis — miroir de l'ENUM `agrement_statut`. */
    private const STATUTS = ['valide', 'suspendu', 'retire'];

    public const CODE = 'assurances';

    public function code(): string
    {
        return self::CODE;
    }

    public function libelle(): string
    {
        return 'Organismes d\'assurance agréés (CNAM et assureurs privés)';
    }

    /**
     * §10 « chaque référentiel a un responsable désigné ». Le ministère — pour la raison même qui a
     * fait naître la permission `assurance.referentiel` : un agrément est délivré par un État, et le
     * rôle `assurance` désigne précisément les organismes que ce registre recense.
     */
    public function roleResponsable(): string
    {
        return 'ministere';
    }

    public function extraire(): array
    {
        return OrganismeAssurance::query()
            // Ordre stable et TOTAL, reproductible d'une base à l'autre.
            ->orderBy('pays_code')
            ->orderBy('code')
            ->orderBy('id')
            ->get()
            ->map(fn (OrganismeAssurance $o): array => [
                'code'            => $o->code,
                'pays_code'       => $o->pays_code,
                'nom'             => $o->nom,
                'sigle'           => $o->sigle,
                'type'            => $o->type,
                // Vide, et c'est dit : aucun numéro d'agrément n'a été chargé dans ce projet.
                'numero_agrement' => $o->numero_agrement,
                'agrement_statut' => $o->agrement_statut,
                'agrement_debut'  => $o->agrement_debut?->toDateString(),
                'agrement_fin'    => $o->agrement_fin?->toDateString(),
                'source'          => $o->source,
                'source_detail'   => $o->source_detail,
                'actif'           => (bool) $o->actif,
            ])
            ->all();
    }

    /**
     * Contrôles qualité §10 — bloquants à la publication.
     *
     * Ce qu'ils garantissent : **un référentiel publié ne peut pas contenir un organisme sans
     * provenance, deux organismes indiscernables dans la liste où un citoyen choisit le sien, un
     * agrément qui finit avant de commencer, ni un état d'agrément hors nomenclature.**
     */
    public function controlerQualite(array $contenu): array
    {
        $erreurs = [];

        if ($contenu === []) {
            return ['Le référentiel est vide : aucun organisme d\'assurance à publier.'];
        }

        $codesVus = [];
        $nomsVus  = [];
        $actifs   = 0;

        foreach ($contenu as $ligne) {
            $code      = (string) ($ligne['code'] ?? '');
            $pays      = strtoupper((string) ($ligne['pays_code'] ?? ''));
            $nom       = trim((string) ($ligne['nom'] ?? ''));
            $etiquette = $code !== '' ? $code : '« '.($nom !== '' ? $nom : 'sans nom').' »';

            if ($code === '') {
                $erreurs[] = "{$etiquette} : code national absent — la commande "
                    .'`masante:assurances:backfill` en attribue un.';
            } elseif (isset($codesVus[$pays.'-'.$code])) {
                // LA CLÉ PORTE LE PAYS, et le contrôle est aussi strict que `uq_organisme_code_pays`,
                // ni plus ni moins. Le G2 de P6.5a a montré ce que coûte un contrôle plus strict que
                // le moteur — un référentiel impubliable dès le deuxième pays ; l'inverse laisserait
                // passer ce que la base refusera.
                $erreurs[] = "Doublon : le code « {$code} » apparaît plusieurs fois pour {$pays}.";
            } else {
                $codesVus[$pays.'-'.$code] = true;
            }

            if ($nom === '') {
                $erreurs[] = "{$etiquette} : nom absent — c'est lui que l'assuré lit et choisit.";
            } else {
                $cle = $pays.'-'.mb_strtolower($nom);

                if (isset($nomsVus[$cle])) {
                    $erreurs[] = "Doublon : le nom « {$nom} » est porté par plusieurs organismes de "
                        ."{$pays} — ils seraient indiscernables dans la liste où un assuré choisit "
                        .'le sien, et ce choix porte sur ce qu\'il lit.';
                }
                $nomsVus[$cle] = true;
            }

            if (! in_array($ligne['type'] ?? null, TypesOrganismeAssurance::codes(), true)) {
                $erreurs[] = "{$etiquette} : famille de tiers payant absente ou inconnue — le §8.2 du "
                    .'CDC_06 en nomme six, et le service de paiement ne sait traiter que celles-là.';
            }

            // LA PROVENANCE, garde centrale du module (5ᵉ application après P6.7a, P6.8b et P6.8c).
            // La colonne est NOT NULL en base ; ce contrôle attrape ce que la base ne peut pas voir —
            // une valeur hors nomenclature arrivée par un import.
            if (! in_array($ligne['source'] ?? null, self::SOURCES, true)) {
                $erreurs[] = "{$etiquette} : provenance absente ou inconnue — une entrée de "
                    .'référentiel sans source ne peut pas être publiée.';
            }

            $statut = $ligne['agrement_statut'] ?? null;

            // NULL est LÉGITIME : l'absence d'agrément renseigné se dit, elle ne s'invente pas. Ce
            // qui ne l'est pas, c'est une valeur hors nomenclature — l'écran ne saurait pas la dire.
            if ($statut !== null && ! in_array($statut, self::STATUTS, true)) {
                $erreurs[] = "{$etiquette} : état d'agrément inconnu (« {$statut} »).";
            }

            // Le déclencheur de la migration refuse déjà d'ÉCRIRE ces dates. Ce contrôle attrape le
            // même défaut arrivé par un autre chemin — un import, une base restaurée d'ailleurs.
            // Deux gardes, deux chemins, aucune ne rattrape l'autre.
            $debut = $ligne['agrement_debut'] ?? null;
            $fin   = $ligne['agrement_fin'] ?? null;

            if ($debut !== null && $fin !== null && $debut > $fin) {
                $erreurs[] = "{$etiquette} : l'agrément se termine ({$fin}) avant de commencer "
                    ."({$debut}).";
            }

            if ($ligne['actif'] ?? false) {
                $actifs++;
            }
        }

        if ($actifs === 0) {
            $erreurs[] = 'Aucun organisme actif : le référentiel publié ne permettrait de rattacher '
                .'aucune couverture, et tous les assurés se retrouveraient hors référentiel.';
        }

        return $erreurs;
    }
}
