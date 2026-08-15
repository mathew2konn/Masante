<?php

namespace Tests\Unit;

use App\Support\ReglesCalendrierVaccinal as R;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * P6.8b — La classe pure du calendrier vaccinal (CDC_09 §8).
 *
 * AUCUNE BASE, AUCUNE HORLOGE : la date du jour est un paramètre, ce qui rend le vecteur central du
 * module — « une ligne saisie il y a un an répond `en_retard` aujourd'hui » — vérifiable sans
 * manipuler le système. Motif de `ReglesReversement` (P5.5a) et `ReglesIntervalleReference` (P6.7a).
 *
 * CE QUI EST VÉRIFIÉ ICI, ET QUI NE L'EST NULLE PART AILLEURS : les ORDRES de décision. Chaque
 * marche répond à une question différente, et les intervertir produirait des réponses fausses que
 * les vecteurs d'intégration ne verraient pas forcément.
 */
class ReglesCalendrierVaccinalTest extends TestCase
{
    private CarbonImmutable $jour;

    protected function setUp(): void
    {
        parent::setUp();
        $this->jour = CarbonImmutable::parse('2026-08-14');
    }

    // ── Statut d'une LIGNE du carnet ─────────────────────────────────────────────────────────────

    public function test_l_administration_l_emporte_sur_une_echeance_depassee(): void
    {
        // Tester l'échéance d'abord afficherait « en retard » sur une vaccination faite avec deux
        // semaines de décalage — une accusation sans objet, portée à un parent.
        $this->assertSame(R::FAIT, R::statutLigne(
            CarbonImmutable::parse('2026-01-01'),
            CarbonImmutable::parse('2025-06-01'),
            $this->jour,
        ));
    }

    public function test_sans_echeance_connue_une_intention_reste_a_faire(): void
    {
        // La présenter comme un retard affirmerait un fait qu'on ignore.
        $this->assertSame(R::A_FAIRE, R::statutLigne(null, null, $this->jour));
    }

    public function test_le_delai_de_grace_est_appliquee_et_vient_de_la_donnee(): void
    {
        $echeance = CarbonImmutable::parse('2026-08-01');

        // 13 jours après l'échéance, avec 14 jours de grâce publiés : encore à faire.
        $this->assertSame(R::A_FAIRE, R::statutLigne(null, $echeance, $this->jour, 14));

        // Sans grâce, la même situation est un retard. Le seuil vient du référentiel, pas d'ici.
        $this->assertSame(R::EN_RETARD, R::statutLigne(null, $echeance, $this->jour, 0));
    }

    public function test_le_jour_meme_de_l_echeance_n_est_pas_un_retard(): void
    {
        $this->assertSame(R::A_FAIRE, R::statutLigne(null, $this->jour, $this->jour));
    }

    // ── Statut d'une ÉCHÉANCE du calendrier ──────────────────────────────────────────────────────

    public function test_un_enfant_trop_jeune_n_est_en_retard_de_rien(): void
    {
        $this->assertSame(R::A_VENIR, R::statutEcheance(false, 35, 42));
    }

    public function test_la_fenetre_de_rattrapage_est_verifiee_AVANT_le_retard(): void
    {
        // ORDRE DÉLIBÉRÉ. Inversé, une échéance définitivement dépassée serait présentée comme
        // rattrapable, et un parent courrait après un rendez-vous que le calendrier ne prévoit plus.
        $this->assertSame(R::HORS_DELAI, R::statutEcheance(false, 400, 42, 14, 105));

        // Dans la fenêtre, c'est bien un retard.
        $this->assertSame(R::EN_RETARD, R::statutEcheance(false, 90, 42, 14, 105));
    }

    public function test_une_dose_faite_clot_la_question_quel_que_soit_l_age(): void
    {
        $this->assertSame(R::FAIT, R::statutEcheance(true, 4000, 42, 14, 105));
    }

    public function test_une_borne_de_rattrapage_absente_laisse_la_fenetre_ouverte(): void
    {
        $this->assertSame(R::EN_RETARD, R::statutEcheance(false, 4000, 270, 30, null));
    }

    // ── Âge ──────────────────────────────────────────────────────────────────────────────────────

    public function test_l_age_se_compte_en_jours(): void
    {
        $this->assertSame(42, R::ageEnJours(CarbonImmutable::parse('2026-07-03'), $this->jour));
    }

    public function test_une_naissance_future_ne_produit_jamais_un_age_negatif(): void
    {
        // Une saisie fautive doit rendre toutes les échéances `a_venir`, jamais un retard.
        $this->assertSame(0, R::ageEnJours(CarbonImmutable::parse('2027-01-01'), $this->jour));
    }

    public function test_l_age_inconnu_se_dit_plutot_que_se_supposer(): void
    {
        $this->assertNull(R::ageEnJours(null, $this->jour));
    }

    // ── Échéance d'une ligne ─────────────────────────────────────────────────────────────────────

    public function test_la_date_de_rappel_saisie_prime_sur_le_calendrier(): void
    {
        // Elle vient d'un carnet papier ou d'un soignant qui a vu le patient, et tient compte de ce
        // que le calendrier ignore : une dose reçue en retard décale les suivantes.
        $due = R::echeanceDeLaLigne(
            CarbonImmutable::parse('2026-12-01'),
            CarbonImmutable::parse('2026-01-01'),
            42,
        );

        $this->assertSame('2026-12-01', $due?->toDateString());
    }

    public function test_a_defaut_l_echeance_se_deduit_de_la_naissance(): void
    {
        $due = R::echeanceDeLaLigne(null, CarbonImmutable::parse('2026-01-01'), 42);

        $this->assertSame('2026-02-12', $due?->toDateString());
    }

    public function test_sans_naissance_ni_rappel_aucune_date_n_est_inventee(): void
    {
        $this->assertNull(R::echeanceDeLaLigne(null, null, 42));
        $this->assertNull(R::echeanceDeLaLigne(null, CarbonImmutable::parse('2026-01-01'), null));
    }
}
