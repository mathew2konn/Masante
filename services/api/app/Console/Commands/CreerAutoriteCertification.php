<?php

namespace App\Console\Commands;

use App\Models\AutoriteCertification as ModeleAutorite;
use App\Services\Pki\AutoriteCertification;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * P6.5b — Crée l'autorité de certification racine (CDC_10 §4.1).
 *
 * ═══ POURQUOI UNE COMMANDE, ET PAS UN SEEDER ═══
 *
 * Un seeder s'exécute avec `db:seed`, souvent en série, souvent sans qu'on y pense. Créer une
 * autorité racine est un acte fondateur : tous les certificats et donc toutes les signatures en
 * dépendent. Le faire sans intention explicite serait le genre d'automatisme qu'on regrette.
 *
 * C'est le même raisonnement qu'en P6.3, où le seeder du registre n'enregistre RIEN au-delà du
 * registre : publier depuis un seeder aurait contourné la gouvernance dès le premier jour.
 *
 * ═══ IDEMPOTENTE PAR REFUS, PAS PAR SILENCE ═══
 *
 * Si une autorité active existe, la commande s'arrête et le dit. Une commande qui aurait
 * silencieusement « rien fait » se rejouerait sans crainte — et un jour, avec un `--force` ajouté
 * à la hâte, elle régénérerait la CA et invaliderait toutes les signatures posées.
 *
 *   XDEBUG_MODE=off php artisan masante:pki:autorite
 */
class CreerAutoriteCertification extends Command
{
    protected $signature = 'masante:pki:autorite {--afficher : Affiche l\'autorité existante sans rien créer}';

    protected $description = "Crée l'autorité de certification racine de MaSanté (CDC_10 §4.1)";

    public function handle(AutoriteCertification $autorite): int
    {
        $existante = ModeleAutorite::where('actif', true)->first();

        if ($this->option('afficher')) {
            if ($existante === null) {
                $this->warn('Aucune autorité racine active.');

                return self::SUCCESS;
            }

            $this->afficher($existante);

            return self::SUCCESS;
        }

        if ($existante !== null) {
            $this->error('Une autorité racine active existe déjà — rien n\'a été créé.');
            $this->line('  La régénérer invaliderait TOUS les certificats émis, donc toutes les');
            $this->line('  signatures déjà posées. Pour en changer, il faut une procédure de');
            $this->line('  migration, pas une commande.');
            $this->newLine();
            $this->afficher($existante);

            return self::FAILURE;
        }

        try {
            $creee = $autorite->creerAutorite();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Autorité racine créée.');
        $this->afficher($creee);
        $this->newLine();
        $this->warn('Rappel : cette autorité est AUTO-SIGNÉE. Aucune autorité de certification');
        $this->warn('nationale n\'a été consultée — voir ADR-032. Elle lie une prescription à un');
        $this->warn('praticien DANS cette plateforme, elle ne vaut pas confiance publique.');

        return self::SUCCESS;
    }

    private function afficher(ModeleAutorite $autorite): void
    {
        $this->table(['Champ', 'Valeur'], [
            ['Nom', $autorite->nom],
            ['Pays', $autorite->pays_code],
            ['Numéro de série', $autorite->numero_serie],
            ['Empreinte SHA-256', $autorite->empreinte],
            ['Valide du', $autorite->valide_du->format('d/m/Y')],
            ['Valide jusqu\'au', $autorite->valide_jusqu_a->format('d/m/Y')],
        ]);
    }
}
