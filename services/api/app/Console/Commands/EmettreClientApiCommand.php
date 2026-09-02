<?php

namespace App\Console\Commands;

use App\Models\ClientApi;
use App\Models\StructureSanitaire;
use Illuminate\Console\Command;

/**
 * P11.2 — Émet une clé d'API pour le logiciel d'un établissement (CDC_11 §7.7, ADR-030).
 *
 * ═══ POURQUOI UNE COMMANDE, ET PAS UN ÉCRAN ═══
 *
 * Émettre une identité machine qui écrira au nom d'un établissement est un acte d'exploitation,
 * pas de gestion courante : il suppose qu'on ait vérifié à qui l'on parle, hors du système. Le
 * précédent est l'autorité de certification de P6.5b, qui est une commande pour la même raison.
 *
 * **Limite annoncée** : l'établissement ne peut donc pas émettre ni faire tourner sa propre clé
 * lui-même. C'est une régression d'ergonomie assumée pour cet incrément, pas un principe — l'écran
 * viendra, et il appellera ce même chemin.
 *
 * ═══ LE SECRET N'EST AFFICHÉ QU'UNE FOIS ═══
 *
 * Il est stocké chiffré parce que vérifier un HMAC exige le secret lui-même, pas son empreinte —
 * mais il n'est **jamais réaffiché**. Un secret perdu se **révoque et se réémet** : c'est la même
 * règle que le certificat de signature de P6.5b, et pour la même raison — offrir un chemin de
 * récupération offrirait le même chemin à qui n'y a pas droit.
 */
class EmettreClientApiCommand extends Command
{
    protected $signature = 'masante:integration:emettre
                            {structure : Identifiant de l\'établissement}
                            {libelle : Nom du logiciel partenaire (ex. « Caisse Sage Officine v4 »)}
                            {--domaine=stock_officine : Domaine ouvert à cette clé}
                            {--revoquer= : Identifiant de client à révoquer au lieu d\'émettre}
                            {--motif= : Motif de révocation (obligatoire avec --revoquer)}';

    protected $description = 'Émet ou révoque une clé d\'API pour le logiciel d\'un établissement (CDC_11 §7.7)';

    public function handle(): int
    {
        if ($this->option('revoquer') !== null) {
            return $this->revoquer();
        }

        return $this->emettre();
    }

    private function emettre(): int
    {
        $structure = StructureSanitaire::find($this->argument('structure'));

        if ($structure === null) {
            $this->error('Établissement introuvable.');

            return self::FAILURE;
        }

        $domaine = (string) $this->option('domaine');

        if (! in_array($domaine, ClientApi::DOMAINES, true)) {
            // Liste blanche FERMÉE : le domaine arrive par une option, donc par l'extérieur.
            $this->error('Domaine inconnu. Domaines ouverts : '.implode(', ', ClientApi::DOMAINES));

            return self::FAILURE;
        }

        $secret = ClientApi::genererSecret();

        $client = new ClientApi([
            'structure_id' => $structure->id,
            'libelle' => (string) $this->argument('libelle'),
            'domaines_json' => [$domaine],
        ]);
        $client->identifiant = ClientApi::genererIdentifiant();
        $client->secret_chiffre = $secret;
        $client->save();

        $this->newLine();
        $this->info('Clé émise pour « '.$structure->nom.' ».');
        $this->table(['', 'Valeur'], [
            ['X-MaSante-Client', $client->identifiant],
            ['Secret', $secret],
            ['Domaine', $domaine],
        ]);
        $this->warn('Ce secret ne sera plus jamais affiché. Transmettez-le par un canal sûr ;');
        $this->warn('en cas de perte, révoquez cette clé et émettez-en une autre.');
        $this->newLine();
        $this->line('Signature attendue : base64(hmac_sha256(timestamp + "." + corps_brut, secret))');
        $this->line('En-têtes : X-MaSante-Client, X-MaSante-Timestamp, X-MaSante-Signature, Idempotency-Key');

        return self::SUCCESS;
    }

    private function revoquer(): int
    {
        $motif = trim((string) $this->option('motif'));

        if ($motif === '') {
            // Sans défaut, et l'échec est bruyant : une clé révoquée sans raison ne dit à
            // personne, dans six mois, s'il faut la réémettre (précédent de la commission sans
            // seed de P5.5a, et du motif de rejet de P11.1).
            $this->error('Une révocation doit porter son motif (--motif).');

            return self::FAILURE;
        }

        $client = ClientApi::query()->where('identifiant', $this->option('revoquer'))->first();

        if ($client === null) {
            $this->error('Client introuvable.');

            return self::FAILURE;
        }

        if (! $client->estActif()) {
            $this->warn('Ce client était déjà révoqué le '.$client->revoque_le->toDateTimeString().'.');

            return self::SUCCESS;
        }

        $client->forceFill(['revoque_le' => now(), 'revoque_motif' => $motif])->save();

        $this->info('Client « '.$client->libelle.' » révoqué. Ses envois seront refusés dès maintenant.');

        return self::SUCCESS;
    }
}
