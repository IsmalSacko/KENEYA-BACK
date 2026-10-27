<?php

declare(strict_types=1);

namespace App\Models;

use App\Sante\File\Priorite;
use App\Sante\File\Statut;
use App\Sync\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un passage dans un service : « A012 ».
 *
 * @property int $id
 * @property string $uuid
 * @property int $etablissement_id
 * @property int $service_id
 * @property Carbon $jour
 * @property int $numero
 * @property string $code
 * @property Priorite $priorite
 * @property Statut $statut
 * @property string $canal
 * @property int|null $personne_id
 * @property int|null $patient_id
 * @property int|null $visite_id
 * @property string|null $nom
 * @property string|null $telephone
 * @property int|null $point_service_id
 * @property int|null $appele_par
 * @property int $appels
 * @property int|null $ticket_precedent_id
 * @property bool $prevenu_bientot
 * @property Carbon $emis_at
 * @property Carbon|null $appele_at
 * @property Carbon|null $servi_at
 * @property Carbon|null $termine_at
 * @property-read Service $service
 * @property-read PointService|null $point
 * @property-read Etablissement $etablissement
 */
class Ticket extends Model
{
    use HasUuid;

    protected $fillable = [
        'uuid', 'etablissement_id', 'service_id', 'jour', 'numero', 'code', 'priorite', 'statut',
        'canal', 'personne_id', 'patient_id', 'visite_id', 'nom', 'telephone', 'point_service_id', 'appele_par',
        'appels', 'ticket_precedent_id', 'prevenu_bientot', 'emis_at', 'appele_at', 'servi_at', 'termine_at',
    ];

    protected $casts = [
        'jour' => 'date:Y-m-d',
        'numero' => 'integer',
        'priorite' => Priorite::class,
        'statut' => Statut::class,
        'appels' => 'integer',
        'prevenu_bientot' => 'boolean',
        'emis_at' => 'datetime',
        'appele_at' => 'datetime',
        'servi_at' => 'datetime',
        'termine_at' => 'datetime',
    ];

    /** @return BelongsTo<Service, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class)->withTrashed();
    }

    /** @return BelongsTo<PointService, $this> */
    public function point(): BelongsTo
    {
        return $this->belongsTo(PointService::class, 'point_service_id')->withTrashed();
    }

    /** @return BelongsTo<Visite, $this> */
    public function visite(): BelongsTo
    {
        return $this->belongsTo(Visite::class);
    }

    /** @return BelongsTo<Etablissement, $this> */
    public function etablissement(): BelongsTo
    {
        return $this->belongsTo(Etablissement::class);
    }
}
