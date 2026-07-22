<?php

declare(strict_types=1);

namespace App\Sync;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Moteur de synchronisation offline (protocole style WatermelonDB), piloté par
 * le registre config/sync.php.
 *
 * - pull : renvoie les changements serveur depuis un curseur (sync_revision).
 * - push : applique des upserts/suppressions idempotents, scopés à
 *   l'établissement du JWT, avec résolution des clés étrangères par uuid parent.
 */
class SyncEngine
{
    public function __construct(
        private readonly SyncRegistry $registry,
        private readonly SyncSequenceService $sequence,
    ) {}

    /**
     * @param  list<string>|null  $tables  restreint le pull à ces tables (null = toutes)
     * @return array{server_rev: int, changes: array<string, array{upserted: list<array<string, mixed>>, deleted: list<string>}>}
     */
    public function pull(User $user, int $lastRev, ?array $tables = null): array
    {
        $scopeId = Cast::int($user->getAttribute('etablissement_id'));
        $serverRev = $this->sequence->current();

        $changes = [];
        foreach ($this->registry->tableNames() as $table) {
            if ($tables !== null && ! in_array($table, $tables, true)) {
                continue;
            }
            $changes[$table] = $this->pullTable($table, $scopeId, $lastRev, $serverRev);
        }

        return ['server_rev' => $serverRev, 'changes' => $changes];
    }

    /**
     * @param  array<string, mixed>  $changes  { table: { upserted: [...], deleted: [uuid...] } }
     * @return array{accepted: bool, server_rev: int, applied: array<string, int>, rejected: list<array{table: string, uuid: string, reason: string, detail: string}>}
     */
    public function push(User $user, array $changes): array
    {
        $scopeId = Cast::int($user->getAttribute('etablissement_id'));
        $resolver = new ForeignKeyResolver($this->registry);
        $stock = new StockService;

        /** @var array<string, int> $applied */
        $applied = [];
        /** @var list<array{table: string, uuid: string, reason: string, detail: string}> $rejected */
        $rejected = [];

        DB::transaction(function () use ($changes, $scopeId, $resolver, $stock, &$applied, &$rejected): void {
            foreach ($this->registry->tableNames() as $table) {
                $payload = $changes[$table] ?? null;
                if (! is_array($payload)) {
                    continue;
                }

                $upserted = isset($payload['upserted']) && is_array($payload['upserted']) ? $payload['upserted'] : [];
                foreach ($upserted as $record) {
                    if (! is_array($record)) {
                        continue;
                    }
                    /** @var array<string, mixed> $record */
                    $uuid = isset($record['uuid']) && is_string($record['uuid']) ? $record['uuid'] : null;
                    if ($uuid === null) {
                        $rejected[] = ['table' => $table, 'uuid' => '', 'reason' => 'validation_failed', 'detail' => 'uuid manquant'];

                        continue;
                    }

                    try {
                        $this->applyUpsert($table, $scopeId, $record, $resolver, $stock);
                        $applied[$table] = ($applied[$table] ?? 0) + 1;
                    } catch (SyncRejection $e) {
                        $rejected[] = ['table' => $table, 'uuid' => $uuid, 'reason' => $e->reason, 'detail' => $e->getMessage()];
                    } catch (QueryException $e) {
                        $rejected[] = ['table' => $table, 'uuid' => $uuid, 'reason' => 'duplicate_unique', 'detail' => $e->getMessage()];
                    }
                }

                $deleted = isset($payload['deleted']) && is_array($payload['deleted']) ? $payload['deleted'] : [];
                foreach ($deleted as $delUuid) {
                    if (! is_string($delUuid)) {
                        continue;
                    }
                    try {
                        if ($this->applyDelete($table, $scopeId, $delUuid)) {
                            $applied[$table] = ($applied[$table] ?? 0) + 1;
                        }
                    } catch (SyncRejection $e) {
                        $rejected[] = ['table' => $table, 'uuid' => $delUuid, 'reason' => $e->reason, 'detail' => $e->getMessage()];
                    }
                }
            }

            $stock->flush($scopeId);
        });

        return [
            'accepted' => $rejected === [],
            'server_rev' => $this->sequence->current(),
            'applied' => $applied,
            'rejected' => $rejected,
        ];
    }

