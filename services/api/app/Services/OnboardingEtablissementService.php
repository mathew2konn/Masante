<?php

namespace App\Services;

use App\Models\ActivationPortail;
use App\Models\StructureSanitaire;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * P11.1 — CRÉATION D'UN ÉTABLISSEMENT ET DE SON GESTIONNAIRE : un seul chemin, deux portes.
 *
 * ═══ POURQUOI CE SERVICE EXISTE ═══
 *
 * CDC_11 §3 impose **deux méthodes d'onboarding** : l'administrateur crée l'établissement
 * (méthode 1, livrée depuis le Module 4), ou l'établissement **demande** son inscription et la
 * plateforme la valide (méthode 2, absente depuis P6.4a — la limite M1, que CDC_11 §3 contredit
 * en affirmant que « les deux méthodes sont implémentées »).
 *
 * Les deux **aboutissent au même acte** : un établissement naît, un compte gestionnaire naît sans
 * mot de passe, un lien d'activation à usage unique lui est remis. Écrire cet acte deux fois
 * serait le laisser diverger — et il diverge toujours du côté qu'on regarde le moins. Le motif
 * est celui de `RendezVousValidationService` (P4), extrait pour que le portail Blade et l'API
 * Sanctum partagent le même workflow de validation, et celui des gardes d'image de P6.4d, non
 * réécrites dans le formulaire.
 *
 * **Ce que ce service ne fait pas** : il ne valide aucune donnée d'établissement. Les règles de
 * saisie (le district appartient-il à sa région ? le nombre de lits dépasse-t-il la capacité ?)
 * vivent là où l'on saisit, parce qu'un message d'erreur doit revenir à l'écran où la faute a été
 * commise. Ici on reçoit des données déjà validées et on les écrit.
 */
class OnboardingEtablissementService
{
    /**
     * Crée l'établissement, son compte gestionnaire et son lien d'activation.
     *
     * Le gestionnaire naît **sans mot de passe** : c'est l'activation qui le lui fait choisir, et
     * personne — pas même l'administrateur qui vient de créer le compte — n'en connaît un.
     *
     * @param  array<string, mixed>  $etablissement  Données déjà validées de la structure.
     * @param  array{nom: string, prenom: string, email: string}  $gestionnaire
     */
    public function creer(array $etablissement, array $gestionnaire): ResultatOnboarding
    {
        return DB::transaction(function () use ($etablissement, $gestionnaire) {
            $structure = StructureSanitaire::create($etablissement + ['actif' => true]);

            $user = User::create([
                'nom' => $gestionnaire['nom'],
                'prenom' => $gestionnaire['prenom'],
                'email' => $gestionnaire['email'],
                'password' => null,
                'structure_id' => $structure->id,
                'actif' => true,
            ]);
            $user->assignRole('gestionnaire_etablissement');

            return new ResultatOnboarding($structure, $user, $this->lienActivation($user));
        });
    }

    /**
     * Réémet un lien d'activation pour un gestionnaire qui n'a pas encore choisi son mot de passe.
     *
     * Rend `null` si le compte est déjà activé : réémettre un lien pour un compte actif offrirait
     * à qui le détient un chemin de réinitialisation silencieux, sans passer par la procédure de
     * mot de passe oublié.
     */
    public function reemettreLien(User $gestionnaire): ?string
    {
        if ($gestionnaire->password !== null) {
            return null;
        }

        return $this->lienActivation($gestionnaire);
    }

    private function lienActivation(User $user): string
    {
        return route('portail.activation.show', ['token' => ActivationPortail::genererPour($user)]);
    }
}
