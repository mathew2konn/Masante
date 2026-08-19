<?php

namespace Database\Seeders;

use App\Models\Protocole;
use App\Models\ProtocoleVersion;
use App\Support\NiveauTriage;
use App\Support\RegistreActionsProtocole;
use App\Services\Triage\ServiceNiveauTriage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * P10b-1 — Le contenu de démonstration du registre des protocoles (CDC_08 §5, §13 étapes 5 et 8).
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * DEUX RÉGIMES, ET LA DIFFÉRENCE EST LA DÉCISION G1 N3 DU PROPRIÉTAIRE
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * **Les protocoles de TRIAGE (§5.4)** sont rédigés en brouillon complet : ils portent les seuils
 * qui vivaient jusqu'ici dans `TriageService::niveauDepuisScore()`. Ce ne sont pas des conduites
 * thérapeutiques mais des règles d'ORIENTATION, et elles existaient déjà — cet incrément ne les
 * invente pas, il les sort du code pour les rendre relisibles et corrigibles sans déploiement.
 *
 * **Les protocoles THÉRAPEUTIQUES (§5.1)** sont rédigés en brouillon **sans aucune validation**.
 * Ils démontrent la structure §4.1/§4.3 et l'écran d'authoring, et le moteur **refuse de les
 * appliquer** — {@see \App\Services\Protocole\DiffusionProtocole} ne sait pas lire un brouillon.
 * Le §1.6 (« aucun protocole utilisable sans validation ») devient ainsi un **comportement
 * prouvable** au lieu d'une promesse.
 *
 * ═══ AUCUNE ATTRIBUTION N'EST FORGÉE, ET C'EST L'ESSENTIEL ═══
 *
 * Le §4.2 donne un exemple signé « Programme National de Lutte contre le Paludisme ». **Aucun
 * document du PNLP n'a été vu.** On en représente donc la STRUCTURE, jamais l'attribution :
 * `organisme` dit que la source manque, et le protocole reste un brouillon.
 *
 * La raison est plus forte qu'ailleurs dans ce projet. Publier un protocole thérapeutique
 * exigerait de seeder ses quatre validations (§7), donc d'inscrire **dans une chaîne d'audit
 * immuable** qu'un médecin spécialiste et le Ministère ont validé une posologie. Le §7 dit
 * « opposable » : c'est la pièce qu'on produirait devant un tribunal. Partout ailleurs un jeu de
 * démonstration fabrique une donnée fausse ; ici il fabriquerait **une validation clinique
 * fausse**.
 *
 * ═══ LE SEEDER NE PUBLIE RIEN ═══
 *
 * Il ouvre des brouillons, point. Publier depuis un seeder contournerait le quatre-yeux du §10 dès
 * le premier jour — précédent `ReferentielRegistreSeeder` (P6.3) et de la mise en vigueur de la v1
 * en L1+L2 : **la publication est une étape de déploiement, faite par deux agents habilités.**
 */
class ProtocoleSeeder extends Seeder
{
    /**
     * Le libellé d'organisme employé tant qu'aucun document source n'a été fourni.
     *
     * Il est écrit une fois : trois protocoles le portent, et *une phrase recopiée trois fois finit
     * par diverger deux fois*. Surtout, il ne doit jamais dériver vers un nom d'autorité — c'est la
     * ligne que ce seeder ne franchit pas.
     */
    private const SOURCE_ABSENTE = 'Source non fournie — aucun document d\'autorité consulté';

    public function run(): void
    {
        $this->protocoleDeNiveau();
        $this->brouillonsTherapeutiques();
    }

