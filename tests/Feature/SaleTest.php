<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class SaleTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    private function sell($user, $shop, array $payload)
    {
        return $this->actingAs($user, 'sanctum')
            ->withHeaders($this->shopHeader($shop))
            ->postJson('/api/sales', $payload);
    }

    public function test_the_server_prices_the_sale_and_ignores_client_prices(): void
    {
        [$user, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $user, price: 2500, cost: 1500);

        $response = $this->sell($user, $shop, [
            'items' => [['product_id' => $product->id, 'quantity' => 3, 'unit_price' => 1, 'total' => 1]],
            'total' => 1,
            'payments' => [['method' => 'CASH', 'amount' => 7500]],
        ]);

        $response->assertCreated()
            ->assertJsonPath('subtotal', 7500)
            ->assertJsonPath('total', 7500)
            ->assertJsonPath('amount_paid', 7500)
            ->assertJsonPath('amount_due', 0)
            ->assertJsonPath('items.0.unit_price', 2500)
            ->assertJsonPath('sale_number', 'S-000001');
    }

    public function test_line_and_order_discounts_reduce_the_total(): void
    {
        [$user, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $user, price: 1000);

        $this->sell($user, $shop, [
            'items' => [['product_id' => $product->id, 'quantity' => 4, 'discount' => 300]],
            'discount' => 200,
            'payments' => [['method' => 'CASH', 'amount' => 3500]],
        ])->assertCreated()
            ->assertJsonPath('subtotal', 4000)
            ->assertJsonPath('discount', 500)
            ->assertJsonPath('total', 3500);
    }

    public function test_a_discount_larger_than_the_sale_is_rejected(): void
    {
        [$user, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $user, price: 1000);

        $this->sell($user, $shop, [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'discount' => 5000,
            'payments' => [['method' => 'CASH', 'amount' => 1000]],
        ])->assertUnprocessable();

        $this->assertDatabaseCount('sales', 0);
    }

    public function test_completing_a_sale_writes_a_stock_out_movement_with_the_historical_cost(): void
    {
        [$user, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $user, price: 1000, cost: 640, stock: 10);

        $this->sell($user, $shop, [
            'items' => [['product_id' => $product->id, 'quantity' => 4]],
            'payments' => [['method' => 'CASH', 'amount' => 4000]],
        ])->assertCreated();

        $movement = StockMovement::where('movement_type', 'SALE')->firstOrFail();
        $this->assertEquals(-4.0, $movement->quantity_delta);
        $this->assertSame(640, $movement->unit_cost);
        $this->assertEquals(6.0, app(StockService::class)->current($shop->id, $product->id));
    }

    public function test_changing_a_products_price_does_not_rewrite_past_sales(): void
    {
        [$user, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $user, price: 1000, cost: 600);

        $saleId = $this->sell($user, $shop, [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'payments' => [['method' => 'CASH', 'amount' => 2000]],
        ])->json('id');

        $product->update(['selling_price' => 9999, 'current_cost' => 5000]);

        $this->actingAs($user, 'sanctum')->withHeaders($this->shopHeader($shop))
            ->getJson("/api/sales/{$saleId}")
            ->assertJsonPath('total', 2000)
            ->assertJsonPath('items.0.unit_price', 1000)
            ->assertJsonPath('items.0.historical_cost', 600);
    }

    public function test_selling_more_than_is_in_stock_is_rejected_and_changes_nothing(): void
    {
        [$user, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $user, stock: 2);

        $this->sell($user, $shop, [
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
            'payments' => [['method' => 'CASH', 'amount' => 3000]],
        ])->assertUnprocessable()->assertJsonValidationErrors('items.0.quantity');

        $this->assertDatabaseCount('sales', 0);
        $this->assertEquals(2.0, app(StockService::class)->current($shop->id, $product->id));
    }

    public function test_the_same_product_on_two_lines_is_checked_against_stock_together(): void
    {
        [$user, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $user, stock: 5);

        $this->sell($user, $shop, [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 3],
                ['product_id' => $product->id, 'quantity' => 3],
            ],
            'payments' => [['method' => 'CASH', 'amount' => 6000]],
        ])->assertUnprocessable();
    }

    public function test_products_from_another_shop_cannot_be_sold(): void
    {
        [$user, $shop] = $this->shopWithMember();
        [$otherOwner, $otherShop] = $this->shopWithMember();
        $foreign = $this->productWithStock($otherShop, $otherOwner);

        $this->sell($user, $shop, [
            'items' => [['product_id' => $foreign->id, 'quantity' => 1]],
            'payments' => [['method' => 'CASH', 'amount' => 1000]],
        ])->assertUnprocessable()->assertJsonValidationErrors('items.0.product_id');
    }

    public function test_selling_by_an_alternate_unit_uses_its_price_and_converts_stock(): void
    {
        [$user, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $user, price: 500, cost: 300, stock: 48);
        $product->units()->create(['unit_name' => 'box', 'conversion_to_base_unit' => 12, 'selling_price' => 5500]);

        $this->sell($user, $shop, [
            'items' => [['product_id' => $product->id, 'quantity' => 2, 'unit' => 'box']],
            'payments' => [['method' => 'CASH', 'amount' => 11000]],
        ])->assertCreated()
            ->assertJsonPath('total', 11000)
            ->assertJsonPath('items.0.historical_cost', 3600);

        $this->assertEquals(24.0, app(StockService::class)->current($shop->id, $product->id));
    }

    public function test_cash_overpayment_records_only_the_net_amount_received(): void
    {
        [$user, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $user, price: 1000);

        $this->sell($user, $shop, [
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
            'payments' => [['method' => 'CASH', 'amount' => 5000]],
        ])->assertCreated()->assertJsonPath('amount_paid', 3000);

        $this->assertSame(3000, (int) Payment::sum('amount'));
    }

    public function test_overpaying_with_mobile_money_is_rejected(): void
    {
        [$user, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $user, price: 1000);

        $this->sell($user, $shop, [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payments' => [['method' => 'MOBILE_MONEY', 'amount' => 2000]],
        ])->assertUnprocessable()->assertJsonValidationErrors('payments');
    }

    public function test_split_payments_across_methods_are_all_recorded(): void
    {
        [$user, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $user, price: 1000);

        $this->sell($user, $shop, [
            'items' => [['product_id' => $product->id, 'quantity' => 5]],
            'payments' => [
                ['method' => 'CASH', 'amount' => 2000],
                ['method' => 'MOBILE_MONEY', 'amount' => 3000, 'reference' => 'MM12345'],
            ],
        ])->assertCreated()->assertJsonCount(2, 'payments');
    }

    public function test_a_short_payment_needs_a_customer_to_owe_the_rest(): void
    {
        [$user, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $user, price: 1000);

        $this->sell($user, $shop, [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'payments' => [['method' => 'CASH', 'amount' => 500]],
        ])->assertUnprocessable();
    }

    public function test_sale_numbers_are_sequential_per_shop(): void
    {
        [$user, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $user, price: 1000, stock: 10);
        $payload = [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payments' => [['method' => 'CASH', 'amount' => 1000]],
        ];

        $this->sell($user, $shop, $payload)->assertJsonPath('sale_number', 'S-000001');
        $this->sell($user, $shop, $payload)->assertJsonPath('sale_number', 'S-000002');

        [$otherUser, $otherShop] = $this->shopWithMember();
        $otherProduct = $this->productWithStock($otherShop, $otherUser, price: 1000);
        $this->sell($otherUser, $otherShop, [
            'items' => [['product_id' => $otherProduct->id, 'quantity' => 1]],
            'payments' => [['method' => 'CASH', 'amount' => 1000]],
        ])->assertJsonPath('sale_number', 'S-000001');
    }

    public function test_repeating_a_request_with_the_same_idempotency_key_never_duplicates_anything(): void
    {
        [$user, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $user, price: 1000, stock: 10);
        $payload = [
            'idempotency_key' => 'device-1-sale-42',
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'payments' => [['method' => 'CASH', 'amount' => 2000]],
        ];

        $first = $this->sell($user, $shop, $payload)->assertCreated();
        $second = $this->sell($user, $shop, $payload)->assertOk();

        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(1, StockMovement::where('movement_type', 'SALE')->count());
        $this->assertEquals(8.0, app(StockService::class)->current($shop->id, $product->id));
    }

    public function test_the_idempotency_key_header_is_honoured(): void
    {
        [$user, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $user, price: 1000);
        $payload = [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payments' => [['method' => 'CASH', 'amount' => 1000]],
        ];

        $headers = $this->shopHeader($shop) + ['Idempotency-Key' => 'abc-123'];
        $this->actingAs($user, 'sanctum')->withHeaders($headers)->postJson('/api/sales', $payload)->assertCreated();
        $this->actingAs($user, 'sanctum')->withHeaders($headers)->postJson('/api/sales', $payload)->assertOk();

        $this->assertDatabaseCount('sales', 1);
    }

    public function test_a_cashier_can_sell_but_only_sees_their_own_sales(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        [$cashierA] = $this->shopWithMember(Role::Cashier, $shop);
        [$cashierB] = $this->shopWithMember(Role::Cashier, $shop);
        $product = $this->productWithStock($shop, $owner, price: 1000, stock: 10);
        $payload = [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payments' => [['method' => 'CASH', 'amount' => 1000]],
        ];

        $this->sell($cashierA, $shop, $payload)->assertCreated();
        $saleB = $this->sell($cashierB, $shop, $payload)->assertCreated()->json('id');

        $this->actingAs($cashierA, 'sanctum')->withHeaders($this->shopHeader($shop))
            ->getJson('/api/sales')->assertJsonCount(1, 'data');
        $this->actingAs($cashierA, 'sanctum')->withHeaders($this->shopHeader($shop))
            ->getJson("/api/sales/{$saleB}")->assertNotFound();
        $this->actingAs($owner, 'sanctum')->withHeaders($this->shopHeader($shop))
            ->getJson('/api/sales')->assertJsonCount(2, 'data');
    }

    public function test_an_outsider_cannot_sell_in_a_shop(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        [$outsider] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner);

        $this->sell($outsider, $shop, [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payments' => [['method' => 'CASH', 'amount' => 1000]],
        ])->assertForbidden();
    }

    public function test_voiding_restores_stock_and_reverses_payments_without_deleting_anything(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, price: 1000, stock: 10);

        $saleId = $this->sell($owner, $shop, [
            'items' => [['product_id' => $product->id, 'quantity' => 4]],
            'payments' => [['method' => 'CASH', 'amount' => 4000]],
        ])->json('id');

        $this->actingAs($owner, 'sanctum')->withHeaders($this->shopHeader($shop))
            ->postJson("/api/sales/{$saleId}/void", ['reason' => 'Customer changed their mind'])
            ->assertOk()
            ->assertJsonPath('status', 'voided');

        $this->assertEquals(10.0, app(StockService::class)->current($shop->id, $product->id));
        $this->assertSame(2, Payment::count());
        $this->assertSame(1, Payment::where('direction', 'out')->count());
        $this->assertSame(0, (int) Payment::where('direction', 'in')->sum('amount') - (int) Payment::where('direction', 'out')->sum('amount'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'sale.void', 'entity_id' => $saleId]);
        $this->assertSame('voided', Sale::find($saleId)->status);
    }

    public function test_a_sale_cannot_be_voided_twice(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, price: 1000);

        $saleId = $this->sell($owner, $shop, [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payments' => [['method' => 'CASH', 'amount' => 1000]],
        ])->json('id');

        $void = fn () => $this->actingAs($owner, 'sanctum')->withHeaders($this->shopHeader($shop))
            ->postJson("/api/sales/{$saleId}/void", ['reason' => 'Mistake']);

        $void()->assertOk();
        $void()->assertUnprocessable();

        $this->assertEquals(10.0, app(StockService::class)->current($shop->id, $product->id));
    }

    public function test_a_cashier_cannot_void_a_sale(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        [$cashier] = $this->shopWithMember(Role::Cashier, $shop);
        $product = $this->productWithStock($shop, $owner, price: 1000);

        $saleId = $this->sell($cashier, $shop, [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payments' => [['method' => 'CASH', 'amount' => 1000]],
        ])->json('id');

        $this->actingAs($cashier, 'sanctum')->withHeaders($this->shopHeader($shop))
            ->postJson("/api/sales/{$saleId}/void", ['reason' => 'Trying it'])
            ->assertForbidden();
    }

    public function test_the_pos_catalogue_lists_active_products_with_live_stock(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $sold = $this->productWithStock($shop, $owner, price: 1000, stock: 10, attributes: ['name' => 'Sugar 1kg', 'barcode' => '6001']);
        $this->productWithStock($shop, $owner, attributes: ['name' => 'Retired item', 'status' => 'archived']);

        $this->sell($owner, $shop, [
            'items' => [['product_id' => $sold->id, 'quantity' => 3]],
            'payments' => [['method' => 'CASH', 'amount' => 3000]],
        ])->assertCreated();

        $this->actingAs($owner, 'sanctum')->withHeaders($this->shopHeader($shop))
            ->getJson('/api/pos/products')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.name', 'Sugar 1kg')
            ->assertJsonPath('0.stock', 7);

        $this->actingAs($owner, 'sanctum')->withHeaders($this->shopHeader($shop))
            ->getJson('/api/pos/products?search=6001')
            ->assertJsonCount(1);
        $this->actingAs($owner, 'sanctum')->withHeaders($this->shopHeader($shop))
            ->getJson('/api/pos/products?search=nomatch')
            ->assertJsonCount(0);
    }
}
