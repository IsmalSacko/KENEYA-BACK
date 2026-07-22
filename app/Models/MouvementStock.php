<?php

declare(strict_types=1);

namespace App\Models;

use App\Sync\Concerns\HasSyncRevision;
use App\Sync\Concerns\HasUuid;
use App\Sync\Contracts\Syncable;
use Database\Factories\MouvementStockFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class MouvementStock extends Model implements Syncable
{
    /** @use HasFactory<MouvementStockFactory> */
    use HasFactory, HasSyncRevision, HasUuid, SoftDeletes;

    protected $fillable = [
        'etablissement_id',
        'uuid',
        'medicament_id',
        'type',
        'quantite',
        'commentaire',
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

    /** @return BelongsTo<Medicament, $this> */
    public function medicament(): BelongsTo
    {
        return $this->belongsTo(Medicament::class);
    }
}
