<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    private function api($user, $shop)
    {
        return $this->actingAs($user, 'sanctum')->withHeaders($this->shopHeader($shop));
    }

    private function stock($shop, Product $product): float
    {
        return app(StockService::class)->current($shop->id, $product->id);
    }

    public function test_a_stock_take_sets_the_stock_to_the_counted_quantity_and_is_audited(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, cost: 600, stock: 10);

        $this->api($owner, $shop)->postJson('/api/inventory/adjustments', [
            'product_id' => $product->id, 'counted_quantity' => 7, 'reason' => 'Monthly stock take',
        ])->assertCreated()->assertJsonPath('stock', 7)->assertJsonPath('movement.quantity_delta', -3)->assertJsonPath('movement.type', 'ADJUSTMENT');

        $this->assertEquals(7.0, $this->stock($shop, $product));
        $log = AuditLog::where('action', 'inventory.adjustment')->firstOrFail();
        $this->assertEquals(10, $log->before_data['stock']);
        $this->assertEquals(7, $log->after_data['stock']);
        $this->assertSame('Monthly stock take', $log->after_data['reason']);
        $this->assertSame($owner->id, StockMovement::where('movement_type', 'ADJUSTMENT')->firstOrFail()->performed_by);
    }

    public function test_stock_movement_belongs_to_its_product_shop_and_performer(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, stock: 3);
        $movement = StockMovement::where('product_id', $product->id)->sole();

        $this->assertTrue($movement->product->is($product));
        $this->assertTrue($movement->shop->is($shop));
        $this->assertTrue($movement->performer->is($owner));
    }

    public function test_an_adjustment_can_add_stock_and_a_count_that_matches_is_rejected(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, stock: 10);

        $this->api($owner, $shop)->postJson('/api/inventory/adjustments', ['product_id' => $product->id, 'quantity_delta' => 5, 'reason' => 'Found a box'])
            ->assertCreated()->assertJsonPath('stock', 15);
        $this->api($owner, $shop)->postJson('/api/inventory/adjustments', ['product_id' => $product->id, 'counted_quantity' => 15, 'reason' => 'Recount'])
            ->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->assertSame(1, StockMovement::where('movement_type', 'ADJUSTMENT')->count());
    }

    public function test_stock_can_never_be_adjusted_below_zero(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, stock: 4);

        $this->api($owner, $shop)->postJson('/api/inventory/adjustments', ['product_id' => $product->id, 'quantity_delta' => -5, 'reason' => 'Too many'])
            ->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->api($owner, $shop)->postJson('/api/inventory/adjustments', ['product_id' => $product->id, 'quantity_delta' => -4, 'reason' => 'All of it'])
            ->assertCreated()->assertJsonPath('stock', 0);
        $this->assertEquals(0.0, $this->stock($shop, $product));
    }

    public function test_an_adjustment_needs_a_reason_and_exactly_one_of_change_or_count(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, stock: 4);

        $this->api($owner, $shop)->postJson('/api/inventory/adjustments', ['product_id' => $product->id, 'counted_quantity' => 3])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->api($owner, $shop)->postJson('/api/inventory/adjustments', ['product_id' => $product->id, 'reason' => 'x y z'])
            ->assertUnprocessable();
        $this->api($owner, $shop)->postJson('/api/inventory/adjustments', ['product_id' => $product->id, 'reason' => 'Both', 'quantity_delta' => 1, 'counted_quantity' => 9])
            ->assertUnprocessable();
        $this->assertEquals(4.0, $this->stock($shop, $product));
    }

    public function test_damage_and_loss_take_stock_out_with_their_own_movement_types(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, cost: 700, stock: 10);

        $this->api($owner, $shop)->postJson('/api/inventory/damage', ['product_id' => $product->id, 'quantity' => 2, 'reason' => 'Dropped crate'])
            ->assertCreated()->assertJsonPath('movement.type', 'DAMAGE')->assertJsonPath('stock', 8);
        $this->api($owner, $shop)->postJson('/api/inventory/loss', ['product_id' => $product->id, 'quantity' => 1, 'reason' => 'Stolen'])
            ->assertCreated()->assertJsonPath('movement.type', 'LOSS')->assertJsonPath('stock', 7);

        $this->assertDatabaseHas('stock_movements', ['movement_type' => 'DAMAGE', 'quantity_delta' => -2, 'unit_cost' => 700]);
        $this->assertSame(2, AuditLog::whereIn('action', ['inventory.damage', 'inventory.loss'])->count());
    }

    public function test_damage_and_loss_cannot_exceed_what_is_in_stock(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, stock: 3);

        $this->api($owner, $shop)->postJson('/api/inventory/damage', ['product_id' => $product->id, 'quantity' => 4, 'reason' => 'Too many'])->assertUnprocessable();
        $this->api($owner, $shop)->postJson('/api/inventory/loss', ['product_id' => $product->id, 'quantity' => 0, 'reason' => 'Nothing'])->assertUnprocessable();
        $this->assertEquals(3.0, $this->stock($shop, $product));
    }

    public function test_opening_stock_can_be_set_once_per_product(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, stock: 0);

        $this->api($owner, $shop)->postJson('/api/inventory/opening-stock', ['product_id' => $product->id, 'quantity' => 25, 'unit_cost' => 900])
            ->assertCreated()->assertJsonPath('stock', 25)->assertJsonPath('movement.type', 'OPENING_STOCK');
        $this->api($owner, $shop)->postJson('/api/inventory/opening-stock', ['product_id' => $product->id, 'quantity' => 5])
            ->assertUnprocessable()->assertJsonValidationErrors('product_id');
        $this->assertEquals(25.0, $this->stock($shop, $product));
    }

    public function test_a_repeated_request_with_the_same_key_moves_stock_once(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, stock: 10);
        $body = ['product_id' => $product->id, 'quantity' => 2, 'reason' => 'Spoiled', 'idempotency_key' => 'k-1'];

        $first = $this->api($owner, $shop)->postJson('/api/inventory/damage', $body)->assertCreated();
        $second = $this->api($owner, $shop)->postJson('/api/inventory/damage', $body)->assertOk();

        $this->assertSame($first->json('movement.id'), $second->json('movement.id'));
        $this->assertEquals(8.0, $this->stock($shop, $product));
        $this->assertSame(1, AuditLog::where('action', 'inventory.damage')->count());
    }

    public function test_cashiers_cannot_touch_inventory_at_all(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        [$cashier] = $this->shopWithMember(Role::Cashier, $shop);
        $product = $this->productWithStock($shop, $owner, stock: 10);

        $this->api($cashier, $shop)->getJson('/api/inventory')->assertForbidden();
        $this->api($cashier, $shop)->getJson('/api/inventory/movements')->assertForbidden();
        $this->api($cashier, $shop)->postJson('/api/inventory/adjustments', ['product_id' => $product->id, 'quantity_delta' => 99, 'reason' => 'Sneaky'])->assertForbidden();
        $this->api($cashier, $shop)->postJson('/api/inventory/damage', ['product_id' => $product->id, 'quantity' => 1, 'reason' => 'Sneaky'])->assertForbidden();
        $this->assertEquals(10.0, $this->stock($shop, $product));
    }

    public function test_another_shops_product_cannot_be_adjusted(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        [$other, $otherShop] = $this->shopWithMember();
        $theirs = $this->productWithStock($otherShop, $other, stock: 10);

        $this->api($owner, $shop)->postJson('/api/inventory/adjustments', ['product_id' => $theirs->id, 'quantity_delta' => -5, 'reason' => 'Hijack'])->assertNotFound();
        $this->assertEquals(10.0, $this->stock($otherShop, $theirs));
    }

    public function test_the_movement_history_can_be_filtered_and_says_where_each_came_from(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $a = $this->productWithStock($shop, $owner, stock: 10, attributes: ['name' => 'A']);
        $b = $this->productWithStock($shop, $owner, stock: 10, attributes: ['name' => 'B']);

        $sale = $this->api($owner, $shop)->postJson('/api/sales', [
            'items' => [['product_id' => $a->id, 'quantity' => 2]], 'payments' => [['method' => 'CASH', 'amount' => 2000]],
        ])->assertCreated()->json();
        $this->api($owner, $shop)->postJson('/api/inventory/loss', ['product_id' => $b->id, 'quantity' => 1, 'reason' => 'Stolen'])->assertCreated();

        $all = $this->api($owner, $shop)->getJson('/api/inventory/movements')->assertOk();
        $all->assertJsonPath('total', 4)->assertJsonPath('data.0.type', 'LOSS')->assertJsonPath('data.0.performed_by', $owner->name);

        $forA = $this->api($owner, $shop)->getJson("/api/inventory/movements?product_id={$a->id}")->assertJsonPath('total', 2);
        $saleRow = collect($forA->json('data'))->firstWhere('type', 'SALE');
        $this->assertSame($sale['sale_number'], $saleRow['reference']['label']);
        $this->assertEquals(-2, $saleRow['quantity_delta']);

        $this->api($owner, $shop)->getJson('/api/inventory/movements?type=OPENING_STOCK')->assertJsonPath('total', 2);
        $this->api($owner, $shop)->getJson('/api/inventory/movements?from='.now()->addDay()->toDateString())->assertJsonPath('total', 0);
    }

    public function test_the_overview_counts_low_and_out_of_stock_and_values_what_is_on_the_shelf(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $this->productWithStock($shop, $owner, cost: 1000, stock: 10, attributes: ['name' => 'Fine', 'low_stock_threshold' => 5]);       // 10,000
        $this->productWithStock($shop, $owner, cost: 500, stock: 4, attributes: ['name' => 'Low', 'low_stock_threshold' => 5]);          // 2,000
        $this->productWithStock($shop, $owner, cost: 800, stock: 0, attributes: ['name' => 'Out']);                                       // 0
        $this->productWithStock($shop, $owner, cost: 999, stock: 100, attributes: ['name' => 'Archived', 'status' => 'archived']);        // excluded

        $this->api($owner, $shop)->getJson('/api/inventory')->assertOk()
            ->assertJsonPath('summary.items', 3)->assertJsonPath('summary.low_stock', 1)
            ->assertJsonPath('summary.out_of_stock', 1)->assertJsonPath('summary.stock_value', 12000);

        $this->api($owner, $shop)->getJson('/api/inventory?filter=low')->assertJsonCount(1, 'products.data')->assertJsonPath('products.data.0.name', 'Low');
        $this->api($owner, $shop)->getJson('/api/inventory?filter=out')->assertJsonCount(1, 'products.data')->assertJsonPath('products.data.0.name', 'Out');
    }

    public function test_every_change_to_stock_is_one_ledger_entry_that_adds_up(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, stock: 50);

        $this->api($owner, $shop)->postJson('/api/sales', ['items' => [['product_id' => $product->id, 'quantity' => 8]], 'payments' => [['method' => 'CASH', 'amount' => 8000]]])->assertCreated();
        $this->api($owner, $shop)->postJson('/api/inventory/damage', ['product_id' => $product->id, 'quantity' => 3, 'reason' => 'Broken'])->assertCreated();
        $this->api($owner, $shop)->postJson('/api/inventory/adjustments', ['product_id' => $product->id, 'quantity_delta' => 4, 'reason' => 'Found'])->assertCreated();
        $this->api($owner, $shop)->postJson('/api/inventory/adjustments', ['product_id' => $product->id, 'counted_quantity' => 40, 'reason' => 'Recount'])->assertCreated();

        $sum = (float) StockMovement::where('product_id', $product->id)->sum('quantity_delta');
        $this->assertEquals(40.0, $sum);
        $this->api($owner, $shop)->getJson("/api/products/{$product->id}")->assertJsonPath('stock', 40);
        $this->assertSame(5, StockMovement::where('product_id', $product->id)->count());
    }
}
