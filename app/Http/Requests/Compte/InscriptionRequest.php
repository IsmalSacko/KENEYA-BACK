<?php

declare(strict_types=1);

namespace App\Http\Requests\Compte;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Création d'un compte personne (espace « Ma santé »). */
class InscriptionRequest extends FormRequest
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
            'pays_code' => ['required', 'string', 'size:2', Rule::exists('pays', 'code')->where('actif', true)],
            'telephone' => 'required|string|max:20',
            'nom' => 'required|string|max:255',
            'pin' => 'required|string|regex:/^\d{4,6}$/',
            // Exigé seulement quand config('sante.verification_telephone') est vrai.
            'code' => 'sometimes|nullable|string|max:10',
        ];
    }
}
