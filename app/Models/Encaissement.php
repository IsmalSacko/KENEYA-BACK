<?php

declare(strict_types=1);

namespace App\Models;

use App\Sync\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Un paiement sur une visite : espèces, ou Mobile Money avec sa référence.
 *
 * @property int $id
 * @property string $uuid
 * @property int $visite_id
 * @property int|null $session_caisse_id
 * @property int $montant
 * @property string $moyen
 * @property string|null $reference
 * @property int|null $encaisse_par
 * @property Carbon|null $rembourse_at
 */
class Encaissement extends Model
{
    use HasUuid;

    protected $fillable = [
        'uuid',
        'visite_id',
        'session_caisse_id',
        'montant',
        'moyen',
        'reference',
        'encaisse_par',
        'rembourse_at',
        'rembourse_par',
    ];

    protected $casts = ['montant' => 'integer', 'rembourse_at' => 'datetime'];
}
