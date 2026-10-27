<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Role;
use App\Sante\Droits;
use App\Sante\Personnes;
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

/**
 * Un membre : une personne dans un établissement, avec son rôle.
 *
 * @property string $uuid
 * @property string $name
 * @property string $role
 * @property int|null $personne_id
 * @property string|null $badge_jeton
 */
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
        'badge_jeton',
    ];

    protected $hidden = [
        'pin',
        'remember_token',
        'badge_jeton',
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
    protected static function booted(): void
    {
        // Tout membre appartient à une personne (un numéro = une personne),
        // quel que soit le chemin de création : inscription, ajout, synchro.
        static::creating(function (User $membre): void {
            if ($membre->getAttribute('personne_id') === null) {
                $membre->setAttribute('personne_id', Personnes::pourMembre($membre)->id);
            }
        });
    }

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

    /** @var list<string>|null Droits effectifs, calculés une fois par requête. */
    private ?array $droits = null;

    /**
     * @return list<string>
     */
    public function permissions(): array
    {
        return $this->droits ??= Droits::de($this);
    }

    public function peut(string $droit): bool
    {
        return in_array($droit, $this->permissions(), true);
    }

    /** @return BelongsTo<Personne, $this> */
    public function personne(): BelongsTo
    {
        return $this->belongsTo(Personne::class);
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
