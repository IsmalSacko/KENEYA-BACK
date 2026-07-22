<?php

declare(strict_types=1);

namespace App\Models;

use App\Sync\Concerns\HasSyncRevision;
use App\Sync\Concerns\HasUuid;
use App\Sync\Contracts\Syncable;
use Database\Factories\VentePharmacieArticleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class VentePharmacieArticle extends Model implements Syncable
{
    /** @use HasFactory<VentePharmacieArticleFactory> */
    use HasFactory, HasSyncRevision, HasUuid, SoftDeletes;

    protected $fillable = [
        'uuid',
        'vente_pharmacie_id',
        'medicament_id',
        'quantite',
        'prix_unitaire',
        'prix_total',
    ];

    protected $casts = [
        'created_at' => 'datetime:d/m/Y H:i',
        'updated_at' => 'datetime:d/m/Y H:i',
    ];

    /** @return BelongsTo<VentesPharmacie, $this> */
    public function ventePharmacie(): BelongsTo
    {
        return $this->belongsTo(VentesPharmacie::class);
    }

    /** @return BelongsTo<Medicament, $this> */
    public function medicament(): BelongsTo
    {
        return $this->belongsTo(Medicament::class);
    }
}
