<?php

namespace App\Services\Triage;

use App\Models\JeuDonneesEntrainement;
use App\Models\User;
use App\Models\ValidationMedecin;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * P10c-2-i (F4) — La revue médicale du jeu d'apprentissage, avant tout export (CDC_05 §7.2).
 *
 * ═══ PERMISSION ORPHELINE, ET LA RAISON EST LITTÉRALE ═══
 *
 * `apprentissage.valider` n'est portée par AUCUN rôle métier — même famille que les permissions
 * référentielles nationales (P6.3 et suivants), pour une raison différente de `triage.retour` :
 * valider une ligne n'est PAS un acte de soin au chevet d'un patient identifié, c'est juger un cas
 * PSEUDONYMISÉ qui alimentera un modèle national. La confier au rôle `medecin` sans distinction
 * aurait laissé n'importe quel soignant décider seul de ce qui entraîne un modèle pour tout le
 * pays — elle est donc accordée individuellement, comme les référentiels.
 *
 * ═══ UNE LIGNE NON VALIDÉE N'ENTRE JAMAIS DANS UN EXPORT — PROUVABLE DÈS MAINTENANT ═══
 *
 * {@see pretsPourExport()} est le contrôle que le futur export de P10c-3 consultera. Il est
 * prouvable par vecteur aujourd'hui, avant qu'un export existe — ce n'est donc pas le socle à vide
 * refusé par P6.3-D3.
 */
class ServiceValidationApprentissage
{
    public function valider(User $medecin, JeuDonneesEntrainement $jeu): ValidationMedecin
    {
        return $this->decider($medecin, $jeu, 'valide', null);
    }

    /** @param  string  $motif  Obligatoire — un rejet sans motif ne dit rien de ce qui cloche. */
    public function rejeter(User $medecin, JeuDonneesEntrainement $jeu, string $motif): ValidationMedecin
    {
        $motif = trim($motif);
        if ($motif === '') {
            throw new \RuntimeException(
                'Précisez pourquoi cette ligne est rejetée : un rejet sans motif ne permet pas de corriger.'
            );
        }

        return $this->decider($medecin, $jeu, 'rejete', $motif);
    }

    private function decider(User $medecin, JeuDonneesEntrainement $jeu, string $statut, ?string $motif): ValidationMedecin
    {
        if (! $medecin->can('apprentissage.valider')) {
            throw new \RuntimeException("Vous n'êtes pas habilité à valider le jeu d'apprentissage.");
        }

        if ($jeu->validation()->exists()) {
            throw new \RuntimeException('Cette ligne a déjà été décidée : une seule décision par ligne.');
        }

        return DB::transaction(fn (): ValidationMedecin => ValidationMedecin::create([
            'jeu_id' => $jeu->id,
            'valide_par' => $medecin->id,
            'statut' => $statut,
            'motif' => $motif,
            'decidee_le' => now(),
        ]));
    }

    /** Les lignes dont l'export de P10c-3 pourra se servir — jamais celles rejetées ou en attente. */
    public function pretsPourExport(): Builder
    {
        return JeuDonneesEntrainement::query()
            ->whereHas('validation', fn (Builder $q) => $q->where('statut', 'valide'));
    }
}
