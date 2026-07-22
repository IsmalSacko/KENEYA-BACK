<?php

declare(strict_types=1);

namespace App\Sync;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Accès typé au registre config/sync.php. Isole les casts « mixed → type »
 * en un seul endroit pour garder le moteur propre vis-à-vis de PHPStan niveau 9.
 */
class SyncRegistry
{
    /** @var array<string, array<string, mixed>> */
    private array $tables;

    public function __construct()
    {
        /** @var array<string, array<string, mixed>> $tables */
        $tables = config('sync.tables', []);
        $this->tables = $tables;
    }

    /**
     * Noms des tables dans l'ordre topologique (parents avant enfants).
     *
     * @return list<string>
     */
    public function tableNames(): array
    {
        return array_keys($this->tables);
    }

    public function has(string $table): bool
    {
        return isset($this->tables[$table]);
    }

    /**
     * @return array<string, mixed>
     */
    public function config(string $table): array
    {
        if (! isset($this->tables[$table])) {
            throw new InvalidArgumentException("Table de sync inconnue : {$table}");
        }

        return $this->tables[$table];
    }

    /**
     * @return class-string<Model>
     */
    public function modelClass(string $table): string
    {
        $class = $this->config($table)['model'] ?? null;

        if (! is_string($class) || ! is_subclass_of($class, Model::class)) {
            throw new InvalidArgumentException("Modèle invalide pour la table {$table}.");
        }

        return $class;
    }

    public function scopeOf(string $table): string
    {
        $scope = $this->config($table)['scope'] ?? 'column';

        return is_string($scope) ? $scope : 'column';
    }

    public function scopeParent(string $table): ?string
    {
        $col = $this->config($table)['scope_parent'] ?? null;

        return is_string($col) ? $col : null;
    }

    public function isAppendOnly(string $table): bool
    {
        return (bool) ($this->config($table)['append_only'] ?? false);
    }

    /**
     * @return list<string>
     */
    public function fields(string $table): array
    {
        return $this->stringList($this->config($table)['fields'] ?? []);
    }

    /**
     * @return list<string>
     */
    public function readonlyFields(string $table): array
    {
        return $this->stringList($this->config($table)['readonly'] ?? []);
    }

    /**
     * @return list<string>
     */
    public function hiddenFields(string $table): array
    {
        return $this->stringList($this->config($table)['hidden'] ?? []);
    }

    /**
     * @return array<string, string> colonne_id => table_parente
     */
    public function foreignKeys(string $table): array
    {
        $fks = $this->config($table)['fks'] ?? [];
        $out = [];

        if (is_array($fks)) {
            foreach ($fks as $col => $parent) {
                if (is_string($col) && is_string($parent)) {
                    $out[$col] = $parent;
                }
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public function nullableForeignKeys(string $table): array
    {
        return $this->stringList($this->config($table)['nullable_fks'] ?? []);
    }

    /**
     * @return array{type_column: string, uuid_column: string, id_column: string, nullable: bool, map: array<string, string>}|null
     */
    public function polymorphic(string $table): ?array
    {
        $poly = $this->config($table)['polymorphic'] ?? null;

        if (! is_array($poly)) {
            return null;
        }

        $map = [];
        if (isset($poly['map']) && is_array($poly['map'])) {
            foreach ($poly['map'] as $type => $parent) {
                if (is_string($type) && is_string($parent)) {
                    $map[$type] = $parent;
                }
            }
        }

        return [
            'type_column' => Cast::string($poly['type_column'] ?? ''),
            'uuid_column' => Cast::string($poly['uuid_column'] ?? ''),
            'id_column' => Cast::string($poly['id_column'] ?? ''),
            'nullable' => (bool) ($poly['nullable'] ?? false),
            'map' => $map,
        ];
    }

    /**
     * @return array{medicament_column: string, quantity_column: string, direction: string, type_column?: string}|null
     */
    public function stockEffect(string $table): ?array
    {
        $effect = $this->config($table)['stock_effect'] ?? null;

        if (! is_array($effect)) {
            return null;
        }

        $out = [
            'medicament_column' => Cast::string($effect['medicament_column'] ?? ''),
            'quantity_column' => Cast::string($effect['quantity_column'] ?? ''),
            'direction' => Cast::string($effect['direction'] ?? ''),
        ];

        if (isset($effect['type_column']) && is_string($effect['type_column'])) {
            $out['type_column'] = $effect['type_column'];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $out[] = $item;
            }
        }

        return $out;
    }
}
