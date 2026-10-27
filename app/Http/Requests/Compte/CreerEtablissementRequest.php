<?php

declare(strict_types=1);

namespace App\Http\Requests\Compte;

use App\Sante\Profil;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Une personne crée son établissement et en devient l'administratrice. */
class CreerEtablissementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'nom' => 'required|string|max:255',
            'profil' => ['required', Rule::in(Profil::values())],
            // Par défaut : le pays de la personne.
            'pays_code' => ['sometimes', 'string', 'size:2', Rule::exists('pays', 'code')->where('actif', true)],
            'telephone' => 'sometimes|nullable|string|max:20',
            'adresse' => 'sometimes|nullable|string|max:255',
            // Création hors ligne rejouée : uuid fournis par le client (idempotence).
            'uuid' => 'sometimes|nullable|uuid',
            'membre_uuid' => 'sometimes|nullable|uuid',
        ];
    }
}
