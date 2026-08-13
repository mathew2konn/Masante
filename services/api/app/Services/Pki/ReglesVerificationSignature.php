<?php

namespace App\Services\Pki;

use App\Support\ProfessionsSante;
use Illuminate\Support\Carbon;

/**
 * Les cinq contrôles obligatoires avant CHAQUE signature (CDC_09 §5.4).
 *
 * > « Le système vérifie : identité, certificat, autorisation d'exercer, expiration, révocation.
 * >   Une signature est refusée si l'un de ces contrôles échoue, et l'échec est journalisé. »
 *
 * ═══ CLASSE PURE, ET CE N'EST PAS UN GOÛT D'ARCHITECTE ═══
 *
 * Aucune requête, aucune écriture, aucune horloge cachée : tout entre par les paramètres. C'est ce
 * qui permet de tester « autorisation expirée hier » et « certificat révoqué il y a une seconde »
 * sans monter une base — et surtout ce qui garantit que le jugement est rendu à UN SEUL endroit.
 * Le disperser en accesseurs sur les modèles créerait des vérités concurrentes, et le jour où
 * elles divergeraient, c'est la signature qui trancherait à la place du corpus.
 *
 * Motif de `ReglesReversement` et `ReglesRapprochement` côté paiement.
 *
 * ═══ L'ORDRE DES CONTRÔLES EST DÉLIBÉRÉ ═══
 *
 * Du plus structurel au plus circonstanciel : sans identité il n'y a pas de sujet, sans certificat
 * il n'y a pas de clé, et ce n'est qu'ensuite qu'on interroge le droit d'exercer et les dates. Un
 * refus renvoie le PREMIER contrôle qui mord, celui qu'il faut corriger d'abord.
 *
 * ═══ CE QUI N'EST PAS UN CONTRÔLE MÉDICAL ═══
 *
 * `peutPrescrire` transcrit un fait ADMINISTRATIF — un kinésithérapeute n'est pas prescripteur —
 * et ne décide d'aucun soin. Le corpus interdit la règle médicale en dur (CDC_00 §4) ; il n'interdit
 * pas de savoir qui a le droit de signer quoi.
 */
final class ReglesVerificationSignature
{
    // Codes stables : ils partent dans le journal et sont assertés par les tests. Les renommer
    // casserait la lisibilité d'un audit ancien, donc on ne les renomme pas.
    public const IDENTITE     = 'identite';
    public const CERTIFICAT   = 'certificat';
    public const AUTORISATION = 'autorisation_exercer';
    public const EXPIRATION   = 'expiration';
    public const REVOCATION   = 'revocation';
    public const HABILITATION = 'habilitation_document';

    /**
     * Rend le verdict.
     *
     * @param  array{
     *     medecin_id: ?int,
     *     numero_professionnel: ?string,
     *     profession: ?string,
     *     autorisation_statut: ?string,
     *     autorisation_expire_le: ?string,
     *     certificat_medecin_id: ?int,
     *     certificat_statut: ?string,
     *     certificat_valide_du: ?string,
     *     certificat_valide_jusqu_a: ?string,
     *     chaine_valide: bool,
     * }  $etat
     * @param  string  $typeDocument  code du registre des documents signables
     */
    public function verifier(array $etat, string $typeDocument, ?Carbon $maintenant = null): VerdictSignature
    {
        $maintenant = $maintenant ?? Carbon::now();

        // ── 1. IDENTITÉ ────────────────────────────────────────────────────────────────
        if (($refus = $this->controlerIdentite($etat)) !== null) {
            return $refus;
        }

        // ── 2. CERTIFICAT ──────────────────────────────────────────────────────────────
        if ($etat['certificat_statut'] === null) {
            return VerdictSignature::refuse(
                self::CERTIFICAT,
                "Aucun certificat numérique n'a été émis pour ce professionnel.",
            );
        }

        // Le certificat appartient-il bien à CE praticien ? La question paraît absurde tant que le
        // code est correct — c'est précisément pour cela qu'on la pose : un jour où une jointure
        // sera écrite de travers, elle sera la seule à s'en apercevoir.
        if ($etat['certificat_medecin_id'] !== $etat['medecin_id']) {
            return VerdictSignature::refuse(
                self::CERTIFICAT,
                "Le certificat présenté n'appartient pas à ce professionnel.",
            );
        }

        // La chaîne : ce certificat vient-il bien de notre autorité ? Un certificat fabriqué
        // ailleurs et inséré en base passerait tous les autres contrôles.
        if ($etat['chaine_valide'] !== true) {
            return VerdictSignature::refuse(
                self::CERTIFICAT,
                "Le certificat n'a pas été émis par l'autorité de MaSanté.",
            );
        }

        // ── 3. RÉVOCATION ──────────────────────────────────────────────────────────────
        //
        // Contrôlée AVANT l'expiration, à dessein : un certificat révoqué la semaine dernière et
        // expiré depuis doit être refusé pour révocation. Le motif journalisé serait sinon
        // « expiré » — vrai, mais il masquerait le fait qui compte en litige.
        if ($etat['certificat_statut'] === 'revoque') {
            return VerdictSignature::refuse(
                self::REVOCATION,
                'Le certificat a été révoqué.',
            );
        }

        // ── 4. EXPIRATION ──────────────────────────────────────────────────────────────
        if ($etat['certificat_valide_jusqu_a'] === null
            || Carbon::parse($etat['certificat_valide_jusqu_a'])->lt($maintenant)) {
            return VerdictSignature::refuse(
                self::EXPIRATION,
                'Le certificat a expiré.',
            );
        }

        if ($etat['certificat_valide_du'] !== null
            && Carbon::parse($etat['certificat_valide_du'])->gt($maintenant)) {
            return VerdictSignature::refuse(
                self::EXPIRATION,
                "Le certificat n'est pas encore entré en vigueur.",
            );
        }

        // ── 5. AUTORISATION D'EXERCER ──────────────────────────────────────────────────
        if (($refus = $this->controlerAutorisation($etat, $maintenant)) !== null) {
            return $refus;
        }

        // ── 6. HABILITATION AU TYPE DE DOCUMENT ────────────────────────────────────────
        //
        // Au-delà des cinq du §5.4 : tous les professionnels ne signent pas tout. Fait
        // administratif, pas jugement médical.
        if ($typeDocument === 'ordonnance' && ! ProfessionsSante::peutPrescrire($etat['profession'])) {
            return VerdictSignature::refuse(
                self::HABILITATION,
                'Cette profession n\'est pas habilitée à prescrire.',
            );
        }

        return VerdictSignature::autorise();
    }

