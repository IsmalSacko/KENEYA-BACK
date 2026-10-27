<?php

declare(strict_types=1);

namespace App\Models;

use App\Sync\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * La caisse d'un membre, de l'ouverture à la clôture (avec l'écart entre
 * les espèces comptées et attendues).
 *
 * @property int $id
 * @property string $uuid
 * @property int $etablissement_id
 * @property int $membre_id
 * @property int $fond_initial
 * @property Carbon $ouverte_at
 * @property Carbon|null $cloturee_at
 * @property int|null $especes_comptees
 * @property int|null $ecart
 */
class SessionCaisse extends Model
{
    use HasUuid;

    protected $table = 'sessions_caisse';

    protected $fillable = [
        'uuid',
        'etablissement_id',
        'membre_id',
        'fond_initial',
        'ouverte_at',
        'cloturee_at',
        'especes_comptees',
        'ecart',
    ];

    protected $casts = ['fond_initial' => 'integer', 'especes_comptees' => 'integer', 'ecart' => 'integer', 'ouverte_at' => 'datetime', 'cloturee_at' => 'datetime'];
}
