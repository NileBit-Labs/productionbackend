<?php

namespace App\Http\Controllers;

use App\Models\MeasurementUnit;
use App\Models\Product;
use App\Models\Recipe;
use App\Support\ShopDefaults;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The shop's own list of units (kg, L, pcs, ...), offered when creating products and recipes. */
class MeasurementUnitController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop');
        ShopDefaults::units($shop);

        return response()->json(MeasurementUnit::where('shop_id', $shop->id)->orderBy('dimension')->orderBy('name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop');
        ShopDefaults::units($shop);

        $unit = MeasurementUnit::create($this->validated($request) + ['shop_id' => $shop->id]);

        return response()->json($unit, 201);
    }

    public function update(Request $request, int $unit): JsonResponse
    {
        $shop = $request->attributes->get('shop');
        $model = MeasurementUnit::where('shop_id', $shop->id)->findOrFail($unit);

        $model->update($this->validated($request, $model->id, partial: true));

        return response()->json($model);
    }

    /** A unit that products or recipes are measured in stays, so their quantities keep their meaning. */
    public function destroy(Request $request, int $unit): JsonResponse
    {
        $shop = $request->attributes->get('shop');
        $model = MeasurementUnit::where('shop_id', $shop->id)->findOrFail($unit);

        $used = Product::where('shop_id', $shop->id)->where('base_unit', $model->symbol)->exists()
            || Recipe::where('shop_id', $shop->id)->where('yield_unit', $model->symbol)->exists();

        abort_if($used, 422, "Products or recipes are measured in {$model->symbol}, so it can't be removed.");

        $model->delete();

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?int $ignore = null, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $shopId = $request->attributes->get('shop')->id;

        return $request->validate([
            'name' => [$required, 'string', 'max:50'],
            'symbol' => [$required, 'string', 'max:20', Rule::unique('measurement_units', 'symbol')->where('shop_id', $shopId)->ignore($ignore)],
            'dimension' => ['sometimes', Rule::in(MeasurementUnit::DIMENSIONS)],
        ]);
    }
}
