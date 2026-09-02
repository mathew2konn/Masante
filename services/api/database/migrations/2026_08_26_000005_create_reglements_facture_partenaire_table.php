<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Facturation partenaire — encaissements reçus d'un établissement (lot A, migration 5/8).
 *
 * POURQUOI UNE TABLE ET PAS UN COMPTEUR. Enregistrer un encaissement en incrémentant
 * `factures_partenaire.montant_regle` ferait disparaître la date, le moyen et la référence de
 * transaction — c'est-à-dire tout ce qu'on produit le jour où un partenaire conteste. Un règlement
 * est un FAIT DATÉ : il s'écrit une fois et ne bouge plus.
 *
 * IMMUABILITÉ TOTALE, sans exception d'état. Le modèle refuse `updating` ET `deleting`, quel que
 * soit le statut de la facture. Une erreur de saisie ne se rattrape pas en corrigeant la ligne
 * fautive, mais en écrivant une ligne de sens contraire — mécanisme à spécifier avec le service
 * d'imputation, hors de ce lot.
 *
 * `moyen` : WAVE | ORANGE_MONEY | MTN_MONEY | MOOV_MONEY | VIREMENT | ESPECES | AUTRE. Le partenaire
 * règle MaSanté par le canal qu'il veut, y compris en espèces — ce n'est pas le flux patient, il ne
 * passe pas par la passerelle.
 *
 * Le partenaire ne désigne JAMAIS la facture qu'il règle : l'imputation se fait sur la plus
 * ancienne impayée (voir docs/REGLES_RECOUVREMENT_PARTENAIRE.md). La clé étrangère porte donc le
 * résultat de l'imputation, pas un choix du payeur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reglements_facture_partenaire', function (Blueprint $table) {
            $table->id();

            $table->foreignId('facture_partenaire_id')->constrained('factures_partenaire')->restrictOnDelete();

            $table->unsignedBigInteger('montant');            // FCFA entiers
            $table->string('moyen');                          // voir en-tête
            $table->string('reference_externe')->nullable();  // référence de transaction du moyen utilisé

            $table->timestamp('date_reglement');
            $table->string('commentaire')->nullable();

            $table->timestamps();

            // Nom EXPLICITE : le nom auto-généré par Laravel ferait 72 caractères, or MySQL plafonne
            // les identifiants à 64 (erreur 1059). Constaté en exécutant `migrate` — `--pretend`
            // n'exécute rien et ne l'aurait jamais montré.
            $table->index(['facture_partenaire_id', 'date_reglement'], 'idx_reglement_facture_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reglements_facture_partenaire');
    }
};
