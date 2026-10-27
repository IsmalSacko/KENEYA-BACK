<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Congé, formation, panne : pas de rendez-vous du `du` au `au` inclus.
 *
 * @property int $id
 * @property int $agenda_id
 * @property Carbon $du
 * @property Carbon $au
 * @property string|null $motif
 */
class AgendaAbsence extends Model
{
    protected $fillable = ['agenda_id', 'du', 'au', 'motif'];

    protected $casts = ['du' => 'date:Y-m-d', 'au' => 'date:Y-m-d'];

    protected $hidden = ['agenda_id', 'created_at', 'updated_at'];
}
