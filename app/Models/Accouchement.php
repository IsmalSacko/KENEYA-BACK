<?php

declare(strict_types=1);

namespace App\Models;

use App\Sync\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $grossesse_id
 * @property int $etablissement_id
 * @property int|null $praticien_id
 * @property Carbon $date_heure
 * @property string $mode voie_basse, ventouse, cesarienne
 * @property string|null $observations
 * @property-read Etablissement $etablissement
 * @property-read Collection<int, Naissance> $naissances
 */
class Accouchement extends Model
{
    use HasUuid;

    protected $fillable = [
        'uuid',
        'grossesse_id',
        'etablissement_id',
        'praticien_id',
        'date_heure',
        'mode',
        'observations',
    ];

    protected $casts = ['date_heure' => 'datetime'];

    /** @return BelongsTo<Etablissement, $this> */
    public function etablissement(): BelongsTo
    {
        return $this->belongsTo(Etablissement::class);
    }

    /** @return HasMany<Naissance, $this> */
    public function naissances(): HasMany
    {
        return $this->hasMany(Naissance::class)->orderBy('id');
    }
}
