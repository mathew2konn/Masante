<?php

namespace App\Services\Referentiel;

use App\Models\Symptome;
use App\Models\SymptomeSpecialite;

/**
 * Référentiel national des symptômes de triage (table `symptomes`, Module 1).
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * P10a — DEUX COLONNES SORTENT DE L'INSTANTANÉ, POUR DEUX RAISONS DIFFÉRENTES
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * **`maladies_probables_json` sort parce qu'elle n'a aucun lecteur** (décision propriétaire D5,
 * 2026-08-15). Le G0 de P6.8c l'avait établi : ni `TriageController` ni le mobile ne l'affichent,
 * et sa SEULE sortie du serveur était cet instantané — c'est-à-dire l'endroit qui lui donnait le
 * plus d'autorité. Elle mêle des maladies (« Paludisme »), des syndromes (« Détresse
 * respiratoire ») et un état physiologique (« Grossesse »), en texte libre, jamais rattachés au
 * référentiel des maladies de P6.8c. Publier cela sous le sceau du §10 revenait à faire dire au
 * référentiel national quelque chose que personne n'avait relu et que rien ne consomme.
 *
 * La colonne et ses données sont CONSERVÉES (ADR-024) : une migration destructive perdrait de
 * l'information réelle pour un gain nul (précédent `specialites_json` en P6.4d). Elle cesse
 * seulement d'être publiée.
 *
 * **`specialite_hint` sort pour une raison plus forte : elle CONTREDIRAIT `orientations`.** Ce
 * n'est plus elle qui décide de l'orientation depuis P10a ; la garder dans le même instantané y
 * mettrait deux représentations du même fait, capables de diverger — exactement les « deux
 * vérités » refusées en P6.6a (interactions en colonne JSON *et* en table). Le G0 a vérifié
 * qu'aucun écran du portail ne la modifie et que le mobile ne l'affiche pas ; seul le seeder
 * l'écrit encore. La colonne reste, plus personne ne l'écrit — même énoncé honnête que
 * `vaccinations.statut` (P6.8b) et les colonnes `cmu_*` (P6.8d).
 *
 * POURQUOI CELUI-CI : `poids_severite`, `drapeau_rouge` et les questions complémentaires ne sont
 * pas de la donnée d'annuaire, ce sont les RÈGLES qui décident du niveau d'urgence d'un citoyen.
 * Aujourd'hui, corriger un poids de sévérité rend inexplicable tout triage antérieur — le G0 a
 * confirmé que `triages` ne stocke aucune version de protocole. Versionner ce référentiel est le
 * préalable à l'estampille des décisions.
 *
 * ═══ CE COMMENTAIRE DISAIT L'INVERSE JUSQU'À P10a, ET IL FAUT LE DIRE ═══
 *
 * Il annonçait : « la table n'est PAS modifiée et `TriageService` continue de la lire directement ».
 * C'était vrai, et c'était la **limite L1** d'ADR-025 : le socle figeait des instantanés que
 * personne ne lisait. Un `UPDATE` direct sur `symptomes` changeait le triage sans version, sans
 * trace et sans relecture — le versionnage était outillé, pas en vigueur.
 *
 * Depuis P10a, `TriageService` lit la **version publiée** ({@see \App\Services\Triage\ServiceSymptomesTriage}).
 * C'est la seconde moitié du défaut du G0 de P6.3, dont L1+L2 avait refermé la première
 * (`seuils_mesure`) le 2026-08-14 en laissant celle-ci à son foyer nommé : **P10**.
 *
 * L'INSTANTANÉ RETIENT LES SYMPTÔMES INACTIFS. `actif` est une donnée du référentiel, pas un
 * filtre du socle : un triage passé a pu s'appuyer sur un symptôme désactivé depuis. L'exclure de
 * l'instantané rendrait ce triage irrejouable — ce que le versionnage est censé empêcher.
 */
final class SourceSymptomesTriage implements SourceReferentiel
{
    public const CODE = 'symptomes_triage';

    /** Le score de triage se lit sur 100 : au-delà, la valeur est aberrante (§10). */
    private const POIDS_MAX = 100;

    public function code(): string
    {
        return self::CODE;
    }

    public function libelle(): string
    {
        return 'Symptômes et règles de triage';
    }

    public function roleResponsable(): string
    {
        return 'ministere';
    }

