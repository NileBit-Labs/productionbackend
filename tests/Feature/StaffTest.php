<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Shop;
use App\Models\User;
use App\Models\UserShopRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class StaffTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    private function api($user, $shop)
    {
        return $this->actingAs($user, 'sanctum')->withHeaders($this->shopHeader($shop));
    }

    private function addStaff($actor, $shop, array $overrides = [])
    {
        return $this->api($actor, $shop)->postJson('/api/staff', $overrides + [
            'name' => 'Cathy Cashier', 'email' => 'cathy@example.com', 'role' => 'cashier', 'password' => 'secret-pass-1',
        ]);
    }

    public function test_an_owner_adds_a_cashier_who_can_then_log_in(): void
    {
        [$owner, $shop] = $this->shopWithMember();

        $this->addStaff($owner, $shop)->assertCreated()
            ->assertJsonPath('role', 'cashier')->assertJsonPath('status', 'active')->assertJsonPath('email', 'cathy@example.com');

        $user = User::where('email', 'cathy@example.com')->firstOrFail();
        $this->assertSame($shop->organization_id, $user->organization_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'staff.create', 'entity_id' => $user->id]);

        $this->postJson('/api/auth/login', ['email' => 'cathy@example.com', 'password' => 'secret-pass-1'])
            ->assertOk()->assertJsonPath('user.shop_roles.0.role', 'cashier');
    }

    public function test_an_owner_can_add_a_manager_but_a_manager_can_only_add_cashiers(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        [$manager] = $this->shopWithMember(Role::Manager, $shop);

        $this->addStaff($owner, $shop, ['email' => 'm@example.com', 'role' => 'manager'])->assertCreated()->assertJsonPath('role', 'manager');
        $this->addStaff($manager, $shop, ['email' => 'm2@example.com', 'role' => 'manager'])->assertForbidden();
        $this->addStaff($manager, $shop, ['email' => 'c@example.com', 'role' => 'cashier'])->assertCreated();
    }

    public function test_nobody_can_create_an_owner_through_the_api(): void
    {
        [$owner, $shop] = $this->shopWithMember();

        $this->addStaff($owner, $shop, ['role' => 'owner'])->assertUnprocessable()->assertJsonValidationErrors('role');
    }

    public function test_cashiers_cannot_see_or_manage_staff(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        [$cashier] = $this->shopWithMember(Role::Cashier, $shop);

        $this->api($cashier, $shop)->getJson('/api/staff')->assertForbidden();
        $this->addStaff($cashier, $shop)->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'cathy@example.com']);
    }

    public function test_the_same_email_cannot_be_added_twice_or_taken_from_another_business(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        [$otherOwner] = $this->shopWithMember();

        $this->addStaff($owner, $shop)->assertCreated();
        $this->addStaff($owner, $shop)->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->addStaff($owner, $shop, ['email' => $otherOwner->email])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_someone_already_in_the_business_can_be_added_to_another_shop_without_touching_their_password(): void
    {
        [$owner, $shopA] = $this->shopWithMember();
        $shopB = Shop::create(['organization_id' => $shopA->organization_id, 'name' => 'Second shop', 'business_type' => 'small_shop']);
        UserShopRole::create(['user_id' => $owner->id, 'shop_id' => $shopB->id, 'role' => Role::Owner]);

        $this->addStaff($owner, $shopA, ['password' => 'original-pass-1'])->assertCreated();
        $this->addStaff($owner, $shopB, ['password' => 'ignored-pass-2'])->assertCreated();

        $this->postJson('/api/auth/login', ['email' => 'cathy@example.com', 'password' => 'original-pass-1'])->assertOk();
        $this->postJson('/api/auth/login', ['email' => 'cathy@example.com', 'password' => 'ignored-pass-2'])->assertUnprocessable();
    }

    public function test_the_list_shows_only_this_shops_people_owner_first_and_marks_you(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        [$manager] = $this->shopWithMember(Role::Manager, $shop);
        [$cashier] = $this->shopWithMember(Role::Cashier, $shop);
        [$outsider] = $this->shopWithMember();

        $rows = $this->api($manager, $shop)->getJson('/api/staff')->assertOk()->assertJsonCount(3)->json();

        $this->assertSame(['owner', 'manager', 'cashier'], array_column($rows, 'role'));
        $this->assertSame([$manager->id], array_column(array_filter($rows, fn ($r) => $r['is_you']), 'id'));
        $this->assertNotContains($outsider->id, array_column($rows, 'id'));
    }

    public function test_only_the_owner_can_change_a_role_and_it_is_audited(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        [$manager] = $this->shopWithMember(Role::Manager, $shop);
        [$cashier] = $this->shopWithMember(Role::Cashier, $shop);

        $this->api($manager, $shop)->patchJson("/api/staff/{$cashier->id}", ['role' => 'manager'])->assertForbidden();

        $this->api($owner, $shop)->patchJson("/api/staff/{$cashier->id}", ['role' => 'manager'])->assertOk()->assertJsonPath('role', 'manager');
        $log = AuditLog::where('action', 'staff.role_change')->firstOrFail();
        $this->assertSame('cashier', $log->before_data['role']);
        $this->assertSame('manager', $log->after_data['role']);
    }

    public function test_the_owner_and_your_own_account_are_protected(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        [$manager] = $this->shopWithMember(Role::Manager, $shop);

        $this->api($manager, $shop)->patchJson("/api/staff/{$owner->id}", ['status' => 'inactive'])->assertForbidden();
        $this->api($owner, $shop)->patchJson("/api/staff/{$owner->id}", ['status' => 'inactive'])->assertForbidden();
        $this->api($manager, $shop)->patchJson("/api/staff/{$manager->id}", ['role' => 'owner'])->assertUnprocessable();
        $this->api($manager, $shop)->postJson("/api/staff/{$manager->id}/password", ['password' => 'new-password-1'])->assertForbidden();

        $this->assertSame('active', $owner->fresh()->status);
    }

    public function test_a_manager_can_manage_cashiers_but_not_other_managers(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        [$manager] = $this->shopWithMember(Role::Manager, $shop);
        [$otherManager] = $this->shopWithMember(Role::Manager, $shop);
        [$cashier] = $this->shopWithMember(Role::Cashier, $shop);

        $this->api($manager, $shop)->patchJson("/api/staff/{$cashier->id}", ['status' => 'inactive'])->assertOk();
        $this->api($manager, $shop)->patchJson("/api/staff/{$otherManager->id}", ['status' => 'inactive'])->assertForbidden();
        $this->api($manager, $shop)->postJson("/api/staff/{$otherManager->id}/password", ['password' => 'new-password-1'])->assertForbidden();
    }

    public function test_deactivating_someone_signs_them_out_everywhere_and_blocks_login_until_reactivated(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $this->addStaff($owner, $shop)->assertCreated();
        $cashier = User::where('email', 'cathy@example.com')->firstOrFail();
        $token = $this->postJson('/api/auth/login', ['email' => 'cathy@example.com', 'password' => 'secret-pass-1'])->json('token');
        $cashier->createToken('second-device');
        $this->assertSame(2, $cashier->tokens()->count());

        $this->api($owner, $shop)->patchJson("/api/staff/{$cashier->id}", ['status' => 'inactive'])->assertOk()->assertJsonPath('status', 'inactive');

        $this->assertSame(0, $cashier->tokens()->count(), 'every session is ended');

        // actingAs() above leaves the owner signed in on the guard; drop that so
        // this request is judged purely on the cashier's old bearer token.
        $this->app['auth']->forgetGuards();
        $this->withHeaders($this->shopHeader($shop) + ['Authorization' => "Bearer {$token}"])->getJson('/api/customers')->assertUnauthorized();
        $this->postJson('/api/auth/login', ['email' => 'cathy@example.com', 'password' => 'secret-pass-1'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseHas('audit_logs', ['action' => 'staff.deactivate', 'entity_id' => $cashier->id]);

        $this->api($owner, $shop)->patchJson("/api/staff/{$cashier->id}", ['status' => 'active'])->assertOk();
        $this->postJson('/api/auth/login', ['email' => 'cathy@example.com', 'password' => 'secret-pass-1'])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'staff.activate', 'entity_id' => $cashier->id]);
    }

    public function test_a_deactivated_account_is_refused_even_with_a_surviving_session(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        [$cashier] = $this->shopWithMember(Role::Cashier, $shop);
        $cashier->update(['status' => 'inactive']);

        $this->api($cashier, $shop)->getJson('/api/customers')->assertForbidden();
    }

    public function test_a_password_reset_replaces_the_password_signs_them_out_and_never_logs_it(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $this->addStaff($owner, $shop)->assertCreated();
        $cashier = User::where('email', 'cathy@example.com')->firstOrFail();
        $this->postJson('/api/auth/login', ['email' => 'cathy@example.com', 'password' => 'secret-pass-1']);

        $this->api($owner, $shop)->postJson("/api/staff/{$cashier->id}/password", ['password' => 'brand-new-pass-9'])->assertOk();

        $this->assertSame(0, $cashier->tokens()->count());
        $this->postJson('/api/auth/login', ['email' => 'cathy@example.com', 'password' => 'secret-pass-1'])->assertUnprocessable();
        $this->postJson('/api/auth/login', ['email' => 'cathy@example.com', 'password' => 'brand-new-pass-9'])->assertOk();

        $log = AuditLog::where('action', 'staff.password_reset')->firstOrFail();
        $this->assertStringNotContainsString('brand-new-pass-9', json_encode($log->toArray()));

        $this->api($owner, $shop)->postJson("/api/staff/{$cashier->id}/password", ['password' => 'short'])->assertUnprocessable();
    }

    public function test_staff_of_another_shop_cannot_be_touched(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        [$otherOwner, $otherShop] = $this->shopWithMember();
        [$theirCashier] = $this->shopWithMember(Role::Cashier, $otherShop);

        $this->api($owner, $shop)->patchJson("/api/staff/{$theirCashier->id}", ['status' => 'inactive'])->assertNotFound();
        $this->api($owner, $shop)->postJson("/api/staff/{$theirCashier->id}/password", ['password' => 'new-password-1'])->assertNotFound();
        $this->assertSame('active', $theirCashier->fresh()->status);
    }

    public function test_someone_can_change_their_own_password_and_other_devices_are_signed_out(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $owner->update(['password' => 'old-password-1']);
        $first = $this->postJson('/api/auth/login', ['email' => $owner->email, 'password' => 'old-password-1'])->json('token');
        $second = $this->postJson('/api/auth/login', ['email' => $owner->email, 'password' => 'old-password-1'])->json('token');

        $call = fn (string $token, array $body) => $this->withHeaders(['Authorization' => "Bearer {$token}"])->postJson('/api/auth/change-password', $body);

        $call($first, ['current_password' => 'wrong', 'password' => 'new-password-1'])->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $call($first, ['current_password' => 'old-password-1', 'password' => 'old-password-1'])->assertUnprocessable();
        $call($first, ['current_password' => 'old-password-1', 'password' => 'new-password-1'])->assertOk();

        $this->assertSame(1, $owner->tokens()->count(), 'the device that changed it stays signed in, the other does not');
        $this->postJson('/api/auth/login', ['email' => $owner->email, 'password' => 'new-password-1'])->assertOk();
        $this->postJson('/api/auth/login', ['email' => $owner->email, 'password' => 'old-password-1'])->assertUnprocessable();
    }
}
