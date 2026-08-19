<?php

namespace Tests\Unit;

use App\Support\MoteurProtocole;
use App\Support\RegistreActionsProtocole;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * P10b-1 — Le moteur d'inférence, éprouvé sans base (CDC_08 §12 « tests unitaires du moteur
 * d'inférence : conditions, opérateurs, priorités »).
 *
 * `PHPUnit\Framework\TestCase` et non celui de Laravel : la classe est PURE, et monter une
 * application pour la tester laisserait croire qu'elle en dépend. Motif `ReglesCalendrierVaccinalTest`
 * (P6.8b) et `CalculateurNisTest` (P6.1).
 *
 * ═══ ÉCRITE DANS LES DEUX SENS ═══
 *
 * Chaque opérateur a son cas qui passe et son cas qui refuse. Un moteur testé seulement sur le
 * chemin heureux prouverait qu'il sait dire oui, pas qu'il sait dire non — et c'est le non qui
 * décide qu'un patient n'est pas envoyé aux urgences.
 */
class MoteurProtocoleTest extends TestCase
{
    /** @param array<int, array{0: string, 1: string, 2: mixed}> $conditions */
    private function regle(int $ordre, array $conditions, array $actions, string $libelle = 'Règle'): array
    {
        return [
            'ordre'      => $ordre,
            'libelle'    => $libelle,
            'conditions' => array_map(static fn (array $c): array => [
                'fait' => $c[0], 'operateur' => $c[1], 'valeur' => $c[2],
            ], $conditions),
            'actions'    => array_map(static fn (array $a): array => [
                'type' => $a[0], 'valeur' => $a[1] ?? null,
            ], $actions),
        ];
    }

