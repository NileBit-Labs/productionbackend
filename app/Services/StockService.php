<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * The only place stock changes. Stock is never stored as a mutable number;
 * it is the sum of the append-only stock_movements ledger.
 */
class StockService
{
    public function current(int $shopId, int $productId): float
    {
        return round((float) StockMovement::where('shop_id', $shopId)
            ->where('product_id', $productId)
            ->sum('quantity_delta'), 3);
    }

    /**
     * @param  array<int>  $productIds
     * @return array<int, float>
     */
    public function currentFor(int $shopId, array $productIds): array
    {
        return StockMovement::where('shop_id', $shopId)
            ->whereIn('product_id', $productIds)
            ->selectRaw('product_id, SUM(quantity_delta) as stock')
            ->groupBy('product_id')
            ->pluck('stock', 'product_id')
            ->map(fn ($stock) => round((float) $stock, 3))
            ->all();
    }

    /**
     * Stock on hand for every product in the shop that has any movement.
     *
     * @return array<int, float> product id => quantity
     */
    public function levelsForShop(int $shopId): array
    {
        return StockMovement::where('shop_id', $shopId)
            ->selectRaw('product_id, SUM(quantity_delta) as stock')
            ->groupBy('product_id')
            ->pluck('stock', 'product_id')
            ->map(fn ($stock) => round((float) $stock, 3))
            ->all();
    }

    public function record(
        Product $product,
        float $delta,
        MovementType $type,
        User $by,
        ?Model $reference = null,
        ?string $reason = null,
        ?int $unitCost = null,
        ?string $idempotencyKey = null,
    ): StockMovement {
        return StockMovement::create([
            'shop_id' => $product->shop_id,
            'product_id' => $product->id,
            'quantity_delta' => $delta,
            'unit_cost' => $unitCost ?? $product->current_cost,
            'movement_type' => $type,
            'reference_type' => $reference ? $reference::class : null,
            'reference_id' => $reference?->getKey(),
            'reason' => $reason,
            'performed_by' => $by->id,
            'idempotency_key' => $idempotencyKey,
        ]);
    }
}
