<?php

declare(strict_types=1);

namespace App\Http\Requests\RendezVous;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Demande de rendez-vous côté patient (public, sans authentification).
 */
class PublicStoreRequest extends FormRequest
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
            'etablissement_uuid' => 'required|uuid',
            'medecin_uuid' => 'sometimes|nullable|uuid',
            'patient_nom' => 'required|string|max:255',
            'patient_telephone' => 'required|string|max:20',
            'date_souhaitee' => 'required|date|after:now',
            'motif' => 'sometimes|nullable|string|max:255',
            // uuid client optionnel → prise de RDV idempotente (retry réseau).
            'uuid' => 'sometimes|nullable|uuid',
        ];
    }
}
