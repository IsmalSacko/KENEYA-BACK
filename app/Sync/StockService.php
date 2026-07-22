<?php

declare(strict_types=1);

namespace App\Sync;

use App\Models\Medicament;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Applique au stock des médicaments les deltas issus du ledger
 * (mouvement_stocks) et des articles de vente, au fil des insertions réelles
 * d'un push. Le stock est ainsi un cache dérivé : deux ventes hors-ligne
 * concurrentes s'additionnent (aucune perte, contrairement à un Last-Write-Wins
 * sur la valeur absolue). Les deltas ne sont appliqués qu'aux lignes réellement
 * insérées → idempotent au rejeu.
 */
class StockService
{
    /** @var array<int, int> medicament_id => delta cumulé */
    private array $deltas = [];

    /**
     * Enregistre l'effet stock d'une ligne append-only tout juste insérée.
     *
     * @param  array{medicament_column: string, quantity_column: string, direction: string, type_column?: string}  $effect
     * @param  array<string, mixed>  $record
     */
    public function record(array $effect, array $record, int $medicamentId): void
    {
        $qty = Cast::int($record[$effect['quantity_column']] ?? 0);
        $direction = $effect['direction'];

        $delta = match ($direction) {
            'out' => -$qty,
            'in' => $qty,
            'by_type' => $this->deltaByType(
                isset($effect['type_column']) ? Cast::string($record[$effect['type_column']] ?? '') : '',
                $qty,
            ),
            default => 0,
        };

        $this->deltas[$medicamentId] = ($this->deltas[$medicamentId] ?? 0) + $delta;
    }

    /**
     * Applique les deltas cumulés. Passe par save() pour que chaque médicament
     * touché reçoive une nouvelle sync_revision (donc soit re-pullé).
     */
    public function flush(int $scopeId): void
    {
        foreach ($this->deltas as $medicamentId => $delta) {
            if ($delta === 0) {
                continue;
            }

            $medicament = Medicament::query()
                ->withoutGlobalScope(SoftDeletingScope::class)
                ->where('id', $medicamentId)
                ->where('etablissement_id', $scopeId)
                ->lockForUpdate()
                ->first();

            if ($medicament === null) {
                continue;
            }

            $medicament->stock = (int) $medicament->stock + $delta;
            $medicament->save();
        }

        $this->deltas = [];
    }

    private function deltaByType(string $type, int $qty): int
    {
        return match ($type) {
            'entree' => $qty,
            'sortie' => -$qty,
            'ajustement' => $qty, // quantité signée fournie par le client
            default => 0,
        };
    }
}
