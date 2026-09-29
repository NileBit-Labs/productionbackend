<?php

namespace App\Support;

use App\Models\Product;

/** The management view of a product: includes cost, which cashiers never see. */
class ManagedProductPresenter
{
    /** @return array<string, mixed> */
    public static function format(Product $product): array
    {
        $stock = round((float) ($product->stock ?? 0), 3);
        $threshold = (float) $product->low_stock_threshold;

        return [
            'id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'category_id' => $product->category_id,
            'category' => $product->category?->name,
            'base_unit' => $product->base_unit,
            'selling_price' => $product->selling_price,
            'current_cost' => $product->current_cost,
            'low_stock_threshold' => $threshold,
            'status' => $product->status,
            'stock' => $stock,
            'stock_value' => (int) round(max($stock, 0) * $product->current_cost),
            'is_out' => $product->status === 'active' && $stock <= 0,
            'is_low' => $product->status === 'active' && $stock > 0 && $threshold > 0 && $stock <= $threshold,
            'units' => $product->relationLoaded('units')
                ? $product->units->map(fn ($u) => [
                    'unit_name' => $u->unit_name,
                    'conversion_to_base_unit' => $u->conversion_to_base_unit,
                    'selling_price' => $u->selling_price,
                ])->values()
                : [],
            'created_at' => $product->created_at,
            'updated_at' => $product->updated_at,
        ];
    }
}
