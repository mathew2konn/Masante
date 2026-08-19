<?php

namespace App\Services\Triage;

use App\Models\SpecialiteMedicale;
use App\Models\Symptome;
use App\Services\Referentiel\DiffusionReferentiel;
use App\Services\Referentiel\ReferentielException;
use App\Services\Referentiel\SourceSymptomesTriage;
use Illuminate\Support\Collection;

/**
 * P10a — Le SEUL chemin de lecture des symptômes de triage (CDC_09 §10, CDC_05 §5).
 *
 * ═══ CE QU'IL REFERME : LA SECONDE MOITIÉ DU DÉFAUT DU G0 DE P6.3 ═══
 *
 * P6.3 avait constaté (F3) que `triages` ne stocke aucune version de protocole, alors que
 * CDC_04 §115 la prévoit : corriger un `poids_severite` rendait **tout triage antérieur
 * inexplicable**. Le socle a outillé le versionnage, mais rien ne le lisait — c'était la limite L1
 * d'ADR-025, dont le §5 disait qu'elle laissait le défaut « outillé mais pas refermé ».
 *
 * L1+L2 en a refermé la moitié le 2026-08-14 (`seuils_mesure`) et a laissé celle-ci à son foyer
 * nommé, **P10**, pour ne pas modifier deux fois un module validé G5. Nous y sommes.
 *
 * ═══ REFUS BRUYANT AVANT LA v1 — ET POURQUOI IL S'APPLIQUE ICI ═══
 *
 * Tant qu'aucune version n'est publiée, le triage répond **503**. Jamais un repli sur la table :
 * *un repli laisserait un oubli de publication passer inaperçu, et la garantie serait inactive sans
 * que personne ne le sache* (décision propriétaire de L1+L2, reprise telle quelle).
 *
 * La question méritait d'être reposée, parce que P6.8e vient précisément de NE PAS l'appliquer :
 * les numéros d'urgence se replient côté client, parce que leur consommateur n'a *ni réseau, ni
 * session, ni compte*. Le triage, lui, est un appel API — il n'existe pas sans réseau. L'argument
 * qui commandait l'exception à P6.8e ne s'applique pas ici ; c'est le précédent L1+L2 qui vaut.
 *
 * **Conséquence assumée et dite avant de coder** : la mise en vigueur de la v1 devient une étape de
 * DÉPLOIEMENT, faite par deux agents habilités. Elle ne peut pas venir d'un seeder — publier depuis
 * un seeder contournerait le quatre-yeux dès le premier jour (précédent `ReferentielRegistreSeeder`).
 *
 * ═══ LES MODÈLES SONT HYDRATÉS SANS EXISTER EN BASE ═══
 *
 * Motif exact de `MesureSanteService::charger()` : les casts, `questions_complementaires_json` et
 * la forme attendue par l'algorithme vivent sur le modèle, donc `TriageService` garde son contrat.
 * Ces instances n'ont pas d'`id` autogénéré et **ne doivent jamais être sauvegardées**.
 *
 * ═══ LA MÉMOÏSATION PINNE UNE VERSION PAR REQUÊTE, ET C'EST VOULU ═══
 *
 * Un triage lit ses symptômes, calcule et s'estampille : les trois doivent voir la même version.
 * Une publication survenant au milieu produirait un triage jugé par une version et estampillé par
 * une autre — précédent `MesureSanteService` (les deux lignes d'une tension jugées par les mêmes
 * seuils).
 */
final class ServiceSymptomesTriage
{
    /**
     * Le code du repli pédiatrique (§5.1.3), FOURNI à la classe de règles et non deviné par elle.
     *
     * Il appartient au vocabulaire de P6.8a. S'il en disparaissait, `ReglesOrientation` ne
     * renverrait rien plutôt que d'inventer un terme (précédent P6.4a) — et le contrôle qualité de
     * `SourceSpecialites` aurait de toute façon refusé la publication qui l'aurait retiré.
     */
    public const CODE_PEDIATRIE = 'pediatrie';

    /** Seuil pédiatrique du §5.1.3, en années. Donnée nommée, plus un littéral enfoui. */
    public const AGE_PEDIATRIE = 15;

    /** @var Collection<int, Symptome>|null */
    private ?Collection $symptomes = null;

    /** @var array<int, array<int, array{code: string, rang: int, sexe_requis: string|null}>> */
    private array $orientations = [];

