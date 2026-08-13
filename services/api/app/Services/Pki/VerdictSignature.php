<?php

namespace App\Services\Pki;

/**
 * Le verdict des contrôles obligatoires avant signature (CDC_09 §5.4).
 *
 * POURQUOI UN OBJET ET PAS UN BOOLÉEN. Le §5.4 exige deux choses d'un refus : qu'il empêche la
 * signature, et qu'il soit JOURNALISÉ. Un booléen dirait « non » sans dire lequel des cinq
 * contrôles a mordu — et le journal ne servirait à rien le jour où un praticien demandera pourquoi
 * il ne peut plus signer.
 *
 * `controle` est un code stable, destiné au journal et aux tests. `motif` est la phrase que lit un
 * humain. Les deux sont nécessaires : un code seul serait illisible en litige, une phrase seule
 * serait ingrepable.
 */
final readonly class VerdictSignature
{
    private function __construct(
        public bool $autorise,
        public ?string $controle = null,
        public ?string $motif = null,
    ) {}

    public static function autorise(): self
    {
        return new self(true);
    }

    public static function refuse(string $controle, string $motif): self
    {
        return new self(false, $controle, $motif);
    }
}
