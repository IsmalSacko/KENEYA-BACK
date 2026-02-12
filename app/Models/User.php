<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Etablissement;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasApiTokens;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'etablissement_id',
        'telephone',
        'role',
        'avatar',
        'pin',
        'actif'
    ];

    protected $hidden = [
        'pin',
        'remember_token',
    ];

    protected $casts = [
        'pin' => 'hashed',
        'created_at' => 'datetime:d/m/Y H:i',
        'updated_at' => 'datetime:d/m/Y H:i',
        'actif' => 'boolean',
    ];

    public function etablissement()
    {
        return $this->belongsTo(Etablissement::class);
    }

    public function etablissements()
    {
        return $this->belongsToMany(Etablissement::class, 'etablissement_user', 'user_id', 'etablissement_id');
    }
}