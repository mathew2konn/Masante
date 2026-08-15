<?php

namespace Database\Seeders;

use App\Models\OrganismeAssurance;
use Illuminate\Database\Seeder;

/**
 * P6.8d — Jeu de DÉMONSTRATION du registre des organismes d'assurance (CDC_09 §8).
 *
 * ═══ CE JEU NE NOMME AUCUN ASSUREUR PRIVÉ RÉEL, ET C'EST DÉLIBÉRÉ ═══
 *
 * Écrire « NSIA » ou le nom d'une mutuelle réelle dans un registre intitulé « organismes agréés »
 * affirmerait un **agrément que personne n'a vu** — et *une liste inventée qui a l'air juste ne se
 * fait jamais corriger* (raisonnement de P6.4a sur les 33 régions sanitaires). La différence avec
 * P6.8c est nette : là-bas les libellés de maladies étaient ADOPTÉS de l'existant du projet, ici il
 * n'y a rien à adopter, et le sujet n'est pas une observation clinique mais un acte administratif
 * nominatif.
 *
 * **La CNAM fait exception parce que le corpus la nomme** (CDC_06 §8.1 : « CNAM, Caisse Nationale
 * d'Assurance Maladie »), et parce que c'est elle que les trois colonnes `cmu_*` désignaient déjà
 * sans le dire. Sa provenance reste `demonstration` : le corpus est un cahier des charges, pas un
 * arrêté.
 *
 * Les cinq autres portent un nom **explicitement fictif**, un par famille du §8.2. Ils ne sont pas
 * décoratifs : ils prouvent que le registre sait porter les six familles, et ils donnent au vecteur
 * de test un organisme non-CNAM à rattacher.
 *
 * ═══ AUCUN NUMÉRO D'AGRÉMENT, AUCUN ÉTAT D'AGRÉMENT ═══
 *
 * Troisième application du motif `analyses.loinc` (P6.7a) puis `code_cim10` (P6.8c) : les colonnes
 * existent, elles restent vides, et l'écran en affiche le compte. Écrire `agrement_statut = valide`
 * serait pire qu'une colonne vide — ce serait **une affirmation d'autorité fabriquée**, exactement
 * ce qu'un guichet lirait pour décider d'accorder un tiers payant.
 *
 * ═══ CE SEEDER NE PUBLIE RIEN ═══
 *
 * Il alimente la table ; la mise en vigueur passe par le cycle §10 (proposition, quatre-yeux,
 * publication). Publier depuis un seeder contournerait la gouvernance dès le premier jour — décision
 * de P6.3, appliquée sans exception depuis.
 *
 * IDEMPOTENT : rejouable sans créer de doublon ni écraser une correction faite depuis.
 */
class OrganismeAssuranceSeeder extends Seeder
{
    private const SOURCE_DETAIL = 'Jeu de démonstration. AUCUN agrément n\'a été vérifié, aucun '
        .'numéro d\'agrément n\'a été chargé, et aucun assureur privé réel n\'est nommé.';

    /** @var array<int, array{nom: string, sigle: string|null, type: string}> */
    private const CATALOGUE = [
        // Le seul organisme réel du jeu — nommé par le CDC_06 §8.1, et déjà désigné en creux par les
        // colonnes `cmu_*` de `membres_famille` depuis le Module 2.
        [
            'nom'   => 'Caisse Nationale d\'Assurance Maladie',
            'sigle' => 'CNAM',
            'type'  => 'cnam',
        ],
        // Les cinq familles restantes du §8.2, sous des noms explicitement fictifs.
        [
            'nom'   => 'Assurance Santé de Démonstration',
            'sigle' => 'ASD',
            'type'  => 'assurance',
        ],
        [
            'nom'   => 'Mutuelle de Démonstration',
            'sigle' => 'MUDEMO',
            'type'  => 'mutuelle',
        ],
        [
            'nom'   => 'Couverture d\'Entreprise de Démonstration',
            'sigle' => 'CED',
            'type'  => 'entreprise',
        ],
        [
            'nom'   => 'ONG de Démonstration',
            'sigle' => 'ONGD',
            'type'  => 'ong',
        ],
        [
            'nom'   => 'Programme Gouvernemental de Démonstration',
            'sigle' => 'PGD',
            'type'  => 'programme_gouvernemental',
        ],
    ];

    public function run(): void
    {
        $pays = (string) config('referentiels.pays_defaut', 'CI');

        foreach (self::CATALOGUE as $entree) {
            OrganismeAssurance::firstOrCreate(
                ['pays_code' => $pays, 'nom' => $entree['nom']],
                [
                    'sigle'         => $entree['sigle'],
                    'type'          => $entree['type'],
                    'source'        => 'demonstration',
                    'source_detail' => self::SOURCE_DETAIL,
                    'actif'         => true,
                    // `agrement_statut`, `agrement_debut`, `agrement_fin` et `numero_agrement`
                    // restent NULS : l'absence se dit, elle ne s'invente pas.
                ],
            );
        }

        $this->command?->info(count(self::CATALOGUE).' organisme(s) d\'assurance au contenu de '
            .'travail. Lancez `masante:assurances:backfill` pour attribuer les codes nationaux, '
            .'puis publiez une version (§10) — rien n\'est diffusé avant.');
    }
}
