<?php

namespace App\Services\Pki;

use App\Models\CertificatNumerique;
use App\Models\Medecin;
use App\Models\SignatureElectronique;
use App\Models\User;
use App\Services\Referentiel\EmpreinteReferentiel;
use App\Support\RegistreDocumentsSignables;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use SensitiveParameter;

/**
 * Pose et vérifie les signatures électroniques (CDC_09 §5.3/§5.4 ; CDC_10 §4.5 ; P6.5b).
 *
 * ═══ LA PROMESSE TENUE ICI ═══
 *
 * Il n'existe, dans tout ce service, aucun chemin qui produise une signature sans que le secret du
 * praticien ait été fourni à l'appel. Ce n'est pas une politique, c'est une conséquence : la clé
 * privée est déchiffrée par une clé dérivée de ce secret, qui n'est stocké nulle part. Retirer le
 * paramètre `$secret` ne rendrait pas le service permissif — il le rendrait inopérant.
 *
 * ═══ SÉQUENCE, ET POURQUOI CET ORDRE ═══
 *
 * 1. les cinq contrôles du §5.4 (classe pure, refus journalisé) ;
 * 2. le verrou anti-force brute, puis le secret ;
 * 3. l'ouverture du coffre ;
 * 4. la signature, puis l'enregistrement et le journal, dans une seule transaction.
 *
 * Les contrôles §5.4 passent AVANT la vérification du secret : un praticien dont l'autorisation
 * est retirée doit lire « autorisation retirée », pas « secret incorrect ». Lui répondre à côté
 * l'enverrait chercher son erreur au mauvais endroit, et le journal enregistrerait le mauvais fait.
 *
 * ═══ FRONTIÈRE ═══
 *
 * Aucune règle médicale. « Quelles règles métier ce service calcule-t-il ? » → **aucune**. Il
 * vérifie des habilitations, il ne juge pas un soin.
 */
final class ServiceSignature
{
    public function __construct(
        private readonly ReglesVerificationSignature $regles,
        private readonly AutoriteCertification $autorite,
        private readonly CoffreCleProfessionnel $coffre,
        private readonly JournalSignature $journal,
    ) {}

    /**
     * Signe un document au nom du praticien relié au compte connecté.
     *
     * @throws RuntimeException message destiné à l'interface — jamais de détail technique
     *                          (CDC_10 §5 : messages d'erreur non bavards).
     */
    public function signer(
        User $acteur,
        string $typeDocument,
        int $documentId,
        #[SensitiveParameter] string $secret,
    ): SignatureElectronique {
        if (! RegistreDocumentsSignables::existe($typeDocument)) {
            throw new RuntimeException("Ce type de document n'est pas signable.");
        }

        $professionnel = $this->ficheDe($acteur);

        // LE DERNIER certificat, révoqué compris — et non « le certificat actif ». Chercher
        // l'actif revenait à préjuger : après une révocation, les règles concluaient « aucun
        // certificat n'a été émis », le contrôle REVOCATION du §5.4 devenait inatteignable, et le
        // journal enregistrait le mauvais motif. Défaut trouvé au G2 live de P6.5b.
        $certificat = $professionnel !== null ? $this->autorite->dernierCertificat($professionnel) : null;

        // ── 1. Les cinq contrôles obligatoires du §5.4 ────────────────────────────────
        $verdict = $this->regles->verifier($this->etatDe($professionnel, $certificat), $typeDocument);

        if (! $verdict->autorise) {
            $this->journal->inscrire(
                JournalSignature::SIGNATURE_REFUSEE,
                $acteur,
                $professionnel,
                $typeDocument,
                $documentId,
                $verdict->motif,
                ['controle' => $verdict->controle, 'numero_serie' => $certificat?->numero_serie],
            );

            throw new RuntimeException($verdict->motif ?? 'Signature refusée.');
        }

        // À ce stade les règles garantissent que les deux sont présents ; l'assertion est là pour
        // l'analyse statique, pas pour la logique.
        assert($professionnel instanceof Medecin && $certificat instanceof CertificatNumerique);

        $document = RegistreDocumentsSignables::document($typeDocument)->trouver($documentId);

        if ($document === null) {
            throw new RuntimeException('Document introuvable.');
        }

        // ── 2. Verrou anti-force brute, puis le secret ────────────────────────────────
        $this->verifierSecret($acteur, $professionnel, $certificat, $secret);

        // ── 3. Ouverture du coffre ────────────────────────────────────────────────────
        $clePrivee = $this->coffre->ouvrir(
            $certificat->cle_privee_chiffree,
            $certificat->nonce,
            $certificat->sel_kdf,
            $secret,
            $certificat->numero_serie,
            (int) $professionnel->getKey(),
        );

        // ── 4. Signature, enregistrement et journal, en une seule transaction ─────────
        return DB::transaction(function () use (
            $acteur, $professionnel, $certificat, $document, $typeDocument, $documentId, $clePrivee
        ): SignatureElectronique {
            $canonique = $this->canoniser($typeDocument, $document);
            $empreinte = hash('sha256', $canonique);

            if (openssl_sign($canonique, $signature, $clePrivee, OPENSSL_ALGO_SHA256) === false) {
                throw new RuntimeException('La signature a échoué.');
            }

            $ligne = SignatureElectronique::create([
                'type_document'     => $typeDocument,
                'document_id'       => $documentId,
                'certificat_id'     => $certificat->id,
                'medecin_id'        => $professionnel->getKey(),
                'empreinte_contenu' => $empreinte,
                'signature'         => base64_encode($signature),
                'algorithme'        => 'RSA-SHA256',
                // Le contexte tel qu'il était AU MOMENT de signer. Le relire depuis la fiche des
                // mois plus tard donnerait l'état d'aujourd'hui — un praticien muté ferait changer
                // d'hôpital toutes ses prescriptions passées (motif P7-D2).
                'signataire_numero'        => $professionnel->numero_professionnel,
                'signataire_nom'           => $professionnel->nom_complet,
                'signataire_etablissement' => $professionnel->structure?->nom,
                'signe_le'                 => Carbon::now(),
            ]);

            $this->journal->inscrire(
                JournalSignature::SIGNATURE_REUSSIE,
                $acteur,
                $professionnel,
                $typeDocument,
                $documentId,
                null,
                // L'empreinte, jamais le contenu : le journal prouve, il ne recopie pas.
                ['empreinte' => $empreinte, 'numero_serie' => $certificat->numero_serie],
            );

            return $ligne;
        }, 3);
    }

