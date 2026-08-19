<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une référence bibliographique d'une version de protocole (CDC_08 §4.1, §9.1 « les références
 * utilisées »).
 *
 * Elle n'est pas décorative : le §9.1 prévoit que le médecin visualise « les recommandations, leur
 * niveau de preuve et les références utilisées », et le §7.3 fait reposer la validation
 * scientifique sur « des publications revues par les pairs ». Une recommandation sans référence est
 * une affirmation sans source — le contrôle qualité en exige au moins une à la publication.
 */
class ProtocoleReference extends Model
{
    protected $table = 'protocole_references';

    protected $fillable = ['version_id', 'type', 'libelle', 'url', 'citation'];

    public function version(): BelongsTo
    {
        return $this->belongsTo(ProtocoleVersion::class, 'version_id');
    }
}
