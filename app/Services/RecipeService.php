<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Recipe;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Recipes (bills of materials): what one run is expected to use and make. A recipe only
 * plans a batch; what the batch really used is recorded on the batch itself.
 */
class RecipeService
{
    public function __construct(private AuditLogger $audit) {}

    /** @param  array<string, mixed>  $data */
    public function create(Shop $shop, User $by, array $data): Recipe
    {
        return DB::transaction(function () use ($shop, $by, $data) {
            $this->assertItems($shop, $data['items']);

            $recipe = Recipe::create([
                'shop_id' => $shop->id,
                'name' => $data['name'],
                'family' => $data['family'] ?? null,
                'yield_quantity' => $data['yield_quantity'],
                'yield_unit' => $data['yield_unit'],
                'instructions' => $data['instructions'] ?? null,
                'created_by' => $by->id,
            ]);

            $this->syncItems($recipe, $data['items']);

            $this->audit->record($by, $shop, 'recipe.create', $recipe, null, $this->snapshot($recipe));

            return $recipe;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(Shop $shop, User $by, Recipe $recipe, array $data): Recipe
    {
        return DB::transaction(function () use ($shop, $by, $recipe, $data) {
            $before = $this->snapshot($recipe);

            $recipe->fill(array_intersect_key($data, array_flip(['name', 'family', 'yield_quantity', 'yield_unit', 'instructions', 'status'])));
            $recipe->save();

            if (array_key_exists('items', $data)) {
                $this->assertItems($shop, $data['items']);
                $this->syncItems($recipe, $data['items']);
                $recipe->touch();
            }

            $after = $this->snapshot($recipe->fresh());

            if ($before !== $after) {
                $this->audit->record($by, $shop, 'recipe.update', $recipe, $before, $after);
            }

            return $recipe;
        });
    }

    /** @param  array<int, array<string, mixed>>  $items */
    private function assertItems(Shop $shop, array $items): void
    {
        $products = Product::where('shop_id', $shop->id)
            ->whereIn('id', collect($items)->pluck('product_id')->map(fn ($id) => (int) $id))
            ->get()->keyBy('id');

        $seen = [];

        foreach ($items as $i => $item) {
            $product = $products->get((int) $item['product_id']);

            if (! $product) {
                throw ValidationException::withMessages(["items.$i.product_id" => 'Choose a product from this shop.']);
            }

            if (isset($seen[$product->id])) {
                throw ValidationException::withMessages(["items.$i.product_id" => "{$product->name} is listed twice. Combine the quantities into one line."]);
            }

            $seen[$product->id] = true;
        }
    }

    /** @param  array<int, array<string, mixed>>  $items */
    private function syncItems(Recipe $recipe, array $items): void
    {
        $recipe->items()->delete();

        foreach ($items as $item) {
            $recipe->items()->create([
                'product_id' => (int) $item['product_id'],
                'quantity' => round((float) $item['quantity'], 3),
                'note' => $item['note'] ?? null,
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(Recipe $recipe): array
    {
        return $recipe->only(['name', 'family', 'yield_quantity', 'yield_unit', 'status']) + [
            'items' => $recipe->items()->get(['product_id', 'quantity'])->map(fn ($i) => [$i->product_id, $i->quantity])->all(),
        ];
    }
}
