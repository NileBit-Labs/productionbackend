<?php

namespace App\Http\Controllers;

use App\Enums\ExpenseType;
use App\Models\ExpenseCategory;
use App\Support\ShopDefaults;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Categories are labels the shop controls. Retiring one hides it from new expenses but
 * leaves every expense already recorded under it as it was.
 */
class ExpenseCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop');
        ShopDefaults::expenseCategories($shop);

        $query = ExpenseCategory::where('shop_id', $shop->id)->orderBy('name');

        if (! $request->boolean('include_inactive')) {
            $query->where('is_active', true);
        }

        return response()->json($query->get());
    }

    public function store(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop');
        ShopDefaults::expenseCategories($shop);

        $category = ExpenseCategory::create($this->validated($request) + ['shop_id' => $shop->id]);

        return response()->json($category->fresh(), 201);
    }

    public function update(Request $request, int $category): JsonResponse
    {
        $shop = $request->attributes->get('shop');
        $model = ExpenseCategory::where('shop_id', $shop->id)->findOrFail($category);

        $model->update($this->validated($request, $model->id, partial: true));

        return response()->json($model);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?int $ignore = null, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $shopId = $request->attributes->get('shop')->id;

        return $request->validate([
            'name' => [$required, 'string', 'max:100', Rule::unique('expense_categories', 'name')->where('shop_id', $shopId)->ignore($ignore)],
            'default_type' => ['sometimes', Rule::enum(ExpenseType::class)],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
