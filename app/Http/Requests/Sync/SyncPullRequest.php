<?php

declare(strict_types=1);

namespace App\Http\Requests\Sync;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SyncPullRequest extends FormRequest
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
        $tables = implode(',', array_keys((array) config('sync.tables', [])));

        return [
            'last_pulled_rev' => 'nullable|integer|min:0',
            'tables' => 'nullable|array',
            'tables.*' => 'string|in:'.$tables,
        ];
    }
}