    /**
     * code => libellé, tel que la version en vigueur l'a FIGÉ.
     *
     * Il ne vient pas de la table `specialites_medicales` : voir `SourceSymptomesTriage`. Un
     * renommage au vocabulaire ne change donc pas le texte lu par un patient tant que le
     * référentiel des symptômes n'est pas republié.
     *
     * @var array<string, string>
     */
    private array $libelles = [];

    private ?int $version = null;

    public function __construct(private readonly DiffusionReferentiel $diffusion) {}

    /**
     * La version qui gouverne cette requête — apposée sur le triage écrit
     * (§10 « toute décision conserve la version du référentiel utilisée »).
     */
    public function version(): int
    {
        $this->charger();

        return $this->version;
    }

    /**
     * Les symptômes ACTIFS de la version en vigueur, pour la sélection citoyenne (F1.1).
     *
     * L'instantané retient les symptômes inactifs — c'est ce qui rend un triage passé rejouable —
     * mais on ne propose évidemment que les actifs.
     *
     * @return Collection<int, Symptome>
     */
    public function actifs(): Collection
    {
        $this->charger();

        return $this->symptomes->filter(fn (Symptome $s): bool => $s->actif === true)->values();
    }

    /**
     * Les symptômes retenus par le citoyen, tels que la version en vigueur les définit.
     *
     * Un identifiant absent de la version publiée est IGNORÉ, exactement comme
     * `Symptome::actif()->whereIn(...)` ignorait un identifiant inconnu : le contrat ne change pas.
     *
     * @param  array<int, int|string>  $ids
     * @return Collection<int, Symptome>
     */
    public function retenus(array $ids): Collection
    {
        $this->charger();

        $voulus = array_flip(array_map('intval', $ids));

        return $this->symptomes
            ->filter(fn (Symptome $s): bool => $s->actif === true && isset($voulus[(int) $s->id]))
            ->values();
    }

    /**
     * Les orientations publiées des symptômes retenus, à plat, prêtes pour `ReglesOrientation`.
     *
     * À PLAT ET NON PAR SYMPTÔME : l'agrégation est le travail de la classe de règles, qui doit
     * pouvoir voir toutes les orientations ensemble pour garder le meilleur rang d'un code proposé
     * par plusieurs symptômes.
     *
     * @param  Collection<int, Symptome>  $symptomes
     * @return array<int, array{code: string, rang: int, sexe_requis: string|null}>
     */
    public function orientationsDe(Collection $symptomes): array
    {
        $this->charger();

        $plat = [];

        foreach ($symptomes as $s) {
            foreach ($this->orientations[(int) $s->id] ?? [] as $o) {
                $plat[] = $o;
            }
        }

        return $plat;
    }

    /**
     * Le libellé figé d'un code d'orientation, tel que la version en vigueur le porte.
     *
     * Renvoie le code lui-même en dernier recours — jamais `null` : un écran citoyen doit afficher
     * quelque chose, et un code technique reste plus honnête qu'une ligne vide. Le contrôle qualité
     * refuse de publier une orientation sans libellé, donc ce recours ne devrait jamais servir.
     */
    public function libelle(string $code): string
    {
        $this->charger();

        return $this->libelles[$code] ?? $code;
    }

    /**
     * Le libellé du repli pédiatrique — ET C'EST LE SEUL QUI NE SOIT PAS FIGÉ. Limite assumée.
     *
     * `pediatrie` n'est pas une orientation comme les autres : aucun symptôme ne l'annote, elle naît
     * de l'ÂGE (§5.1.3). Elle n'a donc aucun porteur dans l'instantané des symptômes, et son libellé
     * ne peut pas y être figé comme les autres.
     *
     * Il est lu **au vocabulaire**, table `specialites_medicales`. Je le dis plutôt que de le
     * déguiser : c'est une lecture directe, du même statut que `/v1/specialites` — la limite L1/L2
     * d'ADR-025 que P6.8a a explicitement déclarée pour son propre référentiel. Conséquence exacte :
     * renommer « Pédiatrie » au vocabulaire change le texte lu par un patient sans publication,
     * alors que renommer « Cardiologie » ne le change pas tant que les symptômes ne sont pas
     * republiés. Deux comportements différents pour deux libellés voisins — c'est inconfortable, et
     * c'est la vérité du modèle actuel.
     *
     * La RÈGLE, elle, n'est pas concernée : le code du repli et le seuil d'âge sont des constantes
     * nommées de cette classe, passées en paramètre à `ReglesOrientation`.
     */
    public function libellePediatrie(): string
    {
        $this->charger();

        if (isset($this->libelles[self::CODE_PEDIATRIE])) {
            return $this->libelles[self::CODE_PEDIATRIE];
        }

        return SpecialiteMedicale::query()
            ->where('pays_code', config('referentiels.pays_defaut', 'CI'))
            ->where('code', self::CODE_PEDIATRIE)
            ->value('libelle')
            // Dernier recours : le code. Jamais un libellé inventé.
            ?? self::CODE_PEDIATRIE;
    }

