<?php

namespace Database\Seeders;

use App\Models\DisponibiliteJour;
use App\Models\Medecin;
use App\Models\PharmacieGarde;
use App\Models\ServiceEtablissement;
use App\Models\StructureSanitaire;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Module 3 / étape 3A.1 — Structures sanitaires réelles d'Abidjan (annuaire géolocalisé).
 *
 * Coordonnées GPS approximatives mais plausibles. Chaque structure porte des services (avec
 * leur code de spécialité pour le matching triage) et une disponibilité du jour (pastille
 * carte). Quelques pharmacies sont inscrites de garde aujourd'hui (F3.8).
 *
 * En production, ces données seront alimentées par le portail admin (Module 4) ; ici elles
 * sont seedées pour faire vivre la carte et les filtres dès le Module 3.
 */
class StructureSanitaireSeeder extends Seeder
{
    /** Spécialités SANS praticien réservable (F3.5) : on ne prend pas de RDV « médecin » en officine/labo. */
    private const SPECIALITES_SANS_MEDECIN = ['pharmacie', 'biologie'];

    /** Pools de noms ivoiriens pour générer des praticiens plausibles (données de démo). */
    private const NOMS = ['Koffi', 'Kouassi', 'Kouamé', 'Yao', 'Aka', 'Bamba', 'Traoré', 'Coulibaly',
        'Diomandé', 'N\'Guessan', 'Konan', 'Touré', 'Ouattara', 'Gnahoré', 'Assé'];
    private const PRENOMS = ['Kablan', 'Serge', 'Aya', 'Mariam', 'Aristide', 'Chantal', 'Franck',
        'Nadège', 'Hervé', 'Estelle', 'Ismaël', 'Rose', 'Marc', 'Adjoua'];

    /** Curseur global pour faire varier les noms entre praticiens. */
    private int $curseur = 0;