    /**
     * `TRIAGE-NIVEAU` — les règles qui décident du niveau de priorité (§5.4, CDC_05 §5.3).
     *
     * ═══ CE QUE CE PROTOCOLE CONTIENT, ET D'OÙ ÇA VIENT ═══
     *
     * Les bandes remplacent le `match` de `TriageService`, qui portait 0-30 / 31-65 / 66-100 pour
     * TROIS niveaux. Le corpus en exige QUATRE côté patient (CDC_05 §5.3) : les bandes sont donc
     * redécoupées en quarts.
     *
     * **Ces seuils sont une base de démonstration, et ils l'étaient déjà.** Ceux du Module 1
     * n'avaient été confrontés à aucun protocole national ; ils étaient simplement invisibles,
     * enfouis dans une méthode privée. Le gain de cet incrément n'est pas qu'ils deviennent justes
     * — c'est qu'ils deviennent **relisibles, signés et corrigibles sans déploiement**. D'où
     * `niveau_preuve = 'D'` : le plus bas, et c'est la vérité.
     */
    private function protocoleDeNiveau(): void
    {
        $protocole = $this->enregistrer(ServiceNiveauTriage::CODE, [
            'titre'         => 'Détermination du niveau de priorité du triage',
            'domaine'       => Protocole::DOMAINE_TRIAGE,
            'niveau_source' => 'national',
            'organisme'     => self::SOURCE_ABSENTE,
            'langue'        => 'fr',
            'mots_cles_json' => ['triage', 'priorite', 'orientation'],
        ]);

        if ($protocole->versions()->exists()) {
            return; // Idempotent : re-seeder ne rouvre pas un brouillon ni n'en crée un second.
        }

        $version = ProtocoleVersion::create([
            'protocole_id'   => $protocole->id,
            'numero'         => 1,
            'libelle'        => '2026.1',
            'etat'           => ProtocoleVersion::BROUILLON,
            'verrou_unicite' => ProtocoleVersion::verrouPour(ProtocoleVersion::BROUILLON, $protocole->id),
            'niveau_preuve'  => 'D',
            'population'     => 'Tous publics — adultes et enfants, triage déclaratif',
            'conditions_utilisation' => 'Orientation uniquement. Ce protocole ne pose aucun '
                .'diagnostic et ne remplace pas l\'examen d\'un professionnel de santé '
                .'(CDC_05 §1). Les bandes de score sont une base de démonstration reprise du '
                .'Module 1 ; elles n\'ont été confrontées à aucun protocole national.',
            'motif'          => 'Première mise en forme protocolaire des règles de niveau, '
                .'jusqu\'ici codées dans TriageService (CDC_08 §1.2).',
            'redige_le'      => Carbon::now(),
        ]);

        // ═══ ORDRE 1 — LE DRAPEAU ROUGE, QUI ÉTAIT `max($score, 90)` DANS LE CODE ═══
        //
        // Il ne fixe PAS le niveau : il relève le score, et le chaînage avant fait que les bandes
        // suivantes voient la valeur relevée. C'est ce qui permet à la priorité d'être une donnée
        // — l'ORDRE de la règle — plutôt qu'une exception codée avant le calcul.
        $this->regle($version, 1, 'Un signe critique relève le score au niveau d\'urgence', [
            ['drapeau_rouge', '=', true],
        ], [
            [RegistreActionsProtocole::DEFINIR_SCORE_MINIMUM, 90,
                'Un symptôme ou une réponse critique impose une prise en charge urgente quel que '
                .'soit le total des autres points.'],
        ]);

        $bandes = [
            [2, 0, 25, NiveauTriage::FAIBLE,
                'Faible priorité',
                'Vos symptômes semblent bénins. Surveillance à domicile et conseils généraux ; '
                .'un pharmacien peut vous orienter. En cas d\'aggravation, refaites un triage.'],
            [3, 26, 50, NiveauTriage::RECOMMANDEE,
                'Consultation recommandée',
                'Une consultation est recommandée : prenez rendez-vous avec un médecin '
                .'généraliste ou un spécialiste.'],
            [4, 51, 75, NiveauTriage::RAPIDE,
                'Consultation rapide',
                'Consultez dans les 24 heures : rendez-vous sans tarder chez un médecin, dans un '
                .'Centre de Santé Urbain (CSU) ou une clinique.'],
            // ═══ LE NUMÉRO N'EST PAS ÉCRIT ICI ═══
            //
            // `{urgence:samu}` est résolu au référentiel national (P6.8e, CDC_02 §37). Écrire
            // « 185 » dans cette ligne aurait déplacé le problème d'un fichier PHP vers une ligne
            // de base — et l'aurait aggravé : corriger le numéro exigerait alors de refaire passer
            // le protocole par les quatre validations du §7, pour un changement sans rien de
            // clinique.
            [5, 76, 100, NiveauTriage::URGENCE,
                'Urgence',
                'Rendez-vous immédiatement au service des urgences d\'un CHU/CHR, ou appelez le '
                .'SAMU au {urgence:samu} (numéro vert, Côte d\'Ivoire).'],
        ];

        foreach ($bandes as [$ordre, $min, $max, $niveau, $titre, $message]) {
            $this->regle($version, $ordre, "Score de {$min} à {$max} : {$titre}", [
                ['score', 'entre', [$min, $max]],
            ], [
                [RegistreActionsProtocole::DEFINIR_NIVEAU, $niveau, null],
                // Le contrôle qualité EXIGE ce message : un niveau sans consigne laisse le citoyen
                // devant une couleur et un mot, alors que CDC_05 §5.3 associe une conduite à
                // chacun des quatre niveaux.
                [RegistreActionsProtocole::MESSAGE, $message, null],
            ]);
        }

        $version->references()->create([
            'type'    => 'recommandation',
            'libelle' => 'CDC_05 §5.3 — Niveaux de priorité côté patient (4 niveaux)',
            'citation' => 'Corpus MaSanté, cahier des charges n°5, section 5.3.',
        ]);

        $version->references()->create([
            'type'    => 'document',
            'libelle' => 'Base de démonstration héritée du Module 1 — aucune validation clinique',
            'citation' => 'Seuils repris de TriageService::niveauDepuisScore(), redécoupés en '
                .'quatre bandes. À remplacer par le protocole national de triage lorsqu\'il sera '
                .'fourni.',
        ]);
    }

