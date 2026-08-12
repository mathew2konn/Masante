<?php

namespace App\Services;

use App\Models\AccesDossier;
use App\Models\Contribution;
use App\Models\MembreFamille;
use App\Support\RegistreSectionsCarnet;
use App\Support\TypeAccesDossier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Fiche de parcours d'un carnet (carnet familial partagé, incrément D2).
 *
 * CE QU'ELLE RÉPOND. Un proche a emmené l'enfant à l'hôpital et propose un ajout au carnet. Avant
 * de valider, un responsable veut savoir : qui a ouvert le dossier, dans quel établissement, par
 * quelle voie, combien de temps, et ce qui a été écrit. La fiche assemble ces faits — elle ne les
 * produit pas.
 *
 * CE QU'ELLE N'EST PAS. Une preuve. Si l'hôpital n'a jamais scanné le QR, la fiche est vide : elle
 * ne dit pas qu'il ne s'est rien passé, elle dit que rien n'a été tracé. C'est un support à l'appel
 * téléphonique, et l'écran doit le dire.
 *
 * FRONTIÈRE (CDC_01 §0.1) — quelles règles métier ce service calcule-t-il ? Aucune. Il regroupe des
 * lignes d'audit, résout des identités et qualifie des liens. Aucun droit, aucun statut, aucune
 * décision n'en sort. La décision sur une contribution reste dans {@see ContributionCarnetService}
 * depuis l'incrément C, et le propriétaire a explicitement demandé que VOIR et DÉCIDER restent
 * séparés : cette classe ne connaît que le voir.
 */
class ServiceFicheParcours
{
    /**
     * Assemble la fiche de parcours d'un membre.
     *
     * @return array<string, mixed>
     */
    public function pour(MembreFamille $membre, ?Carbon $depuis = null): array
    {
        $depuis ??= now()->subDays((int) config('masante.parcours.fenetre_jours', 90));

        $visites = $this->visites($membre, $depuis);

        // Identifiants déjà rattachés à une visite : ils ne doivent pas réapparaître dans le
        // second bloc, sans quoi la même ordonnance serait montrée deux fois — une fois comme
        // fait établi, une fois comme rapprochement possible.
        $dejaLiees = $this->identifiantsLies($visites);

        return [
            'membre' => [
                'id'     => $membre->id,
                'prenom' => $membre->prenom,
                'nom'    => $membre->nom,
            ],
            'depuis'   => $depuis->toIso8601String(),
            'visites'  => $visites->values()->all(),
            // Bloc SÉPARÉ, et au niveau de la fiche — jamais sous une visite. Le placer sous une
            // visite suggérerait le rattachement que précisément on ne connaît pas (décision
            // propriétaire du G1 : « lien non affirmé »).
            'autres_entrees' => $this->autresEntrees($membre, $depuis, $dejaLiees),
            'contributions'  => $this->contributions($membre, $depuis),
        ];
    }

