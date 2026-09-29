<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Sale;
use App\Services\CustomerLedger;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class RefundTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    private function api($user, $shop)
    {
        return $this->actingAs($user, 'sanctum')->withHeaders($this->shopHeader($shop));
    }

    /** Sells and returns the sale JSON. */
    private function sell($user, $shop, array $items, int $paid, array $extra = []): array
    {
        return $this->api($user, $shop)->postJson('/api/sales', [
            'items' => $items,
            'payments' => $paid > 0 ? [['method' => 'CASH', 'amount' => $paid]] : [],
        ] + $extra)->assertCreated()->json();
    }

    private function refund($user, $shop, int $saleId, array $lines, array $extra = [])
    {
        return $this->api($user, $shop)->postJson("/api/sales/{$saleId}/refund", [
            'lines' => $lines, 'method' => 'CASH', 'reason' => 'Customer returned it',
        ] + $extra);
    }

    private function stock($shop, $product): float
    {
        return app(StockService::class)->current($shop->id, $product->id);
    }

    public function test_refunding_a_returned_item_gives_the_money_back_and_restocks_it(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, price: 2000, cost: 1200, stock: 10);
        $sale = $this->sell($owner, $shop, [['product_id' => $product->id, 'quantity' => 4]], 8000);
        $this->assertEquals(6.0, $this->stock($shop, $product));

        $this->refund($owner, $shop, $sale['id'], [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 1, 'restock' => true]])
            ->assertCreated()->assertJsonPath('total_refund', 2000)->assertJsonPath('cash_refund', 2000);

        $this->assertEquals(7.0, $this->stock($shop, $product));
        $out = Payment::where('direction', 'out')->firstOrFail();
        $this->assertSame(2000, $out->amount);
        $this->assertSame($sale['id'], $out->sale_id);
        $this->assertDatabaseHas('stock_movements', ['movement_type' => 'SALE_RETURN', 'unit_cost' => 1200]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'sale.refund', 'entity_id' => $sale['id']]);
    }

    public function test_refunding_a_damaged_item_returns_the_money_but_not_the_stock(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, price: 2000, stock: 10);
        $sale = $this->sell($owner, $shop, [['product_id' => $product->id, 'quantity' => 2]], 4000);

        $this->refund($owner, $shop, $sale['id'], [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 2, 'restock' => false]])
            ->assertCreated()->assertJsonPath('total_refund', 4000);

        $this->assertEquals(8.0, $this->stock($shop, $product), 'a damaged item must not go back on the shelf');
        $this->assertSame(4000, (int) Payment::where('direction', 'out')->sum('amount'));
        $this->assertDatabaseMissing('stock_movements', ['movement_type' => 'SALE_RETURN']);
    }

    public function test_refunds_are_cumulative_and_can_never_exceed_what_was_sold(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, price: 1000, stock: 10);
        $sale = $this->sell($owner, $shop, [['product_id' => $product->id, 'quantity' => 3]], 3000);
        $item = $sale['items'][0]['id'];

        $this->refund($owner, $shop, $sale['id'], [['sale_item_id' => $item, 'quantity' => 2]])->assertCreated();
        $this->refund($owner, $shop, $sale['id'], [['sale_item_id' => $item, 'quantity' => 2]])
            ->assertUnprocessable()->assertJsonValidationErrors('lines.0.quantity');
        $this->refund($owner, $shop, $sale['id'], [['sale_item_id' => $item, 'quantity' => 1]])->assertCreated();
        $this->refund($owner, $shop, $sale['id'], [['sale_item_id' => $item, 'quantity' => 1]])->assertUnprocessable();

        $this->assertSame(3000, (int) Payment::where('direction', 'out')->sum('amount'));
        $this->assertSame(2, Refund::count());
    }

    public function test_partial_refunds_of_an_awkward_price_add_up_to_exactly_what_was_paid(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, price: 3333, stock: 10);
        $sale = $this->sell($owner, $shop, [['product_id' => $product->id, 'quantity' => 3]], 9999);
        $item = $sale['items'][0]['id'];

        foreach ([1, 1, 1] as $qty) {
            $this->refund($owner, $shop, $sale['id'], [['sale_item_id' => $item, 'quantity' => $qty]])->assertCreated();
        }

        $this->assertSame(9999, (int) Refund::sum('total_refund'));
    }

    public function test_an_order_level_discount_is_shared_across_lines_so_a_full_refund_equals_the_amount_paid(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $a = $this->productWithStock($shop, $owner, price: 1000, stock: 10);
        $b = $this->productWithStock($shop, $owner, price: 3000, stock: 10);
        $sale = $this->sell($owner, $shop, [
            ['product_id' => $a->id, 'quantity' => 1],
            ['product_id' => $b->id, 'quantity' => 1],
        ], 3501, ['discount' => 499]);
        $this->assertSame(3501, $sale['total']);

        $lines = collect($sale['items'])->map(fn ($i) => ['sale_item_id' => $i['id'], 'quantity' => 1])->all();
        $this->refund($owner, $shop, $sale['id'], $lines)->assertCreated()->assertJsonPath('total_refund', 3501);
        $this->assertSame(3501, (int) Payment::where('direction', 'out')->sum('amount'));
    }

    public function test_a_line_discount_reduces_what_is_refunded_for_that_line(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, price: 1000, stock: 10);
        $sale = $this->sell($owner, $shop, [['product_id' => $product->id, 'quantity' => 4, 'discount' => 400]], 3600);

        $this->refund($owner, $shop, $sale['id'], [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 1]])
            ->assertCreated()->assertJsonPath('total_refund', 900);
    }

    public function test_a_dry_run_previews_the_refund_without_writing_anything(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, price: 1000, stock: 10);
        $sale = $this->sell($owner, $shop, [['product_id' => $product->id, 'quantity' => 2]], 2000);

        $this->api($owner, $shop)->postJson("/api/sales/{$sale['id']}/refund", [
            'dry_run' => true, 'lines' => [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 1]],
        ])->assertOk()->assertJsonPath('total_refund', 1000)->assertJsonPath('cash_refund', 1000);

        $this->assertDatabaseCount('refunds', 0);
        $this->assertSame(0, Payment::where('direction', 'out')->count());
        $this->assertEquals(8.0, $this->stock($shop, $product));
    }

    public function test_repeating_a_refund_with_the_same_key_pays_out_once(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, price: 1000, stock: 10);
        $sale = $this->sell($owner, $shop, [['product_id' => $product->id, 'quantity' => 2]], 2000);
        $lines = [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 1]];

        $first = $this->refund($owner, $shop, $sale['id'], $lines, ['idempotency_key' => 'r-1'])->assertCreated();
        $second = $this->refund($owner, $shop, $sale['id'], $lines, ['idempotency_key' => 'r-1'])->assertOk();

        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertSame(1, Refund::count());
        $this->assertSame(1000, (int) Payment::where('direction', 'out')->sum('amount'));
        $this->assertEquals(9.0, $this->stock($shop, $product));
    }

    public function test_refunding_a_credit_sale_cancels_the_debt_before_any_cash_goes_out(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, price: 10000, stock: 10);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Mama Rose']);
        // total 30,000: 4,000 paid, 26,000 on her account.
        $sale = $this->sell($owner, $shop, [['product_id' => $product->id, 'quantity' => 3]], 4000, ['customer_id' => $customer->id]);

        // Returning 2 items = 20,000, all of it inside what she still owes.
        $this->refund($owner, $shop, $sale['id'], [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 2]])
            ->assertCreated()->assertJsonPath('total_refund', 20000)->assertJsonPath('balance_credit', 20000)->assertJsonPath('cash_refund', 0);

        $this->assertSame(6000, app(CustomerLedger::class)->balance($customer));
        $this->assertSame(0, Payment::where('direction', 'out')->count());
        $this->assertDatabaseHas('customer_ledger_entries', ['type' => 'REFUND', 'amount' => -20000]);
    }

    public function test_a_credit_refund_larger_than_the_debt_pays_the_rest_back_in_cash(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, price: 10000, stock: 10);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Mama Rose']);
        // total 30,000: 24,000 paid, 6,000 on account.
        $sale = $this->sell($owner, $shop, [['product_id' => $product->id, 'quantity' => 3]], 24000, ['customer_id' => $customer->id]);

        $this->refund($owner, $shop, $sale['id'], [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 3]])
            ->assertCreated()->assertJsonPath('balance_credit', 6000)->assertJsonPath('cash_refund', 24000);

        $this->assertSame(0, app(CustomerLedger::class)->balance($customer));
        $this->assertSame(24000, (int) Payment::where('direction', 'out')->sum('amount'));
    }

    public function test_once_the_debt_is_repaid_a_refund_goes_back_as_cash(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, price: 10000, stock: 10);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Mama Rose']);
        $sale = $this->sell($owner, $shop, [['product_id' => $product->id, 'quantity' => 1]], 0, ['customer_id' => $customer->id]);
        $this->api($owner, $shop)->postJson("/api/customers/{$customer->id}/payments", ['amount' => 10000, 'method' => 'CASH'])->assertOk();

        $this->refund($owner, $shop, $sale['id'], [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 1]])
            ->assertCreated()->assertJsonPath('balance_credit', 0)->assertJsonPath('cash_refund', 10000);
    }

    public function test_the_payout_method_is_required_when_money_goes_back(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, price: 1000, stock: 10);
        $sale = $this->sell($owner, $shop, [['product_id' => $product->id, 'quantity' => 1]], 1000);

        $this->api($owner, $shop)->postJson("/api/sales/{$sale['id']}/refund", [
            'lines' => [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 1]], 'reason' => 'Faulty',
        ])->assertUnprocessable()->assertJsonValidationErrors('method');

        $this->assertDatabaseCount('refunds', 0);
    }

    public function test_a_cashier_cannot_refund_and_a_manager_can(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        [$cashier] = $this->shopWithMember(Role::Cashier, $shop);
        [$manager] = $this->shopWithMember(Role::Manager, $shop);
        $product = $this->productWithStock($shop, $owner, price: 1000, stock: 10);
        $sale = $this->sell($cashier, $shop, [['product_id' => $product->id, 'quantity' => 2]], 2000);
        $line = [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 1]];

        $this->refund($cashier, $shop, $sale['id'], $line)->assertForbidden();
        $this->refund($manager, $shop, $sale['id'], $line)->assertCreated()->assertJsonPath('approved_by', $manager->id);
    }

    public function test_a_voided_sale_cannot_be_refunded_and_a_refunded_sale_cannot_be_voided(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, price: 1000, stock: 10);
        $voided = $this->sell($owner, $shop, [['product_id' => $product->id, 'quantity' => 1]], 1000);
        $refunded = $this->sell($owner, $shop, [['product_id' => $product->id, 'quantity' => 2]], 2000);

        $this->api($owner, $shop)->postJson("/api/sales/{$voided['id']}/void", ['reason' => 'Mistake'])->assertOk();
        $this->refund($owner, $shop, $voided['id'], [['sale_item_id' => $voided['items'][0]['id'], 'quantity' => 1]])->assertUnprocessable();

        $this->refund($owner, $shop, $refunded['id'], [['sale_item_id' => $refunded['items'][0]['id'], 'quantity' => 1]])->assertCreated();
        $this->api($owner, $shop)->postJson("/api/sales/{$refunded['id']}/void", ['reason' => 'Mistake'])
            ->assertUnprocessable();

        // 10 in stock: sale 1 takes 1 and its void puts it back; sale 2 takes 2 and the refund returns 1 => 9.
        $this->assertEquals(9.0, $this->stock($shop, $product));
    }

    public function test_an_item_from_a_different_sale_or_shop_cannot_be_refunded(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        [$other, $otherShop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, price: 1000, stock: 10);
        $otherProduct = $this->productWithStock($otherShop, $other, price: 1000, stock: 10);
        $mine = $this->sell($owner, $shop, [['product_id' => $product->id, 'quantity' => 1]], 1000);
        $theirs = $this->sell($other, $otherShop, [['product_id' => $otherProduct->id, 'quantity' => 1]], 1000);

        $this->refund($owner, $shop, $mine['id'], [['sale_item_id' => $theirs['items'][0]['id'], 'quantity' => 1]])
            ->assertUnprocessable()->assertJsonValidationErrors('lines.0.sale_item_id');
        $this->refund($owner, $shop, $theirs['id'], [['sale_item_id' => $theirs['items'][0]['id'], 'quantity' => 1]])->assertNotFound();
    }

    public function test_net_cash_and_stock_reconcile_after_a_mix_of_sales_and_refunds(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, price: 1500, cost: 900, stock: 50);
        $s1 = $this->sell($owner, $shop, [['product_id' => $product->id, 'quantity' => 6]], 9000);
        $s2 = $this->sell($owner, $shop, [['product_id' => $product->id, 'quantity' => 4]], 6000, ['discount' => 500]);

        $this->refund($owner, $shop, $s1['id'], [['sale_item_id' => $s1['items'][0]['id'], 'quantity' => 2, 'restock' => true]])->assertCreated();
        $this->refund($owner, $shop, $s2['id'], [['sale_item_id' => $s2['items'][0]['id'], 'quantity' => 1, 'restock' => false]])->assertCreated();

        $in = (int) Payment::where('direction', 'in')->sum('amount');
        $out = (int) Payment::where('direction', 'out')->sum('amount');
        $this->assertSame((9000 + 5500) - (3000 + 1375), $in - $out);
        // 50 - 6 - 4 + 2 restocked (the damaged one is not restocked)
        $this->assertEquals(42.0, $this->stock($shop, $product));
        $this->assertSame($out, (int) Refund::sum('cash_refund'));
    }

    public function test_the_refundable_view_shows_what_is_left_on_each_line(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, price: 1000, stock: 10);
        $sale = $this->sell($owner, $shop, [['product_id' => $product->id, 'quantity' => 3]], 3000);
        $this->refund($owner, $shop, $sale['id'], [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 1]])->assertCreated();

        $this->api($owner, $shop)->getJson("/api/sales/{$sale['id']}/refundable")
            ->assertOk()->assertJsonPath('items.0.sold', 3)->assertJsonPath('items.0.refunded', 1)
            ->assertJsonPath('items.0.remaining', 2)->assertJsonPath('items.0.remaining_value', 2000);

        $this->api($owner, $shop)->getJson("/api/sales/{$sale['id']}")->assertJsonCount(1, 'refunds');
        $this->assertSame(1, AuditLog::where('action', 'sale.refund')->count());
        $this->assertSame('completed', Sale::find($sale['id'])->status);
    }
}
