<?php

namespace Tests\Feature;

use App\Services\Nis\GenerateurNis;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * P6.1 — Garde « génération hors transaction » (CDC_09 §3.2).
 *
 * POURQUOI UNE CLASSE À PART : ce test n'utilise VOLONTAIREMENT pas `RefreshDatabase`.
 * Le trait enveloppe chaque test dans une transaction, donc `DB::transactionLevel()` y vaut
 * déjà 1 — la garde ne pourrait jamais se déclencher et le test passerait pour de mauvaises
 * raisons. Isolé ici, il s'exécute au niveau transactionnel 0, exactement comme un appel
 * fautif en production.
 *
 * La garde s'exécute avant tout accès à la base : aucune connexion n'est requise.
 */
class NisGardeTransactionTest extends TestCase
{
    #[Test]
    public function generer_un_nis_hors_transaction_echoue_bruyamment(): void
    {
        // Sans transaction, le verrou pessimiste sur `nis_compteurs` ne survit pas à l'appel :
        // deux requêtes concurrentes pourraient obtenir le même compteur. On préfère échouer
        // que produire un identifiant dont l'unicité n'est pas garantie.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/transaction/i');

        app(GenerateurNis::class)->suivant('CI');
    }
}
