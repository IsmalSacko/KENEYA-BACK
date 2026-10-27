<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Une plage d'ouverture : le lundi (1) de 08:00 à 12:30.
 *
 * @property int $id
 * @property int $agenda_id
 * @property int $jour
 * @property string $debut
 * @property string $fin
 */
class AgendaHoraire extends Model
{
    public $timestamps = false;

    protected $fillable = ['agenda_id', 'jour', 'debut', 'fin'];

    protected $casts = ['jour' => 'integer'];

    protected $hidden = ['id', 'agenda_id'];
}
