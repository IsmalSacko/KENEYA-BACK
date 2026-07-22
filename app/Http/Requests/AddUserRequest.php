<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddUserRequest extends FormRequest
{
    /**
     * Seul un administrateur (instance ou établissement) peut ajouter un utilisateur.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->canAdministerEtablissement();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $user = $this->user();
        // Un admin d'instance peut attribuer n'importe quel rôle ; un admin
        // d'établissement, tout sauf le rôle d'admin d'instance.
        $rolesAutorises = $user instanceof User && $user->isInstanceAdmin()
            ? Role::values()
            : Role::assignableByEtablissementAdmin();

        return [
            'name' => 'required|string|max:255',
            'telephone' => 'required|string|max:20',
            'role' => ['required', Rule::in($rolesAutorises)],
            'pin' => 'required|string|min:4|max:6',
        ];
    }
}
