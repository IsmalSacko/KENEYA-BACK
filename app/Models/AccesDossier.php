<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * L'accord du patient : cet établissement voit l'historique tenu ailleurs,
 * jusqu'à `expire_at` (ou jusqu'à ce que le patient le retire).
 *
 * @property int $id
 * @property int $dossier_id
 * @property int $etablissement_id
 * @property string $par
 * @property Carbon $expire_at
 * @property Carbon|null $revoque_at
 * @property-read Etablissement $etablissement
 */
class AccesDossier extends Model
{
    protected $fillable = [
        'dossier_id',
        'etablissement_id',
        'par',
        'expire_at',
        'revoque_at',
    ];

    protected $casts = ['expire_at' => 'datetime', 'revoque_at' => 'datetime'];

    protected $table = 'acces_dossier';

    /** @return BelongsTo<Etablissement, $this> */
    public function etablissement(): BelongsTo
    {
        return $this->belongsTo(Etablissement::class);
    }
}
