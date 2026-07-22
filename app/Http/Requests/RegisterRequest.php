<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
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
            'nom_etablissement' => 'required|string|max:255',
            'type' => 'required|in:cabinet,pharmacie,cabinet_pharmacie',
            'name' => 'required|string|max:255',
            'telephone' => 'required|string|max:20',
            'pin' => 'required|string|min:4|max:6',
            // Inscription créée hors-ligne puis rejouée : uuid fournis par le
            // client pour rendre l'opération idempotente.
            'uuid' => 'sometimes|nullable|uuid',
            'user_uuid' => 'sometimes|nullable|uuid',
        ];
    }
}
