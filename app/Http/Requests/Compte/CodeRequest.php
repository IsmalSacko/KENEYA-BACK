<?php

declare(strict_types=1);

namespace App\Http\Requests\Compte;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Demande d'un code SMS (inscription ou PIN oublié). */
class CodeRequest extends FormRequest
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
            'objet' => 'required|in:inscription,pin',
        ];
    }
}
