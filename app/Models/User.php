<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Role;
use App\Sync\Concerns\HasSyncRevision;
use App\Sync\Concerns\HasUuid;
use App\Sync\Contracts\Syncable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject, Syncable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasSyncRevision, HasUuid, Notifiable, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'etablissement_id',
        'uuid',
        'telephone',
        'role',
        'avatar',
        'pin',
        'actif',
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

    /**
     * Identifiant stocké dans le "sub" du JWT.
     */
    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    /**
     * Claims personnalisés ajoutés au JWT.
     *
     * @return array<string, mixed>
     */
    public function getJWTCustomClaims(): array
    {
        return [
            'role' => $this->role,
            'etablissement_id' => $this->etablissement_id,
        ];
    }

    /**
     * Administrateur de l'instance : contrôle toute la plateforme.
     */
    public function isInstanceAdmin(): bool
    {
        return $this->role === Role::Admin->value;
    }

    /**
     * Administrateur d'un seul établissement.
     */
    public function isEtablissementAdmin(): bool
    {
        return $this->role === Role::AdminEtablissement->value;
    }

    /**
     * Peut administrer SON établissement (gérer les utilisateurs, régler
     * l'établissement) : admin d'instance OU admin d'établissement.
     */
    public function canAdministerEtablissement(): bool
    {
        return in_array($this->role, Role::etablissementAdmins(), true);
    }

    /** @return BelongsTo<Etablissement, $this> */
    public function etablissement(): BelongsTo
    {
        return $this->belongsTo(Etablissement::class);
    }

    /** @return BelongsToMany<Etablissement, $this> */
    public function etablissements(): BelongsToMany
    {
        return $this->belongsToMany(Etablissement::class, 'etablissement_user', 'user_id', 'etablissement_id');
    }
}