    public function extraire(): array
    {
        // ═══ L'EXTRACTION DES ORIENTATIONS — UNE SEULE REQUÊTE, ET ELLE PORTE LE CODE ═══
        //
        // Le code du vocabulaire, jamais `specialite_id` : un identifiant technique ne veut rien
        // dire hors de cette base, et un instantané est fait pour être relu dans dix ans. Motif des
        // interactions de P6.6a (portées par code national) et des échéances de P6.8b.
        //
        // L'orientation cite donc un terme d'un AUTRE référentiel gouverné (`specialites`). C'est
        // assumé et c'est le cas normal — comme `laboratoire_code` en P6.7b : chacun a sa
        // gouvernance propre. Le contrôle qualité ci-dessous vérifie qu'aucune orientation ne pointe
        // vers un terme désactivé, seul cas où la référence resterait valide en base et vide à
        // l'écran.
        $orientations = SymptomeSpecialite::query()
            ->join('specialites_medicales as sm', 'sm.id', '=', 'symptome_specialites.specialite_id')
            ->orderBy('symptome_specialites.rang')
            ->orderBy('sm.code')
            ->get([
                'symptome_specialites.symptome_id',
                'symptome_specialites.rang',
                'symptome_specialites.sexe_requis',
                'sm.code',
                'sm.libelle',
                'sm.actif as specialite_active',
            ])
            ->groupBy('symptome_id')
            ->map(fn ($lignes): array => $lignes->map(fn ($l): array => [
                'code'              => $l->code,
                // ═══ LE LIBELLÉ EST FIGÉ ICI, IL N'EST PAS RÉSOLU À LA LECTURE ═══
                //
                // Il appartient à un AUTRE référentiel (`specialites`). Le résoudre au moment du
                // triage obligerait à exiger que DEUX référentiels soient en vigueur, et laisserait
                // un renommage changer le texte lu par un patient sans aucune publication.
                //
                // Un instantané est par nature un enregistrement DATÉ : y figer le libellé est le
                // motif déjà établi ici — P6.6b fige la DCI et le dosage dans une ordonnance,
                // P7-D2 copie l'établissement dans le journal d'accès. Republier le référentiel des
                // symptômes relit les libellés courants ; c'est le geste de gouvernance qui les
                // rafraîchit, et il est visible.
                'libelle'           => $l->libelle,
                'rang'              => (int) $l->rang,
                'sexe_requis'       => $l->sexe_requis,
                // Portée dans l'instantané pour que le contrôle qualité puisse REFUSER la
                // publication. Sans elle, il faudrait interroger la base au moment du contrôle,
                // alors que le §10 juge un CONTENU proposé, pas l'état courant du monde.
                'specialite_active' => (bool) $l->specialite_active,
            ])->values()->all());

        return Symptome::query()
            // `id` en second critère : `nom_fr` n'est pas UNIQUE en base (le contrôle qualité le
            // signale s'il se répète), l'ordre doit rester total quand même.
            ->orderBy('nom_fr')
            ->orderBy('id')
            ->get()
            ->map(fn (Symptome $s): array => [
                // `id` fait partie de l'instantané : c'est par lui qu'un triage archivé désigne
                // les symptômes qu'il a retenus. Un instantané sans identifiant serait lisible,
                // mais pas rattachable à une décision passée.
                'id'                             => (int) $s->id,
                'nom_fr'                         => $s->nom_fr,
                'categorie'                      => $s->categorie,
                'poids_severite'                 => (int) $s->poids_severite,
                'drapeau_rouge'                  => (bool) $s->drapeau_rouge,
                'questions_complementaires_json' => $s->questions_complementaires_json,

                // ═══ P10a — L'ORIENTATION ENTRE DANS L'INSTANTANÉ, ET C'EST TOUT L'ENJEU ═══
                //
                // Le `rang` porte la priorité qui vivait dans un `str_contains($h, 'urgenc')` du
                // service : une **règle médicale en dur** (CDC_00 §4) que personne n'avait vue parce
                // qu'elle ne ressemblait pas à une règle. La publier ici la soumet au quatre-yeux du
                // §10 — deux agents habilités décident désormais que les urgences priment sur la
                // cardiologie pour tel symptôme, ce que personne ne pouvait relire hier.
                //
                // Un symptôme sans orientation porte une liste VIDE, pas `null` : l'absence
                // d'orientation est un fait du référentiel (« ce symptôme n'oriente vers rien de
                // particulier »), et neuf des vingt symptômes du jeu sont dans ce cas.
                'orientations'                   => $orientations->get($s->id, []),

                'actif'                          => (bool) $s->actif,
            ])
            ->all();
    }

