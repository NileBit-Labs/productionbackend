<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\ProductionBatchOutput;
use App\Models\ProductionLot;
use App\Models\ProductionLotMovement;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** Immutable lot movements make finished-goods provenance authoritative without replacing the stock ledger. */
class ProductionLotService
{
    public function create(Shop $shop, ProductionBatch $batch, ProductionBatchOutput $output): ProductionLot
    {
        $lot = ProductionLot::create([
            'shop_id' => $shop->id, 'product_id' => $output->product_id, 'production_batch_id' => $batch->id,
            'production_batch_output_id' => $output->id, 'produced_quantity' => $output->quantity,
            'production_date' => $batch->production_date, 'expiry_date' => $output->expiry_date,
        ]);

        return $lot;
    }

    /** @return array<int, array{lot: ProductionLot, quantity: float}> */
    public function consume(Shop $shop, Product $product, float $quantity, User $by, string $type, Model $reference, ?string $reason = null): array
    {
        $lots = $this->available($shop, $product->id, true);
        $left = round($quantity, 3);
        $allocations = [];

        foreach ($lots as $lot) {
            $available = $this->remaining($lot);
            if ($available <= 0) {
                continue;
            }
            if ($type === 'SALE' && $lot->expiry_date && $lot->expiry_date->toDateString() < $shop->today()) {
                continue;
            }
            $take = min($left, $available);
            $this->movement($lot, -$take, $type, $reference, $by, $reason);
            $allocations[] = ['lot' => $lot, 'quantity' => $take];
            $left = round($left - $take, 3);
            if ($left <= 0.0005) {
                break;
            }
        }

        if ($left > 0.0005) {
            throw ValidationException::withMessages(['quantity' => "Only production-lot stock is available for {$product->name}."]);
        }

        return $allocations;
    }

    /**
     * Restores as much as was actually allocated to lots by the original action.
     * A finished good can contain legacy, non-lotted stock as well as production
     * lots, so a refund of a mixed sale must not fabricate lot provenance.
     */
    public function restoreFromReference(Shop $shop, string $consumedType, Model $consumedReference, float $quantity, User $by, Model $reference, string $type, ?string $reason = null): float
    {
        $moves = ProductionLotMovement::where('movement_type', $consumedType)
            ->where('reference_type', $consumedReference::class)->where('reference_id', $consumedReference->getKey())
            ->orderByDesc('id')->lockForUpdate()->get();
        $left = round($quantity, 3);
        foreach ($moves as $move) {
            $alreadyRestored = (float) ProductionLotMovement::where('movement_type', $type)
                ->where('production_lot_id', $move->production_lot_id)
                ->where(fn ($query) => $query->where('reason', 'lot-source:'.$move->id)->orWhere('reason', 'like', 'lot-source:'.$move->id.' %'))->sum('quantity_delta');
            $available = abs($move->quantity_delta) - $alreadyRestored;
            if ($available <= 0) {
                continue;
            }
            $put = min($left, $available);
            $this->movement($move->lot, $put, $type, $reference, $by, 'lot-source:'.$move->id.($reason ? ' '.$reason : ''));
            $left = round($left - $put, 3);
            if ($left <= 0.0005) {
                return round($quantity, 3);
            }
        }

        return round($quantity - $left, 3);
    }

    /** Quantity backed by production lots, locked in allocation order. */
    public function availableQuantity(Shop $shop, int $productId, bool $lock = false): float
    {
        return round($this->available($shop, $productId, $lock)->sum(fn (ProductionLot $lot) => $this->remaining($lot)), 3);
    }

    public function expiredQuantity(Shop $shop, int $productId): float
    {
        return round($this->available($shop, $productId)->filter(fn ($lot) => $lot->expiry_date && $lot->expiry_date->toDateString() < $shop->today())->sum(fn ($lot) => max(0, $this->remaining($lot))), 3);
    }

    public function remaining(ProductionLot $lot): float
    {
        return round((float) $lot->movements()->sum('quantity_delta') + $lot->produced_quantity, 3);
    }

    /** @return Collection<int, ProductionLot> */
    public function available(Shop $shop, int $productId, bool $lock = false): Collection
    {
        $query = ProductionLot::where('shop_id', $shop->id)->where('product_id', $productId)
            ->orderByRaw('expiry_date is null, expiry_date')->orderBy('production_date')->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    public function assertWholeLotAvailable(ProductionLot $lot): void
    {
        if ($this->remaining($lot) + 0.0005 < $lot->produced_quantity) {
            throw ValidationException::withMessages(['batch' => 'A finished lot has been sold, wasted, or otherwise consumed, so this batch cannot be cancelled.']);
        }
    }

    public function consumeLot(ProductionLot $lot, float $quantity, User $by, string $type, Model $reference, ?string $reason = null): void
    {
        if ($this->remaining($lot) + 0.0005 < $quantity) {
            throw ValidationException::withMessages(['batch' => 'The production lot no longer has enough remaining quantity.']);
        }
        $this->movement($lot, -$quantity, $type, $reference, $by, $reason);
    }

    private function movement(ProductionLot $lot, float $delta, string $type, Model $reference, User $by, ?string $reason): void
    {
        ProductionLotMovement::create(['production_lot_id' => $lot->id, 'quantity_delta' => round($delta, 3), 'movement_type' => $type,
            'reference_type' => $reference::class, 'reference_id' => $reference->getKey(), 'reason' => $reason, 'performed_by' => $by->id]);
    }
}
