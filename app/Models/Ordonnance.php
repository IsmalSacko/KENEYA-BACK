<?php

declare(strict_types=1);

namespace App\Models;

use App\Sync\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Une ordonnance signée : le QR se vérifie hors ligne, la délivrance est
 * comptée ligne par ligne, quelle que soit la pharmacie.
 *
 * @property int $id
 * @property string $uuid
 * @property int $etablissement_id
 * @property int|null $prescripteur_id
 * @property int|null $personne_id
 * @property int|null $visite_id
 * @property string $patient_nom
 * @property string $statut
 * @property string $signature
 * @property-read Collection<int, OrdonnanceLigne> $lignes
 * @property-read Etablissement $etablissement
 * @property-read User|null $prescripteur
 */
class Ordonnance extends Model
{
    use HasUuid;

    protected $fillable = [
        'uuid',
        'etablissement_id',
        'prescripteur_id',
        'personne_id',
        'visite_id',
        'patient_nom',
        'statut',
        'signature',
    ];

    protected $casts = [];

    /** @return HasMany<OrdonnanceLigne, $this> */
    public function lignes(): HasMany
    {
        return $this->hasMany(OrdonnanceLigne::class)->orderBy('id');
    }

    /** @return BelongsTo<Etablissement, $this> */
    public function etablissement(): BelongsTo
    {
        return $this->belongsTo(Etablissement::class);
    }

    /** @return BelongsTo<User, $this> */
    public function prescripteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prescripteur_id');
    }
}
