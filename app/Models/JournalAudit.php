<?php

namespace App\Models;

use App\Models\Etablissement;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class JournalAudit extends Model
{
    protected $table = 'journal_audits';

    protected $fillable = [
        'etablissement_id',
        'user_id',
        'action',
        'type_cible',
        'id_cible',
    ];

    protected $casts = [
        'created_at' => 'datetime:d/m/Y H:i',
        'updated_at' => 'datetime:d/m/Y H:i',
    ];


    public function utilisateur()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function etablissement()
    {
        return $this->belongsTo(Etablissement::class);
    }
}
