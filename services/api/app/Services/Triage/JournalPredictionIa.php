<?php

namespace App\Services\Triage;

use App\Models\PredictionIa;
use App\Services\Audit\ChaineAudit;
use App\Services\Audit\JournalChaine;
use App\Services\Referentiel\EmpreinteReferentiel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * P10c-3-ii (F28) — La chaîne des prédictions IA (CDC_05 §9.2 ; CDC_10 « journal immuable »).
 *
 * ═══ POURQUOI MAINTENANT, ET PAS EN P10c-2-i ═══
 *
 * La migration de P10c-2-i l'avait écrit noir sur blanc : durcir une table qui ne portait que des
 * refus honnêtes aurait été le socle à vide refusé par P6.3-D3. Le durcissement était différé « au
 * jour où un modèle réel écrira la première explication ». Ce jour est celui-ci — une explication
 * SHAP nomme les valeurs cliniques qui l'ont produite.
 *
 * ═══ CE QUE L'EMPREINTE PROTÈGE, ET POURQUOI L'EXPLICATION EN FAIT PARTIE ═══
 *
 * Le §9.2 veut pouvoir répondre à « qu'a dit le modèle sur ce triage-là, ce jour-là, et pourquoi ? ».
 * Hors de l'empreinte, l'explication serait le seul élément réécrivable de la ligne — et c'est
 * précisément celui qu'un litige discute. Raisonnement identique à celui des recommandations dans
 * `JournalApplicationProtocole`, et à `acteur_nom` en P6.3.
 *
 * ═══ `modele_version` : LE RUN RÉELLEMENT UTILISÉ ═══
 *
 * Pas celui que le registre espérait. Les deux coïncident aujourd'hui, mais journaliser l'intention
 * plutôt que le fait est la façon dont une traçabilité cesse d'en être une.
 *
 * ═══ LES ENTRÉES ANTÉRIEURES AU MÉCANISME ═══
 *
 * Elles portent `chaine = NULL` et sortent donc de la vérification (`verifierUneChaine` filtre sur
 * `chaine`). Leur nombre est inscrit dans la déclaration d'origine : leur existence est **écrite**,
 * pas déduite d'un trou. Les sceller après coup aurait été un mensonge d'archive.
 */
final class JournalPredictionIa implements JournalChaine
{
    public function nomJournal(): string
    {
        return 'predictions_ia';
    }

    public function requete(): Builder
    {
        return PredictionIa::query();
    }

    /**
     * La charge hachée d'une prédiction relue — identique, clé pour clé, à celle de l'écriture.
     *
     * @return array<string, mixed>
     */
    public function charge(object $entree): array
    {
        return [
            'triage_id' => self::entierOuNull($entree->triage_id),
            'modele_version' => $entree->modele_version,
            'mode' => $entree->mode,
            'motif_degradation' => $entree->motif_degradation,
            'latence_ms' => self::entierOuNull($entree->latence_ms),
            'probabilite' => self::probabiliteNormalisee($entree->probabilite),
            'facteurs' => self::nombresNormalises($entree->facteurs_json ?? []),
            'explication' => self::nombresNormalises($entree->explication_json ?? []),
            'confiance' => $entree->confiance,
            'limites' => $entree->limites,
            'cree_le' => $entree->cree_le->toIso8601String(),
        ];
    }

