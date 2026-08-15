<?php

namespace App\Services\Referentiel;

use App\Models\LibelleMaladie;
use App\Models\Maladie;
use App\Models\SurveillanceMaladie;

/**
 * Référentiel national des maladies (CDC_09 §8), sous gouvernance §10.
 *
 * ═══ LA PROJECTION PREND LA LIGNE ENTIÈRE — question reposée, pas recopiée ═══
 *
 * P6.4a excluait la note d'avis parce qu'elle est RECALCULÉE à chaque avis déposé : l'inclure aurait
 * fait diverger l'instantané en permanence, et l'anti-substitution aurait refusé toute publication.
 * La question est reposée ici, comme en P6.6a, P6.8a et P6.8b, et la vérification donne la même
 * réponse : **rien n'écrit automatiquement dans `maladies`**. Les alertes vivent dans
 * `alertes_epidemiques`, les antécédents dans le carnet, les liens vaccinaux dans une table de
 * liaison — aucun compteur, aucune moyenne. Un vecteur en miroir le prouve : publier une alerte
 * épidémique ne change pas l'empreinte.
 *
 * ═══ UN SEUL INSTANTANÉ POUR LES TROIS TABLES ═══
 *
 * Les publier séparément laisserait une surveillance ou un libellé désigner une maladie absente de
 * la version en vigueur, donc une référence irrésoluble — motif exact des interactions de P6.6a, des
 * strates de P6.7a et des échéances de P6.8b. Les libellés et les surveillances sont portés **sous**
 * leur maladie, jamais par identifiant technique : un instantané doit rester lisible quand les
 * identifiants d'une base ne sont plus ceux d'une autre.
 *
 * ═══ CE QUE LA GOUVERNANCE PROTÈGE ICI ═══
 *
 * Renommer une maladie, la retirer, ou déclarer qu'elle est à déclaration obligatoire dans un pays
 * sont des actes d'autorité sanitaire, pas des corrections de saisie : ils changent le sens des
 * alertes et des antécédents **déjà rattachés**. D'où le quatre-yeux du §10.
 *
 * ═══ CE QUE LES CONTRÔLES N'EXIGENT PAS, ET POURQUOI ═══
 *
 * **Aucun code CIM n'est exigé.** L'exiger rendrait le référentiel impubliable dès le premier jour,
 * puisque CIM-10 et CIM-11 sont des publications de l'OMS que ce projet n'a pas chargées. L'absence
 * est COMPTÉE et AFFICHÉE ({@see App\Http\Controllers\Portail\ReferentielMaladieController}), jamais
 * transformée en blocage. *Un contrôle qu'on ne peut pas satisfaire n'est pas une exigence, c'est un
 * mur.*
 */
final class SourceMaladies implements SourceReferentiel
{
    /** Les provenances admises — miroir des ENUM `source` des trois tables. */
    private const SOURCES = ['demonstration', 'autorite_nationale', 'oms', 'societe_savante', 'publication'];

    public const CODE = 'maladies';

    public function code(): string
    {
        return self::CODE;
    }

    public function libelle(): string
    {
        return 'Maladies (CIM) et surveillance nationale';
    }

    /**
     * §10 « chaque référentiel a un responsable désigné ». Le ministère — pour la raison qui a fait
     * naître la permission `maladie.referentiel` : `sante_publique.manage` publie les alertes, et
     * l'étendre au vocabulaire ferait de **l'auteur d'une alerte celui qui décide de ce qu'est une
     * maladie**.
     */
    public function roleResponsable(): string
    {
        return 'ministere';
    }

    public function extraire(): array
    {
        return Maladie::query()
            ->with(['libelles', 'surveillances'])
            // Ordre stable et TOTAL. Pas de `pays_code` à intercaler ici, à la différence de tous
            // les référentiels précédents : le code est unique GLOBALEMENT (décision E2).
            ->orderBy('code')
            ->orderBy('id')
            ->get()
            ->map(fn (Maladie $m): array => [
                'code'          => $m->code,
                'libelle'       => $m->libelle,
                'code_cim10'    => $m->code_cim10,
                'code_cim11'    => $m->code_cim11,
                'description'   => $m->description,
                'source'        => $m->source,
                'source_detail' => $m->source_detail,
                'actif'         => (bool) $m->actif,
                'libelles'      => $m->libelles
                    ->sortBy(fn (LibelleMaladie $l) => [$l->langue, $l->libelle])
                    ->map(fn (LibelleMaladie $l): array => [
                        'langue'        => $l->langue,
                        'libelle'       => $l->libelle,
                        'principal'     => (bool) $l->principal,
                        'source'        => $l->source,
                        'source_detail' => $l->source_detail,
                    ])
                    ->values()
                    ->all(),
                'surveillance'  => $m->surveillances
                    ->sortBy('pays_code')
                    ->map(fn (SurveillanceMaladie $s): array => [
                        'pays_code'                => $s->pays_code,
                        'declaration_obligatoire'  => (bool) $s->declaration_obligatoire,
                        'surveillance_prioritaire' => (bool) $s->surveillance_prioritaire,
                        'source'                   => $s->source,
                        'source_detail'            => $s->source_detail,
                    ])
                    ->values()
                    ->all(),
            ])
            ->all();
    }

