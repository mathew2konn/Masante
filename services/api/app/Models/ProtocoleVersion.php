<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * Une version d'un protocole médical — le cycle de vie §6 et l'instantané compilé.
 *
 * ÉTATS (§6.1, les trois que le corpus nomme) : `brouillon` → `actif` → `archive`.
 *
 * ═══ CE QUI EST SCELLÉ DÈS LA PUBLICATION, ET POURQUOI ═══
 *
 * Le §6.1 pose une exigence « médico-légale non négociable » : chaque décision clinique conserve la
 * version exacte du protocole utilisée. Cette phrase ne vaut que si la version, une fois publiée,
 * **ne peut plus être réécrite** — sinon l'estampille « ce triage a appliqué la version 3 » ne
 * prouverait rien, puisqu'on pourrait changer la version 3 après coup.
 *
 * Motif `ReferentielVersion` (P6.3), avec la même double garantie : les déclencheurs de la base
 * empêchent les états incohérents, ce crochet empêche la réécriture silencieuse d'un instantané.
 *
 * ═══ UNE SEULE VERSION ACTIVE — ET LE CORPUS EST IMPRÉCIS SUR CE POINT ═══
 *
 * Le tableau du §6.1 montre `2.0 Active` ET `2.1 Active`. On tranche pour une seule, parce que deux
 * versions actives rendraient « laquelle s'applique ? » insoluble — or c'est le §6.1 lui-même qui
 * exige de conserver **la** version exacte utilisée, ce qui présuppose une réponse unique. Dit
 * plutôt que laissé croire que le corpus a été suivi à la lettre.
 */
class ProtocoleVersion extends Model
{
    public const BROUILLON = 'brouillon';

    public const ACTIF = 'actif';

    public const ARCHIVE = 'archive';

    /** Les quatre validations du §7, dans l'ordre où il les énonce. */
    public const TYPES_VALIDATION = ['clinique', 'reglementaire', 'scientifique', 'technique'];

    /** Champs scellés dès qu'une version quitte l'état `brouillon`. */
    private const SCELLES = [
        'protocole_id', 'numero', 'libelle', 'contenu_json', 'empreinte',
        'niveau_preuve', 'population', 'conditions_utilisation', 'date_expiration',
        'redige_par', 'redige_le', 'publie_par', 'publie_le', 'motif',
    ];

    protected $table = 'protocole_versions';

    protected $fillable = [
        'protocole_id', 'numero', 'libelle', 'etat', 'verrou_unicite',
        'niveau_preuve', 'population', 'conditions_utilisation', 'date_expiration',
        'contenu_json', 'empreinte', 'motif',
        'redige_par', 'redige_le', 'publie_par', 'publie_le',
    ];

    protected function casts(): array
    {
        return [
            'contenu_json'    => 'array',
            'numero'          => 'integer',
            'date_expiration' => 'date',
            'redige_le'       => 'datetime',
            'publie_le'       => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $version) {
            // L'état AVANT modification décide : un brouillon se modifie librement, une version
            // publiée est scellée.
            if ($version->getOriginal('etat') === self::BROUILLON) {
                return;
            }

            foreach (self::SCELLES as $champ) {
                if ($version->isDirty($champ)) {
                    throw new RuntimeException(
                        "Version de protocole scellée : « {$champ} » ne peut plus être modifiée "
                        .'(CDC_08 §6.1, exigence médico-légale).'
                    );
                }
            }
        });

        static::deleting(function (self $version) {
            // Un brouillon abandonné se supprime ; une version publiée ou archivée, jamais :
            // §6.1 « un protocole archivé reste consultable indéfiniment ».
            if ($version->etat !== self::BROUILLON) {
                throw new RuntimeException(
                    'Une version publiée ou archivée ne se supprime pas : elle reste consultable '
                    .'indéfiniment (CDC_08 §6.1).'
                );
            }
        });
    }

    public function protocole(): BelongsTo
    {
        return $this->belongsTo(Protocole::class, 'protocole_id');
    }

    public function regles(): HasMany
    {
        return $this->hasMany(ProtocoleRegle::class, 'version_id')->orderBy('ordre');
    }

    public function references(): HasMany
    {
        return $this->hasMany(ProtocoleReference::class, 'version_id');
    }

    /**
     * Les validations, de la plus récente à la plus ancienne.
     *
     * ═══ L'ORDRE EST TOTAL, ET IL A FALLU UN VECTEUR POUR S'EN APERCEVOIR ═══
     *
     * `latest('valide_le')` seul ne suffit pas : deux signatures posées dans la même seconde ont
     * le même horodatage, et « la plus récente » devenait alors l'ordre de retour du moteur. Un
     * relecteur qui corrige son avis dans la foulée pouvait voir son avis PRÉCÉDENT faire
     * autorité — un vecteur l'a montré en rendant `defavorable` là où `favorable` avait été
     * signé ensuite.
     *
     * `id` départage : c'est la même leçon que `ReglesOrientation` (tri par rang PUIS par code) et
     * que `NumeroUrgence::scopeOrdonne` (P6.8e) — sans second critère, la même donnée répond
     * différemment d'une base à l'autre.
     */
    public function validations(): HasMany
    {
        return $this->hasMany(ProtocoleValidation::class, 'version_id')
            ->orderByDesc('valide_le')
            ->orderByDesc('id');
    }

    public function redacteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'redige_par');
    }

    public function publieur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'publie_par');
    }

    /**
     * La dernière validation de chaque type — c'est elle qui fait foi (§7).
     *
     * La table est APPEND-ONLY : une validation ne se réécrit pas, elle se re-signe. Les
     * précédentes racontent l'histoire, la dernière engage. Le §7 dit « opposable » ; effacer une
     * signature pour en poser une autre effacerait précisément ce qui est opposable.
     *
     * @return array<string, ProtocoleValidation>
     */
    public function validationsCourantes(): array
    {
        $courantes = [];

        foreach ($this->validations()->get() as $validation) {
            // `validations()` est trié du plus récent au plus ancien : la première rencontrée
            // pour un type est la bonne.
            $courantes[$validation->type] ??= $validation;
        }

        return $courantes;
    }

    /** La valeur du verrou d'unicité correspondant à un état — `null` si l'état n'en porte pas. */
    public static function verrouPour(string $etat, int $protocoleId): ?string
    {
        return match ($etat) {
            self::BROUILLON => "B:{$protocoleId}",
            self::ACTIF     => "A:{$protocoleId}",
            default         => null,
        };
    }

    /**
     * La version est-elle périmée (§4.1 « date d'expiration ») ?
     *
     * L'expiration n'ARCHIVE pas automatiquement : une version périmée reste la dernière parole
     * officielle tant qu'aucune autre ne l'a remplacée, et la retirer d'office laisserait le
     * triage sans protocole du tout. Elle est SIGNALÉE — le refus appartient à un humain.
     */
    public function estPerimee(?\DateTimeInterface $a = null): bool
    {
        if ($this->date_expiration === null) {
            return false;
        }

        return $this->date_expiration->isBefore($a ?? now());
    }
}
