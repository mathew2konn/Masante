<?php

namespace App\Support;

use App\Services\Analyse\GenerateurIdentifiantPrelevement;

/**
 * Les six états du prélèvement (L6, CDC_09 §7.4, CDC_04 §109).
 *
 * Les huit étapes du §7.4 ne sont pas huit états : l'étape 1 est la création de la DEMANDE
 * (B5-a), et les étapes 2-3 sont un seul acte — l'identifiant EST l'étiquette
 * ({@see GenerateurIdentifiantPrelevement}), posé à l'enregistrement.
 *
 * `EXPEDIE` est FACULTATIF et le dit : un prélèvement effectué au laboratoire même passe
 * directement de `PRELEVE` à `RECU` — prétendre le contraire ferait saisir un transport qui n'a
 * pas eu lieu. `RECU`, lui, n'est PAS facultatif : même un prélèvement fait sur place passe par
 * l'accession formelle au laboratoire.
 *
 * `VALIDE` et `PUBLIE` sont déclarés ICI (B5-b) mais ne seront ATTEIGNABLES qu'en B5-c : la
 * validation biologique et la publication supposent un résultat, qui n'existe pas encore. Un état
 * déclaré-mais-inatteignable À CE STADE n'est pas une faute — c'est la même situation que
 * `StatutDemandeAnalyse::SERVIE`/`ANNULEE` en B5-a, un découpage en deux temps d'une même table.
 *
 * ═══ ÉCART ASSUMÉ AU PLAN G1 (§5 du plan) ═══
 *
 * Le plan annonçait cet enum PROMU dans `@masante/shared`. Reconsidéré à l'exécution : L12 pose
 * que B5 reste en écrans Blade, et aucun consommateur TypeScript ne lit ces valeurs — exactement
 * la situation de `StatutConsultation` (B2-a) et de `StatutDemandeAnalyse` (B5-a), toutes deux
 * BACKEND-ONLY pour la même raison. Promouvoir une clé qu'aucun front ne lit reproduirait le
 * défaut que P11.0 a trouvé sur `RendezVousStatut` : zéro import, des années durant. Le jour où
 * B5-c ou un écran Next les consomme, la promotion est additive, avec sa garde anti-divergence.
 */
enum StatutPrelevement: string
{
    case PRELEVE = 'preleve';
    case EXPEDIE = 'expedie';
    case RECU = 'recu';
    case EN_ANALYSE = 'en_analyse';
    case VALIDE = 'valide';
    case PUBLIE = 'publie';

    /** @return array<int, string> */
    public static function valeurs(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }

    public function libelle(): string
    {
        return match ($this) {
            self::PRELEVE => 'Prélevé',
            self::EXPEDIE => 'Expédié',
            self::RECU => 'Reçu',
            self::EN_ANALYSE => 'En analyse',
            self::VALIDE => 'Validé',
            self::PUBLIE => 'Publié',
        };
    }

    /** Rang du cycle — sert à refuser qu'un état remonte (garde du moteur, doublée ici). */
    public function rang(): int
    {
        return match ($this) {
            self::PRELEVE => 1,
            self::EXPEDIE => 2,
            self::RECU => 3,
            self::EN_ANALYSE => 4,
            self::VALIDE => 5,
            self::PUBLIE => 6,
        };
    }

    public function estTerminal(): bool
    {
        return $this === self::PUBLIE;
    }
}
