<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Droits ajustés d'un rôle dans un établissement.
 *
 * @property int $etablissement_id
 * @property string $role
 * @property list<string> $permissions
 */
class RoleEtablissement extends Model
{
    protected $table = 'roles_etablissement';

    protected $fillable = ['etablissement_id', 'role', 'permissions'];

    protected $casts = ['permissions' => 'array'];
}
