<?php

declare(strict_types=1);

namespace App\Models;

use App\Sync\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Un service de l'établissement, avec sa propre série de tickets.
 *
 * @property int $id
 * @property string $uuid
 * @property int $etablissement_id
 * @property string $nom
 * @property string $prefixe
 * @property int $duree_moyenne_min
 * @property int $ratio_prioritaires
 * @property bool $ticket_distance
 * @property bool $actif
 * @property int $ordre
 * @property int|null $tarif_id acte facturé à chaque ticket
 */
class Service extends Model
{
    use HasUuid, SoftDeletes;

    protected $fillable = [
        'uuid', 'etablissement_id', 'nom', 'prefixe', 'duree_moyenne_min',
        'ratio_prioritaires', 'ticket_distance', 'actif', 'ordre',
        'tarif_id',
    ];

    protected $casts = [
        'duree_moyenne_min' => 'integer',
        'ratio_prioritaires' => 'integer',
        'ticket_distance' => 'boolean',
        'actif' => 'boolean',
        'ordre' => 'integer',
    ];

    protected $hidden = ['id', 'etablissement_id', 'deleted_at'];

    /** @return BelongsTo<Etablissement, $this> */
    public function etablissement(): BelongsTo
    {
        return $this->belongsTo(Etablissement::class);
    }

    /** @return HasMany<PointService, $this> */
    public function points(): HasMany
    {
        return $this->hasMany(PointService::class);
    }
}
