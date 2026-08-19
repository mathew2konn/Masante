<?php

namespace App\Services\Triage;

use App\Models\StructureSanitaire;
use App\Models\Triage;
use App\Models\User;
use App\Services\StructureService;

/**
 * P10a — La fiche de triage du §5.4 (« livrable obligatoire »).
 *
 * ═══ CE QUE LE §5.4 EXIGE, ET CE QUI MANQUAIT ═══
 *
 * « Numéro de triage, date et heure, symptômes déclarés, **réponses au questionnaire**, niveau de
 * priorité, recommandation, service recommandé, **hôpitaux proches proposant ce service**, **QR
 * Code** permettant au médecin d'accéder au triage, et la **mention obligatoire**. »
 *
 * Le G0 a compté : sur ces dix éléments, **quatre manquaient** — les réponses, les hôpitaux, le QR
 * et la mention. La fiche existante (F1.8) en portait six.
 *
 * Reste hors périmètre : le **PDF**. Il exigerait une dépendance Composer, donc l'accord écrit du
 * propriétaire (§2.6) — décision D6 : la fiche est livrée sans, et le manque est nommé plutôt
 * qu'improvisé.
 *
 * ═══ FRONTIÈRE : CE SERVICE NE DÉCIDE DE RIEN ═══
 *
 * Il assemble. Le niveau, le score, la recommandation et les codes d'orientation ont été **calculés
 * et estampillés** au moment du triage ; ils sont relus tels quels. Les hôpitaux sont une
 * **résolution** (quels établissements déclarent ce service ?), pas un jugement clinique. La
 * question de fin de module — « quelles règles métier ce module calcule-t-il ? » — a ici sa réponse
 * obligatoire : *aucune*.
 */
final class ServiceFicheTriage
{
    /**
     * Combien d'établissements par spécialité au maximum.
     *
     * BORNE DITE, JAMAIS SILENCIEUSE : le §5.4 veut « les hôpitaux proches », pas l'annuaire
     * entier ; et le mobile devra calculer un temps de trajet par établissement (D4), ce qui borne
     * naturellement le nombre d'appels. La réponse porte `tronquee` pour que l'écran puisse dire
     * qu'il en existe d'autres — *une troncature silencieuse se lit comme « il n'y a que ça »*
     * (leçon des caps non journalisés).
     */
    public const MAX_PAR_SPECIALITE = 5;

    /** Rayon de recherche par défaut, en kilomètres. Donnée, pas littéral enfoui. */
    public const RAYON_KM = 30.0;

    public function __construct(private readonly StructureService $structures) {}

    /**
     * @param  array{lat?: float|null, lng?: float|null}  $position
     * @return array<string, mixed>
     */
    public function composer(Triage $triage, array $position = []): array
    {
        $niveaux = [
            'leger'  => ['libelle' => 'LÉGER',  'couleur' => 'vert'],
            'modere' => ['libelle' => 'MODÉRÉ', 'couleur' => 'orange'],
            'urgent' => ['libelle' => 'URGENT', 'couleur' => 'rouge'],
        ];

        $niveau = $niveaux[$triage->niveau] ?? ['libelle' => strtoupper((string) $triage->niveau), 'couleur' => 'gris'];

        $orientations = $this->orientations($triage);

        return [
            'triage_id'      => $triage->id,
            'date'           => $triage->created_at->toIso8601String(),

            'patient' => [
                'nom'  => $triage->patient_nom,
                'age'  => $triage->patient_age,
                'sexe' => $triage->patient_sexe,
            ],

            'symptomes'      => $triage->symptomes_json,

            // ═══ LES RÉPONSES AU QUESTIONNAIRE — EXIGÉES PAR LE §5.4, JAMAIS AFFICHÉES JUSQU'ICI ═══
            //
            // Elles étaient stockées depuis le Module 1 (`reponses_json`) et ne sortaient nulle
            // part. Or c'est ce qui permet à un soignant de comprendre POURQUOI le score est ce
            // qu'il est : sans elles, la fiche affirme un niveau sans montrer sur quoi il repose.
            //
            // `valeur_impact` est portée telle quelle : elle vient du référentiel publié, elle est
            // donc explicable et rejouable. On ne la recalcule pas ici — ce serait un second
            // calcul, donc une seconde vérité.
            'reponses'       => $triage->reponses_json ?? [],

            'score_severite' => $triage->score_severite,
            'niveau'         => $triage->niveau,
            'niveau_libelle' => $niveau['libelle'],
            'couleur'        => $niveau['couleur'],

            'recommandation_texte' => $triage->recommandation_texte,

            // Le service recommandé. `specialite_requise` reste l'affichage hérité ; `specialites`
            // porte les codes, dans l'ordre décidé par le référentiel publié.
            'specialite_requise' => $triage->specialite_requise,
            'specialites'        => $orientations,

            'etablissements' => $this->etablissementsProches($orientations, $position),

            // La version qui a gouverné CE triage. Nulle pour les triages antérieurs à P10a : ils
            // n'ont eu aucune version en vigueur, et leur en attribuer une serait un mensonge
            // d'archive (précédent L1+L2).
            'referentiel_version' => $triage->referentiel_version,

            'mention_obligatoire' => Triage::MENTION_OBLIGATOIRE,
        ];
    }