    /**
     * Peut-on ÉMETTRE un certificat pour ce professionnel ?
     *
     * ═══ POURQUOI CE N'EST PAS `verifier()` AVEC MOINS DE CONTRÔLES ═══
     *
     * À l'émission il n'existe encore ni certificat, ni révocation, ni expiration à contrôler :
     * les interroger renverrait « aucun certificat » et interdirait d'en créer un — la boucle
     * parfaite. Ce qui doit tenir, en revanche, est exactement ce qui tiendra à la signature :
     * l'identité et le droit d'exercer.
     *
     * **C'est ici que se joue la garde de P6.5a.** L'autorité ne certifie que ce que le référentiel
     * affirme déjà, et `autorisation_statut` n'est écrite que par un compte portant
     * `professionnel.habiliter`. Un praticien peut donc demander son propre certificat sans que
     * cela soit une auto-certification : il ne peut pas se déclarer autorisé lui-même.
     *
     * @param  array<string, mixed>  $etat
     */
    public function verifierEmission(array $etat, ?Carbon $maintenant = null): VerdictSignature
    {
        $maintenant = $maintenant ?? Carbon::now();

        return $this->controlerIdentite($etat)
            ?? $this->controlerAutorisation($etat, $maintenant)
            ?? VerdictSignature::autorise();
    }

    /**
     * Le compte connecté correspond-il à une fiche professionnelle au référentiel ?
     *
     * Sans ce lien — posé à la main par un gestionnaire en P6.5a — le signataire ne serait qu'un
     * compte, et la signature désignerait un guichet plutôt qu'un praticien. C'est exactement
     * l'état que le G0 de P6.5 avait trouvé.
     *
     * @param  array<string, mixed>  $etat
     */
    private function controlerIdentite(array $etat): ?VerdictSignature
    {
        if (($etat['medecin_id'] ?? null) === null) {
            return VerdictSignature::refuse(
                self::IDENTITE,
                "Ce compte n'est relié à aucune fiche professionnelle.",
            );
        }

        if (($etat['numero_professionnel'] ?? null) === null) {
            return VerdictSignature::refuse(
                self::IDENTITE,
                "Ce professionnel n'a pas de numéro national : il n'est pas au référentiel.",
            );
        }

        return null;
    }

    /**
     * Le droit d'exercer — le contrôle qui donne son sens à tout le reste.
     *
     * Un certificat valide ne dit rien du droit d'exercer : c'est un ordre professionnel qui le
     * délivre et le retire. P6.5a a réservé cette colonne à un compte habilité précisément pour
     * que ce contrôle ne repose pas sur la déclaration de l'intéressé.
     *
     * @param  array<string, mixed>  $etat
     */
    private function controlerAutorisation(array $etat, Carbon $maintenant): ?VerdictSignature
    {
        $statut = $etat['autorisation_statut'] ?? null;

        if ($statut === null) {
            return VerdictSignature::refuse(
                self::AUTORISATION,
                "Aucune autorisation d'exercer n'est enregistrée pour ce professionnel.",
            );
        }

        if ($statut !== 'valide') {
            return VerdictSignature::refuse(
                self::AUTORISATION,
                "L'autorisation d'exercer est "
                .($statut === 'suspendue' ? 'suspendue' : 'retirée').'.',
            );
        }

        // Une autorisation « valide » mais échue reste échue. Les deux colonnes portent deux faits
        // distincts (P6.5a) : les confondre laisserait passer l'un des deux cas.
        $expire = $etat['autorisation_expire_le'] ?? null;

        if ($expire !== null && Carbon::parse($expire)->lt($maintenant->copy()->startOfDay())) {
            return VerdictSignature::refuse(
                self::AUTORISATION,
                "L'autorisation d'exercer a expiré le ".Carbon::parse($expire)->format('d/m/Y').'.',
            );
        }

        return null;
    }
}
