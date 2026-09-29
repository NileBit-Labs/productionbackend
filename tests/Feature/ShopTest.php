<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\Shop;
use App\Models\User;
use App\Models\UserShopRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_create_a_shop_under_their_organization(): void
    {
        $user = User::factory()->create();
        $organization = Organization::create(['name' => 'Test Org', 'owner_user_id' => $user->id]);
        $user->update(['organization_id' => $organization->id]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/shops', [
            'name' => 'Downtown Shop',
            'business_type' => 'mini_mart',
        ]);

        $response->assertCreated()->assertJsonPath('name', 'Downtown Shop');

        $this->assertDatabaseHas('shops', ['name' => 'Downtown Shop', 'organization_id' => $organization->id]);
        $this->assertDatabaseHas('user_shop_roles', [
            'user_id' => $user->id,
            'role' => Role::Owner->value,
        ]);
    }

    public function test_a_user_without_a_role_on_a_shop_cannot_view_it(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::create(['name' => 'Test Org', 'owner_user_id' => $owner->id]);
        $shop = Shop::create([
            'organization_id' => $organization->id,
            'name' => 'Downtown Shop',
            'business_type' => 'mini_mart',
        ]);

        $outsider = User::factory()->create();

        $this->actingAs($outsider, 'sanctum')
            ->getJson("/api/shops/{$shop->id}")
            ->assertForbidden();
    }

    public function test_a_cashier_cannot_update_shop_settings(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::create(['name' => 'Test Org', 'owner_user_id' => $owner->id]);
        $shop = Shop::create([
            'organization_id' => $organization->id,
            'name' => 'Downtown Shop',
            'business_type' => 'mini_mart',
        ]);

        $cashier = User::factory()->create(['organization_id' => $organization->id]);
        UserShopRole::create(['user_id' => $cashier->id, 'shop_id' => $shop->id, 'role' => Role::Cashier]);

        $this->actingAs($cashier, 'sanctum')
            ->patchJson("/api/shops/{$shop->id}", ['name' => 'Renamed'])
            ->assertForbidden();
    }
}
