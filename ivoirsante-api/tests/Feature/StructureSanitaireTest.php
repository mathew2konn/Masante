<?php

namespace Tests\Feature;

use App\Models\DisponibiliteJour;
use App\Models\PharmacieGarde;
use App\Models\ServiceEtablissement;
use App\Models\StructureSanitaire;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Étape 3A.1 — Annuaire géolocalisé des structures sanitaires (F3.1→F3.5, F3.8).
 *
 * On vérifie : accès public sans auth, filtres (type/spécialité/disponibilité), proximité
 * (distance + tri + rayon, calculée en PHP donc portable), fiche détaillée et pharmacies de garde.
 */
class StructureSanitaireTest extends TestCase
{
    use RefreshDatabase;

    /** Crée une structure + un service + sa disponibilité du jour. */
    private function structure(array $attrs, string $specialite = 'medecine_generale', string $statut = 'disponible'): StructureSanitaire
    {
        $structure = StructureSanitaire::create(array_merge([
            'nom' => 'Structure', 'type' => 'chu', 'adresse' => 'Abidjan', 'commune' => 'Cocody',
            'latitude' => 5.3500, 'longitude' => -3.9900,
        ], $attrs));

        $service = ServiceEtablissement::create([
            'structure_id' => $structure->id,
            'nom_service' => 'Service', 'specialite' => $specialite, 'actif' => true,
        ]);

        DisponibiliteJour::create([
            'service_id' => $service->id, 'date' => Carbon::today(), 'statut' => $statut,
        ]);

        return $structure;
    }

    public function test_liste_publique_accessible_sans_authentification(): void
    {
        $this->structure(['nom' => 'CHU de Cocody']);

        $this->getJson('/api/v1/structures')
            ->assertOk()
            ->assertJsonPath('structures.0.nom', 'CHU de Cocody')
            ->assertJsonPath('structures.0.statut_jour', 'disponible');
    }

    public function test_filtre_par_type_et_par_specialite(): void
    {
        $this->structure(['nom' => 'CHU', 'type' => 'chu'], 'cardiologie');
        $this->structure(['nom' => 'Pharmacie Centrale', 'type' => 'pharmacie'], 'pharmacie');

        $this->getJson('/api/v1/structures?type=pharmacie')
            ->assertOk()
            ->assertJsonCount(1, 'structures')
            ->assertJsonPath('structures.0.nom', 'Pharmacie Centrale');

        $this->getJson('/api/v1/structures?specialite=cardiologie')
            ->assertOk()
            ->assertJsonCount(1, 'structures')
            ->assertJsonPath('structures.0.nom', 'CHU');
    }

    public function test_filtre_par_disponibilite_du_jour(): void
    {
        $this->structure(['nom' => 'Dispo'], 'medecine_generale', 'disponible');
        $this->structure(['nom' => 'Complet'], 'medecine_generale', 'complet');

        $this->getJson('/api/v1/structures?statut=complet')
            ->assertOk()
            ->assertJsonCount(1, 'structures')
            ->assertJsonPath('structures.0.nom', 'Complet');
    }

    public function test_proximite_calcule_la_distance_trie_et_filtre_par_rayon(): void
    {
        // Loin (Yopougon) et proche (Cocody) d'un point à Cocody.
        $this->structure(['nom' => 'Proche', 'latitude' => 5.3500, 'longitude' => -3.9900]);
        $this->structure(['nom' => 'Loin', 'latitude' => 5.3360, 'longitude' => -4.0876]);

        $reponse = $this->getJson('/api/v1/structures?lat=5.3500&lng=-3.9900')
            ->assertOk()
            ->assertJsonPath('structures.0.nom', 'Proche'); // le plus proche d'abord

        $this->assertArrayHasKey('distance_km', $reponse->json('structures.0'));

        // Rayon serré : seul « Proche » subsiste.
        $this->getJson('/api/v1/structures?lat=5.3500&lng=-3.9900&rayon_km=2')
            ->assertOk()
            ->assertJsonCount(1, 'structures')
            ->assertJsonPath('structures.0.nom', 'Proche');
    }

    public function test_fiche_structure_expose_services_et_statut(): void
    {
        $structure = $this->structure(['nom' => 'PISAM'], 'orl', 'disponible_apres_14h');

        $this->getJson("/api/v1/structures/{$structure->id}")
            ->assertOk()
            ->assertJsonPath('structure.nom', 'PISAM')
            ->assertJsonPath('structure.statut_jour', 'disponible_apres_14h')
            ->assertJsonPath('structure.services.0.specialite', 'orl');
    }

    public function test_pharmacies_de_garde_ne_retourne_que_celles_du_jour(): void
    {
        $deGarde = $this->structure(['nom' => 'Pharmacie de Garde', 'type' => 'pharmacie'], 'pharmacie');
        $horsGarde = $this->structure(['nom' => 'Pharmacie Fermée', 'type' => 'pharmacie'], 'pharmacie');

        PharmacieGarde::create(['structure_id' => $deGarde->id, 'date' => Carbon::today(), 'periode' => 'jour_nuit']);
        PharmacieGarde::create(['structure_id' => $horsGarde->id, 'date' => Carbon::yesterday(), 'periode' => 'nuit']);

        $this->getJson('/api/v1/pharmacies-garde')
            ->assertOk()
            ->assertJsonCount(1, 'pharmacies')
            ->assertJsonPath('pharmacies.0.nom', 'Pharmacie de Garde');
    }
}