    private function niveau(array $regles, array $faits): ?string
    {
        foreach (MoteurProtocole::evaluer($regles, $faits)['actions'] as $action) {
            if ($action['type'] === RegistreActionsProtocole::DEFINIR_NIVEAU) {
                return (string) $action['valeur'];
            }
        }

        return null;
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Opérateurs — chacun dans les deux sens (§12)
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_operateur_entre_est_inclusif_aux_deux_bornes(): void
    {
        $regles = [$this->regle(1, [['score', 'entre', [26, 50]]], [
            [RegistreActionsProtocole::DEFINIR_NIVEAU, 'recommandee'],
        ])];

        // Les deux bornes appartiennent à la bande : c'est la convention déclarée dans
        // `RegistreOperateursProtocole`, et le contrôle de couverture s'appuie dessus. Si elle
        // changeait ici sans changer là-bas, un score tomberait dans un trou invisible.
        $this->assertSame('recommandee', $this->niveau($regles, ['score' => 26]));
        $this->assertSame('recommandee', $this->niveau($regles, ['score' => 50]));
        $this->assertNull($this->niveau($regles, ['score' => 25]));
        $this->assertNull($this->niveau($regles, ['score' => 51]));
    }

    public function test_operateurs_de_comparaison_numerique(): void
    {
        foreach ([
            ['<', 60, 59, 60],
            ['<=', 60, 60, 61],
            ['>', 60, 61, 60],
            ['>=', 60, 60, 59],
        ] as [$operateur, $seuil, $quiPasse, $quiRefuse]) {
            $regles = [$this->regle(1, [['age', $operateur, $seuil]], [
                [RegistreActionsProtocole::DEFINIR_NIVEAU, 'urgence'],
            ])];

            $this->assertSame('urgence', $this->niveau($regles, ['age' => $quiPasse]),
                "L'opérateur {$operateur} aurait dû accepter {$quiPasse}");
            $this->assertNull($this->niveau($regles, ['age' => $quiRefuse]),
                "L'opérateur {$operateur} aurait dû refuser {$quiRefuse}");
        }
    }

    public function test_contient_et_ne_contient_pas_sur_une_liste(): void
    {
        $regles = [$this->regle(1, [['symptome_categorie', 'contient', 'cardiaque']], [
            [RegistreActionsProtocole::DEFINIR_NIVEAU, 'urgence'],
        ])];

        $this->assertSame('urgence', $this->niveau($regles, ['symptome_categorie' => ['fievre', 'cardiaque']]));
        $this->assertNull($this->niveau($regles, ['symptome_categorie' => ['fievre', 'digestif']]));

        $inverse = [$this->regle(1, [['symptome_categorie', 'ne_contient_pas', 'cardiaque']], [
            [RegistreActionsProtocole::DEFINIR_NIVEAU, 'faible'],
        ])];

        $this->assertSame('faible', $this->niveau($inverse, ['symptome_categorie' => ['fievre']]));
        $this->assertNull($this->niveau($inverse, ['symptome_categorie' => ['cardiaque']]));
    }

    /**
     * `existe` / `absent` portent sur la CONNAISSANCE du fait, pas sur sa valeur.
     *
     * C'est ce qui permet à un protocole de traiter le triage anonyme sans que le système décide à
     * la place du patient — raisonnement de `ReglesOrientation` (un sexe inconnu n'écarte rien) et
     * des trois silences de P7-D2.
     */
    public function test_existe_et_absent_distinguent_le_non_renseigne_du_faux(): void
    {
        $existe = [$this->regle(1, [['sexe', 'existe', null]], [
            [RegistreActionsProtocole::DEFINIR_NIVEAU, 'faible'],
        ])];

        $this->assertSame('faible', $this->niveau($existe, ['sexe' => 'F']));
        $this->assertNull($this->niveau($existe, ['sexe' => null]));
        $this->assertNull($this->niveau($existe, []));

        $absent = [$this->regle(1, [['sexe', 'absent', null]], [
            [RegistreActionsProtocole::DEFINIR_NIVEAU, 'recommandee'],
        ])];

        $this->assertSame('recommandee', $this->niveau($absent, []));
        $this->assertNull($this->niveau($absent, ['sexe' => 'M']));
    }

    public function test_egalite_ne_rapproche_jamais_deux_natures_differentes(): void
    {
        $regles = [$this->regle(1, [['sexe', '=', 'F']], [
            [RegistreActionsProtocole::DEFINIR_NIVEAU, 'faible'],
        ])];

        // `'M' == 0` vaut `true` avec une comparaison lâche en PHP. Un tel rapprochement ferait
        // matcher un sexe contre un nombre — la comparaison se fait donc en chaînes.
        $this->assertNull($this->niveau($regles, ['sexe' => 0]));
        $this->assertSame('faible', $this->niveau($regles, ['sexe' => 'F']));
    }

    public function test_les_booleens_sont_normalises_avant_comparaison(): void
    {
        $regles = [$this->regle(1, [['drapeau_rouge', '=', true]], [
            [RegistreActionsProtocole::DEFINIR_NIVEAU, 'urgence'],
        ])];

        // Le protocole écrit `true`, le fait peut arriver en `'1'` d'un formulaire : les deux
        // doivent se rencontrer, sans pour autant ouvrir la comparaison lâche du test précédent.
        $this->assertSame('urgence', $this->niveau($regles, ['drapeau_rouge' => true]));
        $this->assertSame('urgence', $this->niveau($regles, ['drapeau_rouge' => '1']));
        $this->assertNull($this->niveau($regles, ['drapeau_rouge' => false]));
        $this->assertNull($this->niveau($regles, ['drapeau_rouge' => '0']));
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Conditions multiples — le `ET` du §4.3a
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_toutes_les_conditions_doivent_etre_remplies(): void
    {
        $regles = [$this->regle(1, [
            ['age', '>', 60],
            ['symptome_categorie', 'contient', 'cardiaque'],
        ], [[RegistreActionsProtocole::DEFINIR_NIVEAU, 'urgence']])];

        $this->assertSame('urgence', $this->niveau($regles, [
            'age' => 70, 'symptome_categorie' => ['cardiaque'],
        ]));

        // Une seule condition non remplie suffit : il n'y a pas de `OU` dans ce moteur, et le
        // §4.3a n'en montre aucun.
        $this->assertNull($this->niveau($regles, ['age' => 40, 'symptome_categorie' => ['cardiaque']]));
        $this->assertNull($this->niveau($regles, ['age' => 70, 'symptome_categorie' => ['fievre']]));
    }

    public function test_une_regle_sans_condition_s_applique_toujours(): void
    {
        $regles = [$this->regle(1, [], [[RegistreActionsProtocole::MESSAGE, 'Consigne systématique']])];

        $this->assertCount(1, MoteurProtocole::evaluer($regles, [])['actions']);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Chaînage avant — ce qui remplace `max($score, 90)` (§2 « moteur d'inférence »)
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * LE VECTEUR CENTRAL DE L'INCRÉMENT.
     *
     * Un score de 12 avec un drapeau rouge doit ressortir en `urgence` — non pas parce qu'une
     * ligne de PHP le force, mais parce qu'une règle d'ordre 1 relève le score et que les bandes
     * suivantes voient la valeur relevée. La priorité du drapeau rouge est devenue une DONNÉE :
     * l'ordre de la règle.
     */
    public function test_le_chainage_avant_fait_primer_le_drapeau_rouge_sans_aucune_priorite_codee(): void
    {
        $regles = [
            $this->regle(1, [['drapeau_rouge', '=', true]], [
                [RegistreActionsProtocole::DEFINIR_SCORE_MINIMUM, 90],
            ], 'Signe critique'),
            $this->regle(2, [['score', 'entre', [0, 25]]], [
                [RegistreActionsProtocole::DEFINIR_NIVEAU, 'faible'],
            ], 'Bande basse'),
            $this->regle(5, [['score', 'entre', [76, 100]]], [
                [RegistreActionsProtocole::DEFINIR_NIVEAU, 'urgence'],
            ], 'Bande haute'),
        ];

        $resultat = MoteurProtocole::evaluer($regles, ['score' => 12, 'drapeau_rouge' => true]);

        $this->assertSame('urgence', $this->niveau($regles, ['score' => 12, 'drapeau_rouge' => true]));
        $this->assertSame(90, $resultat['faits']['score'], 'Le score relevé doit être visible par l\'appelant');

        // Sans drapeau rouge, le même score reste dans la bande basse : la règle d'ordre 1 ne
        // s'applique pas, donc rien ne relève rien.
        $this->assertSame('faible', $this->niveau($regles, ['score' => 12, 'drapeau_rouge' => false]));
    }

    public function test_l_ordre_des_regles_est_respecte_meme_si_le_tableau_est_desordonne(): void
    {
        // Volontairement fourni à l'envers : c'est le moteur qui trie, pas l'appelant. Sans ce tri
        // explicite, la même donnée répondrait différemment selon l'ordre de lecture de la base.
        $regles = [
            $this->regle(5, [['score', 'entre', [76, 100]]], [
                [RegistreActionsProtocole::DEFINIR_NIVEAU, 'urgence'],
            ]),
            $this->regle(1, [['drapeau_rouge', '=', true]], [
                [RegistreActionsProtocole::DEFINIR_SCORE_MINIMUM, 90],
            ]),
        ];

        $this->assertSame('urgence', $this->niveau($regles, ['score' => 3, 'drapeau_rouge' => true]));
    }

    public function test_definir_score_minimum_n_est_pas_restitue_comme_recommandation(): void
    {
        $regles = [$this->regle(1, [], [[RegistreActionsProtocole::DEFINIR_SCORE_MINIMUM, 90]])];

        // Ce n'est pas une recommandation à afficher, c'est un effet sur le raisonnement. La
        // restituer ferait apparaître « Relever le score au minimum à : 90 » sur l'écran d'un
        // patient.
        $this->assertSame([], MoteurProtocole::evaluer($regles, ['score' => 0])['actions']);
    }

    public function test_le_score_minimum_ne_baisse_jamais_un_score_plus_eleve(): void
    {
        $regles = [$this->regle(1, [], [[RegistreActionsProtocole::DEFINIR_SCORE_MINIMUM, 90]])];

        $this->assertSame(95, MoteurProtocole::evaluer($regles, ['score' => 95])['faits']['score']);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // L'inconnu lève — il ne vaut pas « faux »
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * LE SECOND VECTEUR CENTRAL.
     *
     * Un fait inconnu traité comme « condition non remplie » rendrait un protocole entier
     * inapplicable SANS QUE RIEN NE LE SIGNALE. C'est la panne muette que P10a vient de refermer
     * sur l'orientation ; on préfère l'exception au silence.
     */
    public function test_un_fait_inconnu_leve_au_lieu_de_valoir_faux(): void
    {
        $regles = [$this->regle(1, [['temperature', '>', 39]], [
            [RegistreActionsProtocole::DEFINIR_NIVEAU, 'urgence'],
        ])];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/temperature/');

        MoteurProtocole::evaluer($regles, ['score' => 10]);
    }

    public function test_un_operateur_inconnu_leve(): void
    {
        $regles = [$this->regle(1, [['score', 'presque_egal', 40]], [
            [RegistreActionsProtocole::DEFINIR_NIVEAU, 'faible'],
        ])];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/presque_egal/');

        MoteurProtocole::evaluer($regles, ['score' => 40]);
    }

    public function test_une_action_inconnue_leve(): void
    {
        $regles = [$this->regle(1, [], [['DIAGNOSTIQUER', 'Paludisme']])];

        // `DIAGNOSTIQUER` n'existe pas au registre, et c'est délibéré : CDC_00 §4 range « triage
        // présenté comme diagnostic » parmi les interdits absolus. Une action de ce nom serait la
        // porte par laquelle l'interdit rentrerait, venue de la donnée.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/DIAGNOSTIQUER/');

        MoteurProtocole::evaluer($regles, []);
    }

    /**
     * Un fait CONNU du registre mais non renseigné pour CE patient ne lève pas.
     *
     * La différence est entre un fait que le système ne sait pas produire (défaut de conception,
     * on lève) et un fait que ce patient n'a pas renseigné (cas normal, la condition n'est pas
     * remplie). Un triage anonyme ne renseigne pas toujours l'âge.
     */
    public function test_un_fait_connu_mais_non_renseigne_ne_leve_pas(): void
    {
        $regles = [$this->regle(1, [['age', '<', 15]], [
            [RegistreActionsProtocole::DEFINIR_NIVEAU, 'faible'],
        ])];

        $this->assertNull($this->niveau($regles, ['age' => null]));
        $this->assertNull($this->niveau($regles, []));
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Traçabilité — §9.1 « justification », §10 « recommandations affichées »
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_chaque_action_porte_la_regle_qui_l_a_produite(): void
    {
        $regles = [$this->regle(1, [['score', '>', 10]], [
            [RegistreActionsProtocole::MESSAGE, 'Consultez rapidement'],
        ], 'Score élevé')];

        $resultat = MoteurProtocole::evaluer($regles, ['score' => 50]);

        // Sans l'origine, une recommandation serait une affirmation sans source — ce que le §9.1
        // interdit précisément en exigeant une « justification ».
        $this->assertSame('Score élevé', $resultat['actions'][0]['regle']);
        $this->assertSame([['ordre' => 1, 'libelle' => 'Score élevé']], $resultat['regles_declenchees']);
    }

    public function test_les_regles_non_declenchees_ne_figurent_pas_dans_la_trace(): void
    {
        $regles = [
            $this->regle(1, [['score', '>', 90]], [[RegistreActionsProtocole::MESSAGE, 'A']], 'Haute'),
            $this->regle(2, [['score', '<', 90]], [[RegistreActionsProtocole::MESSAGE, 'B']], 'Basse'),
        ];

        $resultat = MoteurProtocole::evaluer($regles, ['score' => 10]);

        $this->assertCount(1, $resultat['regles_declenchees']);
        $this->assertSame('Basse', $resultat['regles_declenchees'][0]['libelle']);
    }
}
