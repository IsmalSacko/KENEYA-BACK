<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Une arrivée ou un départ d'un membre, badge scanné.
 *
 * @property int $id
 * @property int $etablissement_id
 * @property int $membre_id
 * @property string $type arrivee, depart
 * @property Carbon $at
 * @property int|null $scanne_par
 * @property-read User $membre
 */
class Pointage extends Model
{
    protected $fillable = ['etablissement_id', 'membre_id', 'type', 'at', 'scanne_par'];

    protected $casts = ['at' => 'datetime'];

    /** @return BelongsTo<User, $this> */
    public function membre(): BelongsTo
    {
        return $this->belongsTo(User::class, 'membre_id');
    }
}
