<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class BotFoundationTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    public function test_customer_payment_replay_records_once_even_after_balance_is_cleared(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Sarah']);
        CustomerLedgerEntry::create(['shop_id' => $shop->id, 'customer_id' => $customer->id, 'type' => 'CREDIT_SALE', 'amount' => 3000, 'recorded_by' => $owner->id]);
        $this->actingAs($owner, 'sanctum')->withHeaders($this->shopHeader($shop));
        $payload = ['amount' => 3000, 'method' => 'CASH', 'reference' => 'receipt', 'idempotency_key' => 'customer-payment-1'];

        $this->postJson("/api/customers/{$customer->id}/payments", $payload)->assertOk()->assertJsonPath('balance', 0);
        $this->postJson("/api/customers/{$customer->id}/payments", $payload)->assertOk()->assertJsonPath('balance', 0);

        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(1, CustomerLedgerEntry::where('type', 'PAYMENT')->count());
    }

    public function test_reused_payment_key_rejects_changed_amount_without_another_posting(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Sarah']);
        CustomerLedgerEntry::create(['shop_id' => $shop->id, 'customer_id' => $customer->id, 'type' => 'CREDIT_SALE', 'amount' => 3000, 'recorded_by' => $owner->id]);
        $this->actingAs($owner, 'sanctum')->withHeaders($this->shopHeader($shop));
        $payload = ['amount' => 1000, 'method' => 'CASH', 'idempotency_key' => 'customer-payment-1'];
        $this->postJson("/api/customers/{$customer->id}/payments", $payload)->assertOk();

        $payload['amount'] = 500;
        $this->postJson("/api/customers/{$customer->id}/payments", $payload)->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');

        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(2000, (int) CustomerLedgerEntry::where('customer_id', $customer->id)->sum('amount'));
    }

    public function test_supplier_payment_header_key_replays_once_and_changed_reference_is_rejected(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $supplier = Supplier::create(['shop_id' => $shop->id, 'name' => 'Fresh Supplies']);
        SupplierLedgerEntry::create(['shop_id' => $shop->id, 'supplier_id' => $supplier->id, 'type' => 'PURCHASE', 'amount' => 5000, 'recorded_by' => $owner->id]);
        $this->actingAs($owner, 'sanctum')->withHeaders($this->shopHeader($shop) + ['Idempotency-Key' => 'supplier-payment-1']);
        $payload = ['amount' => 2000, 'method' => 'CASH', 'reference' => 'paid'];

        $this->postJson("/api/suppliers/{$supplier->id}/payments", $payload)->assertOk();
        $this->postJson("/api/suppliers/{$supplier->id}/payments", $payload)->assertOk();
        $payload['reference'] = 'changed';
        $this->postJson("/api/suppliers/{$supplier->id}/payments", $payload)->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');

        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(3000, (int) SupplierLedgerEntry::where('supplier_id', $supplier->id)->sum('amount'));
    }

    public function test_distinct_payment_keys_allow_two_genuine_payments_with_same_reference(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Sarah']);
        CustomerLedgerEntry::create(['shop_id' => $shop->id, 'customer_id' => $customer->id, 'type' => 'CREDIT_SALE', 'amount' => 3000, 'recorded_by' => $owner->id]);
        $this->actingAs($owner, 'sanctum')->withHeaders($this->shopHeader($shop));

        foreach (['first', 'second'] as $key) {
            $this->postJson("/api/customers/{$customer->id}/payments", ['amount' => 1000, 'method' => 'CASH', 'reference' => 'same reference', 'idempotency_key' => $key])->assertOk();
        }

        $this->assertDatabaseCount('payments', 2);
        $this->assertSame(1000, (int) CustomerLedgerEntry::where('customer_id', $customer->id)->sum('amount'));
    }

    public function test_local_day_sales_and_movement_filters_include_early_morning_and_exclude_next_day(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $shop->organization->update(['timezone' => 'Africa/Kampala']);
        $product = $this->productWithStock($shop, $owner);
        $this->actingAs($owner, 'sanctum')->withHeaders($this->shopHeader($shop));
        $id = $this->postJson('/api/sales', ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'payments' => [['method' => 'CASH', 'amount' => 1000]]])->assertCreated()->json('id');
        Sale::whereKey($id)->update(['created_at' => '2026-11-02 22:30:00']);
        StockMovement::where('product_id', $product->id)->update(['created_at' => '2026-11-02 22:30:00']);

        $this->getJson('/api/sales?from=2026-11-03&to=2026-11-03')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/inventory/movements?from=2026-11-03&to=2026-11-03')->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/sales?from=2026-11-02&to=2026-11-02')->assertOk()->assertJsonPath('total', 0);
        Sale::whereKey($id)->update(['created_at' => '2026-11-03 21:00:00']);
        $this->getJson('/api/sales?from=2026-11-03&to=2026-11-03')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_reversed_date_filters_return_422(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $this->actingAs($owner, 'sanctum')->withHeaders($this->shopHeader($shop));

        $this->getJson('/api/sales?from=2026-11-04&to=2026-11-03')->assertUnprocessable()->assertJsonValidationErrors('from');
        $this->getJson('/api/inventory/movements?from=2026-11-04&to=2026-11-03')->assertUnprocessable()->assertJsonValidationErrors('from');
    }

    public function test_payment_key_cannot_be_reused_for_another_customer_or_actor(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        [$cashier] = $this->shopWithMember(Role::Cashier, $shop);
        $first = Customer::create(['shop_id' => $shop->id, 'name' => 'Sarah']);
        $second = Customer::create(['shop_id' => $shop->id, 'name' => 'Peter']);
        foreach ([$first, $second] as $customer) {
            CustomerLedgerEntry::create(['shop_id' => $shop->id, 'customer_id' => $customer->id, 'type' => 'CREDIT_SALE', 'amount' => 3000, 'recorded_by' => $owner->id]);
        }
        $this->actingAs($owner, 'sanctum')->withHeaders($this->shopHeader($shop));
        $payload = ['amount' => 1000, 'method' => 'CASH', 'idempotency_key' => 'shared-operation'];
        $this->postJson("/api/customers/{$first->id}/payments", $payload)->assertOk();

        $this->postJson("/api/customers/{$second->id}/payments", $payload)->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $this->actingAs($cashier, 'sanctum');
        $this->postJson("/api/customers/{$first->id}/payments", $payload)->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');

        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(3000, (int) CustomerLedgerEntry::where('customer_id', $second->id)->sum('amount'));
    }

    public function test_failed_payment_does_not_reserve_a_key(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Sarah']);
        CustomerLedgerEntry::create(['shop_id' => $shop->id, 'customer_id' => $customer->id, 'type' => 'CREDIT_SALE', 'amount' => 1000, 'recorded_by' => $owner->id]);
        $this->actingAs($owner, 'sanctum')->withHeaders($this->shopHeader($shop));
        $payload = ['amount' => 2000, 'method' => 'CASH', 'idempotency_key' => 'corrected-operation'];

        $this->postJson("/api/customers/{$customer->id}/payments", $payload)->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->assertDatabaseCount('payments', 0);
        $payload['amount'] = 1000;
        $this->postJson("/api/customers/{$customer->id}/payments", $payload)->assertOk()->assertJsonPath('balance', 0);

        $this->assertDatabaseCount('payments', 1);
    }

    public function test_single_bound_history_filters_remain_open_ended(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $this->actingAs($owner, 'sanctum')->withHeaders($this->shopHeader($shop));

        $this->getJson('/api/sales?from=2030-01-01')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/sales?to=2020-01-01')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/inventory/movements?from=2030-01-01')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_inactive_shop_allows_reading_but_refuses_financial_changes_with_403(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $shop->update(['status' => 'inactive']);
        $this->actingAs($owner, 'sanctum')->withHeaders($this->shopHeader($shop));

        $this->getJson('/api/sales')->assertOk();
        $this->postJson('/api/sales', ['items' => []])->assertForbidden();

        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->patchJson("/api/shops/{$shop->id}", ['status' => 'active'])->assertOk();
    }

    public function test_changed_sale_price_requires_review_and_creates_no_sale(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner);
        $this->actingAs($owner, 'sanctum')->withHeaders($this->shopHeader($shop));

        $this->postJson('/api/sales', ['expected_total' => 900, 'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payments' => [['method' => 'CASH', 'amount' => 1000]]])->assertUnprocessable()->assertJsonValidationErrors('expected_total');

        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('payments', 0);
    }
}
