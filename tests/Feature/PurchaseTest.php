<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Purchase;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Services\StockService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class PurchaseTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    private function api($user, $shop)
    {
        return $this->actingAs($user, 'sanctum')->withHeaders($this->shopHeader($shop));
    }

    private function supplier($shop, string $name = 'Kampala Wholesale'): Supplier
    {
        return Supplier::create(['shop_id' => $shop->id, 'name' => $name]);
    }

    private function receive($user, $shop, Supplier $supplier, array $items, array $extra = [])
    {
        return $this->api($user, $shop)->postJson('/api/purchases', [
            'supplier_id' => $supplier->id, 'items' => $items,
        ] + $extra);
    }

    private function stock(Product $product): float
    {
        return app(StockService::class)->current($product->shop_id, $product->id);
    }

    private function balance($user, $shop, Supplier $supplier): int
    {
        return $this->api($user, $shop)->getJson("/api/suppliers/{$supplier->id}")->json('balance');
    }

    public function test_receiving_a_purchase_adds_stock_and_records_what_is_owed(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $supplier = $this->supplier($shop);
        $product = $this->productWithStock($shop, $owner, cost: 3800, stock: 0);

        $response = $this->receive($owner, $shop, $supplier, [
            ['product_id' => $product->id, 'quantity' => 40, 'unit_cost' => 4000],
        ], ['amount_paid' => 60000, 'payment_method' => 'CASH', 'reference' => 'INV-77'])
            ->assertCreated()
            ->assertJsonPath('purchase_number', 'P-000001')
            ->assertJsonPath('total', 160000)
            ->assertJsonPath('amount_paid', 60000)
            ->assertJsonPath('owed', 100000)
            ->assertJsonPath('payment_status', 'partial');

        $this->assertSame(40.0, $this->stock($product));
        $movement = StockMovement::where('product_id', $product->id)->first();
        $this->assertSame('PURCHASE', $movement->movement_type->value);
        $this->assertSame(4000, (int) $movement->unit_cost);
        $this->assertSame($response->json('id'), $movement->reference_id);

        // Only the part left on credit is owed; the part paid on the day never was.
        $this->assertSame(100000, $this->balance($owner, $shop, $supplier));

        $payment = Payment::where('purchase_id', $response->json('id'))->sole();
        $this->assertSame('out', $payment->direction);
        $this->assertSame($supplier->id, $payment->supplier_id);
        $this->assertSame(60000, $payment->amount);

        $this->assertSame(1, AuditLog::where('action', 'purchase.create')->count());
    }

    public function test_cost_becomes_the_weighted_average_of_what_is_on_the_shelf_and_what_arrived(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, cost: 3800, stock: 60);

        $this->receive($owner, $shop, $this->supplier($shop), [
            ['product_id' => $product->id, 'quantity' => 40, 'unit_cost' => 4000],
        ])->assertCreated();

        // (60 x 3800 + 40 x 4000) / 100
        $this->assertSame(3880, $product->fresh()->current_cost);
    }

    public function test_cost_is_simply_the_new_price_when_there_was_no_stock(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, cost: 3800, stock: 0);

        $this->receive($owner, $shop, $this->supplier($shop), [
            ['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 4200],
        ])->assertCreated();

        $this->assertSame(4200, $product->fresh()->current_cost);
    }

    public function test_buying_by_the_carton_is_converted_to_the_base_unit(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, cost: 1900, stock: 0, attributes: ['base_unit' => 'piece']);
        ProductUnit::create(['product_id' => $product->id, 'unit_name' => 'carton', 'conversion_to_base_unit' => 24, 'selling_price' => 60000]);

        $this->receive($owner, $shop, $this->supplier($shop), [
            ['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 48000, 'unit_name' => 'Carton'],
        ])->assertCreated()
            ->assertJsonPath('total', 240000)
            ->assertJsonPath('items.0.base_quantity', 120)
            ->assertJsonPath('items.0.base_unit_cost', 2000);

        $this->assertSame(120.0, $this->stock($product));
        $this->assertSame(2000, $product->fresh()->current_cost);
    }

    public function test_an_unknown_unit_is_refused(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner);

        $this->receive($owner, $shop, $this->supplier($shop), [
            ['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 100, 'unit_name' => 'pallet'],
        ])->assertUnprocessable()->assertJsonValidationErrors('items.0.unit_name');

        $this->assertSame(0, Purchase::count());
    }

    public function test_paying_more_than_the_total_is_refused_and_records_nothing(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, stock: 0);

        $this->receive($owner, $shop, $this->supplier($shop), [
            ['product_id' => $product->id, 'quantity' => 2, 'unit_cost' => 1000],
        ], ['amount_paid' => 2500, 'payment_method' => 'CASH'])->assertUnprocessable()->assertJsonValidationErrors('amount_paid');

        $this->assertSame(0, Purchase::count());
        $this->assertSame(0, StockMovement::where('product_id', $product->id)->count());
        $this->assertSame(0, Payment::count());
    }

    public function test_a_payment_method_is_needed_when_money_is_paid(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, stock: 0);

        $this->receive($owner, $shop, $this->supplier($shop), [
            ['product_id' => $product->id, 'quantity' => 2, 'unit_cost' => 1000],
        ], ['amount_paid' => 500])->assertUnprocessable()->assertJsonValidationErrors('payment_method');
    }

    public function test_a_fully_credit_purchase_is_all_owed_and_a_fully_paid_one_owes_nothing(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $supplier = $this->supplier($shop);
        $product = $this->productWithStock($shop, $owner, stock: 0);
        $item = [['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 1000]];

        $this->receive($owner, $shop, $supplier, $item)->assertJsonPath('payment_status', 'unpaid')->assertJsonPath('owed', 10000);
        $this->receive($owner, $shop, $supplier, $item, ['amount_paid' => 10000, 'payment_method' => 'MOBILE_MONEY'])
            ->assertJsonPath('payment_status', 'paid')->assertJsonPath('owed', 0);

        $this->assertSame(10000, $this->balance($owner, $shop, $supplier));
    }

    public function test_retrying_with_the_same_key_does_not_receive_the_stock_twice(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $supplier = $this->supplier($shop);
        $product = $this->productWithStock($shop, $owner, stock: 0);
        $item = [['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 1000]];

        $first = $this->receive($owner, $shop, $supplier, $item, ['idempotency_key' => 'abc'])->assertCreated();
        $again = $this->receive($owner, $shop, $supplier, $item, ['idempotency_key' => 'abc'])->assertOk();

        $this->assertSame($first->json('id'), $again->json('id'));
        $this->assertSame(1, Purchase::count());
        $this->assertSame(10.0, $this->stock($product));
        $this->assertSame(10000, $this->balance($owner, $shop, $supplier));
    }

    public function test_paying_a_supplier_reduces_what_is_owed_oldest_purchase_first(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $supplier = $this->supplier($shop);
        $product = $this->productWithStock($shop, $owner, stock: 0);
        $item = [['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 1000]];

        $first = $this->receive($owner, $shop, $supplier, $item)->json('id');
        $second = $this->receive($owner, $shop, $supplier, $item)->json('id');

        $this->api($owner, $shop)->postJson("/api/suppliers/{$supplier->id}/payments", ['amount' => 12000, 'method' => 'BANK', 'reference' => 'TT-1'])
            ->assertOk()->assertJsonPath('balance', 8000);

        $this->api($owner, $shop)->getJson("/api/purchases/{$first}")->assertJsonPath('owed', 0)->assertJsonPath('payment_status', 'paid');
        $this->api($owner, $shop)->getJson("/api/purchases/{$second}")->assertJsonPath('owed', 8000)->assertJsonPath('payment_status', 'partial');

        $payment = Payment::whereNull('purchase_id')->where('supplier_id', $supplier->id)->sole();
        $this->assertSame('out', $payment->direction);
        $this->assertSame(12000, $payment->amount);
        $this->assertSame(1, AuditLog::where('action', 'supplier.payment')->count());
    }

    public function test_the_shop_cannot_pay_a_supplier_more_than_it_owes(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $supplier = $this->supplier($shop);
        $product = $this->productWithStock($shop, $owner, stock: 0);
        $this->receive($owner, $shop, $supplier, [['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 1000]]);

        $this->api($owner, $shop)->postJson("/api/suppliers/{$supplier->id}/payments", ['amount' => 5001, 'method' => 'CASH'])
            ->assertUnprocessable()->assertJsonValidationErrors('amount');

        $this->assertSame(5000, $this->balance($owner, $shop, $supplier));
        $this->assertSame(0, Payment::count());
    }

    public function test_cancelling_a_purchase_takes_the_stock_back_and_clears_the_debt(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $supplier = $this->supplier($shop);
        $product = $this->productWithStock($shop, $owner, stock: 5);
        $id = $this->receive($owner, $shop, $supplier, [['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 1000]])->json('id');

        $this->api($owner, $shop)->postJson("/api/purchases/{$id}/cancel", ['reason' => 'Wrong supplier'])
            ->assertOk()->assertJsonPath('status', 'cancelled')->assertJsonPath('owed', 0)->assertJsonPath('payment_status', 'cancelled');

        $this->assertSame(5.0, $this->stock($product));
        $this->assertSame(0, $this->balance($owner, $shop, $supplier));
        $this->assertSame('PURCHASE_RETURN', StockMovement::where('product_id', $product->id)->latest('id')->first()->movement_type->value);
        $this->assertSame(1, AuditLog::where('action', 'purchase.cancel')->count());

        // History is kept: the original entries are still there, plus the reversal.
        $this->assertSame(3, StockMovement::where('product_id', $product->id)->count());
    }

    public function test_cancelling_puts_the_cost_back_when_nothing_else_has_changed_it(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, cost: 3800, stock: 60);
        $id = $this->receive($owner, $shop, $this->supplier($shop), [['product_id' => $product->id, 'quantity' => 40, 'unit_cost' => 4000]])->json('id');
        $this->assertSame(3880, $product->fresh()->current_cost);

        $this->api($owner, $shop)->postJson("/api/purchases/{$id}/cancel", ['reason' => 'Mistake'])->assertOk();

        $this->assertSame(3800, $product->fresh()->current_cost);
    }

    public function test_cancelling_leaves_the_cost_alone_when_a_later_purchase_has_moved_it(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $supplier = $this->supplier($shop);
        $product = $this->productWithStock($shop, $owner, cost: 3800, stock: 60);
        $first = $this->receive($owner, $shop, $supplier, [['product_id' => $product->id, 'quantity' => 40, 'unit_cost' => 4000]])->json('id');
        $this->receive($owner, $shop, $supplier, [['product_id' => $product->id, 'quantity' => 100, 'unit_cost' => 4200]])->assertCreated();
        $costNow = $product->fresh()->current_cost;

        $this->api($owner, $shop)->postJson("/api/purchases/{$first}/cancel", ['reason' => 'Mistake'])->assertOk();

        $this->assertSame($costNow, $product->fresh()->current_cost);
    }

    public function test_two_lines_for_the_same_product_unwind_back_to_the_original_cost(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, cost: 1000, stock: 10);
        $id = $this->receive($owner, $shop, $this->supplier($shop), [
            ['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 2000],
            ['product_id' => $product->id, 'quantity' => 20, 'unit_cost' => 3000],
        ])->assertCreated()->json('id');
        $this->assertNotSame(1000, $product->fresh()->current_cost);

        $this->api($owner, $shop)->postJson("/api/purchases/{$id}/cancel", ['reason' => 'Mistake'])->assertOk();

        $this->assertSame(1000, $product->fresh()->current_cost);
        $this->assertSame(10.0, $this->stock($product));
    }

    public function test_just_after_midnight_in_kampala_todays_date_is_accepted_even_though_utc_is_still_yesterday(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, stock: 0);
        $item = [['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 500]];

        // 00:30 on the 21st in Kampala is 21:30 on the 20th in UTC.
        $this->travelTo(Carbon::parse('2026-09-21 00:30:00', 'Africa/Kampala'));

        $this->receive($owner, $shop, $this->supplier($shop), $item, ['purchase_date' => '2026-09-21'])
            ->assertCreated()->assertJsonPath('purchase_date', '2026-09-21');
        $this->receive($owner, $shop, $this->supplier($shop, 'Second'), $item)
            ->assertCreated()->assertJsonPath('purchase_date', '2026-09-21');
        $this->receive($owner, $shop, $this->supplier($shop, 'Third'), $item, ['purchase_date' => '2026-09-22'])
            ->assertUnprocessable()->assertJsonValidationErrors('purchase_date');
    }

    public function test_a_cancelled_purchase_cannot_be_cancelled_again(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, stock: 0);
        $id = $this->receive($owner, $shop, $this->supplier($shop), [['product_id' => $product->id, 'quantity' => 2, 'unit_cost' => 500]])->json('id');

        $this->api($owner, $shop)->postJson("/api/purchases/{$id}/cancel", ['reason' => 'Mistake'])->assertOk();
        $this->api($owner, $shop)->postJson("/api/purchases/{$id}/cancel", ['reason' => 'Mistake'])->assertUnprocessable();

        $this->assertSame(0.0, $this->stock($product));
    }

    public function test_a_purchase_with_money_paid_cannot_be_cancelled(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, stock: 0);
        $id = $this->receive($owner, $shop, $this->supplier($shop), [['product_id' => $product->id, 'quantity' => 2, 'unit_cost' => 500]], ['amount_paid' => 200, 'payment_method' => 'CASH'])->json('id');

        $this->api($owner, $shop)->postJson("/api/purchases/{$id}/cancel", ['reason' => 'Mistake'])->assertUnprocessable();

        $this->assertSame(2.0, $this->stock($product));
    }

    public function test_a_purchase_that_a_later_payment_was_applied_to_cannot_be_cancelled(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $supplier = $this->supplier($shop);
        $product = $this->productWithStock($shop, $owner, stock: 0);
        $id = $this->receive($owner, $shop, $supplier, [['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 1000]])->json('id');

        $this->api($owner, $shop)->postJson("/api/suppliers/{$supplier->id}/payments", ['amount' => 1000, 'method' => 'CASH'])->assertOk();

        $this->api($owner, $shop)->postJson("/api/purchases/{$id}/cancel", ['reason' => 'Mistake'])->assertUnprocessable();

        $this->assertSame('received', Purchase::find($id)->status);
        $this->assertSame(9000, $this->balance($owner, $shop, $supplier));
    }

    public function test_stock_that_has_already_been_sold_blocks_the_cancellation(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, stock: 0);
        $id = $this->receive($owner, $shop, $this->supplier($shop), [['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 500]])->json('id');

        $this->api($owner, $shop)->postJson('/api/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => 4]], 'payments' => [['method' => 'CASH', 'amount' => 4000]],
        ])->assertCreated();

        $this->api($owner, $shop)->postJson("/api/purchases/{$id}/cancel", ['reason' => 'Mistake'])
            ->assertUnprocessable()->assertJsonValidationErrors('purchase');

        $this->assertSame(6.0, $this->stock($product));
    }

    public function test_a_reason_is_needed_to_cancel(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, stock: 0);
        $id = $this->receive($owner, $shop, $this->supplier($shop), [['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 500]])->json('id');

        $this->api($owner, $shop)->postJson("/api/purchases/{$id}/cancel", [])->assertUnprocessable()->assertJsonValidationErrors('reason');
    }

    public function test_an_archived_product_or_inactive_supplier_cannot_be_received(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, stock: 0);
        $supplier = $this->supplier($shop);
        $item = [['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 500]];

        $product->update(['status' => 'archived']);
        $this->receive($owner, $shop, $supplier, $item)->assertUnprocessable()->assertJsonValidationErrors('items.0.product_id');

        $product->update(['status' => 'active']);
        $supplier->update(['is_active' => false]);
        $this->receive($owner, $shop, $supplier, $item)->assertUnprocessable()->assertJsonValidationErrors('supplier_id');
    }

    public function test_another_shops_supplier_and_products_are_refused_and_its_purchases_are_invisible(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        [$other, $otherShop] = $this->shopWithMember();
        $theirs = $this->supplier($otherShop, 'Theirs');
        $theirProduct = $this->productWithStock($otherShop, $other, stock: 0);
        $mine = $this->productWithStock($shop, $owner, stock: 0);

        $this->receive($owner, $shop, $theirs, [['product_id' => $mine->id, 'quantity' => 1, 'unit_cost' => 1]])
            ->assertUnprocessable()->assertJsonValidationErrors('supplier_id');
        $this->receive($owner, $shop, $this->supplier($shop), [['product_id' => $theirProduct->id, 'quantity' => 1, 'unit_cost' => 1]])
            ->assertUnprocessable()->assertJsonValidationErrors('items.0.product_id');

        $id = $this->receive($other, $otherShop, $theirs, [['product_id' => $theirProduct->id, 'quantity' => 1, 'unit_cost' => 1]])->json('id');
        $this->api($owner, $shop)->getJson("/api/purchases/{$id}")->assertNotFound();
        $this->api($owner, $shop)->postJson("/api/purchases/{$id}/cancel", ['reason' => 'Nope'])->assertNotFound();
        $this->api($owner, $shop)->getJson("/api/suppliers/{$theirs->id}")->assertNotFound();
        $this->assertSame(0, $this->api($owner, $shop)->getJson('/api/purchases')->json('total'));
    }

    public function test_cashiers_cannot_see_suppliers_or_purchases(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        [$cashier] = $this->shopWithMember(Role::Cashier, $shop);
        $supplier = $this->supplier($shop);

        foreach (['/api/suppliers', '/api/purchases', "/api/suppliers/{$supplier->id}", "/api/suppliers/{$supplier->id}/ledger"] as $path) {
            $this->api($cashier, $shop)->getJson($path)->assertForbidden();
        }

        $this->api($cashier, $shop)->postJson('/api/purchases', [])->assertForbidden();
        $this->api($cashier, $shop)->postJson("/api/suppliers/{$supplier->id}/payments", ['amount' => 1, 'method' => 'CASH'])->assertForbidden();
    }

    public function test_paying_a_supplier_does_not_change_a_shifts_expected_cash(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $supplier = $this->supplier($shop);
        $product = $this->productWithStock($shop, $owner, stock: 0);

        $shiftId = $this->api($owner, $shop)->postJson('/api/shifts/open', ['opening_cash' => 10000])->assertCreated()->json('id');

        $this->receive($owner, $shop, $supplier, [['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 1000]], ['amount_paid' => 4000, 'payment_method' => 'CASH']);
        $this->api($owner, $shop)->postJson("/api/suppliers/{$supplier->id}/payments", ['amount' => 3000, 'method' => 'CASH'])->assertOk();

        $this->api($owner, $shop)->postJson("/api/shifts/{$shiftId}/close", ['actual_cash' => 10000])
            ->assertOk()->assertJsonPath('expected_cash', 10000)->assertJsonPath('variance', 0);
    }

    public function test_the_stock_history_names_the_purchase(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, stock: 0);
        $this->receive($owner, $shop, $this->supplier($shop), [['product_id' => $product->id, 'quantity' => 3, 'unit_cost' => 500]])->assertCreated();

        $this->api($owner, $shop)->getJson("/api/inventory/movements?product_id={$product->id}")
            ->assertJsonPath('data.0.type', 'PURCHASE')
            ->assertJsonPath('data.0.reference.type', 'Purchase')
            ->assertJsonPath('data.0.reference.label', 'P-000001');
    }

    public function test_purchase_numbers_count_up_per_shop(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        [$other, $otherShop] = $this->shopWithMember();
        $supplier = $this->supplier($shop);
        $product = $this->productWithStock($shop, $owner, stock: 0);
        $item = [['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 100]];

        $this->receive($owner, $shop, $supplier, $item)->assertJsonPath('purchase_number', 'P-000001');
        $this->receive($owner, $shop, $supplier, $item)->assertJsonPath('purchase_number', 'P-000002');

        $theirs = $this->productWithStock($otherShop, $other, stock: 0);
        $this->receive($other, $otherShop, $this->supplier($otherShop), [['product_id' => $theirs->id, 'quantity' => 1, 'unit_cost' => 100]])
            ->assertJsonPath('purchase_number', 'P-000001');
    }

    public function test_the_purchase_list_filters_by_supplier_and_shows_what_is_owed(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $a = $this->supplier($shop, 'Alpha');
        $b = $this->supplier($shop, 'Bravo');
        $product = $this->productWithStock($shop, $owner, stock: 0);
        $item = [['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 1000]];

        $this->receive($owner, $shop, $a, $item);
        $this->receive($owner, $shop, $b, $item, ['amount_paid' => 1000, 'payment_method' => 'CASH']);

        $this->api($owner, $shop)->getJson('/api/purchases')->assertJsonPath('total', 2);

        $this->api($owner, $shop)->getJson("/api/purchases?supplier_id={$a->id}")
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.supplier.name', 'Alpha')->assertJsonPath('data.0.owed', 1000);
    }
}
