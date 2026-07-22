<?php

declare(strict_types=1);

namespace App\Http\Requests\Sync;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SyncPushRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validation structurelle minimale : la table doit être connue et chaque
     * enregistrement porter un uuid. La validation métier fine (champs, clés
     * étrangères, scope) est faite par le moteur, qui rejette proprement, ligne
     * par ligne, sans faire échouer tout le batch.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $tables = array_keys((array) config('sync.tables', []));

        $rules = [
            'last_pulled_rev' => 'nullable|integer|min:0',
            'changes' => 'required|array',
            'changes.*' => 'array',
        ];

        foreach ($tables as $table) {
            $rules["changes.{$table}.upserted"] = 'sometimes|array';
            $rules["changes.{$table}.upserted.*.uuid"] = 'required|uuid';
            $rules["changes.{$table}.deleted"] = 'sometimes|array';
            $rules["changes.{$table}.deleted.*"] = 'uuid';
        }

        return $rules;
    }
}
