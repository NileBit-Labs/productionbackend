<?php

namespace App\Support;

use App\Models\Product;

/** The compact, sell-ready shape of a product that devices cache. */
class PosProductPresenter
{
    /** @return array<string, mixed> */
    public static function format(Product $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'category' => $product->category?->name,
            'base_unit' => $product->base_unit,
            'selling_price' => $product->selling_price,
            'stock' => round((float) ($product->stock ?? 0), 3),
            'low_stock_threshold' => $product->low_stock_threshold,
            'status' => $product->status,
            'units' => $product->units->map(fn ($unit) => [
                'unit_name' => $unit->unit_name,
                'conversion_to_base_unit' => $unit->conversion_to_base_unit,
                'selling_price' => $unit->selling_price,
            ])->values(),
        ];
    }
}
