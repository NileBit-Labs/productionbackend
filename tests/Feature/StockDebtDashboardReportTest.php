<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class StockDebtDashboardReportTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    private User $owner;

    private User $manager;

    private User $cashier;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->owner, $this->shop] = $this->shopWithMember();
        [$this->manager] = $this->shopWithMember(Role::Manager, $this->shop);
        [$this->cashier] = $this->shopWithMember(Role::Cashier, $this->shop);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function api(User $user, ?Shop $shop = null)
    {
        return $this->actingAs($user, 'sanctum')->withHeaders($this->shopHeader($shop ?? $this->shop));
    }

    private function utc(string $local): string
    {
        return CarbonImmutable::parse($local, 'Africa/Kampala')->utc()->format('Y-m-d H:i:s');
    }

    /** Pretend it is this local (Kampala) moment, for both Carbon flavours. */
    private function itIs(string $local): void
    {
        $now = CarbonImmutable::parse($local, 'Africa/Kampala');
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);
    }

    private function creditSale(User $by, Customer $customer, int $productId, int $quantity, int $paid = 0, ?string $localAt = null, array $extra = []): array
    {
        $sale = $this->api($by)->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'items' => [['product_id' => $productId, 'quantity' => $quantity]],
            'payments' => $paid ? [['method' => 'CASH', 'amount' => $paid]] : [],
        ] + $extra)->assertCreated()->json();

        if ($localAt) {
            Sale::whereKey($sale['id'])->update(['created_at' => $this->utc($localAt)]);
        }

        return $sale;
    }

    private function customer(string $name): Customer
    {
        return Customer::create(['shop_id' => $this->shop->id, 'name' => $name]);
    }

    // ---- Stock -----------------------------------------------------------

    public function test_stock_status_follows_each_products_own_low_stock_level(): void
    {
        $this->productWithStock($this->shop, $this->owner, stock: 0, attributes: ['name' => 'Out']);
        $this->productWithStock($this->shop, $this->owner, stock: 3, attributes: ['name' => 'Low', 'low_stock_threshold' => 5]);
        $this->productWithStock($this->shop, $this->owner, stock: 5, attributes: ['name' => 'Exactly at level', 'low_stock_threshold' => 5]);
        $this->productWithStock($this->shop, $this->owner, stock: 6, attributes: ['name' => 'Fine', 'low_stock_threshold' => 5]);
        $this->productWithStock($this->shop, $this->owner, stock: 1, attributes: ['name' => 'No warning wanted']);
        $this->productWithStock($this->shop, $this->owner, stock: 0, attributes: ['name' => 'Retired', 'status' => 'inactive']);

        $report = $this->api($this->owner)->getJson('/api/reports/stock')->assertOk();

        $report->assertJsonPath('summary.products', 5)->assertJsonPath('summary.out', 1)->assertJsonPath('summary.low', 2)->assertJsonPath('summary.in_stock', 4);
        $status = collect($report->json('page.data'))->pluck('status', 'name');
        $this->assertEquals(['Out' => 'out', 'Low' => 'low', 'Exactly at level' => 'low', 'Fine' => 'ok', 'No warning wanted' => 'ok'], $status->all());
        // Trouble first.
        $this->assertSame(['out', 'low', 'low', 'ok', 'ok'], collect($report->json('page.data'))->pluck('status')->all());
    }

    public function test_stock_on_hand_reflects_sales(): void
    {
        $product = $this->productWithStock($this->shop, $this->owner, stock: 10, attributes: ['name' => 'Soap', 'low_stock_threshold' => 5]);
        $this->api($this->cashier)->postJson('/api/sales', ['items' => [['product_id' => $product->id, 'quantity' => 6]], 'payments' => [['method' => 'CASH', 'amount' => 6000]]])->assertCreated();

        $row = $this->api($this->manager)->getJson('/api/reports/stock')->json('page.data.0');

        $this->assertEquals(4, $row['stock']);
        $this->assertSame('low', $row['status']);
    }

    public function test_only_the_owner_sees_what_the_stock_is_worth(): void
    {
        $this->productWithStock($this->shop, $this->owner, price: 1500, cost: 1000, stock: 4, attributes: ['name' => 'A']);
        $this->productWithStock($this->shop, $this->owner, price: 300, cost: 200, stock: 10, attributes: ['name' => 'B']);

        $this->api($this->owner)->getJson('/api/reports/stock')
            ->assertJsonPath('summary.value_at_cost', 6000)
            ->assertJsonPath('summary.value_at_retail', 9000)
            ->assertJsonPath('page.data.0.value_at_cost', 4000);

        $json = $this->api($this->manager)->getJson('/api/reports/stock')->assertOk()->getContent();
        $this->assertStringNotContainsString('value_at', $json);
        $this->assertStringNotContainsString('cost', $json);

        $this->api($this->cashier)->getJson('/api/reports/stock')->assertForbidden();
    }

    public function test_stock_can_be_filtered_searched_and_paged(): void
    {
        foreach (range(1, 55) as $i) {
            $this->productWithStock($this->shop, $this->owner, stock: 0, attributes: ['name' => sprintf('Item %02d', $i)]);
        }
        $this->productWithStock($this->shop, $this->owner, stock: 20, attributes: ['name' => 'Maize flour', 'sku' => 'MZ-1']);

        $this->api($this->owner)->getJson('/api/reports/stock')->assertJsonCount(50, 'page.data')->assertJsonPath('page.total', 56)->assertJsonPath('page.last_page', 2);
        $this->api($this->owner)->getJson('/api/reports/stock?page=2')->assertJsonCount(6, 'page.data');
        $this->api($this->owner)->getJson('/api/reports/stock?status=ok')->assertJsonCount(1, 'page.data')->assertJsonPath('page.data.0.name', 'Maize flour');
        $this->api($this->owner)->getJson('/api/reports/stock?q=mz-1')->assertJsonCount(1, 'page.data');
        $this->api($this->owner)->getJson('/api/reports/stock?status=nonsense')->assertUnprocessable();
    }

    public function test_the_stock_report_only_shows_this_shops_products(): void
    {
        [$other, $otherShop] = $this->shopWithMember();
        $this->productWithStock($otherShop, $other, stock: 5, attributes: ['name' => 'Not ours']);
        $this->productWithStock($this->shop, $this->owner, stock: 5, attributes: ['name' => 'Ours']);

        $names = collect($this->api($this->owner)->getJson('/api/reports/stock')->json('page.data'))->pluck('name')->all();

        $this->assertSame(['Ours'], $names);
    }

    // ---- Debt ------------------------------------------------------------

    public function test_the_debt_report_matches_each_customers_ledger_balance(): void
    {
        $this->itIs('2026-09-20 09:00');
        $product = $this->productWithStock($this->shop, $this->owner, price: 1000, stock: 100);
        $old = $this->customer('Old debtor');
        $recent = $this->customer('Recent debtor');
        $settled = $this->customer('Settled');

        $this->creditSale($this->owner, $old, $product->id, 10, 0, '2026-07-01 10:00', ['due_date' => '2026-07-31']);   // 10,000, 81 days old, overdue
        $this->api($this->owner)->postJson("/api/customers/{$old->id}/payments", ['amount' => 3000, 'method' => 'CASH'])->assertOk();
        $this->creditSale($this->owner, $recent, $product->id, 5, 0, '2026-09-18 10:00', ['due_date' => '2026-09-30']);  // 5,000, 2 days old, not due
        $this->creditSale($this->owner, $settled, $product->id, 2, 0);
        $this->api($this->owner)->postJson("/api/customers/{$settled->id}/payments", ['amount' => 2000, 'method' => 'CASH'])->assertOk();

        $report = $this->api($this->manager)->getJson('/api/reports/debt')->assertOk();

        $report->assertJsonPath('summary.total_owed', 12000)->assertJsonPath('summary.customers_owing', 2)->assertJsonPath('summary.overdue', 7000);
        $this->assertSame(['Old debtor', 'Recent debtor'], collect($report->json('customers'))->pluck('name')->all());
        $first = $report->json('customers.0');
        $this->assertSame(7000, $first['balance']);
        $this->assertSame(81, $first['oldest_debt_days']);
        $this->assertSame(7000, $first['overdue']);
        $this->assertSame(51, $first['days_overdue']); // due 31 July

        $this->assertSame(['0-30' => 5000, '31-60' => 0, '61-90' => 7000, '90+' => 0], collect($report->json('aging'))->pluck('amount', 'bucket')->all());
    }

    public function test_a_customer_who_is_owed_money_is_not_listed_as_a_debtor(): void
    {
        $product = $this->productWithStock($this->shop, $this->owner, price: 1000, stock: 100);
        $customer = $this->customer('Paid then returned');
        $sale = $this->creditSale($this->owner, $customer, $product->id, 5);
        $this->api($this->owner)->postJson("/api/customers/{$customer->id}/payments", ['amount' => 5000, 'method' => 'CASH'])->assertOk();
        // Voiding the sale after they paid leaves them in credit (-5,000), not owing.
        $this->api($this->manager)->postJson("/api/sales/{$sale['id']}/void", ['reason' => 'Wrong order'])->assertOk();

        $this->api($this->owner)->getJson('/api/reports/debt')
            ->assertJsonPath('summary.total_owed', 0)->assertJsonPath('summary.customers_owing', 0)->assertJsonCount(0, 'customers');
    }

    public function test_the_debt_ages_add_up_to_the_total_owed(): void
    {
        $this->itIs('2026-09-20 09:00');
        $product = $this->productWithStock($this->shop, $this->owner, price: 1000, stock: 500);

        foreach ([[5, '2026-09-19'], [7, '2026-08-01'], [11, '2026-06-15'], [3, '2026-03-01']] as $i => [$qty, $date]) {
            $this->creditSale($this->owner, $this->customer("Customer $i"), $product->id, $qty, 0, "$date 10:00");
        }

        $report = $this->api($this->owner)->getJson('/api/reports/debt')->json();

        $this->assertSame(26000, $report['summary']['total_owed']);
        $this->assertSame($report['summary']['total_owed'], array_sum(array_column($report['aging'], 'amount')));
        $this->assertSame(26000, array_sum(array_column($report['customers'], 'balance')));
        // 1, 50, 97 and 202 days old.
        $this->assertSame(['0-30' => 5000, '31-60' => 7000, '61-90' => 0, '90+' => 14000], collect($report['aging'])->pluck('amount', 'bucket')->all());
    }

    public function test_debt_cancelled_by_a_refund_is_not_counted_as_still_owed(): void
    {
        $this->itIs('2026-09-20 09:00');
        $product = $this->productWithStock($this->shop, $this->owner, price: 1000, stock: 100);
        $customer = $this->customer('Returns things');
        $old = $this->creditSale($this->owner, $customer, $product->id, 10, 0, '2026-08-01 10:00');
        $newer = $this->creditSale($this->owner, $customer, $product->id, 4, 0, '2026-09-15 10:00');

        // Return 4 of the newer sale: 4,000 of their debt is cancelled.
        $this->api($this->manager)->postJson("/api/sales/{$newer['id']}/refund", [
            'lines' => [['sale_item_id' => $newer['items'][0]['id'], 'quantity' => 4]], 'method' => 'CASH', 'reason' => 'Returned',
        ])->assertCreated()->assertJsonPath('balance_credit', 4000);

        $report = $this->api($this->owner)->getJson('/api/reports/debt')->json();
        $this->assertSame(10000, $report['summary']['total_owed']);
        $this->assertSame(10000, array_sum(array_column($report['aging'], 'amount')));

        // The customer screen's list of unpaid sales agrees: only the older one is still open.
        $open = $this->api($this->owner)->getJson("/api/customers/{$customer->id}")->json('open_sales');
        $this->assertSame([$old['sale_number']], array_column($open, 'sale_number'));
        $this->assertSame(10000, $open[0]['owed']);
    }

    public function test_debt_is_visible_to_owners_and_managers_only_and_only_for_this_shop(): void
    {
        $product = $this->productWithStock($this->shop, $this->owner, price: 1000, stock: 100);
        $this->creditSale($this->owner, $this->customer('Ours'), $product->id, 1);
        [$other, $otherShop] = $this->shopWithMember();

        $this->api($this->owner)->getJson('/api/reports/debt')->assertOk()->assertJsonPath('summary.total_owed', 1000);
        $this->api($this->manager)->getJson('/api/reports/debt')->assertOk();
        $this->api($this->cashier)->getJson('/api/reports/debt')->assertForbidden();
        $this->api($other, $otherShop)->getJson('/api/reports/debt')->assertOk()->assertJsonPath('summary.total_owed', 0);
    }

    // ---- Dashboard ---------------------------------------------------------

    public function test_the_owners_dashboard_has_todays_figures_and_profit(): void
    {
        $this->itIs('2026-09-20 15:00');
        $product = $this->productWithStock($this->shop, $this->owner, price: 1000, cost: 600, stock: 100, attributes: ['name' => 'Sugar']);
        $this->api($this->cashier)->postJson('/api/sales', ['items' => [['product_id' => $product->id, 'quantity' => 4]], 'payments' => [['method' => 'CASH', 'amount' => 4000]]])->assertCreated();
        $this->api($this->owner)->postJson('/api/sales', ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'payments' => [['method' => 'MOBILE_MONEY', 'amount' => 1000]]])->assertCreated();

        $yesterday = $this->api($this->cashier)->postJson('/api/sales', ['items' => [['product_id' => $product->id, 'quantity' => 2]], 'payments' => [['method' => 'CASH', 'amount' => 2000]]])->json('id');
        Sale::whereKey($yesterday)->update(['created_at' => $this->utc('2026-09-19 11:00')]);

        $dash = $this->api($this->owner)->getJson('/api/reports/dashboard')->assertOk();

        $dash->assertJsonPath('date', '2026-09-20')
            ->assertJsonPath('today.net_sales', 5000)
            ->assertJsonPath('today.sales_count', 2)
            ->assertJsonPath('today.average_sale', 2500)
            ->assertJsonPath('today.gross_profit', 2000)
            ->assertJsonPath('yesterday_net_sales', 2000)
            ->assertJsonPath('week.net_sales', 7000)
            ->assertJsonPath('week.previous_net_sales', 0)
            ->assertJsonCount(7, 'series')
            ->assertJsonPath('series.6.date', '2026-09-20')
            ->assertJsonPath('series.6.net_sales', 5000)
            ->assertJsonPath('series.5.net_sales', 2000)
            ->assertJsonPath('top_products.0.name', 'Sugar')
            ->assertJsonPath('top_products.0.revenue', 7000)
            ->assertJsonCount(3, 'recent_sales')
            ->assertJsonPath('stock.out', 0);
    }

    public function test_the_dashboard_follows_the_local_day_not_the_utc_day(): void
    {
        // 22:30 UTC on the 19th is 01:30 on the 20th in Kampala.
        $this->itIs('2026-09-20 01:30');
        $product = $this->productWithStock($this->shop, $this->owner, price: 1000, stock: 10);
        $this->api($this->cashier)->postJson('/api/sales', ['items' => [['product_id' => $product->id, 'quantity' => 2]], 'payments' => [['method' => 'CASH', 'amount' => 2000]]])->assertCreated();

        $this->api($this->owner)->getJson('/api/reports/dashboard')
            ->assertJsonPath('date', '2026-09-20')
            ->assertJsonPath('today.net_sales', 2000)
            ->assertJsonPath('yesterday_net_sales', 0);
    }

    public function test_managers_get_the_dashboard_without_profit(): void
    {
        $this->api($this->manager)->getJson('/api/reports/dashboard')->assertOk()
            ->assertJsonPath('role', 'manager')
            ->assertJsonMissingPath('today.gross_profit')
            ->assertJsonStructure(['today' => ['net_sales'], 'stock', 'debt', 'series']);
    }

    public function test_a_cashier_sees_only_what_they_rang_up_themselves(): void
    {
        $product = $this->productWithStock($this->shop, $this->owner, price: 1000, stock: 100);
        $mine = $this->api($this->cashier)->postJson('/api/sales', ['items' => [['product_id' => $product->id, 'quantity' => 3]], 'payments' => [['method' => 'CASH', 'amount' => 3000]]])->json();
        $this->api($this->owner)->postJson('/api/sales', ['items' => [['product_id' => $product->id, 'quantity' => 9]], 'payments' => [['method' => 'CASH', 'amount' => 9000]]])->assertCreated();

        $dash = $this->api($this->cashier)->getJson('/api/reports/dashboard')->assertOk();

        $dash->assertJsonPath('role', 'cashier')->assertJsonPath('today.total', 3000)->assertJsonPath('today.sales_count', 1)
            ->assertJsonCount(1, 'recent_sales')->assertJsonPath('recent_sales.0.sale_number', $mine['sale_number']);

        $json = $dash->getContent();
        foreach (['profit', 'debt', 'stock', 'series', 'top_products', 'payment_methods', 'refunds'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $json);
        }
    }

    public function test_the_dashboard_shows_stock_and_debt_warnings(): void
    {
        $this->productWithStock($this->shop, $this->owner, price: 1000, stock: 0, attributes: ['name' => 'Gone']);
        $this->productWithStock($this->shop, $this->owner, stock: 2, attributes: ['name' => 'Apple juice', 'low_stock_threshold' => 5]);
        $this->productWithStock($this->shop, $this->owner, stock: 1, attributes: ['name' => 'Zebra biscuits', 'low_stock_threshold' => 5]);
        $this->productWithStock($this->shop, $this->owner, stock: 50, attributes: ['name' => 'Plenty', 'low_stock_threshold' => 5]);
        $product = $this->productWithStock($this->shop, $this->owner, price: 1000, stock: 50);
        $this->creditSale($this->owner, $this->customer('Owes'), $product->id, 4, 1000, null, ['due_date' => '2020-01-01']);

        $this->api($this->owner)->getJson('/api/reports/dashboard')
            ->assertJsonPath('stock.out', 1)->assertJsonPath('stock.low', 2)
            // What to reorder, worst first: the one that is gone, then the lowest stock
            // (not alphabetical: Zebra has 1 left, Apple has 2).
            ->assertJsonPath('stock.attention.0.name', 'Gone')->assertJsonPath('stock.attention.1.name', 'Zebra biscuits')->assertJsonPath('stock.attention.2.name', 'Apple juice')
            ->assertJsonCount(3, 'stock.attention')
            ->assertJsonPath('debt.total_owed', 3000)->assertJsonPath('debt.customers_owing', 1)->assertJsonPath('debt.overdue', 3000)
            ->assertJsonPath('debt.top.0.name', 'Owes')->assertJsonPath('debt.top.0.balance', 3000)->assertJsonPath('debt.top.0.overdue', 3000);
    }

    public function test_the_dashboard_is_scoped_to_the_shop(): void
    {
        [$other, $otherShop] = $this->shopWithMember();
        $product = $this->productWithStock($otherShop, $other, price: 5000, stock: 10);
        $this->api($other, $otherShop)->postJson('/api/sales', ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'payments' => [['method' => 'CASH', 'amount' => 5000]]])->assertCreated();

        $this->api($this->owner)->getJson('/api/reports/dashboard')->assertJsonPath('today.net_sales', 0)->assertJsonCount(0, 'recent_sales');
    }
}
