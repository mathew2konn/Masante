<?php

namespace App\Services;

use App\Models\BesoinSang;
use App\Models\DonneurSang;
use App\Models\MembreFamille;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Module 5 / 5.7 — Don de sang (CdC FN6).
 *
 * SOURCE UNIQUE de trois règles qu'on refuse de laisser au client :
 *
 *  1. LA COMPATIBILITÉ ABO/RHÉSUS. Elle vit ICI, en dur — et c'est un écart RAISONNÉ au pattern du
 *     projet (« les règles médicales vivent en base », F1.3 : symptômes, étapes prénatales, seuils
 *     de mesure). Ces référentiels-là sont des politiques de santé publique, révisables par un
 *     médecin. La compatibilité des groupes sanguins n'en est pas une : c'est de l'immunologie, elle
 *     ne se « corrige » pas par un UPDATE. La mettre en base laisserait croire qu'un gestionnaire
 *     pourrait un jour décider qu'un A+ donne à un O−. Une erreur ici tue : la règle est figée,
 *     testée, et relue.
 *
 *  2. L'ÉLIGIBILITÉ d'un donneur (âge, délai de carence depuis le dernier don) — calculée serveur.
 *
 *  3. LE CIBLAGE des alertes. Comme pour les alertes épidémiques (FN3), le serveur décide QUI est
 *     concerné : le mobile ne reçoit que ce qui le regarde, jamais la liste des besoins de tout le
 *     pays à filtrer lui-même.
 *
 * MINIMISATION (loi n°2013-450). L'établissement qui publie un besoin voit un COMPTEUR de donneurs
 * compatibles — jamais leurs noms ni leurs numéros. Un hôpital n'a pas à repartir avec un fichier de
 * porteurs de O−. Les donneurs sont alertés dans leur application et se présentent d'eux-mêmes :
 * c'est la personne qui décide de donner, pas le système qui la désigne.
 */
class DonSangService
{
    /**
     * Qui peut donner à qui. Clé = groupe de la POCHE recherchée (le receveur) ; valeurs = groupes
     * des donneurs compatibles. Règle : le receveur ne doit pas recevoir d'antigène qu'il ne possède
     * pas (A, B, Rh). D'où O− donneur universel, et AB+ receveur universel.
     *
     * @var array<string, array<int, string>>
     */
    public const COMPATIBILITE = [
        'O-'  => ['O-'],
        'O+'  => ['O-', 'O+'],
        'A-'  => ['O-', 'A-'],
        'A+'  => ['O-', 'O+', 'A-', 'A+'],
        'B-'  => ['O-', 'B-'],
        'B+'  => ['O-', 'O+', 'B-', 'B+'],
        'AB-' => ['O-', 'A-', 'B-', 'AB-'],
        'AB+' => ['O-', 'O+', 'A-', 'A+', 'B-', 'B+', 'AB-', 'AB+'],
    ];

    /** Groupes des donneurs pouvant alimenter une poche du groupe demandé. */
    public function donneursCompatiblesAvec(string $groupeDemande): array
    {
        return self::COMPATIBILITE[$groupeDemande] ?? [];
    }

    /**
     * Un membre peut-il être donneur ? Conditions cumulatives, toutes vérifiables par le serveur :
     * groupe sanguin connu (sans lui, aucun ciblage possible) et âge dans les bornes légales.
     *
     * Le poids et l'état de santé du jour ne sont PAS vérifiés ici : ils le sont au centre de
     * collecte, par un soignant. L'application n'a pas à jouer au médecin — elle oriente, elle ne
     * sélectionne pas.
     *
     * @return array{eligible: bool, motif: string|null}
     */
    public function eligibilite(MembreFamille $membre): array
    {
        if ($membre->groupe_sanguin === null) {
            return ['eligible' => false, 'motif' => 'Le groupe sanguin de ce membre n\'est pas renseigné dans le carnet.'];
        }

        $age = $membre->date_naissance?->age;
        $min = (int) config('masante.don_sang.age_min');
        $max = (int) config('masante.don_sang.age_max');

        if ($age === null || $age < $min || $age > $max) {
            return [
                'eligible' => false,
                'motif'    => "Le don du sang est réservé aux personnes de {$min} à {$max} ans.",
            ];
        }

        return ['eligible' => true, 'motif' => null];
    }

    /**
     * Le donneur peut-il donner MAINTENANT ? (délai de carence entre deux dons)
     * Distinct de l'éligibilité : un donneur éligible qui vient de donner reste donneur — il est
     * simplement en repos. On ne l'alerte pas pour rien.
     */
    public function peutDonnerMaintenant(DonneurSang $donneur): bool
    {
        if (! $donneur->disponible) {
            return false;
        }

        if ($donneur->dernier_don_at === null) {
            return true;
        }

        return $donneur->dernier_don_at->addDays((int) config('masante.don_sang.delai_jours'))->isPast();
    }

