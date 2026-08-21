<?php

namespace App\Services\Audit;

use App\Models\AuditChaine;
use App\Services\Pki\JournalSignature;
use App\Services\Protocole\JournalApplicationProtocole;
use App\Services\Protocole\JournalProtocole;
use App\Services\Referentiel\EmpreinteReferentiel;
use App\Services\Referentiel\JournalReferentiel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Le moteur commun des chaînes d'audit : numérotation, vérification, ouverture.
 *
 * ═══ LE DÉFAUT QUE CETTE CLASSE EXISTE POUR FERMER ═══
 *
 * Jusqu'ici, chaque journal vérifiait sa chaîne en partant de `$attendue = null` et acceptait donc
 * **n'importe quelle première entrée**. Conséquence mesurée le 2026-08-20 sur la base de
 * développement : `referentiel_journal` ne contenait plus que 3 entrées (ids 98→100, compteur à
 * 101), les 97 précédentes ayant été supprimées par des restaurations de G2 successives — et la
 * vérification répondait `intacte: true`. `signature_journal` était vide, compteur à 6, « intacte »
 * également.
 *
 * **Une chaîne tronquée par la tête était indétectable.** On ne pouvait pas distinguer une chaîne
 * neuve d'une chaîne dont on avait effacé toute l'histoire. C'était plus grave que la rupture
 * bruyante des protocoles, parce que c'était muet.
 *
 * ═══ CE QUI LE FERME : UNE CHAÎNE DÉCLARE SON ORIGINE ═══
 *
 * Une chaîne porte un numéro (colonne `chaine`), et son ouverture est **déclarée** dans
 * {@see AuditChaine}. Sans déclaration, la vérification ne dit plus « intacte » : elle dit
 * « origine non déclarée », c'est-à-dire *je ne peux pas affirmer que rien n'a été retiré en tête*.
 *
 * ═══ L'ORDRE DES VERDICTS EST DÉLIBÉRÉ ═══
 *
 * Une rupture `CONTENU` ou `CHAINAGE` l'emporte sur l'absence d'origine dans le champ `rupture` :
 * elle est **plus précise**, et c'est elle qu'un humain doit lire en premier en cas de litige.
 * L'absence d'origine reste rendue à part (`origine_declaree`) : les deux faits coexistent, aucun
 * ne masque l'autre. Même raisonnement que l'ordre des cinq contrôles du §5.4 en P6.5b — un
 * certificat révoqué ET expiré doit être refusé POUR RÉVOCATION.
 */
final class ChaineAudit
{
    /**
     * Liste blanche fermée des journaux chaînés.
     *
     * Le nom arrive par la ligne de commande : sans liste blanche, il deviendrait un nom de table
     * choisi par l'appelant (précédent P7-C, `RegistreSectionsCarnet`).
     *
     * @var array<string, class-string<JournalChaine>>
     */
    public const JOURNAUX = [
        'referentiel_journal' => JournalReferentiel::class,
        'protocole_journal' => JournalProtocole::class,
        'signature_journal' => JournalSignature::class,
        'protocole_applications' => JournalApplicationProtocole::class,
    ];

    /**
     * Motif inscrit par la migration quand un journal est vide au moment de l'installation.
     *
     * ═══ CE QUE CETTE DÉCLARATION NE PROUVE PAS, ET IL FAUT LE DIRE ═══
     *
     * Un journal vide peut n'avoir jamais servi — ou avoir été vidé. Rien ne les distingue de
     * façon portable : le compteur d'auto-incrément le dirait sous MySQL et pas sous SQLite, donc
     * la garantie serait plus forte en production qu'en test — la divergence exacte refusée en
     * P6.8c (collation) et P6.8e (REGEXP).
     *
     * La conséquence est assumée et écrite : **ce mécanisme protège à partir du jour où il est
     * installé, il ne témoigne pas du passé.** L'ancrage de tête, lui, attrape toute troncature
     * postérieure.
     */
    public const MOTIF_INSTALLATION = "Ouverture à l'installation du mécanisme : le journal était "
        ."vide. L'absence d'entrées ne prouve pas qu'il n'en a jamais porté — cette chaîne ne "
        .'témoigne que de ce qui suit.';

    public static function connu(string $journal): bool
    {
        return isset(self::JOURNAUX[$journal]);
    }

    /** @return list<string> */
    public static function noms(): array
    {
        return array_keys(self::JOURNAUX);
    }

