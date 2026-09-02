<?php

namespace App\Services\Triage;

use App\Models\ExportJeuEntrainement;
use App\Models\JeuDonneesEntrainement;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * P10c-3-i (F17/F20) — L'export anonymisant (CDC_05 §7.2 ; CDC_13 §12).
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * C'EST ICI QUE L'ANONYMISATION DEVIENT EFFECTIVE — PAS DANS `jeux_donnees_entrainement`
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * P10c-2-i l'avait dit avant de coder : la table source reste PSEUDONYMISÉE pour toujours
 * (`triage_id` y sert à l'idempotence et à la traçabilité §9.2). {@see anonymiser()} construit une
 * ligne NEUVE, par LISTE BLANCHE explicite — jamais `$ligne->toArray()` moins quelques clés, qui
 * laisserait une future colonne fuiter silencieusement dans un export qu'on croit anonyme.
 *
 * ═══ CE QUI EST GÉNÉRALISÉ, ET CE QUI NE L'EST PAS (F20) ═══
 *
 * L'âge devient une BANDE (`config('masante.triage_ia.bandes_age')`) et la date un MOIS-ANNÉE :
 * ce sont les deux quasi-identifiants, ceux qui se recoupent avec une source externe pour
 * ré-identifier quelqu'un. Les constantes cliniques et les symptômes restent à leur précision :
 * les généraliser détruirait le signal que le modèle doit apprendre, et ce ne sont pas, seuls, des
 * quasi-identifiants au sens usuel.
 *
 * ═══ `k_estime` — CALCULÉ ET AFFICHÉ, JAMAIS BLOQUANT ═══
 *
 * La taille du plus petit groupe (bande d'âge, sexe, mois-année). Sur un volume de dizaines de
 * lignes, un seuil de k-anonymat bloquant rendrait l'export perpétuellement impossible sans protéger
 * personne de plus — motif P6.7a (« un contrôle qu'on ne peut pas satisfaire n'est pas une
 * exigence, c'est un mur »).
 *
 * ═══ PAYS_CODE : UNE LIMITE HÉRITÉE, DITE ═══
 *
 * `jeux_donnees_entrainement` ne porte AUCUNE colonne pays — ce projet est CI-only en pratique pour
 * ce chemin, malgré le schéma multi-pays d'`exports_jeu_entrainement`/`versions_modeles`. L'export
 * est donc stampé du pays par défaut (`config('referentiels.pays_defaut')`), jamais filtré : il n'y
 * a rien à filtrer. Ajouter cette colonne à la table source serait une migration hors du périmètre
 * validé de cet incrément (F14 ne portait que sur `score_antecedents`).
 *
 * ═══ HABILITATION : `ia_triage.valider`, MÊME PERMISSION QUE LA PROMOTION (F18/F19) ═══
 *
 * L'écran de gouvernance de P10c-3-i est une seule surface (voir / exporter+entraîner / valider) :
 * la fractionner en plusieurs permissions pour ses trois actions n'a été ni demandé ni justifié par
 * un risque de juge-et-partie propre à CHACUNE — motif `apprentissage.valider`, qui garde de la
 * même façon la liste, la validation ET le rejet d'un contrôleur entier.
 */
class ServiceExportJeuEntrainement
{
    public const PERMISSION = 'ia_triage.valider';

    public function __construct(private readonly ServiceValidationApprentissage $validation) {}

    public function exporter(User $auteur, string $paysCode): ExportJeuEntrainement
    {
        $this->exigerHabilitation($auteur);

        $lignes = $this->validation->pretsPourExport()->get();
        $anonymisees = $lignes->map(fn (JeuDonneesEntrainement $ligne) => $this->anonymiser($ligne))->values()->all();

        return DB::transaction(function () use ($paysCode, $anonymisees, $auteur): ExportJeuEntrainement {
            return ExportJeuEntrainement::create([
                'pays_code' => $paysCode,
                'numero_export' => $this->prochainNumero($paysCode),
                'instantane_json' => $anonymisees,
                'nb_lignes' => count($anonymisees),
                'k_estime' => $this->kEstime($anonymisees),
                'cree_par' => $auteur->id,
                'cree_le' => now(),
            ]);
        });
    }

    /**
     * Une ligne, ANONYMISÉE — liste blanche explicite, aucune colonne source recopiée telle quelle.
     *
     * @return array<string, mixed>
     */
    private function anonymiser(JeuDonneesEntrainement $ligne): array
    {
        return [
            'bande_age' => $this->bandePour($ligne->age),
            'sexe' => $ligne->sexe,
            // `symptomes_json` porte {id, nom, poids} par ligne (forme de `TriageService::analyser()`)
            // — seul l'identifiant sort d'ici : le nom du symptôme n'ajoute rien à un vecteur de
            // features qui ne compte que `nb_symptomes` (motif `features.py` côté Python).
            'symptomes' => array_map(
                static fn (array $s): int => (int) $s['id'],
                $ligne->symptomes_json ?? [],
            ),
            'constantes' => [
                'temperature' => $ligne->temperature !== null ? (float) $ligne->temperature : null,
                'pouls' => $ligne->pouls !== null ? (float) $ligne->pouls : null,
                'saturation_o2' => $ligne->saturation_o2 !== null ? (float) $ligne->saturation_o2 : null,
                'tension_systolique' => $ligne->tension_systolique !== null ? (float) $ligne->tension_systolique : null,
                'tension_diastolique' => $ligne->tension_diastolique !== null ? (float) $ligne->tension_diastolique : null,
                'poids' => $ligne->poids !== null ? (float) $ligne->poids : null,
            ],
            'duree_jours' => $ligne->duree_jours !== null ? (int) $ligne->duree_jours : null,
            'intensite' => $ligne->intensite !== null ? (int) $ligne->intensite : null,
            'grossesse' => $ligne->grossesse,
            'score_antecedents' => $ligne->score_antecedents !== null ? (int) $ligne->score_antecedents : null,
            // Métadonnée d'audit pour le relecteur humain — jamais envoyée comme feature au
            // service Python (D3 du P10c-2-i étendu ici : `niveau_protocole` ne doit jamais entrer
            // dans un vecteur, à l'entraînement pas plus qu'au service, sous peine de recréer
            // exactement le décalage train/serve que Y4 signale).
            'niveau_protocole' => $ligne->niveau_protocole,
            'label' => $ligne->label,
            // P10c-3-ii (F32/F35) — les trois faits captés. Ce sont des CIBLES : aucune tête ne
            // les apprend dans ce lot, mais les faire sortir de l'export dès maintenant évite
            // qu'un futur entraînement doive rejouer tous les exports pour les retrouver.
            'niveau_reel' => $ligne->niveau_reel,
            'maladie_code' => $ligne->maladie_code,
            'specialite_code' => $ligne->specialite_code,
            'annee_mois' => optional($ligne->cree_le)->format('Y-m'),
        ];
    }

    /** La bande d'âge (config, JAMAIS en dur) — `null` pour un âge inconnu, jamais une bande devinée. */
    public function bandePour(?int $age): ?string
    {
        if ($age === null) {
            return null;
        }

        foreach (config('masante.triage_ia.bandes_age', []) as $bande) {
            if ($age >= $bande['min'] && $age <= $bande['max']) {
                return $bande['label'];
            }
        }

        return null;
    }

    /**
     * Le plus petit groupe — voir l'en-tête sur le sens de ce chiffre et pourquoi il n'est jamais
     * bloquant.
     *
     * ═══ P10c-3-ii (F35) — LE DIAGNOSTIC ENTRE DANS LA CLÉ, ET IL LE FALLAIT ═══
     *
     * « Femme, 25-44 ans, août 2026 » n'identifie personne. « Femme, 25-44 ans, août 2026,
     * maladie X » peut n'être qu'une personne si X est rare — un label rare est **identifiant**,
     * même quand il n'est pas un quasi-identifiant au sens usuel.
     *
     * Ignorer le diagnostic ne rendrait pas l'export plus sûr : ça rendrait le CHIFFRE faux. Il
     * annoncerait un k confortable en laissant hors de son calcul la colonne la plus discriminante
     * de la ligne — et un indicateur qui rassure à tort est pire qu'un indicateur absent.
     *
     * Le chiffre reste **calculé et affiché, jamais bloquant** (motif P6.7a inchangé) : sur des
     * dizaines de lignes, un seuil rendrait l'export perpétuellement impossible sans protéger
     * personne de plus. Conséquence attendue et dite : dès qu'un diagnostic est renseigné, `k`
     * baisse — ce n'est pas une régression, c'est la mesure qui cesse de mentir.
     *
     * @param  array<int, array<string, mixed>>  $lignesAnonymisees
     */
    public function kEstime(array $lignesAnonymisees): ?int
    {
        if ($lignesAnonymisees === []) {
            return null;
        }

        $groupes = [];
        foreach ($lignesAnonymisees as $ligne) {
            $cle = implode('|', [
                $ligne['bande_age'] ?? '?',
                $ligne['sexe'] ?? '?',
                $ligne['annee_mois'] ?? '?',
                $ligne['maladie_code'] ?? '?',
            ]);
            $groupes[$cle] = ($groupes[$cle] ?? 0) + 1;
        }

        return min($groupes);
    }

    private function prochainNumero(string $paysCode): int
    {
        return (int) ExportJeuEntrainement::query()->where('pays_code', $paysCode)->max('numero_export') + 1;
    }

    /** Même garde que {@see ServiceGouvernanceModeleIa}, vérifiée ICI aussi. */
    private function exigerHabilitation(User $utilisateur): void
    {
        if (! $utilisateur->can(self::PERMISSION)) {
            throw new \RuntimeException(
                'Cette action exige l\'habilitation « '.self::PERMISSION.' », accordée nominativement '
                .'(CDC_05 §9 : aucun modèle influençant une décision de soins sans validation humaine).'
            );
        }
    }
}
