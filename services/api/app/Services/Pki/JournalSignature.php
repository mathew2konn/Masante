<?php

namespace App\Services\Pki;

use App\Models\Medecin;
use App\Models\SignatureJournal;
use App\Models\User;
use App\Services\Audit\ChaineAudit;
use App\Services\Audit\JournalChaine;
use App\Services\Referentiel\EmpreinteReferentiel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * La chaîne d'audit des signatures (CDC_09 §5.4 ; CDC_10 §6 ; loi 2013-450).
 *
 * ═══ LES REFUS Y SONT AUTANT QUE LES SUCCÈS ═══
 *
 * Le §5.4 l'exige en toutes lettres : « une signature est refusée si l'un de ces contrôles échoue,
 * et **l'échec est journalisé** ». Un journal qui ne garderait que les signatures abouties ne
 * répondrait pas à la question qui compte en litige : « a-t-on tenté de signer avec un certificat
 * révoqué, et combien de fois ? ».
 *
 * ═══ CHAÎNE GLOBALE, PAS UNE PAR PRATICIEN ═══
 *
 * Chaque entrée porte l'empreinte de la précédente ; supprimer ou modifier une ligne casse tout ce
 * qui suit. Une chaîne par praticien laisserait effacer sans trace l'historique complet d'un
 * praticien — exactement le raisonnement de P6.3.
 *
 * `acteur_nom` ENTRE DANS L'EMPREINTE, et pas seulement `acteur_id` : le test d'altération de P6.3
 * l'a prouvé nécessaire — sans lui, réécrire le nom d'un agent en « Système » ne rompait rien, or
 * c'est ce nom-là qu'un humain lit.
 *
 * ═══ CE QUI N'Y ENTRE JAMAIS ═══
 *
 * Aucun contenu clinique. Le journal porte l'EMPREINTE du contenu, jamais les médicaments : il
 * prouve, il ne recopie pas. Deux copies feraient deux vérités (P6.3), et celle-ci serait en clair.
 */
final class JournalSignature implements JournalChaine
{
    public function nomJournal(): string
    {
        return 'signature_journal';
    }

    public function requete(): Builder
    {
        return SignatureJournal::query();
    }

    /**
     * La charge hachée d'une entrée relue — identique, clé pour clé, à celle de l'écriture.
     *
     * @return array<string, mixed>
     */
    public function charge(object $entree): array
    {
        return [
            'action' => $entree->action,
            'type_document' => $entree->type_document,
            'document_id' => $entree->document_id,
            'medecin_id' => $entree->medecin_id,
            'acteur_id' => $entree->acteur_id,
            'acteur_nom' => $entree->acteur_nom,
            'motif' => $entree->motif,
            'cree_le' => $entree->cree_le->toIso8601String(),
            'details' => $entree->details ?? [],
        ];
    }

    public const SIGNATURE_REUSSIE = 'signature_reussie';

    public const SIGNATURE_REFUSEE = 'signature_refusee';

    public const SECRET_INVALIDE = 'secret_invalide';

    public const CERTIFICAT_EMIS = 'certificat_emis';

    public const CERTIFICAT_REVOQUE = 'certificat_revoque';

    /**
     * Ajoute un maillon. À appeler dans une transaction déjà ouverte quand il y en a une.
     *
     * @param  array<string, mixed>  $details  empreinte, numéro de série, contrôle en échec —
     *                                         JAMAIS le contenu du document.
     */
    public function inscrire(
        string $action,
        ?User $acteur,
        ?Medecin $professionnel = null,
        ?string $typeDocument = null,
        ?int $documentId = null,
        ?string $motif = null,
        array $details = [],
    ): SignatureJournal {
        // Le dernier maillon, verrouillé : deux inscriptions simultanées ne peuvent pas partir de
        // la même empreinte précédente et produire deux branches parallèles.
        $chaine = ChaineAudit::numeroCourant($this->nomJournal(), $this->requete());

        $precedent = SignatureJournal::query()
            ->where('chaine', $chaine)
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        $horodatage = Carbon::now();

        $charge = [
            'action' => $action,
            'type_document' => $typeDocument,
            'document_id' => $documentId,
            'medecin_id' => $professionnel?->getKey(),
            'acteur_id' => $acteur?->id,
            'acteur_nom' => $this->nomLisible($acteur),
            'motif' => $motif,
            'cree_le' => $horodatage->toIso8601String(),
            'details' => $details,
        ];

        $entree = SignatureJournal::create([
            'chaine' => $chaine,
            'action' => $action,
            'type_document' => $typeDocument,
            'document_id' => $documentId,
            'medecin_id' => $professionnel?->getKey(),
            'acteur_id' => $acteur?->id,
            'acteur_nom' => $this->nomLisible($acteur),
            'motif' => $motif,
            'details' => $details,
            'empreinte' => EmpreinteReferentiel::duMaillon($precedent?->empreinte, $charge),
            'empreinte_precedente' => $precedent?->empreinte,
            'cree_le' => $horodatage,
        ]);

        // ═══ L'ANCRAGE DE TÊTE ═══
        //
        // La toute première entrée d'une chaîne fixe son point de départ. Sans lui, vider le
        // journal puis le réalimenter donnerait une chaîne qui repart d'une empreinte précédente
        // nulle et se revérifie « intacte » — le scénario exact constaté sur `referentiel_journal`.
        if ($precedent === null) {
            ChaineAudit::ancrer($this->nomJournal(), $chaine, $entree->empreinte);
        }

        return $entree;
    }

    /**
     * La chaîne est-elle intacte ? Recalcule chaque maillon depuis l'origine.
     *
     * Renvoie l'identifiant du PREMIER maillon rompu, ou `null` si tout tient. Le premier, et non
     * la liste : une rupture invalide tout ce qui suit, énumérer la suite noierait le seul fait
     * utile — où l'altération a commencé.
     */
    public function premierMaillonRompu(): ?int
    {
        return $this->verifierChaine()['rupture']['id'] ?? null;
    }

    /**
     * Vérifie la chaîne courante et rend compte des chaînes scellées ({@see ChaineAudit}).
     *
     * @return array<string, mixed>
     */
    public function verifierChaine(): array
    {
        return ChaineAudit::verifier($this);
    }

    /** « Système » quand l'acte n'a pas d'auteur humain (tâche planifiée, commande). */
    /**
     * P10b-1 — LA LOGIQUE A REMONTÉ SUR `User`, ET CE N'EST PAS UN RANGEMENT.
     *
     * Elle était juste ici et fausse ailleurs : `JournalReferentiel` (P6.3) lisait un attribut
     * `name` que ce modèle n'a jamais porté, et inscrivait donc « Système » pour chaque acteur
     * humain. Deux implémentations du même besoin, dont une silencieusement inopérante — c'est
     * exactement la divergence que la règle de source unique existe pour empêcher.
     *
     * Le comportement de ce journal ne change pas d'un caractère : `User::nomLisible()` reprend
     * la cascade prénom+nom → e-mail → identifiant, et le `null` reste traité ici.
     */
    private function nomLisible(?User $acteur): string
    {
        return $acteur?->nomLisible() ?? 'Système';
    }
}