    /**
     * Vérifie une signature posée : le document dit-il encore ce qu'il disait ?
     *
     * ═══ CE QUE « ALTÉRÉE » VEUT DIRE, ET CE QUE ÇA NE VEUT PAS DIRE ═══
     *
     * La signature n'empêche pas la modification — elle la RÉVÈLE. Une ordonnance signée puis
     * modifiée reste modifiable en base ; ce qui change, c'est qu'on peut désormais le dire. C'est
     * la définition de l'intégrité au §5.3, et c'est le vecteur central du G2.
     *
     * DEUX CONTRÔLES, PAS UN : l'empreinte du contenu actuel doit correspondre, ET la signature
     * doit se vérifier avec la clé publique du certificat. Le premier seul se contournerait en
     * réécrivant `empreinte_contenu` ; le second seul ne dirait pas quel contenu a été signé.
     *
     * @return array{signe: bool, integre: ?bool, signature: ?SignatureElectronique, motif: ?string}
     */
    public function verifier(string $typeDocument, int $documentId): array
    {
        $signature = SignatureElectronique::where('type_document', $typeDocument)
            ->where('document_id', $documentId)
            ->with('certificat', 'professionnel')
            ->first();

        if ($signature === null) {
            return ['signe' => false, 'integre' => null, 'signature' => null, 'motif' => 'Document non signé.'];
        }

        $document = RegistreDocumentsSignables::document($typeDocument)->trouver($documentId);

        if ($document === null) {
            return [
                'signe' => true, 'integre' => false, 'signature' => $signature,
                'motif' => 'Le document signé a disparu.',
            ];
        }

        $canonique = $this->canoniser($typeDocument, $document);

        if (! hash_equals($signature->empreinte_contenu, hash('sha256', $canonique))) {
            return [
                'signe' => true, 'integre' => false, 'signature' => $signature,
                'motif' => 'Le document a été modifié depuis sa signature.',
            ];
        }

        $publique = $signature->certificat?->clePublique();

        if ($publique === false || $publique === null) {
            return [
                'signe' => true, 'integre' => false, 'signature' => $signature,
                'motif' => 'Le certificat du signataire est illisible.',
            ];
        }

        $valide = openssl_verify(
            $canonique,
            (string) base64_decode($signature->signature, true),
            $publique,
            OPENSSL_ALGO_SHA256,
        ) === 1;

        return [
            'signe'     => true,
            'integre'   => $valide,
            'signature' => $signature,
            'motif'     => $valide ? null : 'La signature ne correspond pas au contenu.',
        ];
    }