    /**
     * Les passages en établissement de la période, du plus récent au plus ancien.
     *
     * UNE VISITE S'ÉCRIT EN DEUX LIGNES (le journal est immuable, §10.2 : on ne complète pas
     * l'ouverture après coup). La ligne de CLÔTURE porte tout — durée, sections consultées,
     * données ajoutées — et désigne son ouverture depuis D2 (`acces_ouverture_id`). Les lignes
     * antérieures à cette migration se retrouvent encore par `token_qr_id`, exact lui aussi, mais
     * disponible sur la seule voie du scan.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function visites(MembreFamille $membre, Carbon $depuis): Collection
    {
        $lignes = AccesDossier::query()
            ->where('membre_id', $membre->id)
            ->where('created_at', '>=', $depuis)
            // `delegation` exclue : c'est un proche qui lit le carnet depuis son téléphone, à
            // raison d'une ligne PAR SECTION. Les mêler aux visites présenterait une lecture
            // familiale comme un acte de soin — et noierait le passage à l'hôpital.
            ->whereIn('type_acces', TypeAccesDossier::voiesDeVisite())
            ->with('agent:id,nom,prenom')
            ->orderBy('created_at')
            ->get();

        // Seule `SessionDossierService::fermer()` renseigne `duree_minutes` : c'est la marque
        // d'une clôture, sans avoir à interroger une colonne dédiée.
        [$clotures, $ouvertures] = $lignes->partition(fn (AccesDossier $l) => $l->duree_minutes !== null);

        $reclamees = [];
        $visites   = collect();

        foreach ($clotures as $cloture) {
            $ouverture = $this->ouvertureDe($cloture, $ouvertures);

            if ($ouverture !== null) {
                $reclamees[] = $ouverture->id;
            }

            $visites->push($this->composer($membre, $ouverture ?? $cloture, $cloture));
        }

        // Une ouverture que personne ne réclame : l'agent a fermé son navigateur sans clôturer, et
        // `fermer()` n'a jamais été appelé. On ne sait pas combien de temps il est resté — on le
        // dit, plutôt que d'inventer une durée ou de masquer l'accès.
        foreach ($ouvertures as $ouverture) {
            if (! in_array($ouverture->id, $reclamees, true)) {
                $visites->push($this->composer($membre, $ouverture, null));
            }
        }

        return $visites->sortByDesc('a');
    }

    /**
     * Retrouve l'ouverture d'une clôture — par le lien explicite, sinon par le token partagé.
     *
     * Aucun rapprochement par proximité horaire : deux lignes qui se suivent ne sont pas une
     * preuve qu'elles appartiennent au même accès. Faute de clé, on préfère deux lignes séparées
     * à un lien inventé.
     *
     * @param  Collection<int, AccesDossier>  $ouvertures
     */
    private function ouvertureDe(AccesDossier $cloture, Collection $ouvertures): ?AccesDossier
    {
        if ($cloture->acces_ouverture_id !== null) {
            return $ouvertures->firstWhere('id', $cloture->acces_ouverture_id);
        }

        if ($cloture->token_qr_id !== null) {
            return $ouvertures->firstWhere('token_qr_id', $cloture->token_qr_id);
        }

        return null;
    }

    /**
     * Compose une visite lisible à partir de ses une ou deux lignes de journal.
     *
     * `ip_address` n'apparaît nulle part, pour personne : elle n'apprend rien à une famille et
     * désigne un lieu de connexion. Elle reste dans le journal brut, réservé au propriétaire.
     *
     * @return array<string, mixed>
     */
    private function composer(MembreFamille $membre, AccesDossier $ouverture, ?AccesDossier $cloture): array
    {
        $porteuse = $cloture ?? $ouverture;
        $agent    = $ouverture->agent ?? $porteuse->agent;

        return [
            'id'            => $ouverture->id,
            'type'          => $ouverture->type_acces,
            'type_libelle'  => TypeAccesDossier::libelleDe($ouverture->type_acces),
            'a'             => $ouverture->created_at?->toIso8601String(),
            'agent'         => $agent !== null ? trim($agent->prenom.' '.$agent->nom) : null,
            // NULL sur les lignes écrites avant D2 : la fiche dira « établissement non enregistré »
            // plutôt que de le déduire du compte de l'agent, qui a pu changer d'hôpital depuis.
            'etablissement' => $porteuse->etablissement,
            'cloturee'      => $cloture !== null,
            'duree_minutes' => $cloture?->duree_minutes,
            // Le motif d'un accès d'urgence vitale est montré à toute l'audience de la fiche : un
            // accès sans consentement doit rester explicable par ceux qu'il concerne.
            'motif_urgence'       => $porteuse->motif_urgence,
            'sections_consultees' => $cloture?->sections_consultees ?? [],
            'entrees'             => $this->entreesEcrites($membre, $cloture),
        ];
    }

    /**
     * Ce qui a été ÉCRIT pendant cette visite — lien certain, attesté par le journal.
     *
     * `donnees_ajoutees` ne contient que section, identifiant et horodatage (minimisation, loi
     * 2013-450). C'est ici qu'on relit la ligne réelle, par le chemin de la section — le même
     * registre qu'aux incréments C et D0, jamais une table nommée en dur.
     *
     * @return array<int, array<string, mixed>>
     */
    private function entreesEcrites(MembreFamille $membre, ?AccesDossier $cloture): array
    {
        $entrees = [];

        foreach ($cloture?->donnees_ajoutees ?? [] as $trace) {
            $section = $trace['section'] ?? null;
            $id      = $trace['id'] ?? null;

            if ($section === null || $id === null || ! RegistreSectionsCarnet::existe($section)) {
                continue;
            }

            $ligne = $this->ligneDeSection($membre, $section, $id);

            // Une entrée supprimée depuis reste tracée dans le journal immuable, mais n'a plus de
            // contenu. On l'annonce comme telle plutôt que de faire disparaître la trace.
            $entrees[] = [
                'section'         => $section,
                'id'             => $id,
                'a'              => $trace['a'] ?? null,
                'libelle'        => $ligne !== null ? $this->libelleEntree($section, $ligne) : 'Entrée supprimée depuis',
                'toujours_au_carnet' => $ligne !== null,
            ];
        }

        return $entrees;
    }

