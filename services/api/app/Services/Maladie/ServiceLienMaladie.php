<?php

namespace App\Services\Maladie;

use App\Models\Maladie;
use Illuminate\Validation\ValidationException;

/**
 * P6.8c — Le lien d'une ligne vers le référentiel des maladies, résolu et FIGÉ par le serveur.
 *
 * ═══ LE LIEN EST FACULTATIF, ET C'EST UNE DÉCISION ═══
 *
 * Un patient qui recopie un carnet papier n'a pas la liste sous les yeux, et le référentiel livré
 * est un jeu de démonstration : l'imposer ferait de ses LACUNES un blocage clinique (motif P6.6b).
 * Pour une alerte épidémique la raison est plus forte encore — une maladie émergente n'est dans
 * aucune nomenclature au moment où elle émerge (décision propriétaire E4).
 *
 * ═══ MAIS QUAND IL EST FOURNI, LE SERVEUR NE CROIT RIEN DU CLIENT ═══
 *
 * Le code national et le libellé sont RELUS à la version publiée et FIGÉS sur la ligne — figés comme
 * en P6.6b, P6.7b et P6.8b, pour qu'une correction ultérieure du référentiel ne réécrive pas ce qui
 * a été inscrit au carnet ou dans une alerte déjà diffusée.
 *
 * ═══ LE SERVEUR NE DEVINE JAMAIS UNE MALADIE ═══
 *
 * Aucun rapprochement automatique entre `antecedents.description` — « diabète », « DT2 » — et une
 * entrée du référentiel. Ce serait un **diagnostic posé par une machine** (CDC_00 §4). Le lien est
 * DÉCLARÉ par l'humain qui saisit, comme le prescripteur et le laboratoire en P6.7b. Et
 * `description` n'est **jamais réécrite** : le lien s'ajoute À CÔTÉ des mots du patient — c'est la
 * leçon de P6.7a, dont la réécriture inscrivait le nom du mauvais médecin.
 *
 * ═══ LA GARANTIE VAUT SUR LES TROIS CHEMINS D'ÉCRITURE ═══
 *
 * Appelé depuis `preparerDonnees()`, donc par le patient, par le délégué (contribution, résolue AU
 * DÉPÔT) et par le soignant — et aussi sur le `PUT`. *Une garantie qui ne vaudrait que sur l'un des
 * chemins n'en serait pas une* (leçon P6.8b, où le `update()` avait été oublié).
 */
final class ServiceLienMaladie
{
    public function __construct(private readonly ServiceMaladies $maladies) {}

    /**
     * Résout le lien d'un antécédent et pose les valeurs que le serveur sait.
     *
     * @param  array<string, mixed>  $valide
     * @return array<string, mixed>
     *
     * @throws ValidationException si le lien ne désigne rien dans la version en vigueur
     */
    public function resoudreAntecedent(array $valide): array
    {
        return $this->resoudreCodeEtLibelle($valide);
    }

    /**
     * Résout le lien d'un DIAGNOSTIC de consultation (B2-b).
     *
     * MÊME MÉCANIQUE QUE L'ANTÉCÉDENT, ET C'EST VOULU : les deux inscrivent une maladie codée dans
     * le dossier d'un patient, avec code et libellé FIGÉS à côté des mots du soignant, qui ne sont
     * jamais réécrits. L'écrire une seconde fois la laisserait diverger — et elle divergerait du
     * côté qu'on regarde le moins.
     *
     * CE QUI DIFFÈRE N'EST PAS ICI MAIS DANS LA TABLE : un antécédent SUIT le patient et pèse sur
     * ses triages futurs (`impact_triage`), un diagnostic DATE d'un épisode. C'est pourquoi
     * `diagnostics` est une table à part, et pourquoi la promotion de l'un vers l'autre est un acte
     * délibéré du médecin, jamais une conséquence de la saisie.
     *
     * @param  array<string, mixed>  $valide
     * @return array<string, mixed>
     *
     * @throws ValidationException si le lien ne désigne rien dans la version en vigueur
     */
    public function resoudreDiagnostic(array $valide): array
    {
        return $this->resoudreCodeEtLibelle($valide);
    }

