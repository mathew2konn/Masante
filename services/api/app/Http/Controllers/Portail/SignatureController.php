<?php

namespace App\Http\Controllers\Portail;

use App\Http\Controllers\Controller;
use App\Models\CertificatNumerique;
use App\Services\Pki\AutoriteCertification;
use App\Services\Pki\JournalSignature;
use App\Services\Pki\ReglesVerificationSignature;
use App\Services\Pki\ServiceSignature;
use App\Support\RegistreDocumentsSignables;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * P6.5b — « Ma signature » : le certificat numérique du praticien connecté (CDC_09 §5.3).
 *
 * ═══ UN PRATICIEN DEMANDE SON PROPRE CERTIFICAT — ET CE N'EST PAS UNE AUTO-CERTIFICATION ═══
 *
 * L'autorité ne certifie que ce que le référentiel affirme déjà. Or `autorisation_statut` n'est
 * écrite que par un compte portant `professionnel.habiliter` (P6.5a) : un praticien ne peut pas se
 * déclarer autorisé lui-même. La demande est donc un geste de mise en service, pas une
 * habilitation — la vraie porte est en amont, et elle est gardée.
 *
 * Faire passer l'émission par un tiers aurait ajouté une file d'attente sans ajouter de garantie :
 * ce tiers n'aurait eu, pour décider, que l'information déjà inscrite au référentiel.
 *
 * ═══ LE SECRET NE TRANSITE QUE POUR ÊTRE CONSOMMÉ ═══
 *
 * Il n'est ni stocké, ni journalisé, ni renvoyé. À la création il ferme le coffre ; à chaque
 * signature il l'ouvre. Le perdre est irréversible et l'écran le dit avant, pas après.
 */
class SignatureController extends Controller
{
    public function __construct(
        private readonly ServiceSignature $signature,
        private readonly AutoriteCertification $autorite,
        private readonly ReglesVerificationSignature $regles,
        private readonly JournalSignature $journal,
    ) {}

    /** L'état de ma signature : ma fiche, mon certificat, et ce qui me manque le cas échéant. */
    public function index(): View
    {
        $utilisateur   = auth()->user();
        $professionnel = $this->signature->ficheDe($utilisateur);

        // Le DERNIER certificat pour le verdict, l'ACTIF pour décider quel formulaire montrer.
        // Un praticien dont le certificat vient d'être révoqué doit lire « révoqué », pas « aucun
        // certificat n'a été émis » — le second serait faux, et c'est le défaut trouvé au G2.
        $dernier = $professionnel !== null ? $this->autorite->dernierCertificat($professionnel) : null;
        $actif   = $professionnel !== null ? $this->autorite->certificatActif($professionnel) : null;

        $etat = $this->signature->etatDe($professionnel, $dernier);

        return view('portail.signature.index', [
            'professionnel' => $professionnel,
            'certificat'    => $actif,
            'dernier'       => $dernier,
            // Deux verdicts distincts, et la distinction est utile à l'écran : ce qui manque pour
            // OBTENIR un certificat n'est pas ce qui manque pour SIGNER.
            'verdictEmission'  => $this->regles->verifierEmission($etat),
            'verdictSignature' => $this->regles->verifier($etat, 'ordonnance'),
            'longueurMin'      => (int) config('pki.secret.longueur_min'),
            // Les sept types du §4.5, avec l'état de chacun — on n'affiche pas « la signature
            // couvre les documents médicaux » quand un seul est branché.
            'documents'        => RegistreDocumentsSignables::etatDuCorpus(),
        ]);
    }

