<?php

namespace App\Services\Integration;

use App\Models\ClientApi;
use App\Models\CorrespondancePartenaire;
use App\Models\JournalIngestion;
use App\Models\Medicament;
use App\Models\PrixPharmacie;
use App\Services\PrixMedicamentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * P11.2 — Ingestion du stock et des prix d'une officine (CDC_11 §7.3, §7.7).
 *
 * ═══ L'API EST UN CONTRAT D'ÉCHANGE, JAMAIS UN SECOND CHEMIN D'ÉCRITURE ═══
 *
 * ADR-030 le pose littéralement. Concrètement : ce service **n'écrit rien lui-même**. Il résout,
 * il valide la forme, puis il appelle `PrixMedicamentService` — **le même service que le
 * pharmacien qui saisit au portail**. Les bornes de plausibilité du prix, la vérification que
 * l'établissement est bien une pharmacie, la forme du relevé : tout cela existe déjà et n'est pas
 * réécrit ici. Le réécrire aurait produit deux façons d'enregistrer un prix, qui auraient divergé
 * — et du côté qu'on regarde le moins, celui qu'aucun humain n'ouvre jamais.
 *
 * La seule chose que l'ingestion ajoute est sa **provenance** : `logiciel_officine`, distincte de
 * la saisie au portail et du signalement citoyen. Un relevé ne doit jamais mentir sur d'où il
 * vient (précédent `provenance` de P6.8d, `source` de P7-C, `origine` de P10c-1).
 *
 * ═══ LE SERVEUR NE DEVINE JAMAIS ═══
 *
 * Une référence inconnue est **refusée et nommée**, jamais rapprochée par ressemblance de
 * libellé. Le précédent est P6.8c, où rapprocher une maladie d'un texte libre aurait été un
 * diagnostic posé par une machine ; ici l'enjeu est plus direct encore — *se tromper de produit
 * sur un stock enverrait un patient chercher la mauvaise boîte*.
 *
 * La correspondance se déclare d'une seule façon : le partenaire envoie **une fois** notre code
 * national à côté de sa référence. C'est une **affirmation d'équivalence de sa part**, pas une
 * déduction de la nôtre — et elle est retenue, de sorte que les envois suivants n'ont plus à la
 * répéter. C'est ce qui rend vraie la promesse du §7.7 : « le pharmacien n'a rien à ressaisir. »
 *
 * ═══ ACCEPTATION PARTIELLE, ET RAPPORT NOMINATIF ═══
 *
 * Un envoi de cinq cents lignes dont trois échouent doit écrire les quatre cent quatre-vingt-dix-
 * sept autres — les perdre rendrait l'intégration inutilisable au premier produit mal référencé.
 * Mais il doit **nommer les trois**, avec leur motif : « 3 refusées » n'aide personne à corriger
 * quoi que ce soit. C'est l'esprit de la catégorie `illisible` de P10c-3-ii — *jamais zéro, qui
 * fabriquerait une donnée*.
 */
class IngestionStockOfficine
{
    public const DOMAINE = 'stock_officine';

    public const SOURCE = 'logiciel_officine';

    public function __construct(
        private readonly PrixMedicamentService $prix,
    ) {}

