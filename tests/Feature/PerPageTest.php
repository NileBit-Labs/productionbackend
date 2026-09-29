<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class PerPageTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    private function api($user, $shop)
    {
        return $this->actingAs($user, 'sanctum')->withHeaders($this->shopHeader($shop));
    }

    public function test_the_product_list_honours_rows_per_page(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        for ($i = 0; $i < 12; $i++) {
            $this->productWithStock($shop, $owner, stock: 0);
        }

        $ten = $this->api($owner, $shop)->getJson('/api/products?per_page=10')->assertOk();
        $this->assertCount(10, $ten->json('data'));
        $this->assertSame(10, $ten->json('per_page'));
        $this->assertSame(2, $ten->json('last_page'));
        $this->assertSame(12, $ten->json('total'));

        $this->assertCount(2, $this->api($owner, $shop)->getJson('/api/products?per_page=10&page=2')->json('data'));
    }

    public function test_a_number_that_is_not_offered_falls_back_to_the_default_instead_of_being_used(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        for ($i = 0; $i < 12; $i++) {
            $this->productWithStock($shop, $owner, stock: 0);
        }

        foreach (['7', '1000', '0', '-5', 'all', ''] as $odd) {
            $response = $this->api($owner, $shop)->getJson('/api/products?per_page='.$odd)->assertOk();
            $this->assertSame(50, $response->json('per_page'), "per_page={$odd}");
            $this->assertCount(12, $response->json('data'));
        }
    }

    public function test_suppliers_purchases_and_sales_honour_it_too(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, price: 1000, cost: 500, stock: 500);

        for ($i = 0; $i < 12; $i++) {
            Supplier::create(['shop_id' => $shop->id, 'name' => "Supplier {$i}"]);
        }
        $supplier = Supplier::first();

        for ($i = 0; $i < 12; $i++) {
            $this->api($owner, $shop)->postJson('/api/purchases', ['supplier_id' => $supplier->id, 'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 500]]])->assertCreated();
            $this->api($owner, $shop)->postJson('/api/sales', ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'payments' => [['method' => 'CASH', 'amount' => 1000]]])->assertCreated();
        }

        $this->assertCount(10, $this->api($owner, $shop)->getJson('/api/suppliers?per_page=10')->json('suppliers.data'));
        $this->assertCount(10, $this->api($owner, $shop)->getJson('/api/purchases?per_page=10')->json('data'));
        $this->assertCount(10, $this->api($owner, $shop)->getJson('/api/sales?per_page=10')->json('data'));
        // The sales list keeps its own smaller default when nothing valid is asked for.
        $this->assertCount(12, $this->api($owner, $shop)->getJson('/api/sales')->json('data'));
        $this->assertSame(25, $this->api($owner, $shop)->getJson('/api/sales?per_page=3')->json('per_page'));
    }

    public function test_the_inventory_lists_and_the_audit_log_honour_it_too(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        for ($i = 0; $i < 12; $i++) {
            $this->productWithStock($shop, $owner, stock: 5);
            AuditLog::forceCreate(['organization_id' => $shop->organization_id, 'shop_id' => $shop->id, 'user_id' => $owner->id, 'action' => 'product.update', 'entity_type' => Product::class, 'entity_id' => $i + 1]);
        }

        $this->assertCount(10, $this->api($owner, $shop)->getJson('/api/inventory?per_page=10')->json('products.data'));
        $this->assertCount(10, $this->api($owner, $shop)->getJson('/api/inventory/movements?per_page=10')->json('data'));
        $this->assertCount(10, $this->api($owner, $shop)->getJson('/api/audit-logs?per_page=10')->json('page.data'));
    }
}
