<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Customer;
use App\Models\Supplier;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class OperationalCoverageTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    public function test_discounts_are_authoritative_for_cash_mobile_card_split_partial_and_credit(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $this->actingAs($owner, 'sanctum')->withHeaders($this->shopHeader($shop));
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'QA credit']);
        foreach ([['CASH' => 13000], ['MOBILE_MONEY' => 13000], ['CARD' => 13000], ['CASH' => 5000, 'MOBILE_MONEY' => 8000], ['MOBILE_MONEY' => 5000], []] as $methods) {
            $product = $this->productWithStock($shop, $owner, 7000, 3000);
            $body = ['customer_id' => $customer->id, 'discount' => 1000, 'items' => [['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 1]], 'payments' => array_map(fn ($method, $amount) => ['method' => $method, 'amount' => $amount], array_keys($methods), array_values($methods))];
            $sale = $this->postJson('/api/sales', $body)->assertCreated()->assertJsonPath('subtotal', 14000)->assertJsonPath('discount', 1000)->assertJsonPath('total', 13000)->assertJsonPath('amount_due', 13000 - array_sum($methods))->json();
            $this->postJson('/api/sales/'.$sale['id'].'/refund', ['lines' => [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 1, 'restock' => true]], 'method' => 'CASH', 'reason' => 'QA discounted return'])->assertCreated()->assertJsonPath('total_refund', 6500);
        }
        $this->getJson('/api/reports/sales')->assertOk()->assertJsonPath('summary.net_sales', 39000)->assertJsonPath('summary.discounts', 6000);
    }

    public function test_supplier_return_reverses_stock_and_debt_once_and_paid_purchase_needs_confirmed_refund(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $this->actingAs($owner, 'sanctum')->withHeaders($this->shopHeader($shop));
        $supplier = Supplier::create(['shop_id' => $shop->id, 'name' => 'QA supplier']);
        $product = $this->productWithStock($shop, $owner, 0, 0, 0, ['kind' => 'raw_material', 'base_unit' => 'kg']);
        $purchase = $this->postJson('/api/purchases', ['supplier_id' => $supplier->id, 'items' => [['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 1000]]])->assertCreated()->json();
        $body = ['idempotency_key' => 'supplier-return', 'reason' => 'QA fruit rejected', 'lines' => [['purchase_item_id' => $purchase['items'][0]['id'], 'quantity' => 2]]];
        $url = '/api/purchases/'.$purchase['id'].'/returns';
        $this->postJson($url, $body)->assertCreated()->assertJsonPath('total', 2000)->assertJsonPath('balance_credit', 2000)->assertJsonPath('cash_refund', 0);
        $this->postJson($url, $body)->assertOk();
        $this->assertSame(8.0, app(StockService::class)->current($shop->id, $product->id));
        $this->getJson('/api/suppliers/'.$supplier->id)->assertOk()->assertJsonPath('balance', 8000);
        $this->postJson('/api/purchases/'.$purchase['id'].'/cancel', ['reason' => 'QA duplicate'])->assertUnprocessable();
        $paid = $this->postJson('/api/purchases', ['supplier_id' => $supplier->id, 'amount_paid' => 1000, 'payment_method' => 'BANK', 'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 1000]]])->assertCreated()->json();
        $paidBody = ['idempotency_key' => 'paid-return', 'reason' => 'QA paid return', 'lines' => [['purchase_item_id' => $paid['items'][0]['id'], 'quantity' => 1]]];
        $this->postJson('/api/purchases/'.$paid['id'].'/returns', $paidBody)->assertUnprocessable();
        $this->postJson('/api/purchases/'.$paid['id'].'/returns', $paidBody + ['refund_received' => true, 'method' => 'BANK'])->assertCreated()->assertJsonPath('cash_refund', 1000);
        $this->getJson('/api/purchases/'.$paid['id'])->assertOk()->assertJsonPath('payments.0.direction', 'out')->assertJsonPath('payments.1.direction', 'in');
        $this->postJson('/api/purchases/'.$paid['id'].'/cancel', ['reason' => 'QA cannot cancel paid'])->assertUnprocessable();
    }

    public function test_organization_identity_is_owner_controlled_and_receipts_keep_outlet_identity(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $this->actingAs($owner, 'sanctum')->withHeaders($this->shopHeader($shop));
        $this->patchJson('/api/organization', ['name' => 'QA generic business'])->assertOk();
        $product = $this->productWithStock($shop, $owner);
        $this->postJson('/api/sales', ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'payments' => [['method' => 'CASH', 'amount' => 1000]]])->assertCreated()->assertJsonPath('shop.business_name', 'QA generic business')->assertJsonPath('shop.name', 'Test Shop');
        [$manager] = $this->shopWithMember(Role::Manager, $shop);
        $this->actingAs($manager, 'sanctum')->patchJson('/api/organization', ['name' => 'Unauthorized'])->assertForbidden();
    }

    public function test_inventory_production_filters_match_recorded_types(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $this->actingAs($owner, 'sanctum')->withHeaders($this->shopHeader($shop));
        $input = $this->productWithStock($shop, $owner, 0, 1000, 2, ['kind' => 'raw_material']);
        $output = $this->productWithStock($shop, $owner, 1000, 0, 0);
        $id = $this->postJson('/api/production/batches', ['name' => 'QA filtered batch', 'inputs' => [['product_id' => $input->id, 'planned_quantity' => 1]], 'outputs' => [['product_id' => $output->id, 'quantity' => 1]]])->assertCreated()->json('id');
        $this->postJson("/api/production/batches/$id/complete", [])->assertOk();
        foreach (['PRODUCTION_INPUT', 'PRODUCTION_OUTPUT'] as $type) {
            $rows = $this->getJson('/api/inventory/movements?type='.$type)->assertOk()->json('data');
            $this->assertCount(1, $rows);
            $this->assertSame($type, $rows[0]['type']);
        }
    }
}
