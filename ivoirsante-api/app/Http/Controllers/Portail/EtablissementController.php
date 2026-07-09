<?php

namespace App\Http\Controllers\Portail;

use App\Http\Controllers\Controller;
use App\Models\ActivationPortail;
use App\Models\StructureSanitaire;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Module 4 / 4.2 — Gestion des établissements partenaires (CdC §5.4.1 & §5.4.2, ADMIN uniquement).
 *
 * L'admin IVOIRSANTÉ inscrit un établissement (formulaire guidé — pas d'upload PDF) PUIS son compte
 * gestionnaire. Le gestionnaire naît SANS mot de passe : un lien d'activation à usage unique (24h) lui
 * est remis (§5.4.1 étapes 2-3 — « le mot de passe temporaire n'existe pas »). En dev sans passerelle
 * mail, le lien est affiché à l'écran (comme l'OTP simulé). Protégé par `permission:etablissement.manage`.
 */
class EtablissementController extends Controller
{
    /** Types d'établissement (miroir de l'enum de la migration structures_sanitaires). */
    public const TYPES = [
        'chu'             => 'CHU',
        'chr'             => 'CHR',
        'clinique_privee' => 'Clinique privée',
        'cabinet'         => 'Cabinet',
        'pharmacie'       => 'Pharmacie',
        'laboratoire'     => 'Laboratoire',
        'centre_sante'    => 'Centre de santé',
    ];

    /** Liste + recherche (nom/commune) + filtre par type. */
    public function index(Request $request): View
    {
        $recherche = trim((string) $request->query('q', ''));
        $type = (string) $request->query('type', '');

        $etablissements = StructureSanitaire::query()
            ->withCount('services')
            ->with(['staff' => fn ($q) => $q->role('gestionnaire_etablissement')])
            ->when($recherche !== '', fn ($q) => $q->where(function ($sous) use ($recherche) {
                $sous->where('nom', 'like', "%{$recherche}%")
                    ->orWhere('commune', 'like', "%{$recherche}%");
            }))
            ->when($type !== '' && isset(self::TYPES[$type]), fn ($q) => $q->where('type', $type))
            ->orderBy('nom')
            ->paginate(15)
            ->withQueryString();

        return view('portail.etablissements.index', [
            'etablissements' => $etablissements,
            'types'          => self::TYPES,
            'recherche'      => $recherche,
            'typeActif'      => $type,
        ]);
    }

    public function create(): View
    {
        return view('portail.etablissements.create', ['types' => self::TYPES]);
    }

    /** Crée l'établissement + son compte gestionnaire (sans mot de passe) + le lien d'activation. */
    public function store(Request $request): RedirectResponse
    {
        $data = $this->validerEtablissement($request);
        $gestionnaire = $request->validate([
            'gestionnaire_nom'    => ['required', 'string', 'max:100'],
            'gestionnaire_prenom' => ['required', 'string', 'max:100'],
            'gestionnaire_email'  => ['required', 'email', 'max:190', 'unique:users,email'],
        ]);

        $lien = DB::transaction(function () use ($data, $gestionnaire) {
            $structure = StructureSanitaire::create($data + ['actif' => true]);

            // Compte gestionnaire : SANS mot de passe (activation obligatoire), rattaché à l'établissement.
            $user = User::create([
                'nom'          => $gestionnaire['gestionnaire_nom'],
                'prenom'       => $gestionnaire['gestionnaire_prenom'],
                'email'        => $gestionnaire['gestionnaire_email'],
                'password'     => null,
                'structure_id' => $structure->id,
                'actif'        => true,
            ]);
            $user->assignRole('gestionnaire_etablissement');

            return $this->emettreLienActivation($user);
        });

        return redirect()
            ->route('portail.etablissements.index')
            ->with('statut', 'Établissement créé. Transmettez le lien d\'activation au gestionnaire.')
            ->with('lien_activation', $lien);
    }

    public function edit(StructureSanitaire $etablissement): View
    {
        $etablissement->load(['staff' => fn ($q) => $q->role('gestionnaire_etablissement')]);

        return view('portail.etablissements.edit', [
            'etablissement' => $etablissement,
            'types'         => self::TYPES,
            'gestionnaire'  => $etablissement->staff->first(),
        ]);
    }

