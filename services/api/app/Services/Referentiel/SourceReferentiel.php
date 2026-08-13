<?php

namespace App\Services\Referentiel;

/**
 * Contrat d'un référentiel national placé sous gouvernance (CDC_09 §10).
 *
 * POURQUOI UNE INTERFACE plutôt qu'un `if ($code === 'seuils_mesure')` dans le service : c'est le
 * même principe d'ouverture/fermeture que la passerelle de paiement (P5.1) et la passerelle de
 * reversement (P5.5b-2). Placer un référentiel de plus sous gouvernance — les établissements en
 * P6.4, les médicaments en P6.6 — doit être un AJOUT de classe, jamais une modification du moteur.
 *
 * Une source fait deux choses, et deux seulement :
 *   - `extraire()`      : lire la table métier et en produire un instantané DÉTERMINISTE ;
 *   - `controlerQualite()` : dire ce qui, dans cet instantané, interdit de le publier (§10).
 *
 * Elle ne décide jamais de publier, ne journalise rien, ne touche pas au cache. Le moteur s'en
 * charge, identiquement pour toutes les sources.
 */
interface SourceReferentiel
{
    /** Code stable du référentiel — celui du registre et de l'URL de diffusion. */
    public function code(): string;

    public function libelle(): string;

    /** Rôle responsable du référentiel (§10 « chaque référentiel a un responsable désigné »). */
    public function roleResponsable(): string;

    /**
     * L'instantané du contenu actuel de la table métier.
     *
     * DÉTERMINISME EXIGÉ : deux appels sur une base inchangée doivent produire exactement le même
     * tableau, dans le même ordre, avec les mêmes clés. Sans cela l'empreinte SHA-256 changerait
     * sans qu'aucune donnée n'ait bougé, et « le contenu a-t-il changé ? » deviendrait insoluble.
     *
     * @return array<int, array<string, mixed>>
     */
    public function extraire(): array;

    /**
     * Les anomalies qui interdisent la publication (§10 : unicité, format, cohérence, doublons,
     * valeurs aberrantes). Tableau vide = contenu publiable.
     *
     * @param  array<int, array<string, mixed>>  $contenu
     * @return array<int, string>
     */
    public function controlerQualite(array $contenu): array;
}
