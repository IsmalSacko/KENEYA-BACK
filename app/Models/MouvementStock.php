<?php

namespace App\Models;

use App\Models\Etablissement;
use App\Models\Medicament;
use Illuminate\Database\Eloquent\Model;

class MouvementStock extends Model
{
    protected $fillable = [
        'etablissement_id',
        'medicament_id',
        'type',
        'quantite',
        'commentaire',
    ];

    protected $casts = [
        'created_at' => 'datetime:d/m/Y H:i',
        'updated_at' => 'datetime:d/m/Y H:i',
    ];

    public function etablissement()
    {
        return $this->belongsTo(Etablissement::class);
    }

    public function medicament()
    {
        return $this->belongsTo(Medicament::class);
    }
}
