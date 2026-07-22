<?php

declare(strict_types=1);

namespace App\Models;

use App\Sync\Concerns\HasSyncRevision;
use App\Sync\Concerns\HasUuid;
use App\Sync\Contracts\Syncable;
use Database\Factories\PatientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Patient extends Model implements Syncable
{
    /** @use HasFactory<PatientFactory> */
    use HasFactory, HasSyncRevision, HasUuid, SoftDeletes;

    protected $fillable = [
        'etablissement_id',
        'uuid',
        'nom',
        'telephone',
        'adresse',
    ];

    protected $casts = [
        'created_at' => 'datetime:d/m/Y H:i',
        'updated_at' => 'datetime:d/m/Y H:i',
    ];

    /** @return BelongsTo<Etablissement, $this> */
    public function etablissement(): BelongsTo
    {
        return $this->belongsTo(Etablissement::class);
    }

    /** @return HasMany<Consultation, $this> */
    public function consultations(): HasMany
    {
        return $this->hasMany(Consultation::class);
    }

    /** @return HasMany<VentesPharmacie, $this> */
    public function ventesPharmacie(): HasMany
    {
        return $this->hasMany(VentesPharmacie::class);
    }
}