    /**
     * Inscrit une prédiction, quelle qu'en soit l'issue. À appeler DANS une transaction ouverte.
     *
     * Les dégradations sont journalisées **comme les succès** : « le modèle n'a pas répondu ce
     * jour-là » est un fait de traçabilité au même titre que sa réponse, et c'est ce qui permettra
     * plus tard de dire quelle proportion des triages a réellement été observée.
     *
     * @param  array<string, mixed>  $donnees
     */
    public function inscrire(array $donnees): PredictionIa
    {
        // ORDRE DES VERROUS : ce journal est pris EN DERNIER, après les tables métier de l'appelant
        // — une inversion entre deux transactions concurrentes est la définition d'un interblocage
        // (celui qui avait mordu en P6.1).
        $chaine = ChaineAudit::numeroCourant($this->nomJournal(), $this->requete());

        $precedent = PredictionIa::query()
            ->where('chaine', $chaine)
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        $horodatage = Carbon::now();

        // ═══ ON HACHE CE QUI SERA STOCKÉ, PAS CE QU'ON CROIT STOCKER ═══
        //
        // La probabilité est normalisée AVANT d'entrer dans la charge ET dans la ligne — voir
        // {@see probabiliteNormalisee()} pour le défaut réel que cela referme.
        $donnees['probabilite'] = self::probabiliteNormalisee($donnees['probabilite'] ?? null);

        $charge = [
            'triage_id' => self::entierOuNull($donnees['triage_id'] ?? null),
            'modele_version' => $donnees['modele_version'] ?? null,
            'mode' => $donnees['mode'],
            'motif_degradation' => $donnees['motif_degradation'] ?? null,
            'latence_ms' => self::entierOuNull($donnees['latence_ms'] ?? null),
            'probabilite' => $donnees['probabilite'],
            'facteurs' => self::nombresNormalises($donnees['facteurs_json'] ?? []),
            'explication' => self::nombresNormalises($donnees['explication_json'] ?? []),
            'confiance' => $donnees['confiance'] ?? null,
            'limites' => $donnees['limites'] ?? null,
            'cree_le' => $horodatage->toIso8601String(),
        ];

        $prediction = PredictionIa::create($donnees + [
            'chaine' => $chaine,
            'cree_le' => $horodatage,
            'empreinte_precedente' => $precedent?->empreinte,
            'empreinte' => EmpreinteReferentiel::duMaillon($precedent?->empreinte, $charge),
        ]);

        // L'ancrage de tête : la première entrée d'une chaîne fixe son point de départ, sans quoi
        // une troncature par la tête serait indétectable (ADR-042).
        if ($precedent === null) {
            ChaineAudit::ancrer($this->nomJournal(), $chaine, $prediction->empreinte);
        }

        return $prediction;
    }

    /** @return array{intacte: bool, entrees: int, rupture: ?array<string, mixed>} */
    public function verifierChaine(): array
    {
        return ChaineAudit::verifier($this);
    }

    private static function entierOuNull(mixed $valeur): ?int
    {
        return $valeur === null || $valeur === '' ? null : (int) $valeur;
    }

    /**
     * La probabilité, sous la forme EXACTE où la colonne la conservera.
     *
     * ═══ DÉFAUT RÉEL TROUVÉ PAR LE G2 LIVE, INVISIBLE EN TEST ═══
     *
     * La colonne est un `decimal(5,4)`. Le service rend `0.752762` ; MySQL stocke `0.7528`. La
     * première écriture hachait la valeur **envoyée** et la base en conservait une **autre** : la
     * chaîne se déclarait rompue (`CONTENU`) sur une entrée que personne n'avait touchée — c'est-
     * à-dire la pire panne possible pour un journal médico-légal, une **fausse accusation**.
     *
     * SQLite, lui, ne tronque pas : la suite était verte. Encore la divergence prod/test, ici d'une
     * espèce nouvelle — ce n'est pas le pilote qui retype la valeur (leçon `entierOuNull` de
     * P10b-2), c'est **la base qui la modifie**.
     *
     * La parade normalise EN PHP, donc identiquement sur les deux moteurs, et la même valeur part
     * dans la charge hachée et dans la ligne. Testable partout : ce qui est stocké porte au plus
     * quatre décimales, quel que soit le moteur.
     */
    private static function probabiliteNormalisee(mixed $valeur): ?float
    {
        return $valeur === null || $valeur === '' ? null : round((float) $valeur, 4);
    }

    /**
     * Les nombres d'un bloc JSON, sous un typage STABLE de part et d'autre de la base.
     *
     * ═══ SECOND DÉFAUT DU MÊME G2, ET PLUS SOURNOIS QUE LE PREMIER ═══
     *
     * SHAP rend `0.0` pour une feature sans influence. PHP l'écrit `0.0`
     * (`JSON_PRESERVE_ZERO_FRACTION`), **MySQL le range en `0` et le relit en entier**. La charge
     * hachée à l'écriture et celle relue à la vérification différaient donc sur des features qui ne
     * pesaient rien — et la chaîne accusait d'altération une entrée parfaitement intacte.
     *
     * Le premier défaut venait d'un arrondi (la base tronque). Celui-ci vient d'un **typage** : la
     * base ne distingue pas `0.0` de `0`. Aucun test SQLite ne pouvait les voir, l'un comme l'autre.
     *
     * La parade type les feuilles numériques en `float` des DEUX côtés : `0` relu redevient `0.0`,
     * et l'empreinte cesse de dépendre de ce que le moteur a bien voulu conserver.
     *
     * Portée volontairement limitée aux deux blocs de ce journal (facteurs, explication), qui ne
     * contiennent que des nombres réels. Une normalisation générique casterait des entiers qui
     * doivent le rester.
     */
    private static function nombresNormalises(mixed $valeur): mixed
    {
        if (is_array($valeur)) {
            return array_map(static fn (mixed $v): mixed => self::nombresNormalises($v), $valeur);
        }

        return is_int($valeur) || is_float($valeur) ? (float) $valeur : $valeur;
    }
}
