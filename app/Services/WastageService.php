<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Enums\ProductKind;
use App\Enums\WastageStage;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Models\WastageRecord;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Stock that spoiled or was damaged outside a batch: rotten fruit, crushed bottles, expired
 * finished goods. It leaves stock through the ledger and is kept as a loss at its cost.
 * (Losses inside a batch are recorded when the batch is completed; see ProductionService.)
 */
class WastageService
{
    public function __construct(private StockService $stock, private AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data  validated request data
     * @return array{record: WastageRecord, replayed: bool}
     */
    public function record(Shop $shop, User $by, array $data): array
    {
        $key = $data['idempotency_key'] ?? null;

        if ($key && ($existing = $this->findByKey($shop, $key))) {
            return ['record' => $existing, 'replayed' => true];
        }

        try {
            $record = DB::transaction(function () use ($shop, $by, $data, $key) {
                $product = Product::where('shop_id', $shop->id)->lockForUpdate()->find($data['product_id']);

                if (! $product) {
                    throw ValidationException::withMessages(['product_id' => 'Choose a product from this shop.']);
                }

                $quantity = round((float) $data['quantity'], 3);
                $onHand = $this->stock->current($shop->id, $product->id);

                if ($onHand + 0.0005 < $quantity) {
                    throw ValidationException::withMessages(['quantity' => "Only {$onHand} {$product->base_unit} of {$product->name} in stock, so that much can't be written off."]);
                }

                $stage = isset($data['stage']) ? WastageStage::from($data['stage']) : match ($product->kindOrDefault()) {
                    ProductKind::RawMaterial => WastageStage::RawMaterial,
                    ProductKind::Packaging => WastageStage::Packaging,
                    ProductKind::FinishedGood => WastageStage::FinishedGoods,
                };

                $movement = $this->stock->record($product, -$quantity, MovementType::Wastage, $by, null, $data['reason'], $product->current_cost, $key);

                $record = WastageRecord::create([
                    'shop_id' => $shop->id,
                    'product_id' => $product->id,
                    'stage' => $stage,
                    'quantity' => $quantity,
                    'unit_cost' => $product->current_cost,
                    'total_cost' => (int) round($quantity * $product->current_cost),
                    'reason' => $data['reason'],
                    'wastage_date' => $data['wastage_date'] ?? $shop->today(),
                    'stock_movement_id' => $movement->id,
                    'recorded_by' => $by->id,
                    'idempotency_key' => $key,
                ]);

                $this->audit->record($by, $shop, 'wastage.record', $record, ['stock' => $onHand], [
                    'stock' => round($onHand - $quantity, 3),
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'total_cost' => $record->total_cost,
                    'reason' => $data['reason'],
                ]);

                return $record;
            });
        } catch (UniqueConstraintViolationException $e) {
            if ($key && ($existing = $this->findByKey($shop, $key))) {
                return ['record' => $existing, 'replayed' => true];
            }

            throw $e;
        }

        return ['record' => $record, 'replayed' => false];
    }

    private function findByKey(Shop $shop, string $key): ?WastageRecord
    {
        return WastageRecord::where('shop_id', $shop->id)->where('idempotency_key', $key)->first();
    }
}
