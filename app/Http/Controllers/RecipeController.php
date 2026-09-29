<?php

namespace App\Http\Controllers;

use App\Models\Recipe;
use App\Services\RecipeService;
use App\Support\PerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Owner/manager only (see routes): the estimated cost uses cost prices. */
class RecipeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop');
        $status = $request->query('status', 'active');

        $query = Recipe::where('shop_id', $shop->id)->with('items.product')->orderBy('name');

        if ($status !== 'all') {
            $query->where('status', $status === 'archived' ? 'archived' : 'active');
        }

        if ($search = trim((string) $request->query('search', ''))) {
            $like = '%'.mb_strtolower($search).'%';
            $query->where(fn ($q) => $q->whereRaw('lower(name) like ?', [$like])->orWhereRaw('lower(coalesce(family, \'\')) like ?', [$like]));
        }

        return response()->json($query->paginate(PerPage::from($request))->through(fn (Recipe $r) => $this->format($r)));
    }

    public function store(Request $request, RecipeService $recipes): JsonResponse
    {
        $shop = $request->attributes->get('shop');
        $recipe = $recipes->create($shop, $request->user(), $request->validate($this->rules($request)));

        return response()->json($this->fresh($request, $recipe->id), 201);
    }

    public function show(Request $request, int $recipe): JsonResponse
    {
        return response()->json($this->fresh($request, $recipe));
    }

    public function update(Request $request, RecipeService $recipes, int $recipe): JsonResponse
    {
        $shop = $request->attributes->get('shop');
        $model = Recipe::where('shop_id', $shop->id)->findOrFail($recipe);

        $recipes->update($shop, $request->user(), $model, $request->validate($this->rules($request, $model->id, partial: true)));

        return response()->json($this->fresh($request, $model->id));
    }

    public function archive(Request $request, RecipeService $recipes, int $recipe): JsonResponse
    {
        return $this->setStatus($request, $recipes, $recipe, 'archived');
    }

    public function restore(Request $request, RecipeService $recipes, int $recipe): JsonResponse
    {
        return $this->setStatus($request, $recipes, $recipe, 'active');
    }

    private function setStatus(Request $request, RecipeService $recipes, int $recipe, string $status): JsonResponse
    {
        $shop = $request->attributes->get('shop');
        $recipes->update($shop, $request->user(), Recipe::where('shop_id', $shop->id)->findOrFail($recipe), ['status' => $status]);

        return response()->json($this->fresh($request, $recipe));
    }

    /** @return array<string, mixed> */
    private function rules(Request $request, ?int $ignore = null, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $shopId = $request->attributes->get('shop')->id;

        return [
            'name' => [$required, 'string', 'max:255', Rule::unique('recipes', 'name')->where('shop_id', $shopId)->ignore($ignore)],
            'family' => ['nullable', 'string', 'max:255'],
            'yield_quantity' => [$required, 'numeric', 'gt:0', 'max:1000000'],
            'yield_unit' => [$required, 'string', 'max:50'],
            'instructions' => ['nullable', 'string', 'max:5000'],
            'items' => [$required, 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'items.*.note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, mixed> */
    private function fresh(Request $request, int $id): array
    {
        return $this->format(Recipe::where('shop_id', $request->attributes->get('shop')->id)->with('items.product')->findOrFail($id));
    }

    /**
     * The estimate prices each ingredient at what it costs today, so the owner can see what a
     * run would cost before making it. The real cost is only known once a batch is completed.
     *
     * @return array<string, mixed>
     */
    private function format(Recipe $recipe): array
    {
        $items = $recipe->items->map(fn ($item) => [
            'id' => $item->id,
            'product_id' => $item->product_id,
            'product_name' => $item->product->name,
            'kind' => $item->product->kindOrDefault()->value,
            'unit' => $item->product->base_unit,
            'quantity' => $item->quantity,
            'note' => $item->note,
            'unit_cost' => $item->product->current_cost,
            'estimated_cost' => (int) round($item->quantity * $item->product->current_cost),
        ])->values();

        $estimate = (int) $items->sum('estimated_cost');

        return [
            'id' => $recipe->id,
            'name' => $recipe->name,
            'family' => $recipe->family,
            'yield_quantity' => $recipe->yield_quantity,
            'yield_unit' => $recipe->yield_unit,
            'instructions' => $recipe->instructions,
            'status' => $recipe->status,
            'items' => $items,
            'estimated_cost' => $estimate,
            'estimated_cost_per_yield_unit' => $recipe->yield_quantity > 0 ? (int) round($estimate / $recipe->yield_quantity) : null,
            'created_at' => $recipe->created_at,
            'updated_at' => $recipe->updated_at,
        ];
    }
}
