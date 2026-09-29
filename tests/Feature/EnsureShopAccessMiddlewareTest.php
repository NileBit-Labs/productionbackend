<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\Shop;
use App\Models\User;
use App\Models\UserShopRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The shop.access middleware isn't attached to any route yet - it's there
 * for other modules (products, sales, ...) to use on their own routes. This
 * exercises it directly against a throwaway test route so it's proven to
 * work before anyone builds on top of it.
 */
class EnsureShopAccessMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['api', 'auth:sanctum', 'shop.access'])
            ->get('/__test/shop-access', fn () => response()->json(['ok' => true]));

        Route::middleware(['api', 'auth:sanctum', 'shop.access:owner,manager'])
            ->get('/__test/shop-access/managers-only', fn () => response()->json(['ok' => true]));
    }

    public function test_it_requires_a_shop_id_header(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/__test/shop-access')
            ->assertStatus(400);
    }

    public function test_it_rejects_a_nonexistent_shop(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->withHeader('X-Shop-Id', '999999')
            ->getJson('/__test/shop-access')
            ->assertStatus(404);
    }

    public function test_it_rejects_a_user_with_no_role_on_the_shop(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::create(['name' => 'Org', 'owner_user_id' => $owner->id]);
        $shop = Shop::create(['organization_id' => $organization->id, 'name' => 'Shop', 'business_type' => 'small_shop']);

        $outsider = User::factory()->create();

        $this->actingAs($outsider, 'sanctum')
            ->withHeader('X-Shop-Id', (string) $shop->id)
            ->getJson('/__test/shop-access')
            ->assertStatus(403);
    }

    public function test_it_allows_a_user_with_any_role_when_none_specified(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::create(['name' => 'Org', 'owner_user_id' => $owner->id]);
        $shop = Shop::create(['organization_id' => $organization->id, 'name' => 'Shop', 'business_type' => 'small_shop']);

        $cashier = User::factory()->create(['organization_id' => $organization->id]);
        UserShopRole::create(['user_id' => $cashier->id, 'shop_id' => $shop->id, 'role' => Role::Cashier]);

        $this->actingAs($cashier, 'sanctum')
            ->withHeader('X-Shop-Id', (string) $shop->id)
            ->getJson('/__test/shop-access')
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    public function test_it_rejects_a_role_not_in_the_allowed_list(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::create(['name' => 'Org', 'owner_user_id' => $owner->id]);
        $shop = Shop::create(['organization_id' => $organization->id, 'name' => 'Shop', 'business_type' => 'small_shop']);

        $cashier = User::factory()->create(['organization_id' => $organization->id]);
        UserShopRole::create(['user_id' => $cashier->id, 'shop_id' => $shop->id, 'role' => Role::Cashier]);

        $this->actingAs($cashier, 'sanctum')
            ->withHeader('X-Shop-Id', (string) $shop->id)
            ->getJson('/__test/shop-access/managers-only')
            ->assertStatus(403);
    }

    public function test_it_allows_a_role_in_the_allowed_list(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::create(['name' => 'Org', 'owner_user_id' => $owner->id]);
        $shop = Shop::create(['organization_id' => $organization->id, 'name' => 'Shop', 'business_type' => 'small_shop']);

        UserShopRole::create(['user_id' => $owner->id, 'shop_id' => $shop->id, 'role' => Role::Owner]);

        $this->actingAs($owner, 'sanctum')
            ->withHeader('X-Shop-Id', (string) $shop->id)
            ->getJson('/__test/shop-access/managers-only')
            ->assertOk();
    }
}
