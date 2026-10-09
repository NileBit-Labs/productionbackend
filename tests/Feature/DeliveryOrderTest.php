<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\StockMovement;
use App\Services\CustomerLedger;
use App\Services\ShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class DeliveryOrderTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    public function test_fully_returned_order_cannot_be_dispatched_again(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $this->actingAs($owner, 'sanctum')->withHeaders($this->shopHeader($shop));
        $product = $this->productWithStock($shop, $owner, 1000, 500);
        $sale = $this->postJson('/api/sales', ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'payments' => [['method' => 'CASH', 'amount' => 1000]], 'fulfillment' => ['type' => 'delivery', 'recipient_name' => 'QA recipient', 'recipient_phone' => 'QA contact', 'address' => 'QA address']])->assertCreated()->json();
        $this->postJson('/api/sales/'.$sale['id'].'/refund', ['lines' => [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 1, 'restock' => true]], 'method' => 'CASH', 'reason' => 'QA cancelled goods'])->assertCreated();
        $url = '/api/delivery/orders/'.$sale['delivery_order']['id'].'/status';
        $this->postJson($url, ['status' => 'preparing'])->assertUnprocessable();
        $this->postJson($url, ['status' => 'cancelled', 'failure_reason' => 'QA refunded'])->assertOk();
    }

    public function test_next_day_delivery_collection_belongs_to_the_collection_shift(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $this->actingAs($owner, 'sanctum')->withHeaders($this->shopHeader($shop));
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'QA pay tomorrow']);
        $product = $this->productWithStock($shop, $owner, 7000, 3000);
        $this->travel(-1)->days();
        $sale = $this->postJson('/api/sales', ['customer_id' => $customer->id, 'items' => [['product_id' => $product->id, 'quantity' => 1]], 'payments' => [], 'fulfillment' => ['type' => 'delivery', 'recipient_name' => 'QA recipient', 'recipient_phone' => 'QA contact', 'address' => 'QA address']])->assertCreated()->json();
        $this->travelBack();
        $shift = app(ShiftService::class)->open($shop, $owner, 1000);
        $this->postJson('/api/delivery/orders/'.$sale['delivery_order']['id'].'/payments', ['idempotency_key' => 'tomorrow-cash', 'method' => 'CASH', 'amount' => 7000])->assertOk();
        $summary = app(ShiftService::class)->summary($shift);
        $this->assertSame(7000, $summary['cash_repayments']);
        $this->assertSame(0, $summary['cash_sales']);
        $this->assertSame(8000, $summary['expected_cash']);
    }

    public function test_delivery_lifecycle_payment_retries_and_returns_keep_financial_integrity(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $this->actingAs($owner, 'sanctum')->withHeaders($this->shopHeader($shop));
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'QA office']);
        $product = $this->productWithStock($shop, $owner, 7000, 3000);
        $body = ['idempotency_key' => 'delivery-sale', 'customer_id' => $customer->id, 'discount' => 1000, 'items' => [['product_id' => $product->id, 'quantity' => 2]], 'payments' => [['method' => 'MOBILE_MONEY', 'amount' => 5000]], 'fulfillment' => ['type' => 'delivery', 'recipient_name' => 'QA recipient', 'recipient_phone' => 'QA contact', 'address' => 'QA office location', 'delivery_fee' => 2000]];
        $sale = $this->postJson('/api/sales', $body)->assertCreated()->assertJsonPath('total', 15000)->assertJsonPath('amount_due', 10000)->json();
        $id = $sale['delivery_order']['id'];
        $this->postJson('/api/sales', $body)->assertOk();
        $this->assertDatabaseCount('delivery_orders', 1);
        $count = StockMovement::count();
        $url = "/api/delivery/orders/$id/status";
        $this->postJson($url, ['status' => 'delivered', 'proof_of_delivery' => 'QA confirmed'])->assertUnprocessable();
        $this->postJson($url, ['status' => 'preparing'])->assertOk();
        $this->postJson($url, ['status' => 'ready'])->assertOk();
        $this->postJson($url, ['status' => 'out_for_delivery'])->assertUnprocessable();
        $this->postJson($url, ['status' => 'out_for_delivery', 'driver_name' => 'QA rider', 'driver_phone' => 'QA contact'])->assertOk()->assertJsonPath('status', 'out_for_delivery');
        $payment = ['idempotency_key' => 'delivery-payment', 'method' => 'CASH', 'amount' => 10000];
        $this->postJson("/api/delivery/orders/$id/payments", $payment)->assertOk()->assertJsonPath('outstanding', 0);
        $payments = Payment::count();
        $this->postJson("/api/delivery/orders/$id/payments", $payment)->assertOk();
        $this->assertSame($payments, Payment::count());
        $this->assertSame(0, app(CustomerLedger::class)->balance($customer));
        $this->postJson($url, ['status' => 'delivered'])->assertUnprocessable();
        $this->postJson($url, ['status' => 'delivered', 'proof_of_delivery' => 'QA recipient signed note'])->assertOk()->assertJsonPath('status', 'delivered');
        $this->assertSame($count, StockMovement::count());
        $this->getJson('/api/reports/sales')->assertOk()->assertJsonPath('summary.delivery_fees', 2000)->assertJsonPath('summary.product_net_sales', 13000);
        $this->postJson('/api/sales/'.$sale['id'].'/refund', ['lines' => [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 1, 'restock' => true]], 'method' => 'CASH', 'reason' => 'QA return', 'idempotency_key' => 'return-once'])->assertCreated()->assertJsonPath('total_refund', 6500)->assertJsonPath('balance_credit', 0);
        $this->postJson('/api/sales/'.$sale['id'].'/refund', ['lines' => [], 'delivery_fee_refund' => 2000, 'method' => 'CASH', 'reason' => 'QA fee return', 'idempotency_key' => 'fee-once'])->assertCreated()->assertJsonPath('total_refund', 2000);
        $this->postJson('/api/sales/'.$sale['id'].'/refund', ['lines' => [], 'delivery_fee_refund' => 1, 'method' => 'CASH', 'reason' => 'QA excess'])->assertUnprocessable();
        $this->getJson('/api/reports/sales')->assertOk()->assertJsonPath('summary.delivery_fees', 0)->assertJsonPath('summary.net_sales', 6500);
    }

    public function test_failed_delivery_and_cancellation_do_not_refund_or_restock_and_roles_are_enforced(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $this->actingAs($owner, 'sanctum')->withHeaders($this->shopHeader($shop));
        $product = $this->productWithStock($shop, $owner);
        $sale = $this->postJson('/api/sales', ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'payments' => [['method' => 'CARD', 'amount' => 1000]], 'fulfillment' => ['type' => 'pickup', 'recipient_name' => 'QA recipient', 'recipient_phone' => 'QA contact']])->assertCreated()->json();
        $id = $sale['delivery_order']['id'];
        $count = StockMovement::count();
        $this->postJson("/api/delivery/orders/$id/status", ['status' => 'cancelled', 'failure_reason' => 'QA customer cancelled'])->assertOk();
        $this->assertSame($count, StockMovement::count());
        $this->assertDatabaseCount('refunds', 0);
        $this->assertDatabaseCount('payments', 1);
        $this->postJson("/api/delivery/orders/$id/status", ['status' => 'preparing'])->assertUnprocessable();
        [$cashier] = $this->shopWithMember(Role::Cashier, $shop);
        $this->actingAs($cashier, 'sanctum')->getJson('/api/delivery/orders')->assertForbidden();
        [$other, $otherShop] = $this->shopWithMember();
        $this->actingAs($other, 'sanctum')->withHeaders($this->shopHeader($otherShop))->getJson("/api/delivery/orders/$id")->assertNotFound();
    }

    public function test_general_customer_repayment_is_respected_by_delivery_collection(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $this->actingAs($owner, 'sanctum')->withHeaders($this->shopHeader($shop));
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'QA account']);
        $product = $this->productWithStock($shop, $owner);
        $sale = $this->postJson('/api/sales', ['customer_id' => $customer->id, 'items' => [['product_id' => $product->id, 'quantity' => 1]], 'fulfillment' => ['type' => 'pickup', 'recipient_name' => 'QA recipient', 'recipient_phone' => 'QA phone']])->assertCreated()->json();
        $this->postJson('/api/customers/'.$customer->id.'/payments', ['amount' => 1000, 'method' => 'CASH'])->assertOk();
        $this->postJson('/api/delivery/orders/'.$sale['delivery_order']['id'].'/payments', ['idempotency_key' => 'overpayment', 'method' => 'CASH', 'amount' => 1000])->assertUnprocessable();
    }
}
