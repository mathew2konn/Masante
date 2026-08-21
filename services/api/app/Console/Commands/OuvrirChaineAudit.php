<?php

namespace App\Console\Commands;

use App\Services\Audit\ChaineAudit;
use App\Services\Audit\JournalChaine;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Scelle la chaîne d'audit courante d'un journal et en ouvre une neuve.
 *
 * ═══ POURQUOI UNE COMMANDE, ET NON UN ENDPOINT ═══
 *
 * C'est une étape de déploiement, au même titre que les mises en vigueur de L1+L2 et de P10b-1 :
 * un geste rare, fait par un exploitant sur le serveur, tracé. L'exposer en HTTP en ferait une
 * opération de tous les jours, et un journal d'audit dont on tourne la page depuis un navigateur
 * n'est plus un journal d'audit.
 *
 * ═══ CE QUE LA COMMANDE NE FAIT PAS ═══
 *
 * Elle ne supprime rien, ne corrige rien, ne recalcule aucune empreinte. Les entrées de la chaîne
 * scellée restent en base, lisibles et vérifiables : `verdict_actuel` continue de les recalculer
 * après le scellement, pour qu'une altération postérieure reste visible.
 */
class OuvrirChaineAudit extends Command
{
    protected $signature = 'masante:audit:ouvrir-chaine
                            {journal : Nom du journal (voir la liste blanche)}
                            {--motif= : Raison écrite du scellement (obligatoire)}
                            {--acteur=Système : Nom de l\'opérateur qui scelle}
                            {--dry-run : Montre ce qui serait scellé, sans rien écrire}';

    protected $description = "Scelle la chaîne d'audit courante d'un journal et en ouvre une nouvelle.";

    public function handle(): int
    {
        $nom = (string) $this->argument('journal');

        if (! ChaineAudit::connu($nom)) {
            $this->error("Journal inconnu : « {$nom} ».");
            $this->line('Journaux gouvernés : '.implode(', ', ChaineAudit::noms()).'.');

            return self::FAILURE;
        }

        /** @var JournalChaine $journal */
        $journal = app(ChaineAudit::JOURNAUX[$nom]);

        $etat = $journal->verifierChaine();

        $this->line("Journal        : {$nom}");
        $this->line("Chaîne courante: #{$etat['chaine_courante']}  ({$etat['entrees']} entrée(s))");
        $this->line('Origine        : '.($etat['origine_declaree'] ? 'déclarée' : 'NON DÉCLARÉE'));
        $this->line('Verdict        : '.($etat['intacte'] ? 'intacte' : 'ROMPUE — '.($etat['rupture']['type'] ?? '?')));

        if ($etat['rupture'] !== null) {
            $this->warn('  '.$etat['rupture']['message']);
        }

        // Le motif n'a pas de valeur par défaut : un scellement sans raison écrite serait un
        // effacement d'historique déguisé en maintenance (précédent P5.5a).
        $motif = trim((string) $this->option('motif'));

        if ($motif === '') {
            $this->error('Un scellement exige --motif="…" : sans raison écrite, la trace du geste ne vaut rien.');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->info("[simulation] La chaîne #{$etat['chaine_courante']} serait scellée EN L'ÉTAT "
                .'ci-dessus, et la #'.($etat['chaine_courante'] + 1).' ouverte.');

            return self::SUCCESS;
        }

        try {
            $ouverture = ChaineAudit::ouvrir($journal, $motif, (string) $this->option('acteur'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Chaîne #{$ouverture->numero} ouverte. La précédente est scellée avec son verdict.");
        $this->line('Les entrées scellées ne sont ni supprimées ni modifiées : elles restent vérifiables.');

        return self::SUCCESS;
    }
}