    /**
     * Les entrées médicales de la période qu'AUCUNE visite ne réclame.
     *
     * Ce sont des faits : leur provenance est `medecin` ou `structure`. Mais rien ne dit à quelle
     * consultation elles se rattachent — les entrées écrites avant D0 n'ont jamais eu de lien de
     * journal. Elles sont donc présentées à part, comme un rapprochement possible, jamais comme le
     * contenu d'une visite (décision propriétaire).
     *
     * @param  array<int, string>  $dejaLiees
     * @return array<int, array<string, mixed>>
     */
    private function autresEntrees(MembreFamille $membre, Carbon $depuis, array $dejaLiees): array
    {
        $entrees = [];

        foreach (RegistreSectionsCarnet::SECTIONS_SOIGNANT as $section) {
            $relation = RegistreSectionsCarnet::controleur($section)->nomRelation();

            $lignes = $membre->{$relation}()
                ->whereIn('source', ['medecin', 'structure'])
                ->where('created_at', '>=', $depuis)
                ->orderByDesc('created_at')
                ->get();

            foreach ($lignes as $ligne) {
                if (in_array($section.'#'.$ligne->id, $dejaLiees, true)) {
                    continue;
                }

                $entrees[] = [
                    'section' => $section,
                    'id'      => $ligne->id,
                    'a'       => $ligne->created_at?->toIso8601String(),
                    'libelle' => $this->libelleEntree($section, $ligne),
                ];
            }
        }

        return collect($entrees)->sortByDesc('a')->values()->all();
    }

    /**
     * Les contributions de la période, avec leur statut.
     *
     * C'est le cœur du scénario : le responsable voit côte à côte ce qu'on lui demande de valider
     * et le passage à l'hôpital qui le motive. Le statut vient du serveur — jamais déduit ailleurs.
     *
     * @return array<int, array<string, mixed>>
     */
    private function contributions(MembreFamille $membre, Carbon $depuis): array
    {
        return Contribution::query()
            ->where('membre_id', $membre->id)
            ->where('created_at', '>=', $depuis)
            ->with('auteur:id,nom,prenom')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Contribution $c) => [
                'id'      => $c->id,
                'section' => $c->section,
                'statut'  => $c->statut,
                'auteur'  => $c->auteur !== null ? trim($c->auteur->prenom.' '.$c->auteur->nom) : null,
                'a'       => $c->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Les entrées déjà rattachées à une visite, sous la forme `section#id`.
     *
     * @param  Collection<int, array<string, mixed>>  $visites
     * @return array<int, string>
     */
    private function identifiantsLies(Collection $visites): array
    {
        return $visites
            ->flatMap(fn (array $visite) => array_map(
                fn (array $e) => $e['section'].'#'.$e['id'],
                $visite['entrees'],
            ))
            ->all();
    }

    /** Relit une ligne de section par son identifiant, via le registre (jamais une table en dur). */
    private function ligneDeSection(MembreFamille $membre, string $section, int|string $id): mixed
    {
        $relation = RegistreSectionsCarnet::controleur($section)->nomRelation();

        return $membre->{$relation}()->find($id);
    }

    /**
     * Une phrase qui dit ce qu'est l'entrée, SANS son contenu clinique.
     *
     * « Ordonnance du Dr Aka Konan », jamais le nom du médicament. La fiche sert à retrouver et à
     * situer ; le contenu reste dans le carnet, où il est déjà lisible par les mêmes personnes.
     * Cette retenue a un effet concret : la fiche peut être parcourue sans étaler un traitement à
     * l'écran, et les champs chiffrés au repos (ordonnance, résultats, description d'antécédent)
     * n'ont pas besoin d'être déchiffrés pour la construire.
     */
    private function libelleEntree(string $section, mixed $ligne): string
    {
        return match ($section) {
            'antecedents'        => 'Antécédent : '.str_replace('_', ' ', (string) $ligne->type),
            'vaccinations'       => 'Vaccination : '.$ligne->vaccin_nom,
            'ordonnances'        => 'Ordonnance'.($ligne->medecin_nom !== null ? ' du '.$ligne->medecin_nom : ''),
            'resultats-analyses' => 'Analyse : '.$ligne->intitule,
            default              => ucfirst($section),
        };
    }
}
