<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $categories = Category::where('shop_id', $request->attributes->get('shop')->id)
            ->withCount('products')
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json($categories);
    }

    public function store(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('categories')->where('shop_id', $shop->id)],
        ]);

        return response()->json(Category::create($data + ['shop_id' => $shop->id]), 201);
    }

    public function update(Request $request, int $category): JsonResponse
    {
        $shop = $request->attributes->get('shop');
        $model = Category::where('shop_id', $shop->id)->findOrFail($category);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('categories')->where('shop_id', $shop->id)->ignore($model->id)],
        ]);

        $model->update($data);
        // The name is shown on every device's cached catalogue.
        Product::where('category_id', $model->id)->update(['updated_at' => now()]);

        return response()->json($model);
    }

    /** Products in it just become uncategorised (the foreign key nulls them). */
    public function destroy(Request $request, int $category): JsonResponse
    {
        $model = Category::where('shop_id', $request->attributes->get('shop')->id)->findOrFail($category);
        Product::where('category_id', $model->id)->update(['updated_at' => now()]);
        $model->delete();

        return response()->json(null, 204);
    }
}