    /**
     * @return array{upserted: list<array<string, mixed>>, deleted: list<string>}
     */
    private function pullTable(string $table, int $scopeId, int $lastRev, int $serverRev): array
    {
        $modelClass = $this->registry->modelClass($table);
        $query = (new $modelClass)->newQuery()
            ->withoutGlobalScope(SoftDeletingScope::class)
            ->where('sync_revision', '>', $lastRev)
            ->where('sync_revision', '<=', $serverRev);

        $this->applyScope($query, $table, $scopeId);

        /** @var Collection<int, Model> $rows */
        $rows = $query->get();

        $fkMaps = $this->buildFkUuidMaps($table, $rows);

        $upserted = [];
        $deleted = [];
        foreach ($rows as $row) {
            if ($row->getAttribute('deleted_at') !== null) {
                $deleted[] = Cast::string($row->getAttribute('uuid'));

                continue;
            }
            $upserted[] = $this->serializeRow($table, $row, $fkMaps);
        }

        return ['upserted' => $upserted, 'deleted' => $deleted];
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function applyScope(Builder $query, string $table, int $scopeId): void
    {
        $scope = $this->registry->scopeOf($table);

        if ($scope === 'self') {
            $query->where('id', $scopeId);

            return;
        }

        if ($scope === 'parent') {
            $parentIds = $this->scopedParentIds($table, $scopeId);
            $parentCol = (string) $this->registry->scopeParent($table);
            $query->whereIn($parentCol, $parentIds);

            return;
        }

        $query->where('etablissement_id', $scopeId);
    }

    /**
     * @return list<int>
     */
    private function scopedParentIds(string $table, int $scopeId): array
    {
        $parentCol = $this->registry->scopeParent($table);
        $fks = $this->registry->foreignKeys($table);
        $parentTable = $parentCol !== null ? ($fks[$parentCol] ?? null) : null;

        if ($parentTable === null) {
            return [];
        }

        $parentModelClass = $this->registry->modelClass($parentTable);
        $ids = (new $parentModelClass)->newQuery()
            ->withoutGlobalScope(SoftDeletingScope::class)
            ->where('etablissement_id', $scopeId)
            ->pluck('id');

        return array_values($ids->map(fn (mixed $id): int => Cast::int($id))->all());
    }

    /**
     * @param  Collection<int, Model>  $rows
     * @return array<string, array<int, string>> colonne_id => (parent_id => parent_uuid)
     */
    private function buildFkUuidMaps(string $table, Collection $rows): array
    {
        $maps = [];
        foreach ($this->registry->foreignKeys($table) as $col => $parentTable) {
            $ids = $rows
                ->map(fn (Model $r): mixed => $r->getAttribute($col))
                ->filter(fn (mixed $v): bool => $v !== null)
                ->map(fn (mixed $v): int => Cast::int($v))
                ->unique()
                ->values()
                ->all();

            if ($ids === []) {
                $maps[$col] = [];

                continue;
            }

            $parentModelClass = $this->registry->modelClass($parentTable);
            $pairs = (new $parentModelClass)->newQuery()
                ->withoutGlobalScope(SoftDeletingScope::class)
                ->whereIn('id', $ids)
                ->pluck('uuid', 'id');

            $map = [];
            foreach ($pairs as $id => $uuid) {
                $map[Cast::int($id)] = Cast::string($uuid);
            }
            $maps[$col] = $map;
        }

        return $maps;
    }

    /**
     * @param  array<string, array<int, string>>  $fkMaps
     * @return array<string, mixed>
     */
    private function serializeRow(string $table, Model $row, array $fkMaps): array
    {
        $hidden = $this->registry->hiddenFields($table);
        $data = ['uuid' => Cast::string($row->getAttribute('uuid'))];

        foreach ($this->registry->fields($table) as $field) {
            if (in_array($field, $hidden, true)) {
                continue;
            }
            $data[$field] = $row->getAttribute($field);
        }

        foreach ($this->registry->readonlyFields($table) as $field) {
            $data[$field] = $row->getAttribute($field);
        }

        foreach (array_keys($this->registry->foreignKeys($table)) as $col) {
            $parentId = $row->getAttribute($col);
            $mappedUuid = $parentId !== null ? ($fkMaps[$col][Cast::int($parentId)] ?? null) : null;
            $data[$this->fkName($col).'_uuid'] = $mappedUuid;
        }

        $poly = $this->registry->polymorphic($table);
        if ($poly !== null) {
            $data[$poly['type_column']] = $row->getAttribute($poly['type_column']);
            $data[$poly['uuid_column']] = $row->getAttribute($poly['uuid_column']);
        }

        $data['sync_revision'] = Cast::int($row->getAttribute('sync_revision'));
        $data['created_at'] = $this->iso($row->getAttribute('created_at'));
        $data['updated_at'] = $this->iso($row->getAttribute('updated_at'));

        return $data;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function applyUpsert(string $table, int $scopeId, array $record, ForeignKeyResolver $resolver, StockService $stock): void
    {
        $modelClass = $this->registry->modelClass($table);
        $uuid = Cast::string($record['uuid']);

        $existing = (new $modelClass)->newQuery()
            ->withoutGlobalScope(SoftDeletingScope::class)
            ->where('uuid', $uuid)
            ->first();

        if ($existing !== null) {
            $this->assertInScope($existing, $table, $scopeId);
            $resolver->remember($table, $uuid, Cast::int($existing->getKey()));

            // Append-only déjà présent → idempotent, on ne touche pas.
            if ($this->registry->isAppendOnly($table)) {
                return;
            }

            // Conflit sur une table mutable : « la dernière synchro arrivée
            // gagne ». Choix volontaire plutôt qu'un Last-Write-Wins par
            // updated_at : les horloges des appareils (2-3 j hors-ligne, sans
            // NTP) ne sont pas fiables, et un timestamp faussé ferait perdre en
            // silence une modification légitime.
            $existing->fill($this->buildAttributes($table, $scopeId, $record, $resolver));
            $existing->save();

            return;
        }

        if ($this->registry->scopeOf($table) === 'self') {
            throw new SyncRejection('scope_violation', "Création d'un établissement interdite via push.");
        }

        $attrs = $this->buildAttributes($table, $scopeId, $record, $resolver);
        $attrs['uuid'] = $uuid;

        $model = new $modelClass;
        $model->fill($attrs);
        $model->save();

        $newId = Cast::int($model->getKey());
        $resolver->remember($table, $uuid, $newId);

        $effect = $this->registry->stockEffect($table);
        if ($effect !== null) {
            $medId = $attrs[$effect['medicament_column']] ?? null;
            if (is_int($medId)) {
                $stock->record($effect, $record, $medId);
            }
        }
    }

    private function applyDelete(string $table, int $scopeId, string $uuid): bool
    {
        $modelClass = $this->registry->modelClass($table);
        $existing = (new $modelClass)->newQuery()
            ->withoutGlobalScope(SoftDeletingScope::class)
            ->where('uuid', $uuid)
            ->first();

        if ($existing === null) {
            return false; // rien à supprimer → idempotent
        }

        $this->assertInScope($existing, $table, $scopeId);

        if ($existing->getAttribute('deleted_at') !== null) {
            return false; // déjà supprimé
        }

        $existing->delete(); // soft delete → le hook deleting attribue une nouvelle révision

        return true;
    }

    /**
     * Construit les attributs à écrire à partir d'un enregistrement client :
     * champs whitelistés, scope forcé, clés étrangères et polymorphe résolus.
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function buildAttributes(string $table, int $scopeId, array $record, ForeignKeyResolver $resolver): array
    {
        $attrs = [];

        foreach ($this->registry->fields($table) as $field) {
            if (array_key_exists($field, $record)) {
                $attrs[$field] = $record[$field];
            }
        }

        if ($this->registry->scopeOf($table) === 'column') {
            $attrs['etablissement_id'] = $scopeId; // toujours forcé depuis le JWT
        }

        $nullableFks = $this->registry->nullableForeignKeys($table);
        foreach ($this->registry->foreignKeys($table) as $col => $parentTable) {
            $key = $this->fkName($col).'_uuid';
            $parentUuid = isset($record[$key]) && is_string($record[$key]) ? $record[$key] : null;

            if ($parentUuid === null) {
                if (in_array($col, $nullableFks, true)) {
                    $attrs[$col] = null;

                    continue;
                }
                throw new SyncRejection('missing_parent', "Clé étrangère {$key} absente.");
            }

            $id = $resolver->resolve($parentTable, $parentUuid, $scopeId);
            if ($id === null) {
                if (in_array($col, $nullableFks, true)) {
                    $attrs[$col] = null;

                    continue;
                }
                throw new SyncRejection('missing_parent', "Parent {$parentTable} introuvable pour {$key}.");
            }

            $attrs[$col] = $id;
        }

        $poly = $this->registry->polymorphic($table);
        if ($poly !== null) {
            $type = isset($record[$poly['type_column']]) && is_string($record[$poly['type_column']]) ? $record[$poly['type_column']] : null;
            $polyUuid = isset($record[$poly['uuid_column']]) && is_string($record[$poly['uuid_column']]) ? $record[$poly['uuid_column']] : null;

            $attrs[$poly['type_column']] = $type;
            $attrs[$poly['uuid_column']] = $polyUuid;

            $parentTable = $type !== null ? ($poly['map'][$type] ?? null) : null;
            $id = ($parentTable !== null && $polyUuid !== null) ? $resolver->resolve($parentTable, $polyUuid, $scopeId) : null;

            if ($id === null && ! $poly['nullable']) {
                throw new SyncRejection('missing_parent', "Cible polymorphe introuvable ({$poly['type_column']}={$type}).");
            }

            $attrs[$poly['id_column']] = $id;
        }

        return $attrs;
    }

    private function assertInScope(Model $model, string $table, int $scopeId): void
    {
        $scope = $this->registry->scopeOf($table);

        if ($scope === 'self') {
            if (Cast::int($model->getKey()) !== $scopeId) {
                throw new SyncRejection('scope_violation', 'Établissement hors périmètre.');
            }

            return;
        }

        if ($scope === 'parent') {
            $parentCol = $this->registry->scopeParent($table);
            $fks = $this->registry->foreignKeys($table);
            $parentTable = $parentCol !== null ? ($fks[$parentCol] ?? null) : null;
            $parentId = $parentCol !== null ? $model->getAttribute($parentCol) : null;

            if ($parentTable === null || ! is_numeric($parentId)) {
                throw new SyncRejection('scope_violation', 'Parent hors périmètre.');
            }

            $parentModelClass = $this->registry->modelClass($parentTable);
            $ok = (new $parentModelClass)->newQuery()
                ->withoutGlobalScope(SoftDeletingScope::class)
                ->where('id', (int) $parentId)
                ->where('etablissement_id', $scopeId)
                ->exists();

            if (! $ok) {
                throw new SyncRejection('scope_violation', 'Parent hors périmètre.');
            }

            return;
        }

        if (Cast::int($model->getAttribute('etablissement_id')) !== $scopeId) {
            throw new SyncRejection('scope_violation', 'Enregistrement hors périmètre.');
        }
    }

    private function fkName(string $column): string
    {
        return str_ends_with($column, '_id') ? substr($column, 0, -3) : $column;
    }

    private function iso(mixed $value): ?string
    {
        return $value instanceof CarbonInterface ? $value->toIso8601String() : null;
    }
}
