<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Services\ProductService;
use App\Support\ProductRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Bulk product + opening-stock import for onboarding a shop from a
 * spreadsheet. All-or-nothing: every row is checked first (including
 * duplicates within the file), and nothing is written unless all are valid.
 */
class ProductImportController extends Controller
{
    public const MAX_ROWS = 500;

    public function store(Request $request, ProductService $products): JsonResponse
    {
        $request->validate([
            'rows' => ['required', 'array', 'min:1', 'max:'.self::MAX_ROWS],
            'dry_run' => ['nullable', 'boolean'],
        ]);

        $shop = $request->attributes->get('shop');
        $rows = $request->input('rows');

        $base = ProductRules::rules($shop->id);
        $rules = [
            'name' => $base['name'],
            'sku' => $base['sku'],
            'barcode' => $base['barcode'],
            'base_unit' => ['nullable', 'string', 'max:50'],
            'selling_price' => $base['selling_price'],
            'current_cost' => $base['current_cost'],
            'low_stock_threshold' => $base['low_stock_threshold'],
            'category' => ['nullable', 'string', 'max:100'],
            'opening_stock' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
        ];

        $errors = [];
        $seen = ['sku' => [], 'barcode' => []];
        $valid = [];

        foreach ($rows as $index => $row) {
            $validator = Validator::make(is_array($row) ? $row : [], $rules);
            $messages = $validator->fails() ? $validator->errors()->toArray() : [];

            foreach (['sku', 'barcode'] as $field) {
                $value = is_array($row) ? ($row[$field] ?? null) : null;
                if ($value === null || $value === '') {
                    continue;
                }
                $key = mb_strtolower((string) $value);
                if (isset($seen[$field][$key])) {
                    $messages[$field][] = "Same {$field} as row {$seen[$field][$key]} in this file.";
                } else {
                    $seen[$field][$key] = $index + 1;
                }
            }

            if ($messages) {
                $errors[] = ['row' => $index + 1, 'messages' => $messages];
            } else {
                $valid[] = $validator->validated();
            }
        }

        if ($errors) {
            return response()->json([
                'message' => count($errors).' row(s) need fixing. Nothing was imported.',
                'errors' => $errors,
            ], 422);
        }

        if ($request->boolean('dry_run')) {
            return response()->json(['valid' => count($valid), 'created' => 0]);
        }

        DB::transaction(function () use ($valid, $shop, $request, $products) {
            $categories = Category::where('shop_id', $shop->id)->get()->keyBy(fn ($c) => mb_strtolower($c->name));

            foreach ($valid as $row) {
                $categoryId = null;

                if (! empty($row['category'])) {
                    $key = mb_strtolower($row['category']);
                    $categories[$key] ??= Category::create(['shop_id' => $shop->id, 'name' => $row['category']]);
                    $categoryId = $categories[$key]->id;
                }

                $products->create($shop, $request->user(), [
                    'name' => $row['name'],
                    'category_id' => $categoryId,
                    'sku' => $row['sku'] ?? null,
                    'barcode' => $row['barcode'] ?? null,
                    'base_unit' => $row['base_unit'] ?? 'piece',
                    'selling_price' => $row['selling_price'],
                    'current_cost' => $row['current_cost'] ?? 0,
                    'low_stock_threshold' => $row['low_stock_threshold'] ?? 0,
                ], (float) ($row['opening_stock'] ?? 0));
            }
        });

        return response()->json(['valid' => count($valid), 'created' => count($valid)], 201);
    }
}
