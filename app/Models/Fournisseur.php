<?php

declare(strict_types=1);

namespace App\Models;

use App\Sync\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Un grossiste ou fournisseur.
 *
 * @property int $id
 * @property string $uuid
 * @property int $etablissement_id
 * @property string $nom
 * @property string|null $telephone
 */
class Fournisseur extends Model
{
    use HasUuid, SoftDeletes;

    protected $fillable = [
        'uuid',
        'etablissement_id',
        'nom',
        'telephone',
    ];

    protected $casts = [];

    protected $hidden = ['id', 'etablissement_id', 'deleted_at'];
}