    /** Jours restants avant le prochain don possible (0 si le donneur peut donner). */
    public function joursAvantProchainDon(DonneurSang $donneur): int
    {
        if ($donneur->dernier_don_at === null) {
            return 0;
        }

        $prochain = $donneur->dernier_don_at->copy()->addDays((int) config('masante.don_sang.delai_jours'));

        return (int) max(0, now()->startOfDay()->diffInDays($prochain->startOfDay(), false));
    }

    /**
     * Alertes destinées à CE compte : les urgences transfusionnelles en cours dont au moins un de
     * ses membres donneurs peut fournir la poche demandée.
     *
     * Ciblage 100 % serveur (comme FN3) : le mobile n'apprend rien des besoins qui ne le concernent
     * pas. Seules les URGENCES alertent — un besoin « courant » s'affiche dans la liste des groupes
     * demandés, il ne réveille personne.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function alertesPour(User $user): Collection
    {
        $donneurs = $this->donneursDe($user);

        if ($donneurs->isEmpty()) {
            return collect();
        }

        // Groupes que ce foyer peut fournir aujourd'hui (donneurs disponibles et hors carence).
        $groupesDisponibles = $donneurs
            ->filter(fn (DonneurSang $d) => $this->peutDonnerMaintenant($d))
            ->map(fn (DonneurSang $d) => $d->membre?->groupe_sanguin)
            ->filter()
            ->unique();

        if ($groupesDisponibles->isEmpty()) {
            return collect();
        }

        return BesoinSang::enCours()
            ->where('niveau', 'urgent')
            ->with('structure:id,nom,commune,latitude,longitude,telephone')
            ->get()
            ->map(function (BesoinSang $besoin) use ($groupesDisponibles) {
                $compatibles = $groupesDisponibles
                    ->intersect($this->donneursCompatiblesAvec($besoin->groupe_sanguin))
                    ->values();

                return $compatibles->isEmpty() ? null : [
                    'besoin'            => $besoin,
                    // Ce que le porteur du téléphone doit savoir : « c'est TOI qu'on cherche ».
                    'mes_groupes_utiles' => $compatibles->all(),
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * Combien de donneurs pourraient répondre à ce besoin, aujourd'hui. Un NOMBRE, jamais une liste :
     * l'établissement voit l'ampleur du vivier, pas l'identité des personnes (minimisation).
     */
    public function compterDonneursCompatibles(BesoinSang $besoin): int
    {
        $groupes = $this->donneursCompatiblesAvec($besoin->groupe_sanguin);

        return DonneurSang::disponible()
            ->whereHas('membre', fn ($m) => $m->whereIn('groupe_sanguin', $groupes))
            ->with('membre:id,groupe_sanguin')
            ->get()
            ->filter(fn (DonneurSang $d) => $this->peutDonnerMaintenant($d))
            ->count();
    }

    /** Inscrit (ou réactive) un membre comme donneur volontaire. */
    public function inscrire(MembreFamille $membre): DonneurSang
    {
        $donneur = DonneurSang::firstOrNew(['membre_id' => $membre->id]);

        if (! $donneur->exists) {
            $donneur->membre_id = $membre->id;
            $donneur->inscrit_at = now();
        }

        $donneur->disponible = true;
        $donneur->save();

        return $donneur;
    }

    /**
     * Retire le consentement. La ligne demeure : la date du dernier don doit survivre à un retrait
     * suivi d'une réinscription, sinon le délai de carence se réinitialiserait à volonté.
     */
    public function retirer(DonneurSang $donneur): void
    {
        $donneur->update(['disponible' => false]);
    }

    /** Déclare un don effectué : ouvre le délai de carence. */
    public function declarerDon(DonneurSang $donneur, string $date): DonneurSang
    {
        $donneur->update(['dernier_don_at' => $date]);

        return $donneur;
    }

    /**
     * Notifie les donneurs compatibles d'une urgence (FN6 « alerte aux donneurs compatibles »).
     * Ni Firebase ni passerelle SMS dans le projet : trace applicative, à brancher au module
     * Notifications — même stub que le bris de glace (5.3) et le référent (5.6). L'alerte est de
     * toute façon visible à l'ouverture de l'application ({@see alertesPour()}).
     */
    public function notifierUrgence(BesoinSang $besoin): void
    {
        Log::warning('Urgence transfusionnelle publiée', [
            'besoin_id'     => $besoin->id,
            'groupe'        => $besoin->groupe_sanguin,
            'etablissement' => $besoin->structure?->nom,
            'donneurs_compatibles' => $this->compterDonneursCompatibles($besoin), // un compte, pas une liste
        ]);
    }

    /**
     * Membres donneurs du compte (inscription active).
     *
     * @return Collection<int, DonneurSang>
     */
    public function donneursDe(User $user): Collection
    {
        return DonneurSang::disponible()
            ->whereHas('membre', fn ($m) => $m->where('user_id', $user->id))
            ->with('membre:id,user_id,nom,prenom,groupe_sanguin,date_naissance')
            ->get();
    }
}
