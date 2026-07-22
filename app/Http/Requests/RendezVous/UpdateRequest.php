<?php

declare(strict_types=1);

namespace App\Http\Requests\RendezVous;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Traitement d'une demande de rendez-vous côté personnel (authentifié).
 */
class UpdateRequest extends FormRequest
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
            'statut' => 'sometimes|in:demande,confirme,refuse,annule,honore',
            'medecin_id' => 'sometimes|nullable|integer',
            'date_souhaitee' => 'sometimes|date',
            'motif' => 'sometimes|nullable|string|max:255',
        ];
    }
}