    /**
     * Numéro de la chaîne dans laquelle on écrit aujourd'hui.
     *
     * La déclaration fait autorité quand elle existe : c'est elle qui matérialise un
     * recommencement. À défaut, on suit ce que porte le journal, et 1 si tout est vide.
     */
    public static function numeroCourant(string $journal, Builder $requete): int
    {
        $declare = AuditChaine::query()->where('journal', $journal)->max('numero');

        if ($declare !== null) {
            return (int) $declare;
        }

        $vu = $requete->clone()->max('chaine');

        return $vu !== null ? (int) $vu : 1;
    }

    /**
     * Ancre la tête d'une chaîne : l'empreinte de sa toute première entrée, inscrite une fois.
     *
     * ═══ LE TROU QUE CECI FERME, ET IL EST EXACTEMENT CELUI QUI S'EST PRODUIT ═══
     *
     * Sans ancrage, une chaîne déclarée à l'installation puis **vidée et réalimentée** se
     * revérifierait « intacte » : les nouvelles entrées repartent d'une empreinte précédente nulle,
     * la déclaration existe toujours, et rien ne dit que 97 entrées ont disparu entre-temps. C'est
     * le scénario mesuré sur `referentiel_journal` (ids 98→100, compteur à 101).
     *
     * L'ancrage est écrit **une seule fois**, à la première entrée. Ce n'est donc pas un miroir
     * entretenu à chaque écriture — un tel miroir serait une seconde vérité à maintenir, et
     * pourrait diverger.
     */
    public static function ancrer(string $journal, int $numero, string $empreinte): void
    {
        AuditChaine::query()
            ->where('journal', $journal)
            ->where('numero', $numero)
            ->whereNull('empreinte_premiere')
            ->update(['empreinte_premiere' => $empreinte]);
    }

    public static function origineDeclaree(string $journal, int $numero): bool
    {
        return AuditChaine::query()
            ->where('journal', $journal)
            ->where('numero', $numero)
            ->exists();
    }

    /**
     * Vérifie la chaîne courante et rend compte des chaînes scellées.
     *
     * @return array{intacte: bool, entrees: int, rupture: ?array<string, mixed>, chaine_courante: int, origine_declaree: bool, origine_conforme: bool, chaines_scellees: list<array<string, mixed>>}
     */
    public static function verifier(JournalChaine $journal): array
    {
        $nom = $journal->nomJournal();
        $courante = self::numeroCourant($nom, $journal->requete());

        $etat = self::verifierUneChaine($journal, $courante);

        return [
            'intacte' => $etat['intacte'],
            'entrees' => $etat['entrees'],
            'rupture' => $etat['rupture'],
            'chaine_courante' => $courante,
            'origine_declaree' => $etat['origine_declaree'],
            'origine_conforme' => $etat['origine_conforme'],
            'chaines_scellees' => self::chainesScellees($journal, $courante),
        ];
    }

    /**
     * @return array{intacte: bool, entrees: int, rupture: ?array<string, mixed>, origine_declaree: bool, origine_conforme: bool}
     */
    private static function verifierUneChaine(JournalChaine $journal, int $numero): array
    {
        $nom = $journal->nomJournal();

        $declaration = AuditChaine::query()
            ->where('journal', $nom)
            ->where('numero', $numero)
            ->first();

        $declaree = $declaration !== null;

        $attendue = null;
        $premiere = null;
        $nombre = 0;
        $rupture = null;

        foreach ($journal->requete()->where('chaine', $numero)->orderBy('id')->cursor() as $entree) {
            $nombre++;

            if ($nombre === 1) {
                $premiere = $entree->empreinte;
            }

            if ($entree->empreinte_precedente !== $attendue) {
                $rupture = [
                    'id' => $entree->id,
                    'type' => 'CHAINAGE',
                    'message' => "L'entrée #{$entree->id} ne s'enchaîne pas au maillon précédent "
                        .'(entrée supprimée ou insérée hors du moteur).',
                ];
                break;
            }

            $recalculee = EmpreinteReferentiel::duMaillon(
                $entree->empreinte_precedente,
                $journal->charge($entree),
            );

            if (! hash_equals($entree->empreinte, $recalculee)) {
                $rupture = [
                    'id' => $entree->id,
                    'type' => 'CONTENU',
                    'message' => "L'entrée #{$entree->id} a été modifiée après son écriture.",
                ];
                break;
            }

            $attendue = $entree->empreinte;
        }

        $ancre = $declaration?->empreinte_premiere;
        $conforme = $declaree && ($ancre === null || ($premiere !== null && hash_equals($ancre, $premiere)));

        // L'absence — ou la non-conformité — de l'origine ne devient LA rupture que si rien de plus
        // précis n'a été trouvé. Une rupture CONTENU ou CHAINAGE désigne une entrée par son
        // identifiant : c'est elle qu'un humain doit lire en premier.
        if ($rupture === null && ! $conforme) {
            $rupture = [
                'id' => null,
                'type' => 'ORIGINE',
                'message' => $declaree
                    ? "La chaîne #{$numero} de « {$nom} » ne commence plus par l'entrée qui y a été "
                        .'ancrée : sa tête a été supprimée ou remplacée.'
                    : "La chaîne #{$numero} de « {$nom} » ne déclare pas son origine : des entrées "
                        .'ont pu être supprimées en tête, et rien ne permettrait de le voir.',
            ];
        }

        return [
            'intacte' => $rupture === null,
            // ═══ LE VOLUME EST CELUI DE LA CHAÎNE, PAS CELUI DU PARCOURS ═══
            //
            // La boucle s'arrête à la première rupture : compter les tours dirait « 1 entrée » d'une
            // chaîne qui en porte 34, et le scellement inscrirait ce chiffre dans le marbre. Défaut
            // trouvé au G2 live, invisible en test parce que les chaînes y sont courtes.
            'entrees' => $journal->requete()->where('chaine', $numero)->count(),
            'rupture' => $rupture,
            'origine_declaree' => $declaree,
            'origine_conforme' => $conforme,
        ];
    }

