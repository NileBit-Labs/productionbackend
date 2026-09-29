<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\Shop;
use App\Models\User;
use App\Models\UserShopRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_register_and_gets_an_organization(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Elioda Muhangi',
            'email' => 'elioda@example.com',
            'password' => 'password123',
            'organization_name' => 'NileBit Labs',
        ]);

        $response->assertCreated()
            ->assertJsonPath('user.email', 'elioda@example.com')
            ->assertJsonPath('user.organization.name', 'NileBit Labs')
            ->assertJsonStructure(['user', 'token']);

        $this->assertDatabaseHas('organizations', ['name' => 'NileBit Labs']);
    }

    public function test_a_user_can_login_with_correct_credentials(): void
    {
        $user = User::factory()->create(['password' => 'password123']);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ]);

        $response->assertOk()->assertJsonStructure(['user', 'token']);
    }

    public function test_login_returns_the_users_existing_shops(): void
    {
        $user = User::factory()->create(['password' => 'password123']);
        $organization = Organization::create(['name' => 'Org', 'owner_user_id' => $user->id]);
        $user->update(['organization_id' => $organization->id]);
        $shop = Shop::create(['organization_id' => $organization->id, 'name' => 'Existing Shop', 'business_type' => 'small_shop']);
        UserShopRole::create(['user_id' => $user->id, 'shop_id' => $shop->id, 'role' => Role::Owner]);

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password123'])
            ->assertOk()
            ->assertJsonPath('user.shop_roles.0.shop.name', 'Existing Shop');
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $user = User::factory()->create(['password' => 'password123']);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertUnprocessable();
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_me_returns_the_authenticated_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('email', $user->email);
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/logout');

        $response->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
