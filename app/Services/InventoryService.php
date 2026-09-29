<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Models\Product;
use App\Models\Shop;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Manual stock changes: adjustments (stock take), damage, loss and opening
 * stock. Each is one movement on the ledger, written through StockService,
 * never lets stock go negative, is audit-logged, and is idempotent.
 */
class InventoryService
{
    public function __construct(private StockService $stock, private AuditLogger $audit) {}

    /** @return array{movement: StockMovement, stock: float, replayed: bool} */
    public function adjust(Shop $shop, User $by, int $productId, ?float $delta, ?float $counted, string $reason, ?string $key): array
    {
        return $this->move($shop, $by, $productId, MovementType::Adjustment, $reason, $key, function (float $current) use ($delta, $counted) {
            $change = $counted !== null ? round($counted - $current, 3) : round((float) $delta, 3);

            if (abs($change) < 0.0005) {
                throw ValidationException::withMessages(['quantity' => $counted !== null
                    ? 'The count matches what the system already has, so there is nothing to adjust.'
                    : 'The change can\'t be zero.']);
            }

            return $change;
        });
    }

    /** @return array{movement: StockMovement, stock: float, replayed: bool} */
    public function writeOff(Shop $shop, User $by, int $productId, MovementType $type, float $quantity, string $reason, ?string $key): array
    {
        return $this->move($shop, $by, $productId, $type, $reason, $key, fn () => -round($quantity, 3));
    }

    /** @return array{movement: StockMovement, stock: float, replayed: bool} */
    public function openingStock(Shop $shop, User $by, int $productId, float $quantity, ?int $unitCost, ?string $key): array
    {
        return $this->move($shop, $by, $productId, MovementType::OpeningStock, 'Opening stock', $key, function () use ($quantity, $productId) {
            if (StockMovement::where('product_id', $productId)->where('movement_type', MovementType::OpeningStock)->exists()) {
                throw ValidationException::withMessages(['product_id' => 'This product already has opening stock. Use a stock adjustment to correct it.']);
            }

            return round($quantity, 3);
        }, $unitCost);
    }

    /**
     * @param  callable(float): float  $delta  works out the signed change from current stock
     * @return array{movement: StockMovement, stock: float, replayed: bool}
     */
    private function move(Shop $shop, User $by, int $productId, MovementType $type, string $reason, ?string $key, callable $delta, ?int $unitCost = null): array
    {
        if ($key && ($existing = $this->findByKey($shop, $key))) {
            return ['movement' => $existing, 'stock' => $this->stock->current($shop->id, $existing->product_id), 'replayed' => true];
        }

        try {
            return DB::transaction(function () use ($shop, $by, $productId, $type, $reason, $key, $delta, $unitCost) {
                // Locked so two people adjusting the same product can't both act on stale stock.
                $product = Product::where('shop_id', $shop->id)->lockForUpdate()->findOrFail($productId);
                $current = $this->stock->current($shop->id, $product->id);
                $change = $delta($current);

                if ($current + $change < -0.0005) {
                    throw ValidationException::withMessages(['quantity' => "Only {$current} {$product->base_unit} in stock, so that can't be taken out."]);
                }

                $movement = $this->stock->record($product, $change, $type, $by, null, $reason, $unitCost, $key);

                $this->audit->record($by, $shop, 'inventory.'.strtolower($type->value), $product, ['stock' => $current], [
                    'stock' => round($current + $change, 3),
                    'change' => $change,
                    'reason' => $reason,
                ]);

                return ['movement' => $movement, 'stock' => round($current + $change, 3), 'replayed' => false];
            });
        } catch (UniqueConstraintViolationException $e) {
            if ($key && ($existing = $this->findByKey($shop, $key))) {
                return ['movement' => $existing, 'stock' => $this->stock->current($shop->id, $existing->product_id), 'replayed' => true];
            }

            throw $e;
        }
    }

    private function findByKey(Shop $shop, string $key): ?StockMovement
    {
        return StockMovement::where('shop_id', $shop->id)->where('idempotency_key', $key)->first();
    }
}