    /**
     * Le texte prêt à partager (WhatsApp), F1.8 — enrichi de ce que le §5.4 impose.
     *
     * @param  array<string, mixed>  $fiche
     */
    public function textePartage(array $fiche): string
    {
        $noms = collect($fiche['symptomes'] ?? [])->pluck('nom')->implode(', ');

        $lignes = [
            '🏥 MaSante — Fiche de triage n°'.$fiche['triage_id'],
            'Patient : '.($fiche['patient']['nom'] ?: 'N/C')
                .($fiche['patient']['age'] !== null ? ', '.$fiche['patient']['age'].' ans' : '')
                .($fiche['patient']['sexe'] ? ', '.$fiche['patient']['sexe'] : ''),
            'Symptômes : '.($noms ?: 'N/C'),
            'Niveau : '.$fiche['niveau_libelle'].' (score '.$fiche['score_severite'].'/100)',
        ];

        if ($fiche['specialites'] !== []) {
            $lignes[] = 'Orientation : '.collect($fiche['specialites'])->pluck('libelle')->implode(', ');
        }

        $lignes[] = 'Recommandation : '.$fiche['recommandation_texte'];

        // LA MENTION EST DANS LE TEXTE PARTAGÉ, PAS SEULEMENT DANS L'ÉCRAN. C'est justement le
        // texte qui voyage hors de l'application, là où plus rien n'en rappelle la nature.
        $lignes[] = '';
        $lignes[] = Triage::MENTION_OBLIGATOIRE;

        return implode("\n", $lignes);
    }

    /**
     * Les orientations telles que le triage les a ENREGISTRÉES.
     *
     * Relues, jamais recalculées : rejouer l'agrégation aujourd'hui pourrait donner un autre
     * résultat (le référentiel a pu être republié), et la fiche cesserait de décrire la décision
     * qui a réellement été rendue. Même principe que les valeurs figées d'une ordonnance (P6.6b).
     *
     * @return array<int, array{code: string, libelle: string}>
     */
    private function orientations(Triage $triage): array
    {
        return collect($triage->specialites_json ?? [])
            ->filter(fn ($o): bool => is_array($o) && ! empty($o['code']))
            ->map(fn (array $o): array => [
                'code'    => (string) $o['code'],
                'libelle' => (string) ($o['libelle'] ?? $o['code']),
            ])
            ->values()
            ->all();
    }

    /**
     * « Hôpitaux proches proposant ce service » (§5.4).
     *
     * ═══ UN GROUPE PAR SPÉCIALITÉ, DANS L'ORDRE DES RANGS ═══
     *
     * Fusionner les listes ferait perdre la raison pour laquelle chaque établissement est proposé :
     * le patient verrait une liste plate sans savoir lequel répond à quel besoin. L'ordre des
     * groupes est celui du référentiel — le rang, relu du triage.
     *
     * SANS POSITION, LA LISTE EXISTE QUAND MÊME : `StructureService` trie alors par partenaire puis
     * par nom. Un refus de géolocalisation ne doit pas supprimer l'information (motif V5 d'ADR-027 :
     * on ne déduit pas une position, mais on ne prive pas non plus).
     *
     * @param  array<int, array{code: string, libelle: string}>  $orientations
     * @param  array{lat?: float|null, lng?: float|null}  $position
     * @return array<int, array<string, mixed>>
     */
    private function etablissementsProches(array $orientations, array $position): array
    {
        $lat = $position['lat'] ?? null;
        $lng = $position['lng'] ?? null;

        $groupes = [];

        foreach ($orientations as $orientation) {
            $filtres = ['specialite' => $orientation['code']];

            if ($lat !== null && $lng !== null) {
                $filtres['lat'] = $lat;
                $filtres['lng'] = $lng;
                $filtres['rayon_km'] = self::RAYON_KM;
            }

            $trouves = $this->structures->rechercher($filtres);

            $groupes[] = [
                'specialite' => $orientation,
                'tronquee'   => $trouves->count() > self::MAX_PAR_SPECIALITE,
                'total'      => $trouves->count(),
                'etablissements' => $trouves
                    ->take(self::MAX_PAR_SPECIALITE)
                    ->map(fn (StructureSanitaire $s): array => [
                        'id'          => $s->id,
                        'nom'         => $s->nom,
                        'type'        => $s->type,
                        'commune'     => $s->commune,
                        'adresse'     => $s->adresse,
                        'telephone'   => $s->telephone,
                        'latitude'    => $s->latitude,
                        'longitude'   => $s->longitude,
                        'distance_km' => $s->getAttribute('distance_km'),
                        'statut_jour' => $s->getAttribute('statut_jour'),
                    ])
                    ->values()
                    ->all(),
            ];
        }

        return $groupes;
    }

    /**
     * Qui a le droit de lire cette fiche ?
     *
     * DEUX CHEMINS, ET AUCUN N'EST L'IDENTIFIANT SÉQUENTIEL :
     *   1. le **propriétaire authentifié** du triage ;
     *   2. la **détention du jeton** — c'est ce que scanne le médecin (§5.4), et ce que le patient
     *      envoie délibérément.
     *
     * Un triage anonyme n'a pas de propriétaire : seul le jeton l'ouvre. C'est cohérent avec le
     * choix du Module 1 de permettre un triage sans compte — on ne referme pas cette porte, on
     * cesse seulement de la laisser sans serrure.
     *
     * Comparaison en TEMPS CONSTANT : un jeton se compare comme un secret (précédent du principal
     * signé, P5.5b-1).
     */
    public function peutLire(Triage $triage, ?User $utilisateur, ?string $jeton): bool
    {
        if ($utilisateur !== null && $triage->user_id !== null && $triage->user_id === $utilisateur->id) {
            return true;
        }

        return $jeton !== null
            && $triage->jeton_partage !== null
            && hash_equals($triage->jeton_partage, $jeton);
    }
}
