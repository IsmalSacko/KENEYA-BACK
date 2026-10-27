<?php

declare(strict_types=1);

namespace App\Http\Requests\Etablissement;

use App\Sante\Module;
use App\Sante\Profil;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'nom' => 'sometimes|string|max:255',
            'type' => 'sometimes|in:cabinet,pharmacie,cabinet_pharmacie',
            'profil' => ['sometimes', Rule::in(Profil::values())],
            'modules' => 'sometimes|array',
            'modules.*' => ['string', Rule::in(Module::values())],
            'telephone' => 'sometimes|string|max:20|nullable',
            'actif' => 'sometimes|boolean',
            'adresse' => 'sometimes|string|max:255|nullable',
            'latitude' => 'sometimes|nullable|numeric|between:-90,90',
            'longitude' => 'sometimes|nullable|numeric|between:-180,180',
        ];
    }
}