    /**
     * Les protocoles thérapeutiques du §5.1 — BROUILLONS, sans validation, jamais applicables.
     *
     * Ils existent pour trois raisons, et aucune n'est de soigner :
     *   - démontrer que la structure §4.1/§4.3 représente un vrai protocole clinique ;
     *   - donner à l'écran d'authoring quelque chose à montrer ;
     *   - **prouver le §1.6** — un vecteur vérifie qu'aucun d'eux n'est appliqué par le moteur.
     */
    private function brouillonsTherapeutiques(): void
    {
        // L'exemple imposé du §4.2. Sa STRUCTURE est reproduite fidèlement ; son ATTRIBUTION ne
        // l'est pas, et c'est délibéré — voir l'en-tête de la classe.
        $palu = $this->enregistrer('PROT-CI-PALU-SIMPLE', [
            'titre'         => 'Paludisme simple — prise en charge de l\'adulte',
            'domaine'       => 'infectieux',
            'niveau_source' => 'national',
            'organisme'     => self::SOURCE_ABSENTE,
            'langue'        => 'fr',
            'mots_cles_json' => ['paludisme', 'TDR', 'ACT'],
        ]);

        if (! $palu->versions()->exists()) {
            $version = ProtocoleVersion::create([
                'protocole_id'   => $palu->id,
                'numero'         => 1,
                'libelle'        => '2026.2',
                'etat'           => ProtocoleVersion::BROUILLON,
                'verrou_unicite' => ProtocoleVersion::verrouPour(ProtocoleVersion::BROUILLON, $palu->id),
                'population'     => 'Adultes',
                'conditions_utilisation' => 'BROUILLON NON VALIDÉ — ne doit être appliqué à aucun '
                    .'patient. Le document source du programme national n\'a pas été fourni ; '
                    .'les conduites ci-dessous reproduisent la forme de l\'exemple du CDC_08 §4.2 '
                    .'et non une recommandation thérapeutique vérifiée.',
                'motif'          => 'Démonstration de la structure §4.1/§4.3 — en attente du '
                    .'document source et des quatre validations du §7.',
                'redige_le'      => Carbon::now(),
            ]);

            $this->regle($version, 1, 'Fièvre avec TDR positif et sans signe de gravité', [
                ['symptome_categorie', 'contient', 'fievre'],
                ['drapeau_rouge', '=', false],
            ], [
                [RegistreActionsProtocole::TRAITEMENT, 'Combinaison thérapeutique à base '
                    .'d\'artémisinine (ACT) — posologie à renseigner depuis le document source',
                    'Exemple du §4.2. La molécule, la dose et la durée ne sont pas renseignées : '
                    .'les inventer serait une prescription.'],
                [RegistreActionsProtocole::SURVEILLANCE, 'Contrôle à J3', null],
            ]);

            $version->references()->create([
                'type'     => 'document',
                'libelle'  => 'CDC_08 §4.2 — exemple imposé (format, non recommandation)',
                'citation' => 'Aucun document du Programme National de Lutte contre le Paludisme '
                    .'n\'a été consulté.',
            ]);
        }

        // Un second brouillon, d'un autre domaine, pour montrer que la structure ne dépend pas du
        // sujet — §4.3a en donne l'exemple (« Âge > 60 ans ET Diabète ET HTA »).
        $hta = $this->enregistrer('PROT-CI-HTA-SUIVI', [
            'titre'         => 'Hypertension artérielle — suivi du patient à risque',
            'domaine'       => 'chronique',
            'niveau_source' => 'national',
            'organisme'     => self::SOURCE_ABSENTE,
            'langue'        => 'fr',
            'mots_cles_json' => ['HTA', 'cardiovasculaire', 'suivi'],
        ]);

        if ($hta->versions()->exists()) {
            return;
        }

        $version = ProtocoleVersion::create([
            'protocole_id'   => $hta->id,
            'numero'         => 1,
            'libelle'        => '2026.1',
            'etat'           => ProtocoleVersion::BROUILLON,
            'verrou_unicite' => ProtocoleVersion::verrouPour(ProtocoleVersion::BROUILLON, $hta->id),
            'population'     => 'Adultes de plus de 60 ans',
            'conditions_utilisation' => 'BROUILLON NON VALIDÉ — ne doit être appliqué à aucun '
                .'patient. Aucun document d\'autorité n\'a été consulté.',
            'motif'          => 'Démonstration de la structure §4.3a — en attente du document '
                .'source et des quatre validations du §7.',
            'redige_le'      => Carbon::now(),
        ]);

        $this->regle($version, 1, 'Patient de plus de 60 ans présentant un signe cardiaque', [
            ['age', '>', 60],
            ['symptome_categorie', 'contient', 'cardiaque'],
        ], [
            [RegistreActionsProtocole::EXAMEN, 'Électrocardiogramme', null],
            [RegistreActionsProtocole::EXAMEN, 'Bilan biologique', null],
        ]);

        $version->references()->create([
            'type'     => 'document',
            'libelle'  => 'CDC_08 §4.3a — exemple de règle déclarative (format, non recommandation)',
            'citation' => 'Aucun document d\'autorité n\'a été consulté.',
        ]);
    }

