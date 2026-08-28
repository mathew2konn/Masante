<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P10c-2-i (partie A) — La consultation peut désigner le triage à laquelle elle répond
 * (CDC_05 §5.5.4 ; CDC_08 §10 ; ADR-044).
 *
 * Migration STRICTEMENT ADDITIVE : une colonne, sur une table de journal. Aucune table du carnet
 * n'est touchée — elles sont validées G5.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * LE CHAÎNON QUI MANQUAIT (constat Y6 du G0)
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * Quatre tables du carnet portent déjà `triage_id` — `ordonnances`, `rendez_vous`,
 * `documents_medicaux`, `notes_observations`. Mais il y est **déclaré par le client**, sur les
 * chemins citoyens, et il n'était **jamais posé sur le chemin du soignant** (aucune occurrence dans
 * `EcritureSoignantService` ni dans `app/Http/Controllers/Portail/`).
 *
 * Autrement dit : quand le médecin écrivait l'ordonnance qui EST l'issue du triage, rien ne la
 * reliait à ce triage. Le §5.5.4 (« enregistrement du triage réalisé, du diagnostic final posé par
 * le médecin et du traitement prescrit ») était donc irréalisable, non par manque de tables, mais
 * parce que la moitié qui compte n'était pas alimentée.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * POURQUOI LA COLONNE EST ICI, ET NON SUR LES QUATRE SECTIONS ÉCRIVABLES
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * Le soignant peut écrire dans quatre sections (`RegistreSectionsCarnet::SECTIONS_SOIGNANT`), et
 * **une seule** porte `triage_id` : `ordonnances`. Ajouter la colonne aux trois autres aurait
 * modifié trois tables du carnet validées G5, pour un lien qui se déduit déjà.
 *
 * Car `acces_dossier.donnees_ajoutees` (P7-D0) liste **déjà** tout ce qui a été écrit pendant la
 * session — `{section, id, horodatage}`. Relier la SESSION au triage relie donc, d'un seul geste,
 * l'ensemble des actes de cette consultation, quelle que soit leur section. Une colonne au lieu de
 * trois, et le lien vers les entrées existe déjà.
 *
 * `ordonnances.triage_id` n'est PAS écrit par le chemin soignant, délibérément : le lien vit à un
 * seul endroit. Deux endroits, ce serait deux vérités capables de diverger — le constat X5 de
 * P10b-3-i, et le motif de `reponses_json` laissée vide en P10b-3-i.
 *
 * ═══ CE QUE LA COLONNE AFFIRME, ET CE QU'ELLE N'AFFIRME PAS ═══
 *
 * Elle dit : « le soignant a déclaré que cette consultation répond à ce triage ». Elle **n'affirme
 * aucune causalité** entre le triage et chaque ligne écrite : un médecin qui profite de la
 * consultation pour consigner une vaccination ancienne la verra reliée à la session, pas au motif
 * de la venue. C'est le régime des « trois silences » de P7-D2, où la fiche de parcours sépare déjà
 * les actes d'une visite des « autres entrées médicales de la période ».
 *
 * ═══ UN IDENTIFIANT, PAS UNE RELATION VIVANTE (ADR-042 D1) ═══
 *
 * Aucune clé étrangère, et c'est la décision prise il y a huit jours pour les chaînes d'audit :
 * `acces_dossier` est un journal. Une action référentielle mettrait `triage_id` à NULL le jour où
 * un triage disparaîtrait, et **effacerait de l'archive le fait que cette consultation répondait à
 * un triage** — en modifiant une ligne que personne n'a touchée.
 *
 * Le précédent est d'ailleurs déjà dans cette table voisine : `triages.membre_id` est lui aussi
 * « nullable sans FK » (dit dans `DossierController::donneesDe()`).
 *
 * ═══ NULLABLE, ET JAMAIS RÉTROACTIVE ═══
 *
 * Les consultations antérieures n'ont désigné aucun triage. Leur en attribuer un après coup — fût-ce
 * « le triage le plus récent du membre » — serait un mensonge d'archive, et surtout la déduction que
 * la décision F1 refuse : on ne devine pas un lien clinique, on le fait déclarer.
 * Précédents : `mesures_sante.referentiel_version` (L1+L2), `triages.protocole_code` (P10b-1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acces_dossier', function (Blueprint $table): void {
            $table->unsignedBigInteger('triage_id')
                ->nullable()
                ->after('token_qr_id')
                ->comment('P10c-2-i : triage auquel le soignant a déclaré que cette consultation répond. Identifiant, sans FK (ADR-042 D1).');

            // Index : la constitution du jeu d'apprentissage part du triage et cherche ce que la
            // consultation a produit. Sans lui, chaque ligne du jeu coûterait un balayage complet
            // d'un journal qui grossit à chaque accès au dossier.
            $table->index('triage_id', 'idx_acces_dossier_triage');
        });
    }

    public function down(): void
    {
        Schema::table('acces_dossier', function (Blueprint $table): void {
            $table->dropIndex('idx_acces_dossier_triage');
            $table->dropColumn('triage_id');
        });
    }
};
