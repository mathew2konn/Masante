<?php

namespace App\Services\Referentiel;

use App\Models\Medecin;
use App\Services\Professionnel\GenerateurNumeroProfessionnel;
use App\Support\ProfessionsSante;

/**
 * Référentiel national des professionnels de santé (CDC_09 §5) — **projection d'identité
 * professionnelle**, et non la table `medecins` entière.
 *
 * ═══ MÊME RAISONNEMENT QU'EN P6.4a, ET IL FAUT LE REFAIRE, PAS LE RECOPIER ═══
 *
 * P6.4a a exclu `note_moyenne` de la projection des établissements parce qu'elle est recalculée à
 * chaque avis citoyen : versionner la table entière aurait rendu l'instantané divergent en
 * permanence, et l'anti-substitution aurait refusé toute publication.
 *
 * `medecins` n'a pas de note recalculée. Le tri passe donc ailleurs, sur une autre question :
 * **qu'est-ce qui engage une autorité ?**
 *
 *   · ENGAGE — numéro national, profession, ordre professionnel, numéro d'ordre, AUTORISATION
 *     D'EXERCER et ses dates, lieux d'exercice. Ce sont les données qu'un ordre professionnel
 *     délivre ou retire, et sur lesquelles le §5.4 s'appuiera pour laisser signer une ordonnance.
 *     Les corriger doit être un acte de gouvernance.
 *
 *   · N'ENGAGE PAS — `tarif_consultation`, `biographie`, `telephone`, `email`, `langues_json`,
 *     `consultation_en_ligne`, `sous_specialite`, `photo`. Utiles, affichées, modifiées au fil de
 *     l'eau par l'établissement lui-même. Les soumettre à une double validation nationale
 *     transformerait la correction d'un tarif en décision ministérielle.
 *
 * LE VECTEUR QUI LE PROUVE, et il en faut DEUX en miroir, aucun ne suffisant seul :
 *   changer le TARIF d'un praticien  → le référentiel NE diverge PAS ;
 *   changer son NUMÉRO D'ORDRE       → il diverge.
 *
 * ═══ CE QUE `titre`, `nom` ET `prenom` FONT ICI ═══
 *
 * Ce sont des données personnelles, pas administratives — mais §5.2 les nomme, et surtout un
 * référentiel de numéros sans noms serait inexploitable : personne ne peut vérifier qu'un
 * `PRO000007` est bien la sage-femme dont on parle. On les inclut donc, en sachant que corriger
 * une faute d'orthographe fera diverger le référentiel. C'est le prix d'un référentiel lisible,
 * et il est assumé plutôt que contourné par une exclusion qui l'aurait rendu muet.
 */
final class SourceProfessionnels implements SourceReferentiel
{
    public const CODE = 'professionnels';

    public function code(): string
    {
        return self::CODE;
    }

    public function libelle(): string
    {
        return 'Professionnels de santé';
    }

    /**
     * §10 : « chaque référentiel a un responsable désigné ». Le ministère, comme les
     * établissements — c'est lui qui exerce la tutelle des ordres professionnels, et non un
     * établissement, qui ne peut pas se prononcer sur le droit d'exercer d'un praticien concurrent.
     */
    public function roleResponsable(): string
    {
        return 'ministere';
    }

    public function extraire(): array
    {
        return Medecin::query()
            ->with([
                'structure:id,identifiant_national',
                'exercices' => fn ($q) => $q->with('structure:id,identifiant_national'),
            ])
            // Ordre stable : `numero_professionnel` est UNIQUE par pays mais reste NULL tant
            // qu'une fiche n'a pas été servie par le backfill — d'où `id` en second, qui rend
            // l'ordre total dans tous les cas (P6.4a).
            ->orderBy('numero_professionnel')
            ->orderBy('id')
            ->get()
            ->map(fn (Medecin $p): array => [
                'numero_professionnel'     => $p->numero_professionnel,
                'pays_code'                => $p->pays_code,
                'titre'                    => $p->titre,
                'nom'                      => $p->nom,
                'prenom'                   => $p->prenom,
                'sexe'                     => $p->sexe,
                'profession'               => $p->profession,
                'specialite'               => $p->specialite,
                'ordre_professionnel'      => $p->ordre_professionnel,
                'numero_ordre'             => $p->numero_ordre,
                'autorisation_numero'      => $p->autorisation_numero,
                'autorisation_statut'      => $p->autorisation_statut,
                'autorisation_delivree_le' => $p->autorisation_delivree_le?->toDateString(),
                'autorisation_expire_le'   => $p->autorisation_expire_le?->toDateString(),
                'universite'               => $p->universite,
                'annee_diplome'            => $p->annee_diplome,
                'actif'                    => (bool) $p->actif,
                'exercices'                => $this->projeterExercices($p),
                // Conservé pour le contrôle de cohérence plus bas : sans lui, on ne saurait pas
                // dire que l'exercice principal de la fiche n'est pas reporté dans la table.
                'etablissement_principal'  => $p->structure?->identifiant_national,
            ])
            ->all();
    }

