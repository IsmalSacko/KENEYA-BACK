<?php

declare(strict_types=1);

namespace App\Sync;

use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Résout un uuid parent en id local interne, en restant strictement dans le
 * scope de l'établissement courant. Met en cache les résolutions réussies pour
 * la durée d'un push (les parents créés plus tôt dans le même batch sont
 * réutilisables par les enfants).
 */
class ForeignKeyResolver
{
    /** @var array<string, array<string, int>> table => (uuid => id) */
    private array $cache = [];

    public function __construct(private readonly SyncRegistry $registry) {}

    /**
     * @return int|null id local, ou null si le parent est introuvable dans le scope.
     */
    public function resolve(string $parentTable, string $uuid, int $scopeId): ?int
    {
        if (isset($this->cache[$parentTable][$uuid])) {
            return $this->cache[$parentTable][$uuid];
        }

        $modelClass = $this->registry->modelClass($parentTable);
        $probe = new $modelClass;

        // withoutGlobalScope(SoftDeletingScope) = inclut les lignes en corbeille
        // (typage plus sûr que withTrashed() pour PHPStan).
        $query = $probe->newQuery()
            ->withoutGlobalScope(SoftDeletingScope::class)
            ->where('uuid', $uuid);

        // Tous les parents référencés sont scopés par etablissement_id
        // (etablissements n'est jamais une cible de clé étrangère ici).
        if ($this->registry->scopeOf($parentTable) === 'column') {
            $query->where('etablissement_id', $scopeId);
        }

        $id = $query->value('id');

        if ($id === null) {
            return null;
        }

        return $this->cache[$parentTable][$uuid] = Cast::int($id);
    }

    /**
     * Mémorise un parent tout juste créé pour les enfants du même push.
     */
    public function remember(string $parentTable, string $uuid, int $id): void
    {
        $this->cache[$parentTable][$uuid] = $id;
    }
}