    public function run(): void
    {
        $aujourdhui = Carbon::today();

        // Horaires types réutilisés.
        $horairesHopital = ['lun_ven' => '24h/24', 'weekend' => '24h/24'];
        $horairesCabinet = ['lun_ven' => '08:00-18:00', 'samedi' => '08:00-13:00', 'dimanche' => 'Fermé'];
        $horairesPharma = ['lun_ven' => '08:00-20:00', 'samedi' => '08:00-20:00', 'dimanche' => 'Garde'];

        // [structure, services => [[nom_service, specialite, statut_du_jour], ...], garde => 'jour'|'nuit'|'jour_nuit'|null]
        $donnees = [
            [
                'structure' => [
                    'nom' => 'CHU de Cocody', 'type' => 'chu', 'commune' => 'Cocody',
                    'adresse' => 'Boulevard de l\'Université, Cocody, Abidjan',
                    'latitude' => 5.34760000, 'longitude' => -3.98690000,
                    'telephone' => '+2252722481000', 'whatsapp' => null,
                    'horaires_json' => $horairesHopital, 'tarif_min_cfa' => 5000, 'tarif_max_cfa' => 30000,
                    'partenaire_ivoirsante' => true,
                ],
                'services' => [
                    ['Urgences', 'urgences', 'disponible'],
                    ['Médecine générale', 'medecine_generale', 'disponible'],
                    ['Cardiologie', 'cardiologie', 'disponible_apres_14h'],
                    ['ORL', 'orl', 'complet'],
                ],
            ],
            [
                'structure' => [
                    'nom' => 'CHU de Treichville', 'type' => 'chu', 'commune' => 'Treichville',
                    'adresse' => 'Boulevard de Marseille, Treichville, Abidjan',
                    'latitude' => 5.29200000, 'longitude' => -4.00800000,
                    'telephone' => '+2252724412300', 'whatsapp' => null,
                    'horaires_json' => $horairesHopital, 'tarif_min_cfa' => 5000, 'tarif_max_cfa' => 30000,
                    'partenaire_ivoirsante' => true,
                ],
                'services' => [
                    ['Urgences', 'urgences', 'disponible'],
                    ['Médecine générale', 'medecine_generale', 'complet'],
                    ['Pédiatrie', 'pediatrie', 'disponible'],
                    ['Gastro-entérologie', 'gastro_enterologie', 'disponible_apres_14h'],
                ],
            ],
            [
                'structure' => [
                    'nom' => 'CHU de Yopougon', 'type' => 'chu', 'commune' => 'Yopougon',
                    'adresse' => 'Yopougon, Abidjan',
                    'latitude' => 5.33600000, 'longitude' => -4.08760000,
                    'telephone' => '+2252723460000', 'whatsapp' => null,
                    'horaires_json' => $horairesHopital, 'tarif_min_cfa' => 5000, 'tarif_max_cfa' => 30000,
                    'partenaire_ivoirsante' => false,
                ],
                'services' => [
                    ['Urgences', 'urgences', 'disponible'],
                    ['Médecine générale', 'medecine_generale', 'disponible'],
                    ['Maternité', 'gynecologie', 'complet'],
                ],
            ],
            [
                'structure' => [
                    'nom' => 'Polyclinique Internationale Sainte Anne-Marie (PISAM)', 'type' => 'clinique_privee', 'commune' => 'Cocody',
                    'adresse' => 'Boulevard de la Corniche, Cocody, Abidjan',
                    'latitude' => 5.33800000, 'longitude' => -3.99300000,
                    'telephone' => '+2252722482000', 'whatsapp' => '+2250707070707',
                    'horaires_json' => $horairesHopital, 'tarif_min_cfa' => 15000, 'tarif_max_cfa' => 80000,
                    'partenaire_ivoirsante' => true,
                ],
                'services' => [
                    ['Cardiologie', 'cardiologie', 'disponible'],
                    ['ORL', 'orl', 'disponible'],
                    ['Dentisterie', 'dentisterie', 'disponible_apres_14h'],
                    ['Médecine générale', 'medecine_generale', 'disponible'],
                ],
            ],
            [
                'structure' => [
                    'nom' => 'Hôpital Général d\'Abobo', 'type' => 'chr', 'commune' => 'Abobo',
                    'adresse' => 'Abobo, Abidjan',
                    'latitude' => 5.41800000, 'longitude' => -4.01600000,
                    'telephone' => '+2252720000000', 'whatsapp' => null,
                    'horaires_json' => $horairesHopital, 'tarif_min_cfa' => 3000, 'tarif_max_cfa' => 20000,
                    'partenaire_ivoirsante' => false,
                ],
                'services' => [
                    ['Urgences', 'urgences', 'disponible_apres_14h'],
                    ['Médecine générale', 'medecine_generale', 'disponible'],
                    ['Pédiatrie', 'pediatrie', 'ferme'],
                ],
            ],
            [
                'structure' => [
                    'nom' => 'Clinique Farah', 'type' => 'clinique_privee', 'commune' => 'Marcory',
                    'adresse' => 'Zone 4, Marcory, Abidjan',
                    'latitude' => 5.29600000, 'longitude' => -3.98700000,
                    'telephone' => '+2252721260000', 'whatsapp' => '+2250505050505',
                    'horaires_json' => $horairesCabinet, 'tarif_min_cfa' => 10000, 'tarif_max_cfa' => 50000,
                    'partenaire_ivoirsante' => true,
                ],
                'services' => [
                    ['Médecine générale', 'medecine_generale', 'disponible'],
                    ['Gynécologie', 'gynecologie', 'disponible'],
                ],
            ],
            [
                'structure' => [
                    'nom' => 'Cabinet Dentaire du Plateau', 'type' => 'cabinet', 'commune' => 'Plateau',
                    'adresse' => 'Avenue Chardy, Plateau, Abidjan',
                    'latitude' => 5.32000000, 'longitude' => -4.02200000,
                    'telephone' => '+2252720212223', 'whatsapp' => null,
                    'horaires_json' => $horairesCabinet, 'tarif_min_cfa' => 8000, 'tarif_max_cfa' => 40000,
                    'partenaire_ivoirsante' => false,
                ],
                'services' => [
                    ['Dentisterie', 'dentisterie', 'disponible'],
                ],
            ],
            [
                'structure' => [
                    'nom' => 'Laboratoire BIOSMOSE', 'type' => 'laboratoire', 'commune' => 'Marcory',
                    'adresse' => 'Zone 4C, Marcory, Abidjan',
                    'latitude' => 5.30000000, 'longitude' => -3.99000000,
                    'telephone' => '+2252721350000', 'whatsapp' => null,
                    'horaires_json' => $horairesCabinet, 'tarif_min_cfa' => 5000, 'tarif_max_cfa' => 60000,
                    'partenaire_ivoirsante' => false,
                ],
                'services' => [
                    ['Analyses biologiques', 'biologie', 'disponible'],
                ],
            ],
            [
                'structure' => [
                    'nom' => 'Pharmacie de la Paix', 'type' => 'pharmacie', 'commune' => 'Cocody',
                    'adresse' => 'Rue des Jardins, Cocody, Abidjan',
                    'latitude' => 5.34900000, 'longitude' => -3.99000000,
                    'telephone' => '+2252722440000', 'whatsapp' => '+2250101010101',
                    'horaires_json' => $horairesPharma, 'tarif_min_cfa' => null, 'tarif_max_cfa' => null,
                    'partenaire_ivoirsante' => true,
                ],
                'services' => [['Officine', 'pharmacie', 'disponible']],
                'garde' => 'jour_nuit',
            ],
            [
                'structure' => [
                    'nom' => 'Pharmacie Saint Jean', 'type' => 'pharmacie', 'commune' => 'Cocody',
                    'adresse' => 'Saint Jean, Cocody, Abidjan',
                    'latitude' => 5.34500000, 'longitude' => -3.99900000,
                    'telephone' => '+2252722451111', 'whatsapp' => null,
                    'horaires_json' => $horairesPharma, 'tarif_min_cfa' => null, 'tarif_max_cfa' => null,
                    'partenaire_ivoirsante' => false,
                ],
                'services' => [['Officine', 'pharmacie', 'disponible']],
            ],
            [
                'structure' => [
                    'nom' => 'Pharmacie Williamsville', 'type' => 'pharmacie', 'commune' => 'Adjamé',
                    'adresse' => 'Williamsville, Adjamé, Abidjan',
                    'latitude' => 5.36400000, 'longitude' => -4.02200000,
                    'telephone' => '+2252720333333', 'whatsapp' => null,
                    'horaires_json' => $horairesPharma, 'tarif_min_cfa' => null, 'tarif_max_cfa' => null,
                    'partenaire_ivoirsante' => false,
                ],
                'services' => [['Officine', 'pharmacie', 'disponible']],
                'garde' => 'nuit',
            ],
        ];

        foreach ($donnees as $bloc) {
            $attrs = $bloc['structure'];
            // Spécialités affichées = libellés des services.
            $attrs['specialites_json'] = array_map(fn ($s) => $s[0], $bloc['services']);

            // WhatsApp présent sur TOUTES les structures. À défaut d'un numéro WhatsApp dédié,
            // on retombe sur la ligne téléphonique de l'établissement.
            if (empty($attrs['whatsapp'])) {
                $attrs['whatsapp'] = $attrs['telephone'];
            }

            $structure = StructureSanitaire::create($attrs);

            foreach ($bloc['services'] as [$nomService, $specialite, $statut]) {
                $service = ServiceEtablissement::create([
                    'structure_id' => $structure->id,
                    'nom_service' => $nomService,
                    'specialite' => $specialite,
                    'actif' => true,
                ]);

                DisponibiliteJour::create([
                    'service_id' => $service->id,
                    'date' => $aujourdhui,
                    'statut' => $statut,
                    'nb_places_restantes' => $statut === 'disponible' ? random_int(2, 12) : null,
                    'heure_debut_dispo' => $statut === 'disponible_apres_14h' ? '14:00' : null,
                ]);

                $this->seedMedecins($service, $structure);
            }

            if (! empty($bloc['garde'])) {
                PharmacieGarde::create([
                    'structure_id' => $structure->id,
                    'date' => $aujourdhui,
                    'periode' => $bloc['garde'],
                ]);
            }
        }
    }

