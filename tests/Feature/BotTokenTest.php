<?php

namespace Tests\Feature;

use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class BotTokenTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    public function test_whatsapp_login_issues_a_scoped_expiring_token_without_admin_access(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $owner->update(['password' => 'password123']);
        $result = $this->postJson('/api/auth/login', ['email' => $owner->email, 'password' => 'password123', 'client' => 'whatsapp'])->assertOk()->assertJsonPath('token_scope', 'pos:bot');
        $token = $result->json('token');
        $stored = PersonalAccessToken::findToken($token);
        $this->assertSame(['pos:bot'], $stored->abilities);
        $this->assertNotNull($stored->expires_at);
        $this->withToken($token)->withHeaders($this->shopHeader($shop))->getJson('/api/pos/products')->assertOk();
        $this->getJson('/api/staff')->assertForbidden();
        $this->postJson('/api/auth/refresh')->assertForbidden();
        $this->postJson('/api/auth/change-password', [])->assertForbidden();
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_cashier_cannot_create_a_shop_and_promote_themselves_to_owner(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        [$cashier] = $this->shopWithMember(Role::Cashier, $shop);
        $this->actingAs($cashier, 'sanctum')->postJson('/api/shops', ['name' => 'Unauthorized outlet', 'business_type' => 'retail'])->assertForbidden();

        $this->assertDatabaseCount('shops', 1);
        $this->assertDatabaseMissing('user_shop_roles', ['user_id' => $cashier->id, 'role' => 'owner']);
    }

    public function test_a_revoked_bot_token_can_no_longer_read_business_data(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $token = $owner->createToken('nilebit-whatsapp', ['pos:bot'])->plainTextToken;
        $this->withToken($token)->withHeaders($this->shopHeader($shop))->getJson('/api/pos/products')->assertOk();
        $this->postJson('/api/auth/logout')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/pos/products')->assertUnauthorized();
    }

    public function test_web_account_can_revoke_its_own_bot_connection_but_not_another_users(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        [$other] = $this->shopWithMember();
        $own = $owner->createToken('nilebit-whatsapp', ['pos:bot'])->accessToken;
        $foreign = $other->createToken('nilebit-whatsapp', ['pos:bot'])->accessToken;
        $web = $owner->createToken('api')->plainTextToken;
        $this->withToken($web)->getJson('/api/auth/connections')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $own->id);
        $this->deleteJson('/api/auth/connections/'.$foreign->id)->assertNotFound();
        $this->deleteJson('/api/auth/connections/'.$own->id)->assertNoContent();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $own->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $foreign->id]);
        $this->getJson('/api/auth/me')->assertOk();
    }
}
