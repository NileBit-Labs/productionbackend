<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Shift;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class ShiftTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    private function api($user, $shop)
    {
        return $this->actingAs($user, 'sanctum')->withHeaders($this->shopHeader($shop));
    }

    private function open($user, $shop, int $float = 10000)
    {
        return $this->api($user, $shop)->postJson('/api/shifts/open', ['opening_cash' => $float]);
    }

    private function sell($user, $shop, $product, int $qty, array $payments, array $extra = []): array
    {
        return $this->api($user, $shop)->postJson('/api/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => $qty]], 'payments' => $payments,
        ] + $extra)->assertCreated()->json();
    }

    public function test_expected_cash_is_the_float_plus_cash_in_minus_cash_out_and_variance_follows(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        [$cashier] = $this->shopWithMember(Role::Cashier, $shop);
        $product = $this->productWithStock($shop, $owner, price: 1000, stock: 100);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Mama Rose']);

        $shiftId = $this->open($cashier, $shop, 10000)->assertCreated()->json('id');

        // 5,000 cash sale (customer hands over 6,000, change given) => +5,000
        $cashSale = $this->sell($cashier, $shop, $product, 5, [['method' => 'CASH', 'amount' => 6000]]);
        // 3,000 by mobile money => not cash
        $this->sell($cashier, $shop, $product, 3, [['method' => 'MOBILE_MONEY', 'amount' => 3000]]);
        // a credit customer repays 2,000 cash => +2,000
        $this->sell($cashier, $shop, $product, 4, [], ['customer_id' => $customer->id]);
        $this->api($cashier, $shop)->postJson("/api/customers/{$customer->id}/payments", ['amount' => 2000, 'method' => 'CASH'])->assertOk();
        // the cashier, as a manager, refunds 1,000 cash out
        [$manager] = $this->shopWithMember(Role::Manager, $shop);
        $this->api($manager, $shop)->postJson("/api/sales/{$cashSale['id']}/refund", [
            'lines' => [['sale_item_id' => $cashSale['items'][0]['id'], 'quantity' => 1]], 'method' => 'CASH', 'reason' => 'Faulty',
        ])->assertCreated();

        // 10,000 + 5,000 + 2,000 = 17,000 expected in the cashier's drawer (the refund was paid by the manager).
        $closed = $this->api($cashier, $shop)->postJson("/api/shifts/{$shiftId}/close", ['actual_cash' => 16500, 'note' => 'Short 500'])
            ->assertOk()
            ->assertJsonPath('expected_cash', 17000)
            ->assertJsonPath('actual_cash', 16500)
            ->assertJsonPath('variance', -500)
            ->assertJsonPath('summary.cash_sales', 5000)
            ->assertJsonPath('summary.cash_repayments', 2000)
            ->assertJsonPath('summary.cash_refunds', 0)
            ->assertJsonPath('summary.other_methods.0.method', 'MOBILE_MONEY')
            ->assertJsonPath('summary.other_methods.0.in', 3000);

        $this->assertNotNull($closed->json('closed_at'));
        $log = AuditLog::where('action', 'shift.close')->firstOrFail();
        $this->assertSame(-500, $log->after_data['variance']);
    }

    public function test_cash_paid_out_by_the_cashier_reduces_their_expected_cash(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, price: 1000, stock: 100);

        $shiftId = $this->open($owner, $shop, 5000)->json('id');
        $sale = $this->sell($owner, $shop, $product, 4, [['method' => 'CASH', 'amount' => 4000]]);
        $this->api($owner, $shop)->postJson("/api/sales/{$sale['id']}/refund", [
            'lines' => [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 1]], 'method' => 'CASH', 'reason' => 'Faulty',
        ])->assertCreated();

        // 5,000 + 4,000 - 1,000
        $this->api($owner, $shop)->postJson("/api/shifts/{$shiftId}/close", ['actual_cash' => 8000])
            ->assertJsonPath('expected_cash', 8000)->assertJsonPath('variance', 0)->assertJsonPath('summary.cash_refunds', 1000);
    }

    public function test_only_this_cashiers_cash_within_the_shift_window_counts(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        [$cashierA] = $this->shopWithMember(Role::Cashier, $shop);
        [$cashierB] = $this->shopWithMember(Role::Cashier, $shop);
        $product = $this->productWithStock($shop, $owner, price: 1000, stock: 100);

        $this->sell($cashierA, $shop, $product, 1, [['method' => 'CASH', 'amount' => 1000]]); // before the shift

        $this->travel(1)->minutes();
        $shiftId = $this->open($cashierA, $shop, 0)->json('id');

        $this->travel(1)->minutes();
        $this->sell($cashierA, $shop, $product, 2, [['method' => 'CASH', 'amount' => 2000]]); // counts
        $this->sell($cashierB, $shop, $product, 7, [['method' => 'CASH', 'amount' => 7000]]); // someone else's till

        $this->travel(1)->minutes();
        $closed = $this->api($cashierA, $shop)->postJson("/api/shifts/{$shiftId}/close", ['actual_cash' => 2000])->assertOk();
        $closed->assertJsonPath('expected_cash', 2000)->assertJsonPath('variance', 0);

        $this->travel(1)->minutes();
        $this->sell($cashierA, $shop, $product, 5, [['method' => 'CASH', 'amount' => 5000]]); // after the shift

        $this->api($cashierA, $shop)->getJson("/api/shifts/{$shiftId}")->assertJsonPath('expected_cash_now', 2000);
    }

    public function test_an_offline_sale_made_during_the_shift_but_synced_after_it_closed_is_flagged(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, price: 1000, stock: 100);

        $this->travelTo(now()->startOfMinute());
        $shiftId = $this->open($owner, $shop, 0)->json('id');
        $madeAt = now()->addMinutes(5)->toIso8601String();

        $this->travel(30)->minutes();
        $this->api($owner, $shop)->postJson("/api/shifts/{$shiftId}/close", ['actual_cash' => 0])
            ->assertJsonPath('expected_cash', 0);

        // The device reconnects later and pushes a sale it made 5 minutes into the shift.
        $this->travel(2)->hours();
        $this->api($owner, $shop)->postJson('/api/sync/push', ['device_id' => 'd1', 'events' => [[
            'local_event_id' => 'e1', 'entity_type' => 'sale', 'operation' => 'create', 'client_created_at' => $madeAt,
            'payload' => ['idempotency_key' => 'e1', 'items' => [['product_id' => $product->id, 'quantity' => 3]], 'payments' => [['method' => 'CASH', 'amount' => 3000]]],
        ]]])->assertJsonPath('results.0.status', 'processed');

        $this->api($owner, $shop)->getJson("/api/shifts/{$shiftId}")
            ->assertJsonPath('expected_cash', 0)
            ->assertJsonPath('expected_cash_now', 3000)
            ->assertJsonPath('changed_since_close', 3000);
    }

    public function test_only_one_shift_can_be_open_per_cashier_but_others_can_open_theirs(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        [$cashier] = $this->shopWithMember(Role::Cashier, $shop);

        $this->open($cashier, $shop)->assertCreated();
        $this->open($cashier, $shop)->assertUnprocessable()->assertJsonValidationErrors('shift');
        $this->open($owner, $shop)->assertCreated();

        $this->expectException(UniqueConstraintViolationException::class);
        Shift::create(['shop_id' => $shop->id, 'cashier_id' => $cashier->id, 'opening_cash' => 0, 'opened_at' => now()]);
    }

    public function test_a_closed_shift_cannot_be_closed_again_and_a_new_one_can_be_opened(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $id = $this->open($owner, $shop)->json('id');

        $this->api($owner, $shop)->postJson("/api/shifts/{$id}/close", ['actual_cash' => 10000])->assertOk();
        $this->api($owner, $shop)->postJson("/api/shifts/{$id}/close", ['actual_cash' => 1])->assertUnprocessable();
        $this->open($owner, $shop)->assertCreated();
    }

    public function test_the_count_is_blind_for_a_cashier_until_the_shift_is_closed(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        [$cashier] = $this->shopWithMember(Role::Cashier, $shop);
        $shiftId = $this->open($cashier, $shop, 10000)->json('id');

        $current = $this->api($cashier, $shop)->getJson('/api/shifts/current')->assertOk();
        $current->assertJsonPath('shift.opening_cash', 10000)->assertJsonMissingPath('shift.summary.expected_cash')->assertJsonMissingPath('shift.expected_cash');
        $this->api($owner, $shop)->getJson("/api/shifts/{$shiftId}")->assertJsonPath('summary.expected_cash', 10000);

        $this->api($cashier, $shop)->postJson("/api/shifts/{$shiftId}/close", ['actual_cash' => 9000])
            ->assertJsonPath('expected_cash', 10000)->assertJsonPath('variance', -1000);
        $this->api($cashier, $shop)->getJson("/api/shifts/{$shiftId}")->assertJsonPath('variance', -1000);
    }

    public function test_cashiers_only_see_and_close_their_own_shifts_while_managers_see_all(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        [$cashierA] = $this->shopWithMember(Role::Cashier, $shop);
        [$cashierB] = $this->shopWithMember(Role::Cashier, $shop);
        $a = $this->open($cashierA, $shop)->json('id');
        $b = $this->open($cashierB, $shop)->json('id');

        $this->api($cashierA, $shop)->getJson('/api/shifts')->assertJsonCount(1, 'data');
        $this->api($cashierA, $shop)->getJson("/api/shifts/{$b}")->assertNotFound();
        $this->api($cashierA, $shop)->postJson("/api/shifts/{$b}/close", ['actual_cash' => 0])->assertNotFound();

        $this->api($owner, $shop)->getJson('/api/shifts')->assertJsonCount(2, 'data');
        $this->api($owner, $shop)->postJson("/api/shifts/{$a}/close", ['actual_cash' => 10000])->assertOk();
        $this->assertSame($cashierA->id, Shift::find($a)->cashier_id, 'closing on someone\'s behalf must not change whose shift it was');
    }

    public function test_shifts_are_scoped_to_a_shop(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        [$other, $otherShop] = $this->shopWithMember();
        $id = $this->open($owner, $shop)->json('id');

        $this->api($other, $otherShop)->getJson("/api/shifts/{$id}")->assertNotFound();
        $this->api($other, $otherShop)->getJson('/api/shifts')->assertJsonCount(0, 'data');
        $this->api($other, $otherShop)->getJson('/api/shifts/current')->assertJsonPath('shift', null);
    }
}