    /** Met à jour les champs de l'établissement (le compte gestionnaire n'est pas modifié ici). */
    public function update(Request $request, StructureSanitaire $etablissement): RedirectResponse
    {
        $etablissement->update($this->validerEtablissement($request));

        return redirect()
            ->route('portail.etablissements.index')
            ->with('statut', 'Établissement mis à jour.');
    }

    /**
     * Active/désactive l'établissement (CdC §5.4.2 « supprimer »). On ne supprime PAS physiquement :
     * la structure est référencée par des RDV/avis/services. La désactivation la retire de l'annuaire
     * public et suspend ses comptes staff (répercuté sur `users.actif`).
     */
    public function toggleActif(StructureSanitaire $etablissement): RedirectResponse
    {
        $nouvelEtat = ! $etablissement->actif;

        DB::transaction(function () use ($etablissement, $nouvelEtat) {
            $etablissement->update(['actif' => $nouvelEtat]);
            $etablissement->staff()->update(['actif' => $nouvelEtat]);
        });

        return redirect()
            ->route('portail.etablissements.index')
            ->with('statut', $nouvelEtat ? 'Établissement réactivé.' : 'Établissement désactivé.');
    }

    /** Régénère un lien d'activation pour le gestionnaire non encore activé (lien perdu/expiré). */
    public function regenererLien(StructureSanitaire $etablissement): RedirectResponse
    {
        $gestionnaire = $etablissement->staff()->role('gestionnaire_etablissement')->first();

        if (! $gestionnaire) {
            return back()->withErrors(['gestionnaire' => 'Aucun gestionnaire rattaché à cet établissement.']);
        }

        if ($gestionnaire->password !== null) {
            return back()->withErrors(['gestionnaire' => 'Ce compte est déjà activé.']);
        }

        return redirect()
            ->route('portail.etablissements.edit', $etablissement)
            ->with('statut', 'Nouveau lien d\'activation généré.')
            ->with('lien_activation', $this->emettreLienActivation($gestionnaire));
    }

    /** Règles de validation communes aux champs de l'établissement. */
    private function validerEtablissement(Request $request): array
    {
        $valide = $request->validate([
            'nom'                   => ['required', 'string', 'max:200'],
            'type'                  => ['required', 'string', 'in:' . implode(',', array_keys(self::TYPES))],
            'adresse'               => ['required', 'string', 'max:500'],
            'commune'               => ['required', 'string', 'max:100'],
            'latitude'              => ['required', 'numeric', 'between:-90,90'],
            'longitude'             => ['required', 'numeric', 'between:-180,180'],
            'telephone'             => ['nullable', 'string', 'max:20'],
            'whatsapp'              => ['nullable', 'string', 'max:20'],
            'specialites'           => ['nullable', 'string', 'max:500'],
            'tarif_min_cfa'         => ['nullable', 'integer', 'min:0'],
            'tarif_max_cfa'         => ['nullable', 'integer', 'min:0', 'gte:tarif_min_cfa'],
            'partenaire_ivoirsante' => ['nullable', 'boolean'],
        ]);

        // Spécialités saisies en texte « ORL, Cardiologie » → tableau JSON (colonne specialites_json).
        $valide['specialites_json'] = collect(explode(',', (string) ($valide['specialites'] ?? '')))
            ->map(fn ($s) => trim($s))
            ->filter()
            ->values()
            ->all();
        unset($valide['specialites']);

        $valide['partenaire_ivoirsante'] = $request->boolean('partenaire_ivoirsante');

        return $valide;
    }

    /**
     * Émet un jeton d'activation à usage unique (24h) pour un compte staff et renvoie l'URL en clair.
     * Seul le HASH est stocké. Tout jeton antérieur non consommé est invalidé (marqué utilisé).
     */
    private function emettreLienActivation(User $user): string
    {
        ActivationPortail::where('user_id', $user->id)->whereNull('used_at')->update(['used_at' => now()]);

        $token = Str::random(64);
        ActivationPortail::create([
            'user_id'    => $user->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addHours(24),
        ]);

        return route('portail.activation.show', ['token' => $token]);
    }
}
