<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Création d'un membre de la famille (F2.1). La règle métier « max 15 membres par compte »
 * (F2.2, révisée par modification.txt) est vérifiée ici, sur le compte authentifié, et renvoyée
 * en erreur 422 lisible.
 *
 * `matricule_ivs`, `medecin_referent_id` et `user_id` ne sont PAS acceptés du client :
 * ils sont attribués/contrôlés côté serveur.
 */
class StoreMembreRequest extends FormRequest
{
    /** Plafond de membres par compte (F2.2, révisé de 5 à 15 par modification.txt). */
    public const MAX_MEMBRES = 15;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nom'            => ['required', 'string', 'max:100'],
            'prenom'         => ['required', 'string', 'max:100'],
            'date_naissance' => ['required', 'date', 'before_or_equal:today'],
            'sexe'           => ['required', 'in:M,F'],
            'groupe_sanguin' => ['nullable', 'in:A+,A-,B+,B-,AB+,AB-,O+,O-'],
            // `photo_url` n'est PAS accepté du client : la photo se gère via l'endpoint dédié (chemin interne serveur).
            //
            // P6.8d — `cmu_numero`, `cmu_statut` et `cmu_validite` NE SONT PLUS ACCEPTÉS ICI. Une
            // couverture santé est un CONTRAT, pas un attribut de la personne : elle se déclare sur
            // `POST /membres/{membre}/couvertures`, où elle nomme son organisme. Les trois valeurs
            // restent EXPOSÉES en lecture (contrat P2, validé G5), mais dérivées de la couverture.
            //
            // Envoyées quand même, elles sont IGNORÉES en silence plutôt que refusées en 422 : un
            // client mobile plus ancien continue de fonctionner, il ne crée simplement plus de
            // donnée là où elle n'a plus de sens (même choix qu'en P6.8b pour `obligatoire`).
        ];
    }

    /** Vérifie le plafond de membres après la validation des champs. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            // P6.1 (ADR-021 §2.1) — le dossier du TITULAIRE est hors quota : ce n'est pas un
            // « membre ajouté », c'est le dossier de santé du propriétaire du compte. Sans cette
            // exclusion, activer le NIS retirerait un emplacement à chaque compte existant.
            if ($this->user()->membresFamille()->where('est_titulaire', false)->count() >= self::MAX_MEMBRES) {
                $validator->errors()->add(
                    'membre',
                    'Limite atteinte : un compte ne peut pas dépasser '.self::MAX_MEMBRES.' membres.',
                );
            }
        });
    }
}