    /**
     * Ingère un lot de lignes de stock.
     *
     * @param  array<int, array<string, mixed>>  $lignes
     * @return array{journal: JournalIngestion, rejeu: bool}
     */
    public function ingerer(ClientApi $client, array $lignes, ?string $idempotencyKey): array
    {
        // IDEMPOTENCE (précédent P5.1). Un partenaire qui rejoue après un délai réseau ne doit
        // pas écrire deux fois : on lui rend le rapport du premier envoi, à l'identique. La clé
        // est portée par le journal, dont l'unicité est garantie par le moteur.
        if ($idempotencyKey !== null) {
            $deja = JournalIngestion::query()
                ->where('client_api_id', $client->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($deja !== null) {
                return ['journal' => $deja, 'rejeu' => true];
            }
        }

        $structure = $client->structure;
        $acceptees = 0;
        $refus = [];

        foreach ($lignes as $index => $ligne) {
            $reference = (string) ($ligne['reference'] ?? '');

            try {
                $medicament = $this->resoudre($client, $ligne);
                $this->ecrire($medicament, $structure, $ligne);
                $acceptees++;
            } catch (\Throwable $e) {
                // UN SEUL `catch`, et c'est une correction : la première écriture en avait deux,
                // dont un pour `ValidationException` qui lisait `errors()->flatten()->first()`.
                // La mutation p10 a survécu et l'a révélé — Laravel place déjà le premier message
                // d'erreur dans `getMessage()`, vérifié et non supposé, donc **les deux branches
                // produisaient exactement la même chose**. Une branche qui ne change rien est une
                // branche qu'un lecteur croira significative.
                $refus[] = ['index' => $index, 'reference' => $reference, 'motif' => $e->getMessage()];
            }
        }

        $journal = JournalIngestion::create([
            'client_api_id' => $client->id,
            'structure_id' => $structure->id,
            'domaine' => self::DOMAINE,
            'idempotency_key' => $idempotencyKey,
            'lignes_recues' => count($lignes),
            'lignes_acceptees' => $acceptees,
            'lignes_refusees' => count($refus),
            'refus_json' => $refus === [] ? null : $refus,
            'rejeu' => false,
        ]);

        return ['journal' => $journal, 'rejeu' => false];
    }

    /**
     * Résout la référence du partenaire vers un produit du référentiel national.
     *
     * Trois cas, et un seul écrit une correspondance :
     *  - le partenaire fournit `code_masante` → **il déclare** l'équivalence, on la retient ;
     *  - une correspondance existe déjà → on la lit ;
     *  - ni l'un ni l'autre → **refus nommé**, jamais un rapprochement par ressemblance.
     */
    private function resoudre(ClientApi $client, array $ligne): Medicament
    {
        $reference = trim((string) ($ligne['reference'] ?? ''));

        if ($reference === '') {
            throw ValidationException::withMessages(['reference' => ['Référence absente.']]);
        }

        $codeDeclare = trim((string) ($ligne['code_masante'] ?? ''));

        if ($codeDeclare !== '') {
            $medicament = Medicament::query()->where('code', $codeDeclare)->first();

            if ($medicament === null) {
                throw ValidationException::withMessages([
                    'code_masante' => [
                        'Le code national « '.$codeDeclare.' » ne désigne aucun produit du '
                        .'référentiel. Vérifiez-le : aucune correspondance n’est déduite.',
                    ],
                ]);
            }

            // `updateOrCreate` et non `create` : un partenaire qui corrige une équivalence
            // erronée doit pouvoir la remplacer, sinon la première déclaration serait
            // définitive et une faute de saisie exigerait notre intervention.
            CorrespondancePartenaire::updateOrCreate(
                [
                    'structure_id' => $client->structure_id,
                    'domaine' => 'medicament',
                    'reference_externe' => $reference,
                ],
                ['code_masante' => $medicament->code],
            );

            return $medicament;
        }

        $correspondance = CorrespondancePartenaire::query()
            ->where('structure_id', $client->structure_id)
            ->where('domaine', 'medicament')
            ->where('reference_externe', $reference)
            ->first();

        if ($correspondance === null) {
            throw ValidationException::withMessages([
                'reference' => [
                    'Référence « '.$reference.' » inconnue. Envoyez-la une fois avec '
                    .'`code_masante` pour déclarer son équivalence : le serveur ne la devine pas.',
                ],
            ]);
        }

        $medicament = Medicament::query()->where('code', $correspondance->code_masante)->first();

        if ($medicament === null) {
            // La correspondance existe mais désigne un code qui n'est plus au référentiel. On le
            // dit tel quel plutôt que de la supprimer : c'est une déclaration du partenaire, et
            // l'effacer effacerait la trace de ce qu'il avait affirmé.
            throw ValidationException::withMessages([
                'reference' => [
                    'La correspondance déclarée pointe vers « '.$correspondance->code_masante
                    .' », absent du référentiel.',
                ],
            ]);
        }

        return $medicament;
    }

    /**
     * Écrit le relevé PAR LE SERVICE EXISTANT — jamais en direct.
     *
     * Une quantité nulle et une indisponibilité déclarée sont la même chose : le rayon est vide.
     * On appelle alors `signalerRupture`, qui est le chemin que FN8 a déjà prouvé, plutôt que
     * d'inventer un troisième état.
     */
    private function ecrire(Medicament $medicament, $structure, array $ligne): void
    {
        $quantite = array_key_exists('quantite', $ligne) && $ligne['quantite'] !== null
            ? (int) $ligne['quantite']
            : null;

        $disponible = $ligne['disponible'] ?? ($quantite === null ? true : $quantite > 0);
        $prix = array_key_exists('prix_cfa', $ligne) && $ligne['prix_cfa'] !== null
            ? (int) $ligne['prix_cfa']
            : null;

        if ($quantite !== null && $quantite < 0) {
            throw ValidationException::withMessages(['quantite' => ['Quantité négative.']]);
        }

        if (! $disponible) {
            $releve = $this->prix->signalerRupture($medicament, $structure, self::SOURCE);
            $this->porterLaQuantite($releve, $quantite);

            return;
        }

        if ($prix === null) {
            throw ValidationException::withMessages([
                'prix_cfa' => ['Un produit déclaré disponible doit porter son prix.'],
            ]);
        }

        $releve = $this->prix->releverPrix($medicament, $structure, $prix, self::SOURCE);
        $this->porterLaQuantite($releve, $quantite);
    }

    /**
     * La quantité est écrite APRÈS coup, et c'est délibéré : `PrixMedicamentService` est un
     * service validé, et lui ajouter un paramètre pour un seul appelant l'aurait fait porter une
     * notion dont ses trois autres appelants n'ont rien à faire. La colonne est additive, la
     * ligne vient d'être écrite, et `null` reste `null` — un relevé sans quantité n'en invente pas.
     */
    private function porterLaQuantite(PrixPharmacie $releve, ?int $quantite): void
    {
        if ($quantite === null) {
            return;
        }

        DB::table('prix_pharmacie')->where('id', $releve->id)->update(['quantite' => $quantite]);
    }
}
