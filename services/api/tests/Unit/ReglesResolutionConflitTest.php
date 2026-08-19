<?php

namespace Tests\Unit;

use App\Support\ReglesResolutionConflit;
use App\Support\ReglesSelectionProtocoles;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * P10b-2 — Les deux classes PURES de la sélection et du départage (CDC_08 §3, §8).
 *
 * `TestCase` de PHPUnit et non celui de Laravel : ces classes ne touchent ni la base, ni l'horloge,
 * ni la configuration. Si l'un de ces vecteurs se mettait à exiger l'application, c'est que la
 * pureté aurait été perdue — le test le dirait avant la revue.
 */
class ReglesResolutionConflitTest extends TestCase
{
    /** @return array<string, mixed> */
    private function protocole(
        string $code,
        string $source = 'national',
        ?string $preuve = 'D',
        ?string $publieLe = '2026-08-01T10:00:00+00:00',
        int $numero = 1,
    ): array {
        return [
            'code'          => $code,
            'niveau_source' => $source,
            'niveau_preuve' => $preuve,
            'publie_le'     => $publieLe,
            'numero'        => $numero,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // §3 / §8 critère 1 — le rang
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_le_national_l_emporte_sur_le_regional(): void
    {
        $verdict = ReglesResolutionConflit::departager(
            $this->protocole('NAT', 'national'),
            $this->protocole('REG', 'regional'),
        );

        $this->assertSame('NAT', $verdict['gagnant']);
        $this->assertSame(ReglesResolutionConflit::CRITERE_RANG, $verdict['critere']);
    }

    public function test_l_ordre_du_paragraphe_3_est_respecte_de_bout_en_bout(): void
    {
        // national < regional < oms < societe_savante < hospitalier
        $this->assertSame(0, ReglesResolutionConflit::rang('national'));
        $this->assertSame(1, ReglesResolutionConflit::rang('regional'));
        $this->assertSame(2, ReglesResolutionConflit::rang('oms'));
        $this->assertSame(3, ReglesResolutionConflit::rang('societe_savante'));
        $this->assertSame(4, ReglesResolutionConflit::rang('hospitalier'));
    }

    public function test_le_rang_l_emporte_meme_sur_un_protocole_plus_recent_et_mieux_prouve(): void
    {
        // Le régional est publié après, avec un niveau de preuve A. Le national gagne quand même :
        // le §3 place le rang AVANT tout le reste, et c'est l'ordre du corpus.
        $verdict = ReglesResolutionConflit::departager(
            $this->protocole('NAT', 'national', 'D', '2020-01-01T00:00:00+00:00'),
            $this->protocole('REG', 'regional', 'A', '2026-08-01T00:00:00+00:00'),
        );

        $this->assertSame('NAT', $verdict['gagnant']);
        $this->assertSame(ReglesResolutionConflit::CRITERE_RANG, $verdict['critere']);
    }

    public function test_une_source_inconnue_est_classee_derniere_et_non_rejetee(): void
    {
        // Un instantané publié sous un ENUM antérieur ne doit pas rendre un protocole
        // inapplicable : mieux vaut le classer bas que le faire disparaître sans bruit.
        $this->assertSame(5, ReglesResolutionConflit::rang('valeur_dun_enum_disparu'));

        $verdict = ReglesResolutionConflit::departager(
            $this->protocole('CONNU', 'hospitalier'),
            $this->protocole('INCONNU', 'valeur_dun_enum_disparu'),
        );

        $this->assertSame('CONNU', $verdict['gagnant']);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // §8 critère 2 — la récence
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_a_rang_egal_le_plus_recent_l_emporte(): void
    {
        $verdict = ReglesResolutionConflit::departager(
            $this->protocole('ANCIEN', 'national', 'D', '2026-01-01T00:00:00+00:00'),
            $this->protocole('RECENT', 'national', 'D', '2026-08-01T00:00:00+00:00'),
        );

        $this->assertSame('RECENT', $verdict['gagnant']);
        $this->assertSame(ReglesResolutionConflit::CRITERE_RECENCE, $verdict['critere']);
    }

    public function test_la_recence_passe_avant_le_niveau_de_preuve_comme_le_corpus_l_impose(): void
    {
        // On aurait pu juger l'inverse plus sage. Le §8 tranche : « 2. le plus récent » puis
        // « 3. le niveau de preuve le plus élevé ». Ce vecteur existe pour que l'ordre du corpus
        // ne se perde pas dans une future « amélioration ».
        $verdict = ReglesResolutionConflit::departager(
            $this->protocole('PREUVE_A', 'national', 'A', '2026-01-01T00:00:00+00:00'),
            $this->protocole('RECENT_D', 'national', 'D', '2026-08-01T00:00:00+00:00'),
        );

        $this->assertSame('RECENT_D', $verdict['gagnant']);
        $this->assertSame(ReglesResolutionConflit::CRITERE_RECENCE, $verdict['critere']);
    }

    public function test_une_version_jamais_publiee_perd_contre_une_version_publiee(): void
    {
        $verdict = ReglesResolutionConflit::departager(
            $this->protocole('PUBLIE', 'national', 'D', '2026-01-01T00:00:00+00:00'),
            $this->protocole('JAMAIS', 'national', 'D', null),
        );

        $this->assertSame('PUBLIE', $verdict['gagnant']);
    }

    public function test_le_libelle_de_version_ne_sert_jamais_a_departager(): void
    {
        // « 2026.2 » et « 2.10 » ne se comparent pas comme des nombres, et le §6.1 n'impose aucune
        // convention de nommage. Seule la DATE compte : deux protocoles publiés au même instant ne
        // se départagent pas par leur libellé, même si l'un « paraît » plus récent.
        $verdict = ReglesResolutionConflit::departager(
            $this->protocole('A', 'national', 'D', '2026-08-01T00:00:00+00:00', 2),
            $this->protocole('B', 'national', 'D', '2026-08-01T00:00:00+00:00', 3),
        );

        $this->assertSame(ReglesResolutionConflit::CRITERE_NON_DEPARTAGE, $verdict['critere']);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // §8 critère 3 — le niveau de preuve
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_a_rang_et_date_egaux_le_meilleur_niveau_de_preuve_l_emporte(): void
    {
        $verdict = ReglesResolutionConflit::departager(
            $this->protocole('FAIBLE', 'national', 'C', '2026-08-01T00:00:00+00:00'),
            $this->protocole('FORT', 'national', 'A', '2026-08-01T00:00:00+00:00'),
        );

        $this->assertSame('FORT', $verdict['gagnant']);
        $this->assertSame(ReglesResolutionConflit::CRITERE_PREUVE, $verdict['critere']);
    }

    public function test_un_niveau_de_preuve_absent_perd_contre_un_niveau_declare(): void
    {
        // Le traiter comme équivalent à « A » ferait gagner l'ignorance.
        $verdict = ReglesResolutionConflit::departager(
            $this->protocole('DECLARE', 'national', 'D', '2026-08-01T00:00:00+00:00'),
            $this->protocole('ABSENT', 'national', null, '2026-08-01T00:00:00+00:00'),
        );

        $this->assertSame('DECLARE', $verdict['gagnant']);
        $this->assertSame(ReglesResolutionConflit::CRITERE_PREUVE, $verdict['critere']);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // L'ordre total — le défaut réel trouvé en b-1, transposé
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_deux_protocoles_en_tout_point_egaux_sont_departages_de_facon_deterministe(): void
    {
        $a = $this->protocole('AAA', 'national', 'D', '2026-08-01T00:00:00+00:00', 1);
        $b = $this->protocole('BBB', 'national', 'D', '2026-08-01T00:00:00+00:00', 1);

        $premier = ReglesResolutionConflit::departager($a, $b);
        $second = ReglesResolutionConflit::departager($b, $a);

        // Le même résultat quel que soit l'ordre d'arrivée : sans cela, deux audits du même cas se
        // contrediraient — c'est le défaut trouvé en b-1 sur les validations signées dans la même
        // seconde, ici avant qu'il ne survienne.
        $this->assertSame($premier['gagnant'], $second['gagnant']);
        $this->assertSame(ReglesResolutionConflit::CRITERE_NON_DEPARTAGE, $premier['critere']);
    }

    public function test_un_protocole_ne_se_departage_pas_avec_lui_meme(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ReglesResolutionConflit::departager(
            $this->protocole('MEME'),
            $this->protocole('MEME'),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // La sélection (§9.1)
    // ─────────────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function candidat(string $code, array $surcharge = []): array
    {
        return array_merge([
            'code'            => $code,
            'actif'           => true,
            'contextes'       => ['triage'],
            'date_expiration' => null,
        ], $surcharge);
    }

    public function test_un_protocole_sans_contexte_declare_n_est_jamais_selectionne(): void
    {
        $tri = ReglesSelectionProtocoles::trier(
            [$this->candidat('MUET', ['contextes' => []])],
            'triage',
            '2026-08-20',
        );

        $this->assertSame([], $tri['retenus']);
        $this->assertSame(ReglesSelectionProtocoles::MOTIF_CONTEXTE, $tri['ecartes'][0]['motif']);
    }

    public function test_un_contexte_inconnu_dans_la_donnee_ne_compte_pas_comme_un_contexte(): void
    {
        // La valeur vient d'un instantané publié : elle est ignorée, pas fatale. Mais elle ne doit
        // pas rendre le protocole sélectionnable pour autant.
        $tri = ReglesSelectionProtocoles::trier(
            [$this->candidat('BIZARRE', ['contextes' => ['contexte_dun_enum_disparu']])],
            'triage',
            '2026-08-20',
        );

        $this->assertSame(ReglesSelectionProtocoles::MOTIF_CONTEXTE, $tri['ecartes'][0]['motif']);
    }

    public function test_un_protocole_d_un_autre_contexte_est_ecarte_avec_son_motif(): void
    {
        $tri = ReglesSelectionProtocoles::trier(
            [$this->candidat('CONSULT', ['contextes' => ['consultation']])],
            'triage',
            '2026-08-20',
        );

        $this->assertSame(ReglesSelectionProtocoles::MOTIF_HORS_CONTEXTE, $tri['ecartes'][0]['motif']);
    }

    public function test_un_protocole_desactive_est_ecarte(): void
    {
        $tri = ReglesSelectionProtocoles::trier(
            [$this->candidat('RETIRE', ['actif' => false])],
            'triage',
            '2026-08-20',
        );

        $this->assertSame(ReglesSelectionProtocoles::MOTIF_DESACTIVE, $tri['ecartes'][0]['motif']);
    }

    public function test_une_version_expiree_est_ecartee_mais_pas_le_jour_meme(): void
    {
        $candidat = $this->candidat('DATE', ['date_expiration' => '2026-08-20']);

        // Le jour même : « valable jusqu'à », pas « invalide à partir de ».
        $this->assertCount(1, ReglesSelectionProtocoles::trier([$candidat], 'triage', '2026-08-20')['retenus']);

        // Le lendemain : écarté.
        $tri = ReglesSelectionProtocoles::trier([$candidat], 'triage', '2026-08-21');
        $this->assertSame([], $tri['retenus']);
        $this->assertSame(ReglesSelectionProtocoles::MOTIF_EXPIRE, $tri['ecartes'][0]['motif']);
    }

    public function test_la_date_arrive_par_parametre_et_non_de_l_horloge(): void
    {
        // La preuve que la classe est pure : le même candidat rend deux verdicts opposés selon la
        // date fournie, sans qu'aucune horloge ne soit manipulée.
        $candidat = $this->candidat('DATE', ['date_expiration' => '2026-01-01']);

        $this->assertCount(1, ReglesSelectionProtocoles::trier([$candidat], 'triage', '2025-12-31')['retenus']);
        $this->assertCount(0, ReglesSelectionProtocoles::trier([$candidat], 'triage', '2026-06-01')['retenus']);
    }
}
