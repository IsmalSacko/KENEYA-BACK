<?php

declare(strict_types=1);

namespace App\Models;

use App\Sync\Concerns\HasSyncRevision;
use App\Sync\Concerns\HasUuid;
use App\Sync\Contracts\Syncable;
use Database\Factories\RendezVousFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class RendezVous extends Model implements Syncable
{
    /** @use HasFactory<RendezVousFactory> */
    use HasFactory, HasSyncRevision, HasUuid, SoftDeletes;

    protected $table = 'rendez_vous';

    protected $fillable = [
        'uuid',
        'etablissement_id',
        'medecin_id',
        'patient_nom',
        'patient_telephone',
        'date_souhaitee',
        'motif',
        'statut',
    ];

    protected $casts = [
        'date_souhaitee' => 'datetime',
        'created_at' => 'datetime:d/m/Y H:i',
        'updated_at' => 'datetime:d/m/Y H:i',
    ];

    /** @return BelongsTo<Etablissement, $this> */
    public function etablissement(): BelongsTo
    {
        return $this->belongsTo(Etablissement::class);
    }

    /** @return BelongsTo<User, $this> */
    public function medecin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'medecin_id');
    }
}
