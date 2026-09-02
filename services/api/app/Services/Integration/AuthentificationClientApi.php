<?php

namespace App\Services\Integration;

use App\Models\ClientApi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * P11.2 — Authentification d'un partenaire : la troisième population.
 *
 * ═══ POURQUOI PAS OAUTH2, ALORS QU'ADR-030 LE NOMMAIT ═══
 *
 * ADR-030 posait « OAuth2 client credentials » pour les tiers, en notant qu'il n'existait ni
 * Passport ni `league/oauth2` dans ce projet. Les installer serait une dépendance (§2.6) et un
 * point de terminaison de jetons **qu'aucun partenaire réel ne viendrait éprouver** — aucun n'a
 * été consulté, ADR-030 le dit lui-même.
 *
 * Or ce projet a déjà un mécanisme **éprouvé en production** pour exactement ce problème : le
 * **montage A de GeniusPay** (P5.6b), où un secret marchand par établissement est atteint par un
 * identifiant opaque, et où **l'identifiant sélectionne pendant que le HMAC décide** — jamais de
 * boucle d'essai, qui coûterait O(n) et offrirait un oracle de temps à l'attaquant. C'est ce
 * mécanisme, retourné : là il vérifiait un webhook entrant, ici il vérifie un envoi entrant.
 *
 * **Amendement assumé à ADR-030, réversible** : le jour où un partenaire exige OAuth2, ce service
 * est le seul point à remplacer — le contrat d'ingestion ne le connaît pas.
 *
 * ═══ CE QUI EST VÉRIFIÉ, ET POURQUOI CHAQUE CONTRÔLE ═══
 *
 * La signature porte sur le **corps brut**, jamais sur du JSON ré-encodé. C'est la trouvaille de
 * la phase 6 de P5.6b : les exemples PHP/Node/Python du prestataire ré-encodaient le JSON avant
 * de signer (`10000.00` devenait `10000.0`) et produisaient une signature différente de celle du
 * serveur, qui signait les octets reçus. **Vérifier sur les octets reçus était la bonne méthode**,
 * et c'est celle retenue ici.
 *
 * S'y ajoutent la **fraîcheur** (un envoi capté ne peut pas être rejoué des heures plus tard), la
 * **liaison au chemin** (une signature valide pour un flux ne vaut pas pour un autre) et
 * l'**anti-rejeu par nonce** atomique. Aucun n'est décoratif : sans fraîcheur, l'anti-rejeu
 * devrait mémoriser indéfiniment ; sans liaison au chemin, une clé de stock signerait des
 * résultats de laboratoire le jour où ce flux existera.
 *
 * **Une seule cause d'échec est exposée : « refusé », 401 sans détail.** Le motif précis est
 * journalisé, jamais renvoyé — un attaquant ne doit rien apprendre de la raison exacte du refus
 * (même règle que `VerificateurPrincipalSigne`).
 */
class AuthentificationClientApi
{
    /** Fenêtre de fraîcheur, en secondes. Même ordre de grandeur que le principal signé. */
    private const FENETRE_FRAICHEUR = 300;

    /**
     * Authentifie l'appel et rend le client. Le domaine est vérifié ici : une clé émise pour un
     * logiciel d'officine ne doit pas pouvoir alimenter un autre flux.
     *
     * @throws RuntimeException toujours avec le même message côté appelant (401 générique).
     */
    public function authentifier(Request $request, string $domaine): ClientApi
    {
        $identifiant = trim((string) $request->header('X-MaSante-Client', ''));
        $signature = trim((string) $request->header('X-MaSante-Signature', ''));
        $horodatage = (int) $request->header('X-MaSante-Timestamp', '0');

        if ($identifiant === '' || $signature === '' || $horodatage === 0) {
            throw new RuntimeException('En-têtes d’authentification absents.');
        }

        // FRAÎCHEUR AVANT TOUT ACCÈS À LA BASE : un envoi périmé ne mérite pas une requête.
        $ecart = abs(now()->timestamp - $horodatage);
        if ($ecart > self::FENETRE_FRAICHEUR) {
            throw new RuntimeException('Horodatage hors fenêtre ('.$ecart.' s).');
        }

        // L'identifiant SÉLECTIONNE le candidat. Il ne prouve rien : c'est le HMAC qui décide.
        $client = ClientApi::query()->where('identifiant', $identifiant)->first();

        if ($client === null) {
            throw new RuntimeException('Identifiant de client inconnu.');
        }

        if (! $client->estActif()) {
            throw new RuntimeException('Client révoqué le '.$client->revoque_le?->toDateString().'.');
        }

        // Le CORPS BRUT, jamais un JSON ré-encodé (leçon de la phase 6 de P5.6b).
        $charge = $horodatage.'.'.$request->getContent();
        $attendue = base64_encode(hash_hmac('sha256', $charge, (string) $client->secret_chiffre, true));

        // Comparaison à temps constant : elle ne fuit pas la position du premier octet divergent.
        if (! hash_equals($attendue, $signature)) {
            throw new RuntimeException('Signature invalide.');
        }

        // Liaison au chemin : la signature ne porte pas le chemin, donc c'est ici qu'on refuse
        // qu'une clé serve un flux qu'elle n'a pas reçu. Deux gardes distinctes, pas une seule
        // à deux effets — la première dit « ce n'est pas vous », la seconde « ce n'est pas à vous ».
        if (! $client->couvre($domaine)) {
            throw new RuntimeException('Domaine « '.$domaine.' » non ouvert à ce client.');
        }

        // ANTI-REJEU ATOMIQUE. `Cache::add()` échoue si la clé existe : c'est un vrai verrou, pas
        // un « lire puis écrire » qui laisserait passer deux envois simultanés. La durée de vie
        // couvre exactement la fenêtre de fraîcheur — au-delà, l'horodatage refuse déjà.
        $empreinteEnvoi = hash('sha256', $identifiant.'|'.$signature);
        if (! Cache::add('ingestion:rejeu:'.$empreinteEnvoi, 1, self::FENETRE_FRAICHEUR)) {
            throw new RuntimeException('Envoi déjà présenté (rejeu).');
        }

        $client->forceFill(['dernier_appel_le' => now()])->save();

        return $client;
    }
}