    /**
     * Le code du repli pédiatrique, ou `null` s'il n'est pas un terme VIVANT du vocabulaire.
     *
     * C'est ce qui donne son sens à la signature de `ReglesOrientation::agreger()`, où le code est
     * **fourni** et non deviné : si `pediatrie` était retiré ou désactivé au vocabulaire national,
     * la règle du §5.1.3 renverrait un terme que l'annuaire ne sait plus proposer. Mieux vaut ne
     * rien renvoyer — le triage retombe alors sur « médecine générale », qui est son comportement
     * de défaut depuis le Module 1 — que d'orienter vers une spécialité éteinte.
     *
     * Même lecture directe, donc même limite, que `libellePediatrie()` juste au-dessus.
     */
    public function codePediatrie(): ?string
    {
        $existe = SpecialiteMedicale::query()
            ->where('pays_code', config('referentiels.pays_defaut', 'CI'))
            ->where('code', self::CODE_PEDIATRIE)
            ->where('actif', true)
            ->exists();

        return $existe ? self::CODE_PEDIATRIE : null;
    }

    /**
     * Une version est-elle en vigueur ?
     *
     * NE REPLIE PAS ET NE JOURNALISE PAS — c'est la vérité brute, destinée à ce qu'un écran
     * d'exploitation puisse dire honnêtement que le triage est hors service (motif
     * `ServiceNumerosUrgence::estEnVigueur()`, P6.8e).
     */
    public function estEnVigueur(): bool
    {
        try {
            $this->diffusion->lire(SourceSymptomesTriage::CODE);

            return true;
        } catch (ReferentielException) {
            return false;
        }
    }

    private function charger(): void
    {
        if ($this->symptomes !== null) {
            return;
        }

        try {
            $diffuse = $this->diffusion->lire(SourceSymptomesTriage::CODE);
        } catch (ReferentielException) {
            // Voir l'en-tête : jamais de repli sur la table. 503 et non 404 — les symptômes
            // existent, c'est leur mise en vigueur qui manque.
            abort(503, 'Le référentiel national des symptômes de triage n\'a aucune version en '
                .'vigueur : aucun triage ne peut être rendu tant qu\'une version n\'a pas été '
                .'publiée (CDC_09 §10).');
        }

        $this->version = $diffuse['version'];

        $symptomes = [];

        foreach ($diffuse['contenu'] as $ligne) {
            $symptome = new Symptome;

            // `forceFill` : `id` n'est pas `$fillable`, et il doit l'être ici — c'est par lui que
            // le citoyen désigne ses symptômes et que le triage archivé les retrouve.
            $symptome->forceFill([
                'id'                             => (int) $ligne['id'],
                'nom_fr'                         => $ligne['nom_fr'],
                'categorie'                      => $ligne['categorie'],
                'poids_severite'                 => (int) $ligne['poids_severite'],
                'drapeau_rouge'                  => (bool) $ligne['drapeau_rouge'],
                'questions_complementaires_json' => $ligne['questions_complementaires_json'],
                'actif'                          => (bool) $ligne['actif'],
            ]);

            // Ces instances ne correspondent à aucune ligne écrite : `exists = false` fait échouer
            // bruyamment un `save()` involontaire en INSERT plutôt qu'en UPDATE silencieux.
            $symptome->exists = false;

            $symptomes[] = $symptome;

            $orientations = [];

            foreach ($ligne['orientations'] ?? [] as $o) {
                $code = (string) $o['code'];

                $orientations[] = [
                    'code'        => $code,
                    'rang'        => (int) $o['rang'],
                    'sexe_requis' => $o['sexe_requis'] ?? null,
                ];

                $this->libelles[$code] = (string) ($o['libelle'] ?? $code);
            }

            $this->orientations[(int) $ligne['id']] = $orientations;
        }

        // L'ordre change de sens entre les deux mondes : l'instantané est trié par `nom_fr` puis
        // `id` (ordre total, au service de l'empreinte), l'écran veut `categorie` puis `nom_fr`
        // (au service de la lecture) — même dédoublement qu'en L1+L2.
        $this->symptomes = collect($symptomes)
            ->sortBy([['categorie', 'asc'], ['nom_fr', 'asc']])
            ->values();
    }
}
