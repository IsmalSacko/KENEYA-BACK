<?php

declare(strict_types=1);

namespace App\Http\Requests\Etablissement;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreRequest extends FormRequest
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
            'type' => 'required|in:cabinet,pharmacie,cabinet_pharmacie',
            'telephone' => 'sometimes|nullable|string|max:20',
            'adresse' => 'sometimes|nullable|string|max:255',
            'actif' => 'sometimes|boolean',
            'latitude' => 'sometimes|nullable|numeric|between:-90,90',
            'longitude' => 'sometimes|nullable|numeric|between:-180,180',
            // uuid client optionnel : rend la création idempotente (offline).
            'uuid' => 'sometimes|nullable|uuid',
        ];
    }
}