    /**
     * Les lieux d'exercice, par identifiant national d'établissement.
     *
     * PAR IDENTIFIANT NATIONAL ET NON PAR `structure_id`. Une clé primaire technique n'a de sens
     * que dans cette base ; un référentiel national publié doit pouvoir être lu par un autre
     * système, et c'est `ETS000152` qui le permet. C'est aussi ce qui rend l'instantané stable si
     * la base est un jour rechargée.
     *
     * TRI indispensable — sans lui, deux ensembles d'exercices identiques produiraient deux
     * empreintes différentes selon l'ordre d'insertion, et le référentiel divergerait sans raison
     * (leçon des images en P6.4c).
     *
     * @return list<array{etablissement:string|null, principal:bool, actif:bool}>
     */
    private function projeterExercices(Medecin $professionnel): array
    {
        return $professionnel->exercices
            ->map(fn ($e): array => [
                'etablissement' => $e->structure?->identifiant_national,
                'principal'     => (bool) $e->est_principal,
                'actif'         => (bool) $e->actif,
            ])
            ->sortBy([['etablissement', 'asc']])
            ->values()
            ->all();
    }

    public function controlerQualite(array $contenu): array
    {
        $erreurs = [];

        if ($contenu === []) {
            return ['Le référentiel est vide : aucun professionnel à publier.'];
        }

        $numerosVus = [];
        $ordresVus  = [];

        foreach ($contenu as $ligne) {
            $nom = trim(($ligne['prenom'] ?? '').' '.($ligne['nom'] ?? ''));
            $etiquette = $ligne['numero_professionnel'] ?? ('« '.($nom !== '' ? $nom : 'sans nom').' »');
            $pays = $ligne['pays_code'] ?? '??';

            // — Numéro national (§5.2) —
            //
            // LA CLÉ DE DOUBLON PORTE LE PAYS, et ce n'est pas une précaution abstraite : le G2
            // live l'a prouvée nécessaire. Sans elle, un `PRO000001` ivoirien et un `PRO000001`
            // sénégalais — parfaitement légitimes, puisque l'unicité en base est
            // `UNIQUE(pays_code, numero_professionnel)` — étaient signalés comme un doublon, et le
            // référentiel devenait impubliable dès le premier pays ajouté.
            //
            // Le contrôle était plus strict que le moteur, et il avait tort : le moteur dit
            // « CI-PRO000001 », pas « PRO000001 ». On dit désormais la même chose que lui.
            $cleNumero = $pays.'-'.$ligne['numero_professionnel'];

            if ($ligne['numero_professionnel'] === null) {
                $erreurs[] = "{$etiquette} : aucun numéro professionnel "
                    .'(php artisan masante:professionnels:backfill).';
            } elseif (! GenerateurNumeroProfessionnel::formeValide($ligne['numero_professionnel'])) {
                $erreurs[] = "{$etiquette} : numéro professionnel mal formé (attendu PRO + 6 chiffres).";
            } elseif (isset($numerosVus[$cleNumero])) {
                $erreurs[] = "Doublon : le numéro {$ligne['numero_professionnel']} est porté "
                    ."par plusieurs professionnels du pays {$pays}.";
            } else {
                $numerosVus[$cleNumero] = true;
            }

            // — Format —
            if ($nom === '') {
                $erreurs[] = "{$etiquette} : nom absent.";
            }

            if ($ligne['profession'] === null) {
                $erreurs[] = "{$etiquette} : profession absente — le §5.1 en énumère onze, "
                    .'aucune ne peut être devinée depuis la spécialité.';
            }

            // — AUTORISATION D'EXERCER : le bloc dont dépendra le §5.4 —
            //
            // Un professionnel sans autorisation enregistrée n'est pas « probablement autorisé » :
            // c'est un professionnel dont nul ne sait s'il a le droit d'exercer. Publier le
            // référentiel dans cet état ferait croire le contraire.
            if ($ligne['autorisation_statut'] === null) {
                $erreurs[] = "{$etiquette} : aucune autorisation d'exercer enregistrée.";
            }

            if ($ligne['autorisation_statut'] !== null
                && ! array_key_exists($ligne['autorisation_statut'], ProfessionsSante::STATUTS_AUTORISATION)) {
                $erreurs[] = "{$etiquette} : statut d'autorisation inconnu "
                    ."« {$ligne['autorisation_statut']} ».";
            }

            // Incohérence de dates : délivrée après son échéance. Les deux valeurs sont plausibles
            // prises séparément — c'est leur ordre qui est faux, l'anomalie sournoise de P6.4a.
            if ($ligne['autorisation_delivree_le'] !== null && $ligne['autorisation_expire_le'] !== null
                && $ligne['autorisation_delivree_le'] > $ligne['autorisation_expire_le']) {
                $erreurs[] = "{$etiquette} : autorisation délivrée le "
                    ."{$ligne['autorisation_delivree_le']} mais expirant le "
                    ."{$ligne['autorisation_expire_le']}.";
            }

            // Valeur aberrante au sens du §10 : une autorisation dite valide, déjà expirée. Le
            // §5.4 refusera la signature ; le référentiel, lui, ne doit pas publier la
            // contradiction sans la signaler.
            if ($ligne['autorisation_statut'] === 'valide'
                && $ligne['autorisation_expire_le'] !== null
                && $ligne['autorisation_expire_le'] < now()->toDateString()) {
                $erreurs[] = "{$etiquette} : autorisation déclarée valide alors qu'elle a expiré "
                    ."le {$ligne['autorisation_expire_le']}.";
            }

            // — Unicité du numéro d'ordre au sein d'un même ordre professionnel —
            if ($ligne['numero_ordre'] !== null && $ligne['ordre_professionnel'] !== null) {
                // Le pays entre aussi dans la clé, pour la raison ci-dessus : deux ordres
                // nationaux peuvent numéroter leurs inscrits à partir de 1.
                $cle = $pays.'#'.mb_strtolower($ligne['ordre_professionnel']).'#'.$ligne['numero_ordre'];
                if (isset($ordresVus[$cle])) {
                    $erreurs[] = "Doublon : le numéro d'ordre {$ligne['numero_ordre']} est porté "
                        ."deux fois au sein de « {$ligne['ordre_professionnel']} ».";
                } else {
                    $ordresVus[$cle] = true;
                }
            }

            // — Valeurs aberrantes (§10) —
            if ($ligne['annee_diplome'] !== null && $ligne['annee_diplome'] > (int) now()->format('Y')) {
                $erreurs[] = "{$etiquette} : année de diplôme dans le futur ({$ligne['annee_diplome']}).";
            }

            // — Cohérence de la redondance assumée par P6.5a —
            //
            // `medecins.structure_id` (lu par P3/P4, validés G5) et la table des exercices disent
            // la même chose de deux façons. Si l'exercice principal n'est pas reporté, le
            // référentiel affirmerait qu'un professionnel exerce ailleurs que là où l'annuaire le
            // montre. C'est précisément le prix de la redondance : il se paie par ce contrôle.
            $etablissements = array_column($ligne['exercices'], 'etablissement');

            if ($ligne['etablissement_principal'] !== null
                && ! in_array($ligne['etablissement_principal'], $etablissements, true)) {
                $erreurs[] = "{$etiquette} : l'établissement principal "
                    ."{$ligne['etablissement_principal']} n'apparaît pas dans ses lieux d'exercice "
                    .'(php artisan masante:professionnels:backfill).';
            }

            if ($ligne['exercices'] === []) {
                $erreurs[] = "{$etiquette} : aucun lieu d'exercice.";
            }

            if (in_array(null, $etablissements, true)) {
                $erreurs[] = "{$etiquette} : un lieu d'exercice désigne un établissement sans "
                    .'identifiant national (php artisan masante:etablissement:backfill).';
            }
        }

        return $erreurs;
    }
}
