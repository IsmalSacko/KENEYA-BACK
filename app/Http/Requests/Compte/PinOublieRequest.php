<?php

declare(strict_types=1);

namespace App\Http\Requests\Compte;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Nouveau PIN après réception d'un code SMS. */
class PinOublieRequest extends FormRequest
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
            'code' => 'required|string|max:10',
            'pin' => 'required|string|regex:/^\d{4,6}$/',
        ];
    }
}
