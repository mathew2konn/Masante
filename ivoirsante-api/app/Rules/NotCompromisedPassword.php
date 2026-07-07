<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Http;

/**
 * Vérifie qu'un mot de passe ne figure pas dans une fuite de données connue (Have I Been Pwned),
 * via le modèle de k-anonymat : seuls les 5 premiers caractères de l'empreinte SHA-1 sont envoyés,
 * jamais le mot de passe ni son empreinte complète.
 *
 * Différence VOLONTAIRE avec `Password::uncompromised()` de Laravel : cette règle est **fail-open**.
 * En cas d'indisponibilité de l'API (réseau, certificat, coupure — fréquent sur le terrain), on
 * NE bloque PAS l'utilisateur ; on journalise et on laisse passer. Rationale sécurité : la
 * disponibilité de l'accès au dossier médical prime sur un contrôle d'hygiène opportuniste, et
 * les autres critères (longueur, casse, chiffres, symboles) restent, eux, toujours appliqués.
 */
final class NotCompromisedPassword implements ValidationRule
{
    /** Délai court : le contrôle est un bonus, il ne doit jamais ralentir l'inscription. */
    private const TIMEOUT_SECONDES = 3;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return; // les règles `required`/`string` s'en chargent.
        }

        $empreinte = strtoupper(sha1($value));
        $prefixe = substr($empreinte, 0, 5);
        $suffixe = substr($empreinte, 5);

        try {
            $reponse = Http::withHeaders(['Add-Padding' => 'true'])
                ->timeout(self::TIMEOUT_SECONDES)
                ->get("https://api.pwnedpasswords.com/range/{$prefixe}");

            if (! $reponse->successful()) {
                return; // service indisponible : fail-open.
            }

            foreach (preg_split('/\r?\n/', trim($reponse->body())) ?: [] as $ligne) {
                [$candidat, $occurrences] = array_pad(explode(':', trim($ligne)), 2, '0');
                if (strcasecmp($candidat, $suffixe) === 0 && (int) $occurrences > 0) {
                    $fail('Ce mot de passe figure dans une fuite de données connue. Choisissez-en un autre.');
                    return;
                }
            }
        } catch (\Throwable $e) {
            report($e); // panne réseau/certificat : on ne verrouille jamais l'utilisateur.
        }
    }
}
