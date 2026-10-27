<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Etablissement;
use App\Models\RoleEtablissement;
use App\Sante\Droits;
use App\Sante\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Les droits de chaque rôle dans l'établissement : l'admin les ajuste.
 *
 * @tag Droits
 */
class DroitsController extends Controller
{
    public function index(): JsonResponse
    {
        $etablissement = $this->etablissement();
        $modules = $etablissement->modules;

        $permissions = array_map(static fn (Permission $p): array => [
            'code' => $p->value,
            'label' => $p->label(),
            'module' => $p->module()?->value,
            'disponible' => $p->module() === null || in_array($p->module()->value, $modules, true),
        ], Permission::cases());

        $roles = array_map(static fn (Role $r): array => [
            'role' => $r->value,
            'label' => $r->label(),
            'modifiable' => Droits::modifiable($r),
            'permissions' => array_map(
                static fn (Permission $p): string => $p->value,
                Droits::duRole($etablissement, $r),
            ),
        ], array_values(array_filter(Role::cases(), static fn (Role $r): bool => $r !== Role::Admin)));

        return response()->json(['permissions' => $permissions, 'roles' => $roles]);
    }

    public function modifier(Request $request, string $role): JsonResponse
    {
        $roleEnum = Role::tryFrom($role);
        if ($roleEnum === null || ! Droits::modifiable($roleEnum)) {
            return response()->json(['message' => 'Ce rôle ne se modifie pas.'], 422);
        }

        /** @var array{permissions: list<string>} $donnees */
        $donnees = $request->validate([
            'permissions' => 'present|array',
            'permissions.*' => ['string', Rule::in(Permission::values())],
        ]);

        RoleEtablissement::updateOrCreate(
            ['etablissement_id' => $this->etablissement()->id, 'role' => $roleEnum->value],
            ['permissions' => array_values(array_unique($donnees['permissions']))],
        );

        return response()->json(['message' => 'Droits enregistrés.']);
    }

    public function reinitialiser(string $role): JsonResponse
    {
        RoleEtablissement::where('etablissement_id', $this->etablissement()->id)
            ->where('role', $role)
            ->delete();

        return response()->json(['message' => 'Droits par défaut rétablis.']);
    }

    private function etablissement(): Etablissement
    {
        return Etablissement::findOrFail($this->currentUser()->etablissement_id);
    }
}
