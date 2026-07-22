<?php

declare(strict_types=1);

namespace App\Http\Requests\Etablissement;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

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
            'telephone' => 'sometimes|string|max:20|nullable',
            'actif' => 'sometimes|boolean',
            'adresse' => 'sometimes|string|max:255|nullable',

        ];
    }
}
