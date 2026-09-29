<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\Shop;
use App\Models\User;
use App\Models\UserShopRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class PublicBetaAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_returns_a_clear_forbidden_response_when_private_beta_is_enabled(): void
    {
        config(['beta.public_registration' => false]);

        $this->postJson('/api/auth/register', [
            'name' => 'New Person',
            'email' => 'new@example.com',
            'password' => 'password123',
            'organization_name' => 'New Business',
        ])->assertForbidden()
            ->assertJsonPath('message', 'Public registration is currently closed. Please contact NileBit Labs for an invitation.');
    }

    public function test_an_invited_owner_can_login_while_public_registration_is_closed(): void
    {
        config(['beta.public_registration' => false]);

        $user = User::factory()->create(['password' => 'password123']);
        $organization = Organization::create(['name' => 'Invited Business', 'owner_user_id' => $user->id]);
        $user->update(['organization_id' => $organization->id]);
        $shop = Shop::create(['organization_id' => $organization->id, 'name' => 'Invited Shop', 'business_type' => 'small_shop']);
        UserShopRole::create(['user_id' => $user->id, 'shop_id' => $shop->id, 'role' => Role::Owner]);

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password123'])
            ->assertOk()
            ->assertJsonPath('user.shop_roles.0.shop.id', $shop->id)
            ->assertJsonPath('user.shop_roles.0.role', Role::Owner->value);
    }

    public function test_login_is_limited_per_email_and_ip_address(): void
    {
        $email = 'rate-limited-login@example.com';
        RateLimiter::clear('login:'.$email.'|127.0.0.1');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'wrong-password'])
                ->assertUnprocessable();
        }

        $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'wrong-password'])
            ->assertTooManyRequests();
    }

    public function test_registration_is_limited_per_ip_address_when_explicitly_enabled(): void
    {
        config(['beta.public_registration' => true]);
        RateLimiter::clear('registration:127.0.0.1');

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->postJson('/api/auth/register', [
                'name' => "New Person {$attempt}",
                'email' => "new-person-{$attempt}@example.com",
                'password' => 'password123',
                'organization_name' => "New Business {$attempt}",
            ])->assertCreated();
        }

        $this->postJson('/api/auth/register', [
            'name' => 'Fourth Person',
            'email' => 'fourth-person@example.com',
            'password' => 'password123',
            'organization_name' => 'Fourth Business',
        ])->assertTooManyRequests();
    }

    public function test_only_the_exact_production_origins_are_allowed_by_cors(): void
    {
        config(['cors.allowed_origins' => ['https://pos.nilebitlabs.com', 'https://nilebitlabs.com']]);

        $this->withHeaders([
            'Origin' => 'https://pos.nilebitlabs.com',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'content-type',
        ])->options('/api/auth/login')
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'https://pos.nilebitlabs.com');

        $this->withHeaders([
            'Origin' => 'https://untrusted.example.com',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'content-type',
        ])->options('/api/auth/login')
            ->assertNoContent()
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }
}
