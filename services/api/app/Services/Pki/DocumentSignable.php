<?php

namespace App\Services\Pki;

use Illuminate\Database\Eloquent\Model;

/**
 * Contrat d'un document que la PKI sait signer (CDC_10 §4.5).
 *
 * POURQUOI UNE INTERFACE plutôt qu'un `if ($type === 'ordonnance')` dans le service de signature :
 * même principe d'ouverture/fermeture que `SourceReferentiel` pour les référentiels et que la
 * passerelle de paiement pour les canaux. **Brancher un type de document de plus doit être un
 * AJOUT de classe, jamais une modification du moteur** — c'est ce qui rend tenable l'engagement
 * pris au plan G1 : les cinq entités documentaires manquantes se brancheront ici, une classe et
 * une ligne chacune.
 *
 * Un document signable fait deux choses, et deux seulement :
 *   - se retrouver par son identifiant ;
 *   - produire le CONTENU CANONIQUE qui sera signé.
 *
 * Il ne signe rien, ne vérifie rien, ne journalise rien.
 */
interface DocumentSignable
{
    /** Code stable — celui qui part en base dans `signatures_electroniques.type_document`. */
    public function code(): string;

    public function libelle(): string;

    public function trouver(int $id): ?Model;

    /**
     * Le contenu à signer, en CLAIR et sous forme structurée.
     *
     * ═══ LA RÈGLE QUI COMMANDE TOUTES LES IMPLÉMENTATIONS ═══
     *
     * On signe le SENS du document, jamais sa représentation stockée. `ordonnances.medicaments_json`
     * est chiffré au repos : signer les octets de la base ferait casser la signature au premier
     * rechiffrement, sans qu'aucune donnée n'ait bougé. C'est le piège évité en P6.4c pour
     * l'empreinte des images, et il est plus grave ici — une signature qui casse toute seule ne
     * prouve plus rien, et pire, elle accuse.
     *
     * DÉTERMINISME EXIGÉ : deux appels sur un document inchangé doivent produire exactement le
     * même tableau. La canonicalisation (tri des clés) est faite en aval par
     * `EmpreinteReferentiel` ; ce qui est exigé ici, c'est de ne pas y glisser d'horodatage de
     * lecture, d'identifiant de session ou de valeur aléatoire.
     *
     * NE JAMAIS Y METTRE `updated_at` : le patient peut corriger une faute de frappe dans un champ
     * non signé, et la signature n'a pas à se casser pour cela.
     *
     * @return array<string, mixed>
     */
    public function contenuCanonique(Model $document): array;
}
