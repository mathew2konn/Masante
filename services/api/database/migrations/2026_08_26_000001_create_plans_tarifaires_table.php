<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Facturation partenaire — catalogue des plans tarifaires (lot A, migration 1/8).
 *
 * MaSanté facture l'établissement. Un plan décrit ce qui lui est vendu ; l'abonnement décrit ce
 * qu'un établissement donné a souscrit. Les deux sont séparés pour que corriger un tarif ne
 * réécrive jamais ce qu'un partenaire a signé.
 *
 * CONVENTIONS DU LOT, tenues dans les huit migrations :
 *  · le franc CFA n'a pas de sous-unité → tout montant est un ENTIER de francs. Jamais de
 *    `decimal`, jamais de `float` : un arrondi sur de l'argent est une erreur qui ne se voit pas ;
 *  · les états sont des `string` + enum PHP applicatif, jamais un ENUM SQL — un ENUM natif se migre
 *    mal et fige le vocabulaire dans le moteur ;
 *  · aucun `softDeletes` : une ligne financière ne se supprime pas, elle change de statut.
 *
 * `date_effet` / `date_fin` : un tarif ne se modifie pas en place. On ferme la ligne courante et on
 * en insère une nouvelle, sinon une facture passée deviendrait irreproductible.
 *
 * ⚠️ DÉFAUT RÉEL TROUVÉ À L'EXÉCUTION DU SEEDER, CORRIGÉ ICI (26/08/2026, option (a) retenue par
 * le propriétaire). `code` seul N'EST PAS UNIQUE EN DONNÉES : `P1_GESTION` désigne UN SEUL plan
 * commercial vendu en quatre variantes tarifaires, une par `categorie_structure` — c'est ce que
 * les quatre libellés disent déjà (« Gestion — cabinet… », « Gestion — clinique… »). Une
 * contrainte `unique(code)` seule aurait rejeté la deuxième variante dès l'insertion, avant même
 * d'atteindre la clause de recherche d'`updateOrCreate` : `--pretend` ne l'aurait jamais montré,
 * seule l'exécution réelle du seeder l'a révélé. L'unicité porte donc sur le COUPLE
 * `(code, categorie_structure)` : deux variantes du même code coexistent, mais jamais deux fois
 * la même variante.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans_tarifaires', function (Blueprint $table) {
            $table->id();

            // P0_VISIBILITE | P1_GESTION | P2_INTEGRATION | P1_FORFAIT_0 — PAS unique seul, voir
            // en-tête : un même code porte plusieurs variantes tarifaires par catégorie.
            $table->string('code');
            $table->string('libelle');

            // CABINET | CLINIQUE | HOPITAL | PHARMACIE. Nullable : P0 s'applique à toutes.
            $table->string('categorie_structure')->nullable();

            $table->unsignedBigInteger('montant_mensuel')->default(0);   // FCFA entiers
            $table->string('devise', 3)->default('XOF');

            // Forfait « 0 % » (A1e) : la commission est incluse dans l'abonnement, donc aucune
            // ligne de commission ne doit être produite pour ce plan.
            $table->boolean('commission_incluse')->default(false);

            $table->boolean('actif')->default(true);
            $table->date('date_effet');
            $table->date('date_fin')->nullable();

            $table->timestamps();

            // Unicité sur le COUPLE, pas sur `code` seul : voir l'avertissement en en-tête.
            $table->unique(['code', 'categorie_structure'], 'uq_plan_tarifaire_code_categorie');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans_tarifaires');
    }
};
