<?php

declare(strict_types=1);

namespace App\Models;

use App\Sync\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Une consultation médicale : constantes, examen, diagnostic, conclusion.
 *
 * @property int $id
 * @property string $uuid
 * @property int $dossier_id
 * @property int|null $visite_id
 * @property int $etablissement_id
 * @property int|null $praticien_id
 * @property string $motif
 * @property array<string, mixed>|null $constantes
 * @property string|null $examen
 * @property string|null $diagnostic
 * @property string|null $cim10
 * @property string|null $conclusion
 * @property Carbon|null $prochain_rdv
 * @property-read Etablissement $etablissement
 * @property-read User|null $praticien
 */
class ExamenClinique extends Model
{
    use HasUuid;

    protected $fillable = [
        'uuid',
        'dossier_id',
        'visite_id',
        'etablissement_id',
        'praticien_id',
        'motif',
        'constantes',
        'examen',
        'diagnostic',
        'cim10',
        'conclusion',
        'prochain_rdv',
    ];

    protected $casts = ['constantes' => 'array', 'prochain_rdv' => 'date:Y-m-d'];

    protected $table = 'examens_cliniques';

    /** @return BelongsTo<Etablissement, $this> */
    public function etablissement(): BelongsTo
    {
        return $this->belongsTo(Etablissement::class);
    }

    /** @return BelongsTo<User, $this> */
    public function praticien(): BelongsTo
    {
        return $this->belongsTo(User::class, 'praticien_id');
    }
}
