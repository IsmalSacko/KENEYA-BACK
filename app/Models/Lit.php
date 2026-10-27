<?php

declare(strict_types=1);

namespace App\Models;

use App\Sync\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $uuid
 * @property int $chambre_id
 * @property string $numero
 * @property bool $actif
 * @property-read Chambre $chambre
 */
class Lit extends Model
{
    use HasUuid;

    protected $fillable = ['uuid', 'chambre_id', 'numero', 'actif'];

    protected $casts = ['actif' => 'boolean'];

    /** @return BelongsTo<Chambre, $this> */
    public function chambre(): BelongsTo
    {
        return $this->belongsTo(Chambre::class)->withTrashed();
    }

    /** « Salle 2 · lit 3 » */
    public function libelle(): string
    {
        return "{$this->chambre->nom} · lit {$this->numero}";
    }
}
