<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Sale;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    private function api($user, $shop)
    {
        return $this->actingAs($user, 'sanctum')->withHeaders($this->shopHeader($shop));
    }

    private function log(Shop $shop, $user, string $action, ?string $at = null, array $extra = []): AuditLog
    {
        $log = new AuditLog;
        $log->forceFill($extra + [
            'organization_id' => $shop->organization_id, 'shop_id' => $shop->id, 'user_id' => $user->id,
            'action' => $action, 'entity_type' => Sale::class, 'entity_id' => 1,
            'created_at' => $at ?? now(),
        ])->save();

        return $log;
    }

    public function test_only_the_owner_can_read_the_audit_log(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        [$manager] = $this->shopWithMember(Role::Manager, $shop);
        [$cashier] = $this->shopWithMember(Role::Cashier, $shop);

        $this->api($owner, $shop)->getJson('/api/audit-logs')->assertOk();
        $this->api($manager, $shop)->getJson('/api/audit-logs')->assertForbidden();
        $this->api($cashier, $shop)->getJson('/api/audit-logs')->assertForbidden();
    }

    public function test_it_shows_this_shops_entries_newest_first_and_never_another_shops(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        [$other, $otherShop] = $this->shopWithMember();
        $this->log($shop, $owner, 'sale.void');
        $this->log($shop, $owner, 'sale.refund');
        $this->log($otherShop, $other, 'sale.void');

        $rows = $this->api($owner, $shop)->getJson('/api/audit-logs')->assertOk()->json('page.data');

        $this->assertSame(['sale.refund', 'sale.void'], array_column($rows, 'action'));
    }

    public function test_each_entry_names_who_did_it_what_it_touched_and_the_before_and_after(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $this->log($shop, $owner, 'expense.update', null, ['entity_id' => 42, 'before_data' => ['amount' => 500], 'after_data' => ['amount' => 450]]);

        $this->api($owner, $shop)->getJson('/api/audit-logs')
            ->assertJsonPath('page.data.0.user.name', $owner->name)
            ->assertJsonPath('page.data.0.entity', 'Sale')
            ->assertJsonPath('page.data.0.entity_id', 42)
            ->assertJsonPath('page.data.0.before.amount', 500)
            ->assertJsonPath('page.data.0.after.amount', 450);
    }

    public function test_it_can_be_filtered_by_action_and_by_person_and_lists_the_available_actions(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        [$manager] = $this->shopWithMember(Role::Manager, $shop);
        $this->log($shop, $owner, 'sale.void');
        $this->log($shop, $manager, 'sale.refund');
        $this->log($shop, $manager, 'sale.void');

        $this->api($owner, $shop)->getJson('/api/audit-logs?action=sale.void')->assertJsonCount(2, 'page.data');
        $this->api($owner, $shop)->getJson("/api/audit-logs?user_id={$manager->id}")->assertJsonCount(2, 'page.data');
        $this->api($owner, $shop)->getJson("/api/audit-logs?user_id={$manager->id}&action=sale.refund")->assertJsonCount(1, 'page.data');
        $this->api($owner, $shop)->getJson('/api/audit-logs')->assertJsonPath('actions', ['sale.refund', 'sale.void']);
    }

    public function test_dates_are_the_shops_local_days_not_utc_days(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        // 22:30 UTC on 1 Sept is 01:30 on 2 Sept in Kampala (UTC+3).
        $this->log($shop, $owner, 'sale.void', '2026-09-01 22:30:00');

        $this->api($owner, $shop)->getJson('/api/audit-logs?from=2026-09-02&to=2026-09-02')->assertJsonCount(1, 'page.data');
        $this->api($owner, $shop)->getJson('/api/audit-logs?from=2026-09-01&to=2026-09-01')->assertJsonCount(0, 'page.data');
    }

    public function test_a_period_must_make_sense(): void
    {
        [$owner, $shop] = $this->shopWithMember();

        $this->api($owner, $shop)->getJson('/api/audit-logs?from=2026-09-10&to=2026-09-01')->assertUnprocessable()->assertJsonValidationErrors('from');
        $this->api($owner, $shop)->getJson('/api/audit-logs?from=2024-01-01&to=2026-09-01')->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->api($owner, $shop)->getJson('/api/audit-logs?from=not-a-date')->assertUnprocessable();
    }

    public function test_real_actions_from_other_modules_show_up_in_it(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, price: 1000, stock: 10);
        $sale = $this->api($owner, $shop)->postJson('/api/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]], 'payments' => [['method' => 'CASH', 'amount' => 1000]],
        ])->json();
        $this->api($owner, $shop)->postJson("/api/sales/{$sale['id']}/void", ['reason' => 'Test'])->assertOk();

        $this->api($owner, $shop)->getJson('/api/audit-logs?action=sale.void')
            ->assertJsonCount(1, 'page.data')->assertJsonPath('page.data.0.entity', 'Sale')->assertJsonPath('page.data.0.entity_id', $sale['id']);
    }
}