    /**
     * Les chaînes closes, avec DEUX verdicts.
     *
     * Celui du scellement est figé — c'est ce que l'opérateur a constaté ce jour-là. Celui
     * d'aujourd'hui est recalculé : une altération postérieure au scellement doit rester visible,
     * sinon sceller reviendrait à mettre l'ancien à l'abri du contrôle.
     *
     * @return list<array<string, mixed>>
     */
    private static function chainesScellees(JournalChaine $journal, int $courante): array
    {
        $nom = $journal->nomJournal();

        $declarations = AuditChaine::query()
            ->where('journal', $nom)
            ->orderBy('numero')
            ->get()
            ->keyBy('numero');

        $scellees = [];

        for ($numero = 1; $numero < $courante; $numero++) {
            $etat = self::verifierUneChaine($journal, $numero);

            // La déclaration de la chaîne SUIVANTE porte le constat de scellement de celle-ci.
            $suivante = $declarations->get($numero + 1);

            $scellees[] = [
                'numero' => $numero,
                'entrees' => $etat['entrees'],
                'scellee_le' => $suivante?->cree_le?->toIso8601String(),
                'scellee_par' => $suivante?->acteur_nom,
                'motif' => $suivante?->motif,
                'verdict_au_scellement' => $suivante?->verdict_scelle_json,
                'verdict_actuel' => [
                    'intacte' => $etat['intacte'],
                    'rupture' => $etat['rupture'],
                ],
            ];
        }

        return $scellees;
    }

    /**
     * Scelle la chaîne courante et en ouvre une neuve.
     *
     * Le motif est OBLIGATOIRE et sans valeur par défaut : un scellement sans raison écrite serait
     * un effacement d'historique déguisé en opération de maintenance (précédent de la commission
     * sans seed, P5.5a — l'absence doit échouer bruyamment, jamais valoir zéro en silence).
     *
     * @throws RuntimeException si la chaîne courante est vide (ouvrir une chaîne qui n'a jamais
     *                          commencé n'a pas de sens) ou si le motif est vide.
     */
    public static function ouvrir(JournalChaine $journal, string $motif, string $acteurNom): AuditChaine
    {
        $motif = trim($motif);

        if ($motif === '') {
            throw new RuntimeException('Un scellement exige un motif écrit.');
        }

        $nom = $journal->nomJournal();
        $courante = self::numeroCourant($nom, $journal->requete());

        $etat = self::verifierUneChaine($journal, $courante);

        if ($etat['entrees'] === 0) {
            throw new RuntimeException(
                "La chaîne #{$courante} de « {$nom} » est vide : il n'y a rien à sceller."
            );
        }

        $derniere = $journal->requete()
            ->where('chaine', $courante)
            ->orderByDesc('id')
            ->first();

        return AuditChaine::create([
            'journal' => $nom,
            'numero' => $courante + 1,
            'motif' => $motif,
            'acteur_nom' => $acteurNom,
            'entrees_scellees' => $etat['entrees'],
            'empreinte_scellee' => $derniere?->empreinte,
            // ═══ LE POINT QUI REND LE GESTE HONNÊTE ═══
            //
            // On n'ouvre pas une chaîne neuve en tournant la page : on inscrit dans son premier
            // acte l'état exact de celle qu'on ferme. Une chaîne rompue est scellée EN TANT QUE
            // rompue, et le marbre neuf le dit.
            'verdict_scelle_json' => [
                'intacte' => $etat['intacte'],
                'rupture' => $etat['rupture'],
                'origine_declaree' => $etat['origine_declaree'],
            ],
            'cree_le' => Carbon::now(),
        ]);
    }
}
