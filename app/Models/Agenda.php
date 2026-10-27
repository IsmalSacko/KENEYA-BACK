<?php

declare(strict_types=1);

namespace App\Models;

use App\Sync\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * L'agenda d'un praticien ou d'un service : ses horaires, la durée des
 * créneaux, et combien de patients par créneau.
 *
 * @property int $id
 * @property string $uuid
 * @property int $etablissement_id
 * @property int|null $service_id
 * @property int|null $membre_id
 * @property string $nom
 * @property int $duree_creneau_min
 * @property int $places_par_creneau
 * @property bool $reservation_en_ligne
 * @property bool $validation_auto
 * @property int $delai_min_heures
 * @property int $horizon_jours
 * @property bool $actif
 * @property-read Etablissement $etablissement
 * @property-read Service|null $service
 * @property-read User|null $membre
 * @property-read Collection<int, AgendaHoraire> $horaires
 * @property-read Collection<int, AgendaAbsence> $absences
 */
class Agenda extends Model
{
    use HasUuid, SoftDeletes;

    protected $fillable = [
        'uuid', 'etablissement_id', 'service_id', 'membre_id', 'nom', 'duree_creneau_min',
        'places_par_creneau', 'reservation_en_ligne', 'validation_auto', 'delai_min_heures',
        'horizon_jours', 'actif',
    ];

    protected $casts = [
        'duree_creneau_min' => 'integer',
        'places_par_creneau' => 'integer',
        'reservation_en_ligne' => 'boolean',
        'validation_auto' => 'boolean',
        'delai_min_heures' => 'integer',
        'horizon_jours' => 'integer',
        'actif' => 'boolean',
    ];

    protected $hidden = ['id', 'etablissement_id', 'service_id', 'membre_id', 'deleted_at'];

    /** @return BelongsTo<Etablissement, $this> */
    public function etablissement(): BelongsTo
    {
        return $this->belongsTo(Etablissement::class);
    }

    /** @return BelongsTo<Service, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function membre(): BelongsTo
    {
        return $this->belongsTo(User::class, 'membre_id');
    }

    /** @return HasMany<AgendaHoraire, $this> */
    public function horaires(): HasMany
    {
        return $this->hasMany(AgendaHoraire::class)->orderBy('jour')->orderBy('debut');
    }

    /** @return HasMany<AgendaAbsence, $this> */
    public function absences(): HasMany
    {
        return $this->hasMany(AgendaAbsence::class)->orderBy('du');
    }
}
