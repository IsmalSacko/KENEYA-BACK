<?php

declare(strict_types=1);

namespace App\Models;

use App\Sync\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un vaccin fait, dans le carnet.
 *
 * @property int $id
 * @property string $uuid
 * @property int $dossier_id
 * @property int $etablissement_id
 * @property int|null $fait_par
 * @property string $vaccin
 * @property Carbon $fait_le
 * @property string|null $lot
 * @property-read Etablissement $etablissement
 */
class Vaccination extends Model
{
    use HasUuid;

    protected $fillable = [
        'uuid',
        'dossier_id',
        'etablissement_id',
        'fait_par',
        'vaccin',
        'fait_le',
        'lot',
    ];

    protected $casts = ['fait_le' => 'date:Y-m-d'];

    /** @return BelongsTo<Etablissement, $this> */
    public function etablissement(): BelongsTo
    {
        return $this->belongsTo(Etablissement::class);
    }
}
