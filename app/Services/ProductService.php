<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Models\Product;
use App\Models\SaleItem;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductService
{
    /** Fields whose change is worth an audit entry (they move money or meaning). */
    private const AUDITED = ['name', 'sku', 'barcode', 'category_id', 'base_unit', 'selling_price', 'current_cost', 'low_stock_threshold', 'status'];

    public function __construct(private StockService $stock, private AuditLogger $audit) {}

    /** @param  array<string, mixed>  $data */
    public function create(Shop $shop, User $by, array $data, float $openingStock = 0): Product
    {
        $this->assertUnitsDifferFromBase($data['base_unit'] ?? 'piece', $data['units'] ?? []);

        return DB::transaction(function () use ($shop, $by, $data, $openingStock) {
            $product = Product::create($this->attributes($data) + ['shop_id' => $shop->id]);

            $this->syncUnits($product, $data['units'] ?? []);

            if ($openingStock > 0) {
                $this->stock->record($product, $openingStock, MovementType::OpeningStock, $by, null, 'Opening stock');
            }

            return $product;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(Shop $shop, User $by, Product $product, array $data): Product
    {
        return DB::transaction(function () use ($shop, $by, $product, $data) {
            $product = Product::where('shop_id', $shop->id)->lockForUpdate()->findOrFail($product->id);

            $newBase = $data['base_unit'] ?? $product->base_unit;
            $this->assertUnitsDifferFromBase($newBase, $data['units'] ?? $product->units->map->only('unit_name')->all());

            // Quantities are stored in the base unit, so renaming what the base
            // *is* after stock or sales exist would silently change their meaning.
            if ($newBase !== $product->base_unit && $this->hasHistory($product)) {
                throw ValidationException::withMessages([
                    'base_unit' => 'The base unit can\'t be changed once this product has stock or sales. Create a new product instead.',
                ]);
            }

            $before = $product->only(self::AUDITED);
            $unitsBefore = $product->units()->get(['unit_name', 'conversion_to_base_unit', 'selling_price'])->toArray();

            $product->fill($this->attributes($data, partial: true));
            $changed = $product->getDirty();
            $product->save();

            $unitsChanged = false;
            if (array_key_exists('units', $data)) {
                $unitsChanged = $this->syncUnits($product, $data['units'] ?? []);
            }

            // Devices refresh from "products changed since...", so a units-only
            // edit must still move the product's updated_at.
            if ($unitsChanged) {
                $product->touch();
            }

            if ($changed !== [] || $unitsChanged) {
                $after = $product->only(self::AUDITED);
                $this->audit->record(
                    $by, $shop, 'product.update', $product,
                    array_intersect_key($before, $changed) + ($unitsChanged ? ['units' => $unitsBefore] : []),
                    array_intersect_key($after, $changed) + ($unitsChanged ? ['units' => $product->units()->get(['unit_name', 'conversion_to_base_unit', 'selling_price'])->toArray()] : []),
                );
            }

            return $product;
        });
    }

    public function setStatus(Shop $shop, User $by, Product $product, string $status): Product
    {
        if ($product->status === $status) {
            return $product;
        }

        $before = ['status' => $product->status];
        $product->update(['status' => $status]);
        $this->audit->record($by, $shop, $status === 'archived' ? 'product.archive' : 'product.restore', $product, $before, ['status' => $status]);

        return $product;
    }

    /** @param  array<string, mixed>  $data */
    private function attributes(array $data, bool $partial = false): array
    {
        $attributes = array_intersect_key($data, array_flip([
            'name', 'category_id', 'sku', 'barcode', 'base_unit', 'selling_price', 'current_cost', 'low_stock_threshold',
        ]));

        if (! $partial) {
            $attributes += ['current_cost' => 0, 'low_stock_threshold' => 0];
            $attributes['current_cost'] ??= 0;
            $attributes['low_stock_threshold'] ??= 0;
        } else {
            foreach (['current_cost', 'low_stock_threshold'] as $field) {
                if (array_key_exists($field, $attributes) && $attributes[$field] === null) {
                    $attributes[$field] = 0;
                }
            }
        }

        return $attributes;
    }

    /**
     * Makes the product's alternate units exactly the given set.
     *
     * @param  array<int, array<string, mixed>>  $units
     */
    private function syncUnits(Product $product, array $units): bool
    {
        $existing = $product->units()->get()->keyBy(fn ($u) => mb_strtolower($u->unit_name));
        $changed = false;
        $keep = [];

        foreach ($units as $unit) {
            $key = mb_strtolower($unit['unit_name']);
            $keep[] = $key;
            $row = $existing->get($key);

            if ($row) {
                $row->fill([
                    'unit_name' => $unit['unit_name'],
                    'conversion_to_base_unit' => $unit['conversion_to_base_unit'],
                    'selling_price' => $unit['selling_price'],
                ]);
                if ($row->isDirty()) {
                    $row->save();
                    $changed = true;
                }
            } else {
                $product->units()->create($unit);
                $changed = true;
            }
        }

        foreach ($existing as $key => $row) {
            if (! in_array($key, $keep, true)) {
                $row->delete();
                $changed = true;
            }
        }

        return $changed;
    }

    /** @param  array<int, array<string, mixed>>  $units */
    private function assertUnitsDifferFromBase(string $base, array $units): void
    {
        foreach ($units as $i => $unit) {
            if (mb_strtolower($unit['unit_name']) === mb_strtolower($base)) {
                throw ValidationException::withMessages(["units.$i.unit_name" => "\"{$unit['unit_name']}\" is already this product's base unit."]);
            }
        }
    }

    private function hasHistory(Product $product): bool
    {
        return $product->stockMovements()->exists() || SaleItem::where('product_id', $product->id)->exists();
    }
}
