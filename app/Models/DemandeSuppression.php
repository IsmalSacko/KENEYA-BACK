<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DemandeSuppression extends Model
{
    protected $table = 'demandes_suppression';

    protected $fillable = [
        'nom',
        'telephone',
        'type_compte',
        'motif',
        'statut',
        'traitee_le',
    ];

    protected $casts = [
        'traitee_le' => 'datetime',
        'created_at' => 'datetime:d/m/Y H:i',
        'updated_at' => 'datetime:d/m/Y H:i',
    ];
}
