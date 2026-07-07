<?php

namespace App\Rules;

use Illuminate\Validation\Rules\Password;

/**
 * Politique de mot de passe UNIQUE du projet (§3.4 Sécurité + note Securite_IVOIRSANTE_2, chap. 4).
 *
 * Source de vérité partagée par l'inscription, la réinitialisation et le changement de mot de passe,
 * pour éviter toute divergence entre écrans. La barre de force côté mobile reflète exactement ces
 * critères. Argon2id est cité par la note comme option de production ; le projet hache en bcrypt
 * (cast `hashed`), conservé ici pour cohérence et compatibilité de l'environnement WAMP.
 *
 * Le contrôle « non compromis » (HIBP) est confié à {@see NotCompromisedPassword} (fail-open sur
 * panne réseau) plutôt qu'à `Password::uncompromised()`, qui lève une exception bloquante quand
 * l'API est injoignable — inacceptable pour l'accès à un dossier médical.
 */
final class PasswordPolicy
{
    /**
     * Jeu de règles réutilisable : ≥8, lettres, MAJ+min, chiffres, symboles, non compromis (HIBP).
     * À étaler dans un tableau de règles : `['required', 'confirmed', ...PasswordPolicy::regles()]`.
     *
     * @return array<int, mixed>
     */
    public static function regles(): array
    {
        return [
            Password::min(8)->letters()->mixedCase()->numbers()->symbols(),
            new NotCompromisedPassword(),
        ];
    }
}
