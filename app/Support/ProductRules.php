<?php

namespace App\Support;

use Illuminate\Validation\Rule;

class ProductRules
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(int $shopId, ?int $ignoreId = null, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $unique = fn (string $column) => Rule::unique('products', $column)->where('shop_id', $shopId)->ignore($ignoreId);

        return [
            'name' => [$required, 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('shop_id', $shopId)],
            'sku' => ['nullable', 'string', 'max:100', $unique('sku')],
            'barcode' => ['nullable', 'string', 'max:100', $unique('barcode')],
            'base_unit' => [$required, 'string', 'max:50'],
            'selling_price' => [$required, 'integer', 'min:0', 'max:1000000000000'],
            'current_cost' => ['nullable', 'integer', 'min:0', 'max:1000000000000'],
            'low_stock_threshold' => ['nullable', 'numeric', 'min:0', 'max:1000000'],

            'units' => ['nullable', 'array', 'max:10'],
            'units.*.unit_name' => ['required', 'string', 'max:50', 'distinct:ignore_case'],
            'units.*.conversion_to_base_unit' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'units.*.selling_price' => ['required', 'integer', 'min:0', 'max:1000000000000'],
        ];
    }
}
