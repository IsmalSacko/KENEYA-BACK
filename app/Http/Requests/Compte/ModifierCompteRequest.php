<?php

declare(strict_types=1);

namespace App\Http\Requests\Compte;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** Mise à jour de son propre compte. */
class ModifierCompteRequest extends FormRequest
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
            'nom' => 'sometimes|string|max:255',
            'date_naissance' => 'sometimes|nullable|date|before_or_equal:today',
            'sexe' => 'sometimes|nullable|in:F,M',
            'langue' => 'sometimes|nullable|string|max:5',
        ];
    }
}
