<?php

namespace Tests\Concerns;

use App\Enums\MovementType;
use App\Enums\Role;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Shop;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\UserShopRole;

trait CreatesShops
{
    /** @return array{0: User, 1: Shop} */
    protected function shopWithMember(Role $role = Role::Owner, ?Shop $existing = null): array
    {
        $user = User::factory()->create();

        if (! $existing) {
            $organization = Organization::create(['name' => 'Org', 'owner_user_id' => $user->id]);
            $user->update(['organization_id' => $organization->id]);
            $existing = Shop::create([
                'organization_id' => $organization->id,
                'name' => 'Test Shop',
                'business_type' => 'small_shop',
            ]);
        } else {
            $user->update(['organization_id' => $existing->organization_id]);
        }

        UserShopRole::create(['user_id' => $user->id, 'shop_id' => $existing->id, 'role' => $role]);

        return [$user, $existing];
    }

    protected function productWithStock(Shop $shop, User $by, int $price = 1000, int $cost = 600, float $stock = 10, array $attributes = []): Product
    {
        $product = Product::create($attributes + [
            'shop_id' => $shop->id,
            'name' => 'Product '.uniqid(),
            'selling_price' => $price,
            'current_cost' => $cost,
        ]);

        if ($stock > 0) {
            StockMovement::create([
                'shop_id' => $shop->id,
                'product_id' => $product->id,
                'quantity_delta' => $stock,
                'unit_cost' => $cost,
                'movement_type' => MovementType::OpeningStock,
                'performed_by' => $by->id,
            ]);
        }

        return $product;
    }

    /** @return array<string, string> */
    protected function shopHeader(Shop $shop): array
    {
        return ['X-Shop-Id' => (string) $shop->id];
    }
}