    /**
     * Le geste commun aux deux : effacer ce que le client aurait pu déclarer, puis reposer ce que
     * la version publiée du référentiel dit.
     *
     * @param  array<string, mixed>  $valide
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function resoudreCodeEtLibelle(array $valide): array
    {
        // JAMAIS du client, sur aucun chemin : on les efface avant de les reposer nous-mêmes. C'est
        // la SECONDE couche, celle qui tient face à un import ou à un appel direct au service — la
        // première étant les règles de validation. Leçon de la mutation de P6.6b : un vecteur qui ne
        // teste que le validateur ne teste pas le service.
        unset($valide['maladie_code'], $valide['maladie_libelle']);

        if (! isset($valide['maladie_id'])) {
            return $valide;
        }

        $publiee = $this->exigerPubliee((int) $valide['maladie_id'], 'maladie_id');

        $valide['maladie_code']    = $publiee['code'];
        $valide['maladie_libelle'] = $publiee['libelle'];

        return $valide;
    }

    /**
     * Résout le lien d'une alerte épidémique.
     *
     * DIFFÉRENCE ASSUMÉE AVEC L'ANTÉCÉDENT : ici le libellé porté par la ligne (`maladie`) est
     * ALIGNÉ sur la version publiée quand un lien existe, parce que c'est LUI que le mobile affiche.
     * Garder deux noms différents dans la même ligne laisserait le lecteur choisir lequel croire
     * (motif P6.7b). Sur un antécédent, au contraire, `description` appartient au patient et n'est
     * jamais touchée : ce ne sont pas les mêmes données, ce ne sont pas les mêmes règles.
     *
     * @param  array<string, mixed>  $valide
     * @return array<string, mixed>
     *
     * @throws ValidationException si le lien ne désigne rien dans la version en vigueur
     */
    public function resoudreAlerte(array $valide): array
    {
        unset($valide['maladie_code']);

        if (($valide['maladie_id'] ?? null) === null) {
            // E4 — l'alerte reste publiable en texte libre. L'écart est compté et affiché au
            // portail, jamais tu : c'est le témoin qui rend la lacune visible sans la bloquer.
            $valide['maladie_id'] = null;

            return $valide;
        }

        $publiee = $this->exigerPubliee((int) $valide['maladie_id'], 'maladie_id');

        $valide['maladie_code'] = $publiee['code'];
        $valide['maladie']      = $publiee['libelle'];

        return $valide;
    }

    /**
     * Avertissements NON BLOQUANTS joints à la réponse de création.
     *
     * UNE MALADIE DÉSACTIVÉE EST SIGNALÉE, JAMAIS REFUSÉE — décision identique à celle des
     * médicaments retirés (P6.6a/b) et des vaccins retirés (P6.8b) : refuser d'inscrire au carnet un
     * antécédent réel parce que l'entrée a été retirée du référentiel effacerait un fait médical, et
     * refuser serait une décision médicale prise par une machine (CDC_00 §4).
     *
     * @return array<int, array<string, mixed>>
     */
    public function avertissements(?string $code): array
    {
        if ($code === null) {
            return [];
        }

        $publiee = $this->maladies->maladiePubliee($code);

        if ($publiee === null || ($publiee['actif'] ?? true)) {
            return [];
        }

        return [[
            'code'    => 'maladie_retiree',
            'message' => sprintf(
                '« %s » a été retirée du référentiel national des maladies. L\'entrée est '
                .'enregistrée ; signalez-le au professionnel qui suit ce dossier.',
                $publiee['libelle'],
            ),
        ]];
    }

    /**
     * DEUX LECTURES, ET CHACUNE RÉPOND À UNE QUESTION DIFFÉRENTE (motif P6.8b).
     *
     * La TABLE répond de l'intégrité référentielle : `maladie_id` est une clé étrangère, elle doit
     * désigner une ligne réelle. La VERSION PUBLIÉE fait autorité sur le CONTENU — code et libellé.
     * Lire le contenu dans la table rendrait la gouvernance décorative : un `UPDATE` direct
     * changerait ce qui s'inscrit dans les carnets sans relecture ni quatre-yeux, ce qui est
     * exactement le défaut que L1+L2 ont dû refermer pour `seuils_mesure` (ADR-025 §5/§6).
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function exigerPubliee(int $id, string $champ): array
    {
        $maladie = Maladie::find($id);

        if ($maladie === null) {
            throw ValidationException::withMessages([
                $champ => "La maladie n°{$id} n'existe pas au référentiel national.",
            ]);
        }

        $publiee = $maladie->code !== null ? $this->maladies->maladiePubliee($maladie->code) : null;

        if ($publiee === null) {
            // Le refus est BRUYANT et attribué au champ que l'utilisateur a rempli. Accepter le lien
            // en lisant la table laisserait rattacher une maladie que personne n'a mise en vigueur ;
            // l'ignorer en silence ferait croire à un rattachement qui n'a pas eu lieu.
            throw ValidationException::withMessages([
                $champ => sprintf(
                    '« %s » ne figure pas dans la version en vigueur du référentiel national des '
                    .'maladies : l\'entrée peut être enregistrée sans être rattachée.',
                    $maladie->libelle,
                ),
            ]);
        }

        return $publiee;
    }
}