    public function controlerQualite(array $contenu): array
    {
        $erreurs = [];

        if ($contenu === []) {
            return ['Le référentiel est vide : le triage n\'aurait plus aucun symptôme à proposer.'];
        }

        // Un référentiel de triage dont tous les symptômes sont désactivés est syntaxiquement
        // valide et cliniquement inutilisable. C'est le genre de publication qu'il faut arrêter.
        if (! collect($contenu)->contains(fn (array $l): bool => $l['actif'] === true)) {
            $erreurs[] = 'Aucun symptôme actif : le triage serait publié hors d\'état de fonctionner.';
        }

        $nomsVus = [];

        foreach ($contenu as $ligne) {
            $nom = trim((string) $ligne['nom_fr']);

            if ($nom === '') {
                $erreurs[] = "Symptôme n°{$ligne['id']} : libellé absent.";
                continue;
            }

            // Doublons (§10). La comparaison ignore la casse : « Fièvre » et « fièvre » sont le
            // même symptôme pour un citoyen qui choisit dans une liste.
            $cle = mb_strtolower($nom);
            if (isset($nomsVus[$cle])) {
                $erreurs[] = "Doublon : le symptôme « {$nom} » apparaît plusieurs fois "
                    ."(n°{$nomsVus[$cle]} et n°{$ligne['id']}).";
            }
            $nomsVus[$cle] = $ligne['id'];

            // Valeurs aberrantes (§10).
            if ($ligne['poids_severite'] < 0 || $ligne['poids_severite'] > self::POIDS_MAX) {
                $erreurs[] = "« {$nom} » : poids de sévérité aberrant ({$ligne['poids_severite']}, "
                    .'attendu entre 0 et '.self::POIDS_MAX.').';
            }

            // Cohérence clinique : un drapeau rouge force le niveau URGENT. Un drapeau rouge à
            // poids nul est contradictoire — l'un dit « critique », l'autre « sans gravité ».
            if ($ligne['drapeau_rouge'] === true && $ligne['poids_severite'] === 0) {
                $erreurs[] = "« {$nom} » : drapeau rouge posé mais poids de sévérité nul — "
                    .'la règle se contredit elle-même.';
            }

            // Format des questions complémentaires : le triage les parcourt comme une liste.
            $questions = $ligne['questions_complementaires_json'];
            if ($questions !== null && ! array_is_list($questions)) {
                $erreurs[] = "« {$nom} » : les questions complémentaires ne forment pas une liste.";
            }

            foreach ($this->erreursDOrientation($nom, $ligne['orientations'] ?? []) as $erreur) {
                $erreurs[] = $erreur;
            }
        }

        return $erreurs;
    }

    /**
     * P10a — Contrôles §10 sur l'orientation d'un symptôme.
     *
     * ═══ LE CONTRÔLE CENTRAL : ORIENTER VERS UN TERME DÉSACTIVÉ ═══
     *
     * C'est le seul défaut de cette famille qui ne fait AUCUN bruit. Le déclencheur
     * `ck_orientation_specialite_inactive` refuse d'écrire une orientation vers un terme déjà
     * désactivé — mais il ne se déclenche pas quand la désactivation vient APRÈS, du côté du
     * vocabulaire. La clé étrangère reste satisfaite, la ligne reste en base, et le triage se met à
     * proposer une spécialité que l'annuaire ne peut plus rendre : *l'écran est vide et rien ne le
     * signale*. C'est exactement la forme de défaut latent que cet incrément referme, et il ne doit
     * pas pouvoir revenir par la porte de derrière.
     *
     * ═══ CE QUI N'EST DÉLIBÉRÉMENT PAS CONTRÔLÉ ═══
     *
     * **Deux orientations au même rang** : ce n'est pas une contradiction. `ReglesOrientation`
     * départage par le code, donc la réponse reste déterministe. Interdire une donnée inoffensive
     * ferait un contrôle plus strict que la règle — le défaut relevé en P6.8c avec la collation.
     *
     * **Un symptôme à drapeau rouge qui n'oriente pas vers les urgences** : ce serait un
     * **arbitrage clinique**, et le §10 ne donne pas au socle qualité de le rendre. Neuf symptômes
     * du jeu n'orientent vers rien, et c'est un état légitime.
     *
     * @param  array<int, array{code?: string, libelle?: string, rang?: int, sexe_requis?: string|null, specialite_active?: bool}>  $orientations
     * @return array<int, string>
     */
    private function erreursDOrientation(string $nom, array $orientations): array
    {
        $erreurs = [];
        $codesVus = [];

        foreach ($orientations as $o) {
            $code = trim((string) ($o['code'] ?? ''));

            if ($code === '') {
                $erreurs[] = "« {$nom} » : une orientation sans code de spécialité.";

                continue;
            }

            if (isset($codesVus[$code])) {
                $erreurs[] = "« {$nom} » : orienté deux fois vers « {$code} » — "
                    .'la même orientation à deux rangs se contredit.';
            }
            $codesVus[$code] = true;

            // Le libellé est ce que le patient LIT. Publier une orientation sans libellé afficherait
            // un code technique dans un écran citoyen — ou rien du tout.
            if (trim((string) ($o['libelle'] ?? '')) === '') {
                $erreurs[] = "« {$nom} » : orientation « {$code} » sans libellé lisible.";
            }

            if (($o['specialite_active'] ?? true) !== true) {
                $erreurs[] = "« {$nom} » : orienté vers « {$code} », terme DÉSACTIVÉ du "
                    .'vocabulaire national — le patient serait envoyé vers une spécialité que '
                    ."l'annuaire ne peut plus proposer, sans que rien ne le signale.";
            }

            if ((int) ($o['rang'] ?? 0) < 1) {
                $erreurs[] = "« {$nom} » : rang d'orientation invalide pour « {$code} » "
                    .'(attendu 1 ou plus).';
            }

            $sexe = $o['sexe_requis'] ?? null;
            if ($sexe !== null && ! in_array($sexe, ['M', 'F'], true)) {
                $erreurs[] = "« {$nom} » : restriction de sexe inconnue « {$sexe} » pour « {$code} ».";
            }
        }

        return $erreurs;
    }
}
