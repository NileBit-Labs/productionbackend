<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\Payment;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class CustomerCreditTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    private function api($user, $shop)
    {
        return $this->actingAs($user, 'sanctum')->withHeaders($this->shopHeader($shop));
    }

    private function sellOnCredit($user, $shop, int $productId, int $qty, int $paid, ?int $customerId, array $extra = [])
    {
        return $this->api($user, $shop)->postJson('/api/sales', [
            'customer_id' => $customerId,
            'items' => [['product_id' => $productId, 'quantity' => $qty]],
            'payments' => $paid > 0 ? [['method' => 'CASH', 'amount' => $paid]] : [],
        ] + $extra);
    }

    private function customer($shop, string $name = 'Mama Rose', ?string $phone = null): Customer
    {
        return Customer::create(['shop_id' => $shop->id, 'name' => $name, 'phone' => $phone]);
    }

    private function balance($user, $shop, Customer $customer): int
    {
        return $this->api($user, $shop)->getJson("/api/customers/{$customer->id}")->json('balance');
    }

    public function test_customers_can_be_created_listed_and_searched(): void
    {
        [$owner, $shop] = $this->shopWithMember();

        $this->api($owner, $shop)->postJson('/api/customers', ['name' => 'Mama Rose', 'phone' => '0772000111'])->assertCreated();
        $this->api($owner, $shop)->postJson('/api/customers', ['name' => 'John Okello', 'phone' => '0701555222'])->assertCreated();

        $this->api($owner, $shop)->getJson('/api/customers')->assertJsonCount(2);
        $this->api($owner, $shop)->getJson('/api/customers?search=rose')->assertJsonCount(1)->assertJsonPath('0.name', 'Mama Rose');
        $this->api($owner, $shop)->getJson('/api/customers?search=0701')->assertJsonCount(1)->assertJsonPath('0.name', 'John Okello');
    }

    public function test_a_phone_number_is_unique_within_a_shop_but_not_across_shops(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        [$other, $otherShop] = $this->shopWithMember();

        $this->api($owner, $shop)->postJson('/api/customers', ['name' => 'A', 'phone' => '0772000111'])->assertCreated();
        $this->api($owner, $shop)->postJson('/api/customers', ['name' => 'B', 'phone' => '0772000111'])->assertUnprocessable();
        $this->api($other, $otherShop)->postJson('/api/customers', ['name' => 'C', 'phone' => '0772000111'])->assertCreated();
    }

    public function test_a_cashier_gets_only_what_a_sale_needs_and_cannot_see_history_or_edit(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        [$cashier] = $this->shopWithMember(Role::Cashier, $shop);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Mama Rose', 'notes' => 'Pays on Fridays']);

        $this->api($cashier, $shop)->postJson('/api/customers', ['name' => 'Walk-in', 'notes' => 'sneaky'])->assertCreated()->assertJsonMissingPath('notes');

        $this->api($cashier, $shop)->getJson("/api/customers/{$customer->id}")
            ->assertOk()->assertJsonMissingPath('notes')->assertJsonMissingPath('open_sales');
        $this->api($owner, $shop)->getJson("/api/customers/{$customer->id}")->assertJsonPath('notes', 'Pays on Fridays');

        $this->api($cashier, $shop)->getJson("/api/customers/{$customer->id}/ledger")->assertForbidden();
        $this->api($cashier, $shop)->patchJson("/api/customers/{$customer->id}", ['name' => 'Renamed'])->assertForbidden();
        $this->assertNull(Customer::where('name', 'Walk-in')->first()->notes);
    }

    public function test_a_partly_paid_sale_puts_the_rest_on_the_customers_account(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, price: 10000);
        $customer = $this->customer($shop);

        $this->sellOnCredit($owner, $shop, $product->id, 3, 12000, $customer->id, ['due_date' => '2026-12-01'])
            ->assertCreated()
            ->assertJsonPath('total', 30000)
            ->assertJsonPath('amount_paid', 12000)
            ->assertJsonPath('amount_due', 18000)
            ->assertJsonPath('customer.name', 'Mama Rose');

        $this->assertSame(18000, $this->balance($owner, $shop, $customer));
        $this->assertDatabaseHas('customer_ledger_entries', ['customer_id' => $customer->id, 'type' => 'CREDIT_SALE', 'amount' => 18000]);
    }

    public function test_a_whole_sale_can_go_on_credit_with_no_payment_at_all(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, price: 5000);
        $customer = $this->customer($shop);

        $this->sellOnCredit($owner, $shop, $product->id, 2, 0, $customer->id)
            ->assertCreated()->assertJsonPath('amount_paid', 0)->assertJsonPath('amount_due', 10000);

        $this->assertSame(10000, $this->balance($owner, $shop, $customer));
        $this->assertSame(0, Payment::count());
    }

    public function test_credit_without_a_customer_is_rejected_and_writes_nothing(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, price: 5000);

        $this->sellOnCredit($owner, $shop, $product->id, 1, 1000, null)
            ->assertUnprocessable()->assertJsonValidationErrors('customer_id');

        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('customer_ledger_entries', 0);
    }

    public function test_a_customer_from_another_shop_cannot_be_used(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        [$other, $otherShop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, price: 1000);
        $foreign = $this->customer($otherShop, 'Not ours');

        // Even a fully paid sale must not be tied to another shop's customer.
        $this->sellOnCredit($owner, $shop, $product->id, 1, 1000, $foreign->id)
            ->assertUnprocessable()->assertJsonValidationErrors('customer_id');
    }

    public function test_a_repayment_reduces_the_balance_and_is_recorded_as_money_in(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, price: 10000);
        $customer = $this->customer($shop);
        $this->sellOnCredit($owner, $shop, $product->id, 3, 0, $customer->id)->assertCreated();

        $this->api($owner, $shop)->postJson("/api/customers/{$customer->id}/payments", ['amount' => 12000, 'method' => 'MOBILE_MONEY', 'reference' => 'MM99'])
            ->assertOk()->assertJsonPath('balance', 18000);

        $payment = Payment::firstOrFail();
        $this->assertSame($customer->id, $payment->customer_id);
        $this->assertSame('in', $payment->direction);
        $this->assertSame(12000, $payment->amount);
        $this->assertDatabaseHas('customer_ledger_entries', ['type' => 'PAYMENT', 'amount' => -12000]);
    }

    public function test_a_cashier_can_take_a_repayment(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        [$cashier] = $this->shopWithMember(Role::Cashier, $shop);
        $product = $this->productWithStock($shop, $owner, price: 10000);
        $customer = $this->customer($shop);
        $this->sellOnCredit($owner, $shop, $product->id, 1, 0, $customer->id)->assertCreated();

        $this->api($cashier, $shop)->postJson("/api/customers/{$customer->id}/payments", ['amount' => 4000, 'method' => 'CASH'])
            ->assertOk()->assertJsonPath('balance', 6000);
    }

    public function test_a_customer_cannot_repay_more_than_they_owe(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, price: 10000);
        $customer = $this->customer($shop);
        $this->sellOnCredit($owner, $shop, $product->id, 1, 0, $customer->id)->assertCreated();

        $this->api($owner, $shop)->postJson("/api/customers/{$customer->id}/payments", ['amount' => 10001, 'method' => 'CASH'])
            ->assertUnprocessable()->assertJsonValidationErrors('amount');

        $this->api($owner, $shop)->postJson("/api/customers/{$customer->id}/payments", ['amount' => 10000, 'method' => 'CASH'])->assertOk();
        $this->api($owner, $shop)->postJson("/api/customers/{$customer->id}/payments", ['amount' => 1, 'method' => 'CASH'])
            ->assertUnprocessable();

        $this->assertSame(0, $this->balance($owner, $shop, $customer));
    }

    public function test_voiding_a_credit_sale_removes_the_debt(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, price: 10000);
        $customer = $this->customer($shop);
        $saleId = $this->sellOnCredit($owner, $shop, $product->id, 2, 5000, $customer->id)->json('id');

        $this->assertSame(15000, $this->balance($owner, $shop, $customer));

        $this->api($owner, $shop)->postJson("/api/sales/{$saleId}/void", ['reason' => 'Customer returned everything'])->assertOk();

        $this->assertSame(0, $this->balance($owner, $shop, $customer));
        $this->assertDatabaseHas('customer_ledger_entries', ['type' => 'SALE_VOID', 'amount' => -15000]);
    }

    public function test_the_balance_always_equals_credit_given_minus_repayments_minus_voids(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, price: 1000, stock: 500);
        $customer = $this->customer($shop);

        $this->sellOnCredit($owner, $shop, $product->id, 10, 2000, $customer->id)->assertCreated(); // +8000
        $this->sellOnCredit($owner, $shop, $product->id, 5, 0, $customer->id)->assertCreated();      // +5000
        $voidId = $this->sellOnCredit($owner, $shop, $product->id, 4, 1000, $customer->id)->json('id'); // +3000
        $this->api($owner, $shop)->postJson("/api/customers/{$customer->id}/payments", ['amount' => 6000, 'method' => 'CASH'])->assertOk();
        $this->api($owner, $shop)->postJson("/api/sales/{$voidId}/void", ['reason' => 'Wrong customer'])->assertOk(); // -3000
        $this->api($owner, $shop)->postJson("/api/customers/{$customer->id}/payments", ['amount' => 1000, 'method' => 'BANK'])->assertOk();

        $fromTransactions = Sale::where('customer_id', $customer->id)->where('status', 'completed')->sum('amount_due')
            - Payment::where('customer_id', $customer->id)->whereNull('sale_id')->where('direction', 'in')->sum('amount');

        $this->assertSame(13000 - 7000, $fromTransactions);
        $this->assertSame($fromTransactions, $this->balance($owner, $shop, $customer));
        $this->assertSame($fromTransactions, (int) CustomerLedgerEntry::where('customer_id', $customer->id)->sum('amount'));
    }

    public function test_overdue_is_worked_out_by_applying_repayments_to_the_oldest_debt_first(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, price: 10000, stock: 100);
        $customer = $this->customer($shop);

        $this->sellOnCredit($owner, $shop, $product->id, 1, 0, $customer->id, ['due_date' => now()->subDays(3)->toDateString()])->assertCreated();
        $this->sellOnCredit($owner, $shop, $product->id, 1, 0, $customer->id, ['due_date' => now()->addDays(30)->toDateString()])->assertCreated();

        $this->api($owner, $shop)->getJson("/api/customers/{$customer->id}")
            ->assertJsonPath('balance', 20000)->assertJsonPath('overdue', true)->assertJsonCount(2, 'open_sales');

        // Paying off the first (overdue) sale leaves only the not-yet-due one.
        $this->api($owner, $shop)->postJson("/api/customers/{$customer->id}/payments", ['amount' => 10000, 'method' => 'CASH'])
            ->assertJsonPath('balance', 10000)
            ->assertJsonPath('overdue', false)
            ->assertJsonPath('oldest_due_date', now()->addDays(30)->toDateString())
            ->assertJsonCount(1, 'open_sales');
    }

    public function test_the_debtors_list_shows_only_people_who_owe_biggest_first(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, price: 1000, stock: 100);
        $small = $this->customer($shop, 'Small Debt');
        $big = $this->customer($shop, 'Big Debt');
        $this->customer($shop, 'Owes Nothing');

        $this->sellOnCredit($owner, $shop, $product->id, 2, 0, $small->id)->assertCreated();
        $this->sellOnCredit($owner, $shop, $product->id, 9, 0, $big->id)->assertCreated();

        $this->api($owner, $shop)->getJson('/api/customers?owing=1')
            ->assertJsonCount(2)->assertJsonPath('0.name', 'Big Debt')->assertJsonPath('0.balance', 9000)->assertJsonPath('1.name', 'Small Debt');
    }

    public function test_an_offline_credit_sale_syncs_onto_the_customers_account(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, price: 10000);
        $customer = $this->customer($shop);

        $this->api($owner, $shop)->postJson('/api/sync/push', [
            'device_id' => 'device-A',
            'events' => [[
                'local_event_id' => 'credit-1', 'entity_type' => 'sale', 'operation' => 'create',
                'payload' => [
                    'idempotency_key' => 'credit-1', 'customer_id' => $customer->id, 'due_date' => '2026-12-01',
                    'items' => [['product_id' => $product->id, 'quantity' => 2]],
                    'payments' => [['method' => 'CASH', 'amount' => 5000]],
                ],
            ]],
        ])->assertJsonPath('results.0.status', 'processed');

        $this->assertSame(15000, $this->balance($owner, $shop, $customer));
    }

    public function test_an_outsider_cannot_see_or_use_customers(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        [$outsider] = $this->shopWithMember();
        $customer = $this->customer($shop);

        $this->api($outsider, $shop)->getJson('/api/customers')->assertForbidden();
        $this->api($outsider, $shop)->postJson("/api/customers/{$customer->id}/payments", ['amount' => 1, 'method' => 'CASH'])->assertForbidden();
    }
}
