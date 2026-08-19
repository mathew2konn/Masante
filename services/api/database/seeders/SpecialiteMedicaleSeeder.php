<?php

namespace Database\Seeders;

use App\Models\SpecialiteMedicale;
use Illuminate\Database\Seeder;

/**
 * P6.8a — Le vocabulaire des spécialités et activités de service (CDC_09 §8).
 *
 * ═══ CE JEU EST UNE ADOPTION, PAS UNE INVENTION ═══
 *
 * Chaque code ci-dessous EXISTE DÉJÀ dans ce projet — dix sont portés par les services seedés
 * (`services_etablissement.specialite`), `don_sang` est référencé par `DonSangController` et par le
 * mobile, `ophtalmologie`, `traumatologie` et `maternite` sont nommées par
 * `symptomes.specialite_hint`. Rien n'a été ajouté « pour faire complet ».
 *
 * `maternite` a été ajouté en **P10a** : il manquait, et c'est le backfill de l'orientation qui l'a
 * fait remarquer — non pas en le devinant, mais en le SIGNALANT comme part sans code. C'est le
 * comportement attendu d'un référentiel gouverné : *un manque nommé ne s'oublie pas*.
 *
 * CE QUI N'Y EST PAS, ET POURQUOI. La nomenclature officielle des spécialités reconnues en Côte
 * d'Ivoire relève d'un arrêté que je n'ai pas vu. En inventer trente de plus produirait une liste
 * qui A L'AIR juste — et c'est précisément le genre de liste qui ne se fait jamais corriger
 * (raisonnement de P6.4a, qui a refusé de deviner la répartition d'Abidjan entre ses deux régions
 * sanitaires). Charger la nomenclature officielle sera de la DONNÉE : une ligne par terme, zéro
 * ligne de code.
 *
 * ═══ IDEMPOTENT, ET IL NE PUBLIE RIEN ═══
 *
 * Rejouer ce seeder ne duplique aucun terme et ne met aucune version en vigueur. La première
 * publication passe par le cycle §10 (une proposition, une décision par quelqu'un d'autre) —
 * publier depuis un seeder contournerait le quatre-yeux dès le premier jour (précédent
 * `ReferentielRegistreSeeder`).
 */
class SpecialiteMedicaleSeeder extends Seeder
{
    /**
     * code => [libellé, nature, profession, ordre].
     *
     * `nature` reprend une distinction que le code faisait DÉJÀ sans la dire :
     * `StructureSanitaireSeeder::SPECIALITES_SANS_MEDECIN` exclut `pharmacie` et `biologie` de la
     * génération de praticiens. Ce sont des activités de service, pas des spécialités médicales —
     * et `don_sang` encore moins. Les ranger parmi les « spécialités médicales reconnues » du §8
     * ferait affirmer au référentiel national quelque chose de faux.
     *
     * `urgences` reste une SPÉCIALITÉ : le service des urgences est tenu par des médecins, et le
     * même seeder ne l'a jamais rangée parmi les exceptions.
     *
     * @var array<string, array{0: string, 1: string, 2: string|null, 3: int}>
     */
    private const TERMES = [
        // — Spécialités médicales —
        'medecine_generale'  => ['Médecine générale',   'specialite_medicale', 'medecin_generaliste', 10],
        'urgences'           => ["Médecine d'urgence",  'specialite_medicale', 'medecin_specialiste', 20],
        'cardiologie'        => ['Cardiologie',         'specialite_medicale', 'medecin_specialiste', 30],
        'dentisterie'        => ['Dentisterie',         'specialite_medicale', 'dentiste',            31],
        'gastro_enterologie' => ['Gastro-entérologie',  'specialite_medicale', 'medecin_specialiste', 32],
        'gynecologie'        => ['Gynécologie-obstétrique', 'specialite_medicale', 'medecin_specialiste', 33],
        'ophtalmologie'      => ['Ophtalmologie',       'specialite_medicale', 'medecin_specialiste', 34],
        'orl'                => ['Oto-rhino-laryngologie (ORL)', 'specialite_medicale', 'medecin_specialiste', 35],
        'pediatrie'          => ['Pédiatrie',           'specialite_medicale', 'medecin_specialiste', 36],
        'traumatologie'      => ['Traumatologie',       'specialite_medicale', 'medecin_specialiste', 37],

        // — Activités de service —
        //
        // ═══ `maternite` — AJOUTÉ EN P10a, ET C'EST ENCORE UNE ADOPTION ═══
        //
        // Le terme était nommé par `symptomes.specialite_hint` (« Gynécologie / Maternité ») depuis
        // le Module 1, exactement comme `ophtalmologie` et `traumatologie` — mais il avait été omis
        // en P6.8a, et le backfill de P10a l'a fait ressortir en le SIGNALANT comme sans code. La
        // décision d'ajouter le terme est du propriétaire (2026-08-15) ; ce commentaire dit
        // seulement pourquoi il est rangé ICI et pas parmi les spécialités.
        //
        // POURQUOI `activite` ET NON `specialite_medicale` : la spécialité médicale correspondante
        // s'appelle **gynécologie-obstétrique**, et elle est déjà au vocabulaire sous `gynecologie`.
        // Une maternité est un SERVICE — une unité de soins tenue par des sages-femmes et des
        // obstétriciens. La ranger parmi les « spécialités médicales reconnues » du §8 ferait
        // affirmer au référentiel national quelque chose de faux, et rendrait insoluble la question
        // du §4.4 en comptant deux fois la même compétence.
        'maternite'          => ['Maternité',           'activite', 'sage_femme',  89],
        'biologie'           => ['Biologie médicale',   'activite', 'biologiste',  90],
        'pharmacie'          => ['Pharmacie',           'activite', 'pharmacien',  91],
        // Référencé par `DonSangController` et par le mobile, jamais seedé : aucun établissement de
        // démonstration ne porte ce service. Le terme entre quand même au vocabulaire — c'est ce
        // qui permet au gestionnaire d'un centre de collecte de le CHOISIR au lieu de le taper.
        'don_sang'           => ['Collecte de sang',    'activite', null,          92],
    ];

    public function run(): void
    {
        $pays = config('referentiels.pays_defaut', 'CI');

        foreach (self::TERMES as $code => [$libelle, $nature, $profession, $ordre]) {
            // `updateOrCreate` sur la clé métier : rejouer met à jour un libellé corrigé sans
            // jamais créer de doublon, et sans toucher aux rattachements existants (l'`id` ne bouge
            // pas, donc `services_etablissement.specialite_id` reste valide).
            SpecialiteMedicale::updateOrCreate(
                ['pays_code' => $pays, 'code' => $code],
                [
                    'libelle'    => $libelle,
                    'nature'     => $nature,
                    'profession' => $profession,
                    'ordre'      => $ordre,
                    'actif'      => true,
                ],
            );
        }

        $this->command?->info(count(self::TERMES)." spécialités et activités au vocabulaire ({$pays}).");
    }
}
