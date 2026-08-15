<?php

namespace App\Models;

use App\Support\ReglesCalendrierVaccinal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Vaccination d'un membre (CdC §8.3, F2.7) — carnet de vaccination.
 */
class Vaccination extends Model
{
    protected $fillable = [
        'vaccin_nom',
        'date_administration',
        'date_rappel',
        // P6.8b — `statut` A DISPARU DE CETTE LISTE : plus personne ne l'écrit, il est calculé
        // ({@see statut()}). La colonne subsiste et devient nullable ; les lignes antérieures
        // conservent leur valeur, les nouvelles n'en portent aucune.
        //
        // `obligatoire` RESTE assignable, et c'est le piège de P6.7b : il est posé par
        // `ServiceLienVaccination` DANS le tableau validé, qui traverse `fill()`. Le retirer d'ici
        // ferait silencieusement écarter la valeur lue au calendrier — Eloquent n'aurait rien dit.
        // La garantie ne vient donc pas de `$fillable` mais du service, qui l'écrase toujours ;
        // chaque couche a son propre vecteur.
        'obligatoire',
        'centre_vaccination',
        'numero_lot',
        'medecin_nom',
        // D0 — provenance. Jusqu'ici absentes de cette table : l'incrément C les forçait en vain,
        // Eloquent les écartait sans bruit. `source` et `added_by` sont TOUJOURS réécrits par le
        // serveur (contribution → `patient`, écriture soignant → `medecin`) : les rendre
        // assignables ici n'ouvre donc rien au client.
        'added_by',
        'source',
        // P6.8b — le lien au référentiel national. Assignables parce que les trois chemins
        // d'écriture passent par une assignation de masse ; la garantie ne vient PAS de `$fillable`
        // mais de {@see App\Services\Vaccin\ServiceLienVaccination}, qui efface ces clés avant de
        // les reposer depuis le référentiel. Leçon de P6.7b : les valeurs figées doivent être
        // `$fillable`, et chaque couche a son propre vecteur.
        'vaccin_id',
        'vaccin_code',
        'numero_dose',
    ];

    /**
     * `statut` est SERVI même quand la colonne ne porte rien.
     *
     * Sans cela, une ligne fraîchement créée serait renvoyée sans statut : l'accesseur ne
     * s'applique d'office qu'aux attributs déjà chargés, or la colonne n'est plus écrite. Le
     * contrat de lecture doit rester exactement celui d'avant la bascule — c'est ce qui la rend
     * chirurgicale pour le cache hors ligne de P2 et les écrans validés G5.
     *
     * @var array<int, string>
     */
    protected $appends = ['statut'];

    protected function casts(): array
    {
        return [
            'obligatoire'         => 'boolean',
            'date_administration' => 'date',
            'date_rappel'         => 'date',
            'numero_dose'         => 'integer',
        ];
    }

    public function membre(): BelongsTo
    {
        return $this->belongsTo(MembreFamille::class, 'membre_id');
    }

    /** Le vaccin du référentiel national, quand la ligne y est rattachée. */
    public function vaccin(): BelongsTo
    {
        return $this->belongsTo(Vaccin::class, 'vaccin_id');
    }

    /**
     * ═══ P6.8b — LE STATUT EST CALCULÉ, IL N'EST PLUS STOCKÉ NI DÉCLARÉ ═══
     *
     * Frontière CDC_01 §0.1 : un état métier est FOURNI par le backend, jamais déclaré par le
     * client ni déduit par le front. Il l'était doublement ici — le formulaire du carnet en faisait
     * un menu déroulant obligatoire, et le serveur écrivait ce qu'on lui donnait.
     *
     * **CE QUE CET ACCESSEUR REFERME.** La valeur en base était écrite UNE FOIS, à la saisie, par
     * les trois chemins d'écriture, et rien ne repassait jamais dessus : un « à faire » dont la date
     * de rappel était dépassée depuis six mois restait « à faire », avec une pastille de couleur qui
     * lui donnait l'autorité d'un calcul. Il n'y a plus de valeur à rafraîchir, puisqu'il n'y a plus
     * de valeur stockée qui fasse autorité.
     *
     * **LA COLONNE RESTE** (ADR-024, additif) : elle est lue par le cache hors ligne de P2 et
     * sérialisée par des modules validés G5. Le contrat de lecture ne change pas d'un octet — c'est
     * ce qui rend la bascule chirurgicale, exactement comme `statutPour()` en L1+L2. Elle conserve
     * la dernière valeur écrite, qui n'est plus consultée.
     *
     * **POURQUOI IL NE CONSULTE PAS LE CALENDRIER.** Il ne lit que des colonnes de SA PROPRE LIGNE.
     * S'il interrogeait le référentiel et la date de naissance du membre, la réponse dépendrait de
     * ce qui se trouve chargé au moment de l'appel : la même ligne répondrait `a_faire` dans un
     * endpoint et `en_retard` dans un autre, selon l'eager loading. **Une valeur qui change avec la
     * façon dont on la demande n'est pas un calcul, c'est un hasard.**
     *
     * L'échéance issue du calendrier est donc résolue et INSCRITE dans `date_rappel` au moment de
     * l'écriture, par le service de lien — une seule vérité, posée une fois, au moment où le serveur
     * la connaît (motif P6.6b : ce que le serveur peut vérifier n'a pas à être cru, et ce qu'il a
     * vérifié doit rester stable).
     */
    protected function statut(): Attribute
    {
        return Attribute::make(
            get: fn (): string => ReglesCalendrierVaccinal::statutLigne(
                $this->date_administration,
                $this->date_rappel,
                CarbonImmutable::now(),
            ),
        );
    }
}