    /** La fiche professionnelle reliée à ce compte (lien posé à la main par un gestionnaire, P6.5a). */
    public function ficheDe(User $acteur): ?Medecin
    {
        return Medecin::with('structure:id,nom')->where('user_id', $acteur->id)->first();
    }

    /**
     * L'état interrogé par les règles. Traduit les modèles en données plates — c'est cette
     * frontière qui permet aux règles de rester pures et testables sans base.
     *
     * @return array<string, mixed>
     */
    public function etatDe(?Medecin $professionnel, ?CertificatNumerique $certificat): array
    {
        return [
            'medecin_id'                => $professionnel?->getKey(),
            'numero_professionnel'      => $professionnel?->numero_professionnel,
            'profession'                => $professionnel?->profession,
            'autorisation_statut'       => $professionnel?->autorisation_statut,
            'autorisation_expire_le'    => $professionnel?->autorisation_expire_le?->toDateString(),
            'certificat_medecin_id'     => $certificat?->medecin_id,
            'certificat_statut'         => $certificat?->statut,
            'certificat_valide_du'      => $certificat?->valide_du?->toIso8601String(),
            'certificat_valide_jusqu_a' => $certificat?->valide_jusqu_a?->toIso8601String(),
            // Un certificat fabriqué ailleurs et inséré en base passerait tous les autres
            // contrôles : celui-ci demande à l'autorité si elle le reconnaît.
            'chaine_valide'             => $certificat !== null && $this->autorite->chaineValide($certificat),
        ];
    }

    /**
     * Vérifie le secret de signature, avec verrouillage temporaire après N échecs.
     *
     * ═══ POURQUOI TEMPORAIRE ═══
     *
     * Un verrouillage définitif transformerait une faute de frappe répétée en perte de certificat,
     * donc en ordonnances non signables pour un praticien en exercice. Le seuil et la durée sont
     * des DONNÉES (`config/pki.php`), jamais des littéraux — motif du PIN wallet, P5.3b-1.
     *
     * Le compteur est remis à zéro au succès : sinon cinq erreurs étalées sur six mois
     * finiraient par verrouiller quelqu'un qui n'a jamais rien fait de suspect.
     */
    private function verifierSecret(
        User $acteur,
        Medecin $professionnel,
        CertificatNumerique $certificat,
        #[SensitiveParameter] string $secret,
    ): void {
        if ($certificat->verrouille_jusqu_a !== null && $certificat->verrouille_jusqu_a->isFuture()) {
            throw new RuntimeException(
                'Signature temporairement bloquée après plusieurs secrets incorrects. '
                .'Réessayez dans quelques minutes.'
            );
        }

        if (Hash::check($secret, $certificat->secret_hash)) {
            if ($certificat->echecs_secret > 0 || $certificat->verrouille_jusqu_a !== null) {
                $certificat->forceFill(['echecs_secret' => 0, 'verrouille_jusqu_a' => null])->save();
            }

            return;
        }

        $echecs  = $certificat->echecs_secret + 1;
        $seuil   = (int) config('pki.secret.echecs_avant_verrou');
        $atteint = $echecs >= $seuil;

        $certificat->forceFill([
            'echecs_secret'      => $atteint ? 0 : $echecs,
            'verrouille_jusqu_a' => $atteint
                ? Carbon::now()->addMinutes((int) config('pki.secret.verrou_minutes'))
                : $certificat->verrouille_jusqu_a,
        ])->save();

        $this->journal->inscrire(
            JournalSignature::SECRET_INVALIDE,
            $acteur,
            $professionnel,
            null,
            null,
            'Secret de signature incorrect.',
            ['echecs' => $echecs, 'verrouille' => $atteint],
        );

        // Message générique : ne pas dire combien d'essais restent, ni si le compte existe.
        throw new RuntimeException('Secret de signature incorrect.');
    }

    /** Le contenu canonique du document — l'octet exact qui est signé et vérifié. */
    private function canoniser(string $typeDocument, Model $document): string
    {
        return EmpreinteReferentiel::canoniser(
            RegistreDocumentsSignables::document($typeDocument)->contenuCanonique($document)
        );
    }
}