    /**
     * Crée 1 à 2 praticiens réservables pour un service de consultation (F3.5). Les officines et
     * laboratoires n'ont pas de médecin réservable. Tarif indicatif (aucun paiement) calé sur le
     * type de structure.
     */
    private function seedMedecins(ServiceEtablissement $service, StructureSanitaire $structure): void
    {
        if (in_array($service->specialite, self::SPECIALITES_SANS_MEDECIN, true)) {
            return;
        }

        $tarifBase = match ($structure->type) {
            'clinique_privee' => 20000,
            'cabinet'         => 15000,
            'chu', 'chr'      => 5000,
            default           => 8000,
        };

        $nb = random_int(1, 2);
        for ($i = 0; $i < $nb; $i++) {
            $nom = self::NOMS[$this->curseur % count(self::NOMS)];
            $prenom = self::PRENOMS[$this->curseur % count(self::PRENOMS)];
            $this->curseur++;

            Medecin::create([
                'structure_id'       => $structure->id,
                'service_id'         => $service->id,
                'titre'              => $this->curseur % 5 === 0 ? 'Pr' : 'Dr',
                'nom'                => $nom,
                'prenom'             => $prenom,
                'specialite'         => $service->nom_service,
                'tarif_consultation' => $tarifBase + random_int(0, 4) * 1000,
                'actif'              => true,
            ]);
        }
    }
}