    /**
     * Contrôles qualité §10 — bloquants à la publication.
     *
     * Ce qu'ils garantissent : **un référentiel publié ne peut pas contenir une entrée sans
     * provenance, deux maladies indiscernables à l'écran, un libellé alternatif qui recopie le
     * libellé officiel, ni une langue dont on ne saurait pas lequel des libellés afficher.**
     */
    public function controlerQualite(array $contenu): array
    {
        $erreurs = [];

        if ($contenu === []) {
            return ['Le référentiel est vide : aucune maladie à publier.'];
        }

        $codesVus    = [];
        $libellesVus = [];
        $actives     = 0;

        foreach ($contenu as $ligne) {
            $code      = (string) ($ligne['code'] ?? '');
            $libelle   = trim((string) ($ligne['libelle'] ?? ''));
            $etiquette = $code !== '' ? $code : '« '.($libelle !== '' ? $libelle : 'sans libellé').' »';

            if ($code === '') {
                $erreurs[] = "{$etiquette} : code national absent — la commande "
                    .'`masante:maladies:backfill` en attribue un.';
            } elseif (isset($codesVus[$code])) {
                // PAS DE PAYS DANS LA CLÉ, et c'est le pendant exact de la décision E2 : le contrôle
                // doit être aussi strict que l'index `uq_maladie_code`, ni plus ni moins. Le G2 de
                // P6.5a a montré ce que coûte un contrôle plus strict que le moteur — un référentiel
                // impubliable ; l'inverse laisserait passer ce que la base refusera.
                $erreurs[] = "Doublon : le code « {$code} » apparaît plusieurs fois.";
            } else {
                $codesVus[$code] = true;
            }

            if ($libelle === '') {
                $erreurs[] = "{$etiquette} : libellé absent — c'est lui que le citoyen lit.";
            } else {
                $cle = mb_strtolower($libelle);

                if (isset($libellesVus[$cle])) {
                    $erreurs[] = "Doublon : le libellé « {$libelle} » est porté par plusieurs "
                        ."maladies — elles seraient indiscernables dans une liste d'alerte.";
                }
                $libellesVus[$cle] = true;
            }

            // LA PROVENANCE, garde centrale du module (4ᵉ application après P6.7a et P6.8b). La
            // colonne est NOT NULL en base ; ce contrôle attrape ce que la base ne peut pas voir —
            // une valeur hors nomenclature arrivée par un import.
            if (! in_array($ligne['source'] ?? null, self::SOURCES, true)) {
                $erreurs[] = "{$etiquette} : provenance absente ou inconnue — une entrée de "
                    .'référentiel sans source ne peut pas être publiée.';
            }

            if ($ligne['actif'] ?? false) {
                $actives++;
            }

            $erreurs = array_merge(
                $erreurs,
                $this->controlerLesLibelles($ligne, $etiquette, $libelle),
                $this->controlerLaSurveillance($ligne, $etiquette),
            );
        }

        if ($actives === 0) {
            $erreurs[] = 'Aucune maladie active : le référentiel publié ne permettrait de rattacher '
                .'ni une alerte, ni un antécédent.';
        }

        return $erreurs;
    }