    /**
     * Enregistre un protocole au registre, sans version. Idempotent.
     *
     * `code` et `pays_code` sont hors `$fillable` : posés explicitement, ils ne peuvent pas venir
     * d'une assignation de masse (précédent `identifiant_national` en P6.4a).
     *
     * @param  array<string, mixed>  $attributs
     */
    private function enregistrer(string $code, array $attributs): Protocole
    {
        $pays = config('referentiels.pays_defaut', 'CI');

        $protocole = Protocole::query()->where('pays_code', $pays)->where('code', $code)->first();

        if ($protocole !== null) {
            return $protocole;
        }

        $protocole = new Protocole($attributs);
        $protocole->code = $code;
        $protocole->pays_code = $pays;
        $protocole->save();

        return $protocole;
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2: mixed}>  $conditions
     * @param  array<int, array{0: string, 1: mixed, 2: string|null}>  $actions
     */
    private function regle(
        ProtocoleVersion $version,
        int $ordre,
        string $libelle,
        array $conditions,
        array $actions,
    ): void {
        $regle = $version->regles()->create(['ordre' => $ordre, 'libelle' => $libelle]);

        foreach ($conditions as $i => [$fait, $operateur, $valeur]) {
            $regle->conditions()->create([
                'ordre'       => $i + 1,
                'fait'        => $fait,
                'operateur'   => $operateur,
                // Une valeur simple est rangée comme `[x]`, un intervalle comme `[min, max]` —
                // voir `ProtocoleCondition::valeur()`, qui déballe au même endroit pour tous.
                'valeur_json' => is_array($valeur) ? $valeur : [$valeur],
            ]);
        }

        foreach ($actions as $i => [$type, $valeur, $justification]) {
            $regle->actions()->create([
                'ordre'         => $i + 1,
                'type'          => $type,
                'valeur_json'   => is_array($valeur) ? $valeur : [$valeur],
                'justification' => $justification,
            ]);
        }
    }
}
