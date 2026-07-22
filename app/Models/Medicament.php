<?php

declare(strict_types=1);

namespace App\Models;

use App\Sync\Concerns\HasSyncRevision;
use App\Sync\Concerns\HasUuid;
use App\Sync\Contracts\Syncable;
use Database\Factories\MedicamentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Medicament extends Model implements Syncable
{
    /** @use HasFactory<MedicamentFactory> */
    use HasFactory, HasSyncRevision, HasUuid, SoftDeletes;

    protected $fillable = [
        'etablissement_id',
        'uuid',
        'nom',
        'stock',
        'prix_unitaire',
        'seuil_alerte',
        'date_expiration',
        'actif',
    ];

    protected $casts = [
        'date_expiration' => 'date:d/m/Y',
        'actif' => 'boolean',
        'created_at' => 'datetime:d/m/Y H:i',
        'updated_at' => 'datetime:d/m/Y H:i',
    ];

    /** @return BelongsTo<Etablissement, $this> */
    public function etablissement(): BelongsTo
    {
        return $this->belongsTo(Etablissement::class);
    }
}
