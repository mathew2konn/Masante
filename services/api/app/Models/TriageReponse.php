<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une réponse DONNÉE par un patient lors d'un triage (CDC_04 §115 `triage_reponses`).
 *
 * Reliquat W6 du plan de P10b : le §115 attend sur le triage « numéro, date/heure, **réponses**,
 * niveau de priorité, recommandation, service recommandé, établissements proposés, QR code,
 * version du protocole, version du modèle IA », plus cette table. P10a a livré le QR et
 * `referentiel_version`, P10b-1 `protocole_code`/`protocole_version` ; il restait la table.
 *
 * ═══ LE LIBELLÉ EST FIGÉ, ET C'EST LE POINT ═══
 *
 * `question_libelle` est copié au moment du triage, jamais résolu à la lecture. Motif établi :
 * P6.6b fige la DCI et le dosage dans une ordonnance, P7-D2 copie l'établissement dans le journal
 * d'accès, P10a fige le libellé d'orientation dans l'instantané. Republier le questionnaire ne doit
 * pas réécrire ce qu'un patient a lu le jour de son triage.
 *
 * ═══ ELLE NE PORTE PAS DE POINTS, ET C'EST UNE CONSÉQUENCE DE LA BASCULE ═══
 *
 * Le plan G1 prévoyait une colonne « impact réellement retenu ». Elle n'a pas été livrée parce que
 * cette valeur **n'existe plus** : depuis que l'impact est une règle, une seule règle peut porter
 * sur plusieurs réponses, et ses points ne se répartissent entre elles par aucun partage
 * défendable. L'explication du score vit dans le journal d'exécution du §10 (P10b-2), qui nomme
 * les règles déclenchées — le vrai grain de la décision.
 *
 * ═══ `triages.reponses_json` N'EST PLUS ÉCRITE, ET RESTE ═══
 *
 * Les triages antérieurs à cette bascule gardent leur colonne : leur inventer des lignes ici serait
 * un **mensonge d'archive** — précédent L2, où les mesures antérieures restent sans version de
 * référentiel plutôt que d'en recevoir une fausse.
 *
 * La fiche §5.4 lit donc la table quand elle a des lignes, la colonne sinon. **Ce n'est pas un
 * repli sur une règle** (celui que L1+L2 a refusé, où lire la table de travail masquait un oubli de
 * publication) : c'est lire des archives dans la forme où elles ont été écrites.
 */
class TriageReponse extends Model
{
    protected $table = 'triage_reponses';

    protected $fillable = [
        'triage_id', 'question_cle', 'question_libelle', 'valeur',
        'protocole_code', 'protocole_version',
    ];

    protected function casts(): array
    {
        return ['protocole_version' => 'integer'];
    }

    public function triage(): BelongsTo
    {
        return $this->belongsTo(Triage::class, 'triage_id');
    }
}
