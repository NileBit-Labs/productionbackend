<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\Supplier;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Two days of trading with known figures, worked out by hand:
 *
 *  10 Sep  S1  3 Sugar + 1 Rice, paid cash 5,500                     (cost 3,300)
 *  11 Sep  S2  2 Sugar, 100 order discount, paid cash 1,900          (cost 1,200)
 *          -   rung up at 01:30 local, which is still 10 Sep in UTC
 *  11 Sep  S3  1 Rice on credit, 1,000 mobile money, 1,500 owed      (cost 1,500)
 *  11 Sep  S4  1 Sugar paid cash, then voided                        (never counts)
 *  11 Sep  R1  refund 1 Sugar from S1, restocked, 1,000 cash back    (cost back 600)
 *  11 Sep  R2  refund the Rice on S3, damaged so not restocked:
 *              2,500 = 1,500 debt cancelled + 1,000 mobile money back (cost stays)
 *
 *  10 Sep local:  sales 5,500                       net 5,500   cost 3,300
 *  11 Sep local:  sales 4,400, refunds 3,500        net   900   cost 2,100
 *  Both days:     sales 9,900, refunds 3,500        net 6,400   cost 5,400   profit 1,000
 */
class SalesProfitReportTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    private User $owner;

    private User $manager;

    private User $cashier;

    private Shop $shop;

    /** Local (Kampala) wall-clock time as the UTC string the database stores. */
    private function utc(string $local): string
    {
        return CarbonImmutable::parse($local, 'Africa/Kampala')->utc()->format('Y-m-d H:i:s');
    }

    private function api(User $user, ?Shop $shop = null)
    {
        return $this->actingAs($user, 'sanctum')->withHeaders($this->shopHeader($shop ?? $this->shop));
    }

    private function sell(User $by, array $items, array $payments, string $at, array $extra = []): array
    {
        $sale = $this->api($by)->postJson('/api/sales', ['items' => $items, 'payments' => $payments] + $extra)->assertCreated()->json();

        Sale::whereKey($sale['id'])->update(['created_at' => $at]);
        Payment::where('sale_id', $sale['id'])->update(['created_at' => $at]);

        return $sale;
    }

    private function refund(int $saleId, array $lines, string $method, string $at)
    {
        $refund = $this->api($this->manager)->postJson("/api/sales/{$saleId}/refund", [
            'lines' => $lines, 'method' => $method, 'reason' => 'Customer returned it',
        ])->assertCreated()->json();

        Refund::whereKey($refund['id'])->update(['created_at' => $at]);
        Payment::where('reference', "Refund R-{$refund['id']}")->update(['created_at' => $at]);

        return $refund;
    }

    /** @return array<string, mixed> */
    private function trade(): array
    {
        [$this->owner, $this->shop] = $this->shopWithMember();
        [$this->manager] = $this->shopWithMember(Role::Manager, $this->shop);
        [$this->cashier] = $this->shopWithMember(Role::Cashier, $this->shop);

        $sugar = $this->productWithStock($this->shop, $this->owner, price: 1000, cost: 600, stock: 100, attributes: ['name' => 'Sugar']);
        $rice = $this->productWithStock($this->shop, $this->owner, price: 2500, cost: 1500, stock: 50, attributes: ['name' => 'Rice']);
        $customer = Customer::create(['shop_id' => $this->shop->id, 'name' => 'Mama Grace']);

        $s1 = $this->sell($this->cashier, [['product_id' => $sugar->id, 'quantity' => 3], ['product_id' => $rice->id, 'quantity' => 1]],
            [['method' => 'CASH', 'amount' => 5500]], $this->utc('2026-09-10 10:00'));

        $s2 = $this->sell($this->cashier, [['product_id' => $sugar->id, 'quantity' => 2]],
            [['method' => 'CASH', 'amount' => 1900]], $this->utc('2026-09-11 01:30'), ['discount' => 100]);

        $s3 = $this->sell($this->owner, [['product_id' => $rice->id, 'quantity' => 1]],
            [['method' => 'MOBILE_MONEY', 'amount' => 1000]], $this->utc('2026-09-11 12:00'), ['customer_id' => $customer->id]);

        $s4 = $this->sell($this->cashier, [['product_id' => $sugar->id, 'quantity' => 1]],
            [['method' => 'CASH', 'amount' => 1000]], $this->utc('2026-09-11 14:00'));
        $this->api($this->manager)->postJson("/api/sales/{$s4['id']}/void", ['reason' => 'Rang up twice'])->assertOk();
        Payment::where('sale_id', $s4['id'])->update(['created_at' => $this->utc('2026-09-11 14:05')]);

        $this->refund($s1['id'], [['sale_item_id' => $s1['items'][0]['id'], 'quantity' => 1, 'restock' => true]], 'CASH', $this->utc('2026-09-11 15:00'));
        $this->refund($s3['id'], [['sale_item_id' => $s3['items'][0]['id'], 'quantity' => 1, 'restock' => false]], 'MOBILE_MONEY', $this->utc('2026-09-11 16:00'));

        Expense::create(['shop_id' => $this->shop->id, 'category' => 'Transport', 'amount' => 1200, 'recorded_by' => $this->owner->id, 'expense_date' => '2026-09-10']);
        Expense::create(['shop_id' => $this->shop->id, 'category' => 'Rent', 'amount' => 800, 'recorded_by' => $this->owner->id, 'expense_date' => '2026-09-11']);

        return compact('sugar', 'rice', 's1', 's2', 's3', 's4');
    }

    private const BOTH = '?from=2026-09-10&to=2026-09-11';

    public function test_the_sales_report_matches_the_hand_worked_figures(): void
    {
        $this->trade();

        $report = $this->api($this->owner)->getJson('/api/reports/sales'.self::BOTH)->assertOk();

        $report->assertJsonPath('summary.sales_count', 3)
            ->assertJsonPath('summary.gross_sales', 9900)
            ->assertJsonPath('summary.discounts', 100)
            ->assertJsonPath('summary.refund_count', 2)
            ->assertJsonPath('summary.refunds', 3500)
            ->assertJsonPath('summary.net_sales', 6400)
            ->assertJsonPath('summary.average_sale', 3300)
            ->assertJsonPath('summary.credit_given', 1500)
            ->assertJsonPath('summary.voided_count', 1)
            ->assertJsonPath('summary.voided_value', 1000);

        $daily = collect($report->json('daily'))->keyBy('date');
        $this->assertEquals(['gross_sales' => 5500, 'refunds' => 0, 'net_sales' => 5500, 'sales_count' => 1],
            collect($daily['2026-09-10'])->only(['gross_sales', 'refunds', 'net_sales', 'sales_count'])->all());
        $this->assertEquals(['gross_sales' => 4400, 'refunds' => 3500, 'net_sales' => 900, 'sales_count' => 2],
            collect($daily['2026-09-11'])->only(['gross_sales', 'refunds', 'net_sales', 'sales_count'])->all());
    }

    public function test_a_sale_just_after_local_midnight_counts_on_the_local_day(): void
    {
        $this->trade();

        // S2 was rung up at 01:30 on the 11th in Kampala, i.e. 22:30 UTC on the 10th.
        $tenth = $this->api($this->owner)->getJson('/api/reports/sales?from=2026-09-10&to=2026-09-10')->assertOk();
        $tenth->assertJsonPath('summary.sales_count', 1)->assertJsonPath('summary.gross_sales', 5500)
            // The refunds were given on the 11th, so the 10th's closed figures do not move.
            ->assertJsonPath('summary.refunds', 0)->assertJsonPath('summary.net_sales', 5500);
        $this->api($this->owner)->getJson('/api/reports/profit?from=2026-09-10&to=2026-09-10')->assertJsonPath('summary.cost_of_goods', 3300)->assertJsonPath('summary.gross_profit', 2200);

        $eleventh = $this->api($this->owner)->getJson('/api/reports/sales?from=2026-09-11&to=2026-09-11')->assertOk();
        $eleventh->assertJsonPath('summary.sales_count', 2)->assertJsonPath('summary.gross_sales', 4400)->assertJsonPath('summary.refunds', 3500)->assertJsonPath('summary.net_sales', 900);
    }

    public function test_every_breakdown_adds_up_to_the_headline_numbers(): void
    {
        $this->trade();

        $report = $this->api($this->owner)->getJson('/api/reports/sales'.self::BOTH)->json();

        $this->assertSame($report['summary']['net_sales'], array_sum(array_column($report['daily'], 'net_sales')));
        $this->assertSame($report['summary']['gross_sales'], array_sum(array_column($report['daily'], 'gross_sales')));
        $this->assertSame($report['summary']['net_sales'], array_sum(array_column($report['products'], 'revenue')));
        $this->assertSame($report['summary']['gross_sales'], array_sum(array_column($report['cashiers'], 'total')));
        $this->assertSame($report['summary']['sales_count'], array_sum(array_column($report['cashiers'], 'sales_count')));
    }

    public function test_products_are_shown_net_of_discounts_and_refunds_in_base_units(): void
    {
        $this->trade();

        $products = collect($this->api($this->owner)->getJson('/api/reports/sales'.self::BOTH)->json('products'))->keyBy('name');

        // Sugar: 3 + 2 sold, 1 refunded = 4; 3,000 + 1,900 (after the 100 discount) - 1,000.
        $this->assertEquals(4, $products['Sugar']['quantity']);
        $this->assertSame(3900, $products['Sugar']['revenue']);
        // Rice: 2 sold, 1 refunded; 5,000 - 2,500.
        $this->assertEquals(1, $products['Rice']['quantity']);
        $this->assertSame(2500, $products['Rice']['revenue']);
    }

    public function test_money_received_is_net_of_what_was_paid_back_out(): void
    {
        $this->trade();

        $methods = collect($this->api($this->owner)->getJson('/api/reports/sales'.self::BOTH)->json('payment_methods'))->pluck('amount', 'method');

        // Cash in 5,500 + 1,900 + 1,000; out 1,000 (void) + 1,000 (refund). Mobile money in 1,000, out 1,000.
        $this->assertSame(6400, $methods['CASH']);
        $this->assertSame(0, $methods['MOBILE_MONEY']);
    }

    public function test_supplier_payments_do_not_reduce_the_retail_cash_payment_split(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $saleProduct = $this->productWithStock($shop, $owner, price: 10000, stock: 1);
        $purchaseProduct = $this->productWithStock($shop, $owner, stock: 0);
        $supplier = Supplier::create(['shop_id' => $shop->id, 'name' => 'Kampala Wholesale']);

        $this->api($owner, $shop)->postJson('/api/sales', [
            'items' => [['product_id' => $saleProduct->id, 'quantity' => 1]],
            'payments' => [['method' => 'CASH', 'amount' => 10000]],
        ])->assertCreated();

        $this->api($owner, $shop)->postJson('/api/purchases', [
            'supplier_id' => $supplier->id,
            'items' => [['product_id' => $purchaseProduct->id, 'quantity' => 1, 'unit_cost' => 6000]],
            'amount_paid' => 6000,
            'payment_method' => 'CASH',
        ])->assertCreated();

        $salesMethods = collect($this->api($owner, $shop)->getJson('/api/reports/sales')->assertOk()->json('payment_methods'))
            ->pluck('amount', 'method');
        $dashboardMethods = collect($this->api($owner, $shop)->getJson('/api/reports/dashboard')->assertOk()->json('payment_methods'))
            ->pluck('amount', 'method');

        $this->assertSame(10000, $salesMethods['CASH']);
        $this->assertSame(10000, $dashboardMethods['CASH']);
    }

    public function test_the_sales_report_only_shows_this_shops_data(): void
    {
        $this->trade();
        [$otherOwner, $otherShop] = $this->shopWithMember();
        $product = $this->productWithStock($otherShop, $otherOwner, price: 7000, stock: 10);
        $this->sell2($otherOwner, $otherShop, $product->id, '2026-09-10 11:00');

        $this->api($this->owner)->getJson('/api/reports/sales'.self::BOTH)->assertJsonPath('summary.gross_sales', 9900);
        $this->api($otherOwner, $otherShop)->getJson('/api/reports/sales'.self::BOTH)->assertJsonPath('summary.gross_sales', 7000)->assertJsonPath('summary.sales_count', 1);
    }

    private function sell2(User $by, Shop $shop, int $productId, string $localAt): void
    {
        $sale = $this->api($by, $shop)->postJson('/api/sales', ['items' => [['product_id' => $productId, 'quantity' => 1]], 'payments' => [['method' => 'CASH', 'amount' => 7000]]])->json();
        Sale::whereKey($sale['id'])->update(['created_at' => $this->utc($localAt)]);
    }

    public function test_managers_see_sales_but_never_a_cost_or_profit_figure_and_cashiers_see_nothing(): void
    {
        $this->trade();

        $json = $this->api($this->manager)->getJson('/api/reports/sales'.self::BOTH)->assertOk()->getContent();
        $this->assertStringNotContainsString('cost', $json);
        $this->assertStringNotContainsString('profit', $json);

        $this->api($this->cashier)->getJson('/api/reports/sales'.self::BOTH)->assertForbidden();
    }

    public function test_the_profit_report_matches_the_hand_worked_figures(): void
    {
        $this->trade();

        $this->api($this->owner)->getJson('/api/reports/profit'.self::BOTH)->assertOk()
            ->assertJsonPath('summary.net_sales', 6400)
            ->assertJsonPath('summary.cost_of_goods', 5400)
            ->assertJsonPath('summary.gross_profit', 1000)
            ->assertJsonPath('summary.margin', 15.6)
            ->assertJsonPath('summary.expenses', 2000)
            ->assertJsonPath('summary.operating_profit', -1000)
            ->assertJsonPath('expenses.0.category', 'Transport')
            ->assertJsonPath('expenses.0.amount', 1200);
    }

    public function test_daily_profit_uses_local_days_and_returned_stock_gives_its_cost_back(): void
    {
        $this->trade();

        $daily = collect($this->api($this->owner)->getJson('/api/reports/profit'.self::BOTH)->json('daily'))->keyBy('date');

        $this->assertSame([5500, 3300, 2200, 1200, 1000], array_values(collect($daily['2026-09-10'])->only(['net_sales', 'cost_of_goods', 'gross_profit', 'expenses', 'operating_profit'])->all()));
        // 11th: net 900; cost 1,200 (S2) + 1,500 (S3) - 600 (sugar back on the shelf) = 2,100.
        // The damaged rice is not restocked, so its 1,500 stays a cost.
        $this->assertSame([900, 2100, -1200, 800, -2000], array_values(collect($daily['2026-09-11'])->only(['net_sales', 'cost_of_goods', 'gross_profit', 'expenses', 'operating_profit'])->all()));
    }

    public function test_profit_by_product_adds_up_to_the_headline_profit(): void
    {
        $this->trade();

        $report = $this->api($this->owner)->getJson('/api/reports/profit'.self::BOTH)->json();
        $products = collect($report['products'])->keyBy('name');

        $this->assertSame(3900 - 2400, $products['Sugar']['profit']);
        $this->assertSame(2500 - 3000, $products['Rice']['profit']);
        $this->assertSame($report['summary']['gross_profit'], array_sum(array_column($report['products'], 'profit')));
        $this->assertSame($report['summary']['cost_of_goods'], array_sum(array_column($report['products'], 'cost')));
        $this->assertSame($report['summary']['gross_profit'], array_sum(array_column($report['daily'], 'gross_profit')));
        // Best seller first: Sugar makes 1,500, Rice loses 500.
        $this->assertSame('Sugar', $report['products'][0]['name']);
    }

    public function test_only_the_owner_can_see_profit(): void
    {
        $this->trade();

        $this->api($this->owner)->getJson('/api/reports/profit'.self::BOTH)->assertOk();
        $this->api($this->manager)->getJson('/api/reports/profit'.self::BOTH)->assertForbidden();
        $this->api($this->cashier)->getJson('/api/reports/profit'.self::BOTH)->assertForbidden();
    }

    public function test_cost_is_the_cost_when_it_was_sold_not_todays_cost(): void
    {
        $trade = $this->trade();
        $trade['sugar']->update(['current_cost' => 900]);

        $this->api($this->owner)->getJson('/api/reports/profit'.self::BOTH)->assertJsonPath('summary.cost_of_goods', 5400);
    }

    public function test_awkward_discounts_and_fractions_still_reconcile_to_the_shilling(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $this->shop = $shop;
        $this->owner = $owner;
        $this->manager = $owner;
        $a = $this->productWithStock($shop, $owner, price: 333, cost: 201, stock: 100, attributes: ['name' => 'A']);
        $b = $this->productWithStock($shop, $owner, price: 1001, cost: 555, stock: 100, attributes: ['name' => 'B']);
        $c = $this->productWithStock($shop, $owner, price: 777, cost: 401, stock: 100, attributes: ['name' => 'C']);

        $sale = $this->sell($owner, [
            ['product_id' => $a->id, 'quantity' => 2.5],
            ['product_id' => $b->id, 'quantity' => 3],
            ['product_id' => $c->id, 'quantity' => 0.5, 'discount' => 17],
        ], [['method' => 'CASH', 'amount' => 100]], $this->utc('2026-09-10 09:00'), ['discount' => 101, 'customer_id' => Customer::create(['shop_id' => $shop->id, 'name' => 'X'])->id]);

        $this->refund($sale['id'], [['sale_item_id' => $sale['items'][1]['id'], 'quantity' => 1, 'restock' => true]], 'CASH', $this->utc('2026-09-10 10:00'));

        $sales = $this->api($owner)->getJson('/api/reports/sales?from=2026-09-10&to=2026-09-10')->json();
        $profit = $this->api($owner)->getJson('/api/reports/profit?from=2026-09-10&to=2026-09-10')->json();

        $this->assertSame($sales['summary']['net_sales'], array_sum(array_column($sales['products'], 'revenue')));
        $this->assertSame($profit['summary']['gross_profit'], array_sum(array_column($profit['products'], 'profit')));
        $this->assertSame($profit['summary']['cost_of_goods'], array_sum(array_column($profit['products'], 'cost')));
    }

    public function test_an_empty_period_is_all_zeros_with_every_day_present(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $this->shop = $shop;

        $report = $this->api($owner)->getJson('/api/reports/profit?from=2026-01-01&to=2026-01-03')->assertOk();

        $report->assertJsonCount(3, 'daily')->assertJsonPath('summary.gross_profit', 0)->assertJsonPath('summary.margin', null);
        $this->api($owner)->getJson('/api/reports/sales?from=2026-01-01&to=2026-01-03')->assertJsonPath('summary.average_sale', 0)->assertJsonCount(3, 'daily');
    }

    public function test_the_period_must_make_sense(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $this->shop = $shop;

        $this->api($owner)->getJson('/api/reports/sales?from=2026-09-12&to=2026-09-01')->assertUnprocessable()->assertJsonValidationErrors('from');
        $this->api($owner)->getJson('/api/reports/profit?from=2024-01-01&to=2026-09-01')->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->api($owner)->getJson('/api/reports/sales?from=yesterday')->assertUnprocessable();
    }
}
