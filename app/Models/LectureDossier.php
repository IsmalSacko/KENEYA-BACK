<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Une ouverture du dossier : qui, où, quand. Le patient la voit.
 *
 * @property int $id
 * @property int $dossier_id
 * @property int $etablissement_id
 * @property int|null $membre_id
 * @property bool $historique
 * @property Carbon $lu_at
 * @property-read Etablissement $etablissement
 * @property-read User|null $membre
 */
class LectureDossier extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'dossier_id',
        'etablissement_id',
        'membre_id',
        'historique',
        'lu_at',
    ];

    protected $casts = ['historique' => 'boolean', 'lu_at' => 'datetime'];

    protected $table = 'lectures_dossier';

    /** @return BelongsTo<Etablissement, $this> */
    public function etablissement(): BelongsTo
    {
        return $this->belongsTo(Etablissement::class);
    }

    /** @return BelongsTo<User, $this> */
    public function membre(): BelongsTo
    {
        return $this->belongsTo(User::class, 'membre_id');
    }
}