    /**
     * @param  array<string, mixed>  $ligne
     * @return array<int, string>
     */
    private function controlerLesLibelles(array $ligne, string $etiquette, string $officiel): array
    {
        $erreurs    = [];
        $libelles   = is_array($ligne['libelles'] ?? null) ? $ligne['libelles'] : [];
        $principaux = [];
        $pivot      = (string) config('referentiels.langue_pivot', 'fr');

        foreach ($libelles as $l) {
            $langue  = (string) ($l['langue'] ?? '');
            $valeur  = trim((string) ($l['libelle'] ?? ''));

            if ($langue === '' || $valeur === '') {
                $erreurs[] = "{$etiquette} : un libellé alternatif est incomplet (langue ou texte "
                    .'absent).';

                continue;
            }

            // ═══ LE SENS INVERSE DE CE QUE GARDE LE DÉCLENCHEUR ═══
            //
            // Le déclencheur de la migration refuse d'ÉCRIRE un alternatif identique au libellé
            // officiel. Il ne voit pas le cas contraire : renommer le libellé officiel pour qu'il
            // rejoigne un alternatif déjà stocké. Ce contrôle-ci l'attrape. Deux gardes, deux
            // chemins, aucune ne rattrape l'autre — et c'est dit plutôt que supposé.
            if (mb_strtolower($valeur) === mb_strtolower($officiel)) {
                $erreurs[] = "{$etiquette} : le libellé alternatif « {$valeur} » recopie le libellé "
                    .'officiel — la même chaîne serait stockée à deux endroits, et le second serait '
                    .'oublié au premier renommage.';
            }

            if (! in_array($l['source'] ?? null, self::SOURCES, true)) {
                $erreurs[] = "{$etiquette} : le libellé « {$valeur} » n'a pas de provenance connue.";
            }

            // ═══ LA LANGUE PIVOT N'A PAS DE PRINCIPAL ICI, ET C'EST STRUCTUREL ═══
            //
            // Son libellé officiel vit sur `maladies.libelle`. Une ligne alternative en langue pivot
            // est donc forcément un SYNONYME (« palu ») : la marquer principale ferait dire à
            // l'écran d'afficher le surnom à la place du nom, et rétablirait la concurrence de
            // libellés que le schéma évite.
            if ($langue === $pivot) {
                if ($l['principal'] ?? false) {
                    $erreurs[] = "{$etiquette} : « {$valeur} » est marqué principal en langue pivot "
                        ."({$pivot}) — le libellé officiel de cette langue est celui de la maladie, "
                        .'et un synonyme ne peut pas le remplacer.';
                }

                continue;
            }

            $principaux[$langue] = ($principaux[$langue] ?? 0) + (($l['principal'] ?? false) ? 1 : 0);
        }

        // ═══ EXACTEMENT UN PRINCIPAL PAR LANGUE NON PIVOT — CONTRÔLE APPLICATIF, ET C'EST DIT ═══
        //
        // MySQL 8 n'a pas d'index unique partiel : « un seul principal par langue » ne peut pas être
        // tenu par le moteur sans une colonne générée et un jeu de contraintes qui coûterait plus
        // qu'il ne garantit. Il est donc tenu ici — annoncé comme applicatif, jamais déguisé en
        // garantie du moteur (précédent du quota d'images de P6.4c).
        foreach ($principaux as $langue => $nombre) {
            if ($nombre === 0) {
                $erreurs[] = "{$etiquette} : la langue « {$langue} » n'a aucun libellé principal — "
                    .'on ne saurait pas lequel afficher.';
            } elseif ($nombre > 1) {
                $erreurs[] = "{$etiquette} : la langue « {$langue} » a {$nombre} libellés principaux.";
            }
        }

        return $erreurs;
    }

    /**
     * @param  array<string, mixed>  $ligne
     * @return array<int, string>
     */
    private function controlerLaSurveillance(array $ligne, string $etiquette): array
    {
        $erreurs = [];
        $paysVus = [];

        foreach (is_array($ligne['surveillance'] ?? null) ? $ligne['surveillance'] : [] as $s) {
            $pays = strtoupper((string) ($s['pays_code'] ?? ''));

            if (! preg_match('/^[A-Z]{2}$/', $pays)) {
                $erreurs[] = "{$etiquette} : code pays de surveillance mal formé (« {$pays} »).";

                continue;
            }

            if (isset($paysVus[$pays])) {
                $erreurs[] = "{$etiquette} : deux statuts de surveillance pour {$pays} — ils "
                    .'diraient deux choses différentes sur la même question de santé publique.';
            }
            $paysVus[$pays] = true;

            // Une déclaration obligatoire est une OBLIGATION LÉGALE : la publier sans dire d'où elle
            // vient exposerait un professionnel à une obligation dont personne ne peut citer la
            // source.
            if (! in_array($s['source'] ?? null, self::SOURCES, true)) {
                $erreurs[] = "{$etiquette} : la surveillance pour {$pays} n'a pas de provenance "
                    .'connue.';
            }
        }

        return $erreurs;
    }
}
