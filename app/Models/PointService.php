<?php

declare(strict_types=1);

namespace App\Models;

use App\Sync\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Là où l'on appelle les patients : Salle 2, Guichet 1, Comptoir B.
 *
 * @property int $id
 * @property string $uuid
 * @property int $etablissement_id
 * @property int $service_id
 * @property string $nom
 * @property bool $actif
 */
class PointService extends Model
{
    use HasUuid, SoftDeletes;

    protected $table = 'points_service';

    protected $fillable = ['uuid', 'etablissement_id', 'service_id', 'nom', 'actif'];

    protected $casts = ['actif' => 'boolean'];

    protected $hidden = ['id', 'etablissement_id', 'service_id', 'deleted_at'];

    /** @return BelongsTo<Service, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
