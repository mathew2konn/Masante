<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B2-c — L'ORDONNANCE DÉSIGNE ENFIN SON PRESCRIPTEUR (constat Y2 du G0 de B2).
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * CE QUI MANQUAIT, ET CE QUI NE MANQUAIT PAS
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * `ordonnances.medecin_nom` est une CHAÎNE. Depuis P6.5a elle est fiable — `EcritureSoignantService`
 * la réécrit avec le nom de la fiche professionnelle du soignant connecté, le client ne peut plus
 * la déclarer. Mais **une valeur fiable n'est pas un lien** : « toutes les ordonnances du D<sup>r</sup>
 * X » et « ce prescripteur exerce-t-il encore ? » restaient insolubles.
 *
 * C'est exactement le geste que P6.7b a fait sur `resultats_analyses` en y ajoutant
 * `medecin_prescripteur_id` et `laboratoire_id`. On le refait ici, à l'identique.
 *
 * ═══ POURQUOI `medicaments_json` N'EST PAS TOUCHÉ (décision D3 du plan G1) ═══
 *
 * CDC_04 §105 prévoit `ordonnance_lignes`. Elle n'a **aucun consommateur** aujourd'hui : sa raison
 * d'être est la délivrance en pharmacie, qui n'existe pas (constat Y7). La créer maintenant serait
 * le « socle à vide » refusé par P6.3-D3. Et l'interrogeabilité ne s'obtient qu'**en cessant de
 * chiffrer** — une décision qui mérite d'être prise pour elle-même, pas au détour d'une
 * restructuration.
 *
 * ═══ CE QUI N'ENTRE PAS DANS LA SIGNATURE, ET C'EST LE POINT LE PLUS SENSIBLE ═══
 *
 * `DocumentOrdonnance::contenuCanonique()` signe « tout ce dont la modification changerait le sens
 * de la prescription » : le patient, le prescripteur **tel qu'il est écrit**, la structure, la date,
 * les médicaments en clair. Les trois colonnes ajoutées ici sont des RATTACHEMENTS — au même titre
 * que `triage_id`, que ce contenu canonique exclut déjà en le nommant « un rattachement de
 * navigation ».
 *
 * Les y ajouter **casserait toutes les signatures existantes** : chaque ordonnance signée avant ce
 * jour deviendrait « altérée » alors que personne n'y a touché — *une signature qui casse toute
 * seule ne prouve plus rien, et pire, elle accuse* (P6.5b). Le contenu canonique n'est donc PAS
 * modifié, et un vecteur dédié vérifie qu'une ordonnance signée avant B2-c reste INTÈGRE.
 *
 * ═══ AUCUNE GARDE DU MOTEUR ═══
 *
 * Les trois colonnes sont indépendantes et nullables : il n'existe aucune cohérence à faire
 * respecter entre elles. On n'invente pas un déclencheur pour la forme — « un contrôle toujours
 * vert ne prouve rien » (P5.3b-4). L'intégrité référentielle est assurée par les clés étrangères.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ordonnances', function (Blueprint $table) {
            // La fiche professionnelle du prescripteur. `nullOnDelete` : retirer un praticien du
            // référentiel ne doit pas emporter les ordonnances qu'il a signées — `medecin_nom`
            // continue alors de dire qui c'était, et c'est lui qui est signé.
            $table->foreignId('medecin_id')->nullable()->after('triage_id')
                ->constrained('medecins')->nullOnDelete();

            $table->foreignId('structure_id')->nullable()->after('medecin_id')
                ->constrained('structures_sanitaires')->nullOnDelete();

            // Le rattachement à l'acte de soin (B2-a). Même mode que `triage_id`, qui est sur cette
            // table depuis le Module 1 : une ordonnance vit dans le CARNET du patient, elle survit
            // à la consultation qui l'a produite.
            $table->foreignId('consultation_id')->nullable()->after('structure_id')
                ->constrained('consultations')->nullOnDelete();

            $table->index('medecin_id', 'idx_ordonnance_medecin');
            $table->index('consultation_id', 'idx_ordonnance_consultation');
        });
    }

    public function down(): void
    {
        Schema::table('ordonnances', function (Blueprint $table) {
            $table->dropIndex('idx_ordonnance_consultation');
            $table->dropIndex('idx_ordonnance_medecin');
            $table->dropConstrainedForeignId('consultation_id');
            $table->dropConstrainedForeignId('structure_id');
            $table->dropConstrainedForeignId('medecin_id');
        });
    }
};
