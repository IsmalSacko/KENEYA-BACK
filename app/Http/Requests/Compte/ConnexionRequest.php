<?php

declare(strict_types=1);

namespace App\Http\Requests\Compte;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Connexion d'une personne : numéro (dans son pays) et PIN. */
class ConnexionRequest extends FormRequest
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
            'pin' => 'required|string',
        ];
    }
}
