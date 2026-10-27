<?php

declare(strict_types=1);

namespace App\Sante;

use App\Enums\Role;
use App\Models\Etablissement;
use App\Models\RoleEtablissement;
use App\Models\User;

/**
 * Droits effectifs d'un membre : ceux de son rôle (par défaut, ou ajustés
 * par l'admin), limités aux modules actifs de son établissement.
 */
final class Droits
{
    /**
     * @return list<string>
     */
    public static function de(User $membre): array
    {
        $brut = $membre->getAttribute('role');
        $role = is_string($brut) ? Role::tryFrom($brut) : null;
        if ($role === null) {
            return [];
        }

        $etablissementId = $membre->getAttribute('etablissement_id');
        $etablissement = is_numeric($etablissementId) ? Etablissement::find((int) $etablissementId) : null;
        if ($etablissement === null) {
            return [];
        }

        return self::filtrer(self::duRole($etablissement, $role), $etablissement);
    }

    /**
     * Droits configurés d'un rôle (avant le filtre des modules). Les rôles
     * d'admin ont toujours tout : on ne peut pas s'enfermer dehors.
     *
     * @return list<Permission>
     */
    public static function duRole(Etablissement $etablissement, Role $role): array
    {
        if (in_array($role->value, Role::etablissementAdmins(), true)) {
            return Permission::cases();
        }

        $reglage = RoleEtablissement::where('etablissement_id', $etablissement->id)
            ->where('role', $role->value)
            ->first();

        if ($reglage === null) {
            return Permission::parDefaut($role);
        }

        return array_values(array_filter(array_map(
            static fn (string $code): ?Permission => Permission::tryFrom($code),
            $reglage->permissions,
        )));
    }

    public static function modifiable(Role $role): bool
    {
        return ! in_array($role->value, Role::etablissementAdmins(), true);
    }

    /**
     * @param  list<Permission>  $permissions
     * @return list<string>
     */
    private static function filtrer(array $permissions, Etablissement $etablissement): array
    {
        $modules = $etablissement->modules;

        return array_values(array_map(
            static fn (Permission $p): string => $p->value,
            array_filter(
                $permissions,
                static fn (Permission $p): bool => $p->module() === null || in_array($p->module()->value, $modules, true),
            ),
        ));
    }
}