    /** Émet mon certificat et scelle ma clé privée avec le secret que je choisis. */
    public function creer(Request $request): RedirectResponse
    {
        $donnees = $request->validate([
            'secret' => [
                'required', 'string',
                'min:'.(int) config('pki.secret.longueur_min'),
                'max:200',
                // Confirmation exigée : une faute de frappe au scellement rendrait le certificat
                // inutilisable dès sa création, sans aucun moyen de la rattraper.
                'confirmed',
            ],
        ], [
            'secret.confirmed' => 'Les deux secrets saisis ne correspondent pas.',
            'secret.min'       => 'Le secret de signature doit faire au moins :min caractères.',
        ], ['secret' => 'secret de signature']);

        $utilisateur   = auth()->user();
        $professionnel = $this->signature->ficheDe($utilisateur);
        $certificat    = $professionnel !== null ? $this->autorite->certificatActif($professionnel) : null;

        $verdict = $this->regles->verifierEmission($this->signature->etatDe($professionnel, $certificat));

        if (! $verdict->autorise) {
            // Refus journalisé, comme un refus de signature : la question « pourquoi ce praticien
            // n'a-t-il jamais eu de certificat ? » doit avoir une réponse datée.
            $this->journal->inscrire(
                JournalSignature::SIGNATURE_REFUSEE,
                $utilisateur,
                $professionnel,
                null,
                null,
                $verdict->motif,
                ['controle' => $verdict->controle, 'contexte' => 'emission'],
            );

            return back()->withErrors(['secret' => $verdict->motif]);
        }

        try {
            $emis = $this->autorite->emettre($professionnel, $donnees['secret']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['secret' => $e->getMessage()]);
        }

        $this->journal->inscrire(
            JournalSignature::CERTIFICAT_EMIS,
            $utilisateur,
            $professionnel,
            null,
            null,
            null,
            ['numero_serie' => $emis->numero_serie, 'empreinte' => $emis->empreinte],
        );

        return redirect()->route('portail.signature.index')->with(
            'statut',
            'Certificat émis. Conservez votre secret de signature : il n\'est stocké nulle part et '
            .'ne peut pas être retrouvé.'
        );
    }

    /**
     * Révoque mon certificat.
     *
     * DÉFINITIVE, et le certificat n'est pas supprimé : les signatures déjà posées le référencent,
     * et une signature dont le certificat aurait disparu deviendrait invérifiable — ce qui
     * reviendrait à l'effacer. Les prescriptions signées avant la révocation restent valides :
     * elles ont été posées quand le certificat l'était.
     */
    public function revoquer(Request $request): RedirectResponse
    {
        $donnees = $request->validate([
            'motif' => ['required', 'string', 'max:300'],
        ], [], ['motif' => 'motif de révocation']);

        $utilisateur   = auth()->user();
        $professionnel = $this->signature->ficheDe($utilisateur);

        if ($professionnel === null) {
            return back()->withErrors(['motif' => "Aucune fiche professionnelle n'est reliée à ce compte."]);
        }

        $nombre = $this->autorite->revoquerLesActifs($professionnel, $donnees['motif'], $utilisateur->id);

        if ($nombre === 0) {
            return back()->withErrors(['motif' => 'Aucun certificat actif à révoquer.']);
        }

        $this->journal->inscrire(
            JournalSignature::CERTIFICAT_REVOQUE,
            $utilisateur,
            $professionnel,
            null,
            null,
            $donnees['motif'],
        );

        return redirect()->route('portail.signature.index')
            ->with('statut', 'Certificat révoqué. Les prescriptions déjà signées restent vérifiables.');
    }

    /**
     * Vérifie la signature d'un document : intègre, altérée, ou non signé.
     *
     * La signature n'empêche pas la modification, elle la RÉVÈLE. Un document signé puis modifié
     * reste modifiable en base ; ce qui change, c'est qu'on peut désormais le dire.
     */
    public function verifier(string $type, int $id): View
    {
        abort_unless(RegistreDocumentsSignables::existe($type), 404);

        return view('portail.signature.verification', [
            'type'      => $type,
            'documentId' => $id,
            'resultat'  => $this->signature->verifier($type, $id),
        ]);
    }

    /** Mon journal de signatures — les refus autant que les succès (§5.4). */
    public function journal(): View
    {
        $professionnel = $this->signature->ficheDe(auth()->user());

        return view('portail.signature.journal', [
            'professionnel' => $professionnel,
            'entrees'       => $professionnel === null
                ? collect()
                : \App\Models\SignatureJournal::where('medecin_id', $professionnel->getKey())
                    ->orderByDesc('id')
                    ->limit(50)
                    ->get(),
        ]);
    }

    /** Les certificats du praticien, historique compris (un révoqué reste visible). */
    public function historique(): View
    {
        $professionnel = $this->signature->ficheDe(auth()->user());

        return view('portail.signature.historique', [
            'professionnel' => $professionnel,
            'certificats'   => $professionnel === null
                ? collect()
                : CertificatNumerique::where('medecin_id', $professionnel->getKey())
                    ->orderByDesc('id')
                    ->get(),
        ]);
    }
}
