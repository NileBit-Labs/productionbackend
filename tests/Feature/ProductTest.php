<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    private function api($user, $shop)
    {
        return $this->actingAs($user, 'sanctum')->withHeaders($this->shopHeader($shop));
    }

    private function create($user, $shop, array $overrides = [])
    {
        return $this->api($user, $shop)->postJson('/api/products', $overrides + [
            'name' => 'Sugar 1kg', 'base_unit' => 'kg', 'selling_price' => 4500, 'current_cost' => 3800,
        ]);
    }

    public function test_a_manager_creates_a_product_with_units_and_opening_stock(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $category = Category::create(['shop_id' => $shop->id, 'name' => 'Groceries']);

        $this->create($owner, $shop, [
            'category_id' => $category->id, 'sku' => 'SUG-1', 'barcode' => '6001', 'low_stock_threshold' => 10,
            'opening_stock' => 60,
            'units' => [['unit_name' => 'sack', 'conversion_to_base_unit' => 50, 'selling_price' => 210000]],
        ])->assertCreated()
            ->assertJsonPath('name', 'Sugar 1kg')
            ->assertJsonPath('category', 'Groceries')
            ->assertJsonPath('current_cost', 3800)
            ->assertJsonPath('stock', 60)
            ->assertJsonPath('stock_value', 228000)
            ->assertJsonPath('units.0.unit_name', 'sack');

        $movement = StockMovement::firstOrFail();
        $this->assertSame('OPENING_STOCK', $movement->movement_type->value);
        $this->assertSame(3800, $movement->unit_cost);
    }

    public function test_cost_prices_are_for_managers_only(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        [$cashier] = $this->shopWithMember(Role::Cashier, $shop);
        $this->create($owner, $shop)->assertCreated();

        $this->api($cashier, $shop)->getJson('/api/products')->assertForbidden();
        $this->create($cashier, $shop, ['name' => 'Sneaky'])->assertForbidden();
        $this->assertSame(1, Product::count());
    }

    public function test_sku_and_barcode_are_unique_per_shop_but_blank_ones_can_repeat(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        [$other, $otherShop] = $this->shopWithMember();

        $this->create($owner, $shop, ['sku' => 'A1', 'barcode' => '111'])->assertCreated();
        $this->create($owner, $shop, ['name' => 'B', 'sku' => 'A1'])->assertUnprocessable()->assertJsonValidationErrors('sku');
        $this->create($owner, $shop, ['name' => 'C', 'barcode' => '111'])->assertUnprocessable()->assertJsonValidationErrors('barcode');
        $this->create($owner, $shop, ['name' => 'D', 'sku' => '', 'barcode' => ''])->assertCreated();
        $this->create($owner, $shop, ['name' => 'E', 'sku' => '', 'barcode' => ''])->assertCreated();
        $this->create($other, $otherShop, ['sku' => 'A1', 'barcode' => '111'])->assertCreated();
    }

    public function test_a_category_from_another_shop_is_rejected(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        [, $otherShop] = $this->shopWithMember();
        $foreign = Category::create(['shop_id' => $otherShop->id, 'name' => 'Theirs']);

        $this->create($owner, $shop, ['category_id' => $foreign->id])->assertUnprocessable()->assertJsonValidationErrors('category_id');
    }

    public function test_an_alternate_unit_cannot_repeat_the_base_unit_or_each_other(): void
    {
        [$owner, $shop] = $this->shopWithMember();

        $this->create($owner, $shop, ['units' => [['unit_name' => 'KG', 'conversion_to_base_unit' => 1, 'selling_price' => 1]]])
            ->assertUnprocessable()->assertJsonValidationErrors('units.0.unit_name');
        $this->create($owner, $shop, ['units' => [
            ['unit_name' => 'sack', 'conversion_to_base_unit' => 50, 'selling_price' => 1],
            ['unit_name' => 'Sack', 'conversion_to_base_unit' => 25, 'selling_price' => 1],
        ]])->assertUnprocessable();
        $this->create($owner, $shop, ['units' => [['unit_name' => 'sack', 'conversion_to_base_unit' => 0, 'selling_price' => 1]]])
            ->assertUnprocessable()->assertJsonValidationErrors('units.0.conversion_to_base_unit');
    }

    public function test_changing_a_price_or_cost_is_audited_and_does_not_rewrite_past_sales(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $id = $this->create($owner, $shop, ['opening_stock' => 10])->json('id');

        $saleId = $this->api($owner, $shop)->postJson('/api/sales', [
            'items' => [['product_id' => $id, 'quantity' => 2]], 'payments' => [['method' => 'CASH', 'amount' => 9000]],
        ])->assertCreated()->json('id');

        $this->api($owner, $shop)->patchJson("/api/products/{$id}", ['selling_price' => 5000, 'current_cost' => 4200])
            ->assertOk()->assertJsonPath('selling_price', 5000);

        $this->api($owner, $shop)->getJson("/api/sales/{$saleId}")
            ->assertJsonPath('total', 9000)->assertJsonPath('items.0.unit_price', 4500)->assertJsonPath('items.0.historical_cost', 3800);

        $log = AuditLog::where('action', 'product.update')->firstOrFail();
        $this->assertSame(4500, $log->before_data['selling_price']);
        $this->assertSame(5000, $log->after_data['selling_price']);
        $this->assertSame(3800, $log->before_data['current_cost']);
        $this->assertArrayNotHasKey('name', $log->after_data, 'only what changed is logged');
    }

    public function test_an_unchanged_save_writes_no_audit_entry(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $id = $this->create($owner, $shop)->json('id');

        $this->api($owner, $shop)->patchJson("/api/products/{$id}", ['name' => 'Sugar 1kg', 'selling_price' => 4500])->assertOk();

        $this->assertSame(0, AuditLog::where('action', 'product.update')->count());
    }

    public function test_the_base_unit_cannot_change_once_the_product_has_stock_but_can_before(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $withStock = $this->create($owner, $shop, ['opening_stock' => 5])->json('id');
        $fresh = $this->create($owner, $shop, ['name' => 'Fresh', 'sku' => 'F'])->json('id');

        $this->api($owner, $shop)->patchJson("/api/products/{$withStock}", ['base_unit' => 'litre'])
            ->assertUnprocessable()->assertJsonValidationErrors('base_unit');
        $this->api($owner, $shop)->patchJson("/api/products/{$fresh}", ['base_unit' => 'litre'])->assertOk()->assertJsonPath('base_unit', 'litre');
    }

    public function test_editing_only_the_units_still_reaches_devices_on_their_next_sync(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $id = $this->create($owner, $shop, ['opening_stock' => 5])->json('id');

        $cursor = $this->api($owner, $shop)->getJson('/api/sync/pull')->json('cursor');
        $this->travel(30)->seconds();

        $this->api($owner, $shop)->patchJson("/api/products/{$id}", [
            'units' => [['unit_name' => 'sack', 'conversion_to_base_unit' => 50, 'selling_price' => 200000]],
        ])->assertOk()->assertJsonPath('units.0.unit_name', 'sack');

        $this->api($owner, $shop)->getJson('/api/sync/pull?cursor='.urlencode($cursor))
            ->assertJsonCount(1, 'products')->assertJsonPath('products.0.units.0.unit_name', 'sack');

        // ...and removing it does too.
        $cursor = $this->api($owner, $shop)->getJson('/api/sync/pull')->json('cursor');
        $this->travel(30)->seconds();
        $this->api($owner, $shop)->patchJson("/api/products/{$id}", ['units' => []])->assertOk()->assertJsonCount(0, 'units');
        $this->api($owner, $shop)->getJson('/api/sync/pull?cursor='.urlencode($cursor))->assertJsonCount(1, 'products');
    }

    public function test_archiving_hides_a_product_from_selling_and_flags_it_for_devices_and_it_can_be_restored(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $id = $this->create($owner, $shop, ['opening_stock' => 5])->json('id');
        $cursor = $this->api($owner, $shop)->getJson('/api/sync/pull')->json('cursor');
        $this->travel(30)->seconds();

        $this->api($owner, $shop)->postJson("/api/products/{$id}/archive")->assertOk()->assertJsonPath('status', 'archived');

        $this->api($owner, $shop)->getJson('/api/pos/products')->assertJsonCount(0);
        $this->api($owner, $shop)->getJson('/api/sync/pull?cursor='.urlencode($cursor))->assertJsonPath('products.0.status', 'archived');
        $this->api($owner, $shop)->postJson('/api/sales', [
            'items' => [['product_id' => $id, 'quantity' => 1]], 'payments' => [['method' => 'CASH', 'amount' => 4500]],
        ])->assertUnprocessable();
        $this->assertDatabaseHas('audit_logs', ['action' => 'product.archive', 'entity_id' => $id]);

        $this->api($owner, $shop)->postJson("/api/products/{$id}/restore")->assertOk()->assertJsonPath('status', 'active');
        $this->api($owner, $shop)->getJson('/api/pos/products')->assertJsonCount(1);
    }

    public function test_a_product_can_never_be_hard_deleted(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $id = $this->create($owner, $shop)->json('id');

        $this->api($owner, $shop)->deleteJson("/api/products/{$id}")->assertStatus(405);
        $this->assertNotNull(Product::find($id));
    }

    public function test_the_list_filters_by_search_category_status_and_stock_level(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $groceries = Category::create(['shop_id' => $shop->id, 'name' => 'Groceries']);
        $this->create($owner, $shop, ['name' => 'Rice', 'sku' => 'R', 'barcode' => '900', 'category_id' => $groceries->id, 'low_stock_threshold' => 10, 'opening_stock' => 50]);
        $this->create($owner, $shop, ['name' => 'Salt', 'sku' => 'S', 'low_stock_threshold' => 10, 'opening_stock' => 4]);   // low
        $this->create($owner, $shop, ['name' => 'Matches', 'sku' => 'M', 'opening_stock' => 0]);                              // out
        $archived = $this->create($owner, $shop, ['name' => 'Old', 'sku' => 'O'])->json('id');
        $this->api($owner, $shop)->postJson("/api/products/{$archived}/archive");

        $names = fn ($query) => collect($this->api($owner, $shop)->getJson('/api/products'.$query)->json('data'))->pluck('name')->sort()->values()->all();

        $this->assertSame(['Matches', 'Rice', 'Salt'], $names(''));
        $this->assertSame(['Rice'], $names('?search=900'));
        $this->assertSame(['Rice'], $names("?category_id={$groceries->id}"));
        $this->assertSame(['Salt'], $names('?low_stock=1'));
        $this->assertSame(['Matches'], $names('?out_of_stock=1'));
        $this->assertSame(['Old'], $names('?status=archived'));
        $this->assertSame(['Matches', 'Old', 'Rice', 'Salt'], $names('?status=all'));
    }

    public function test_the_product_flags_reflect_stock_against_the_threshold(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $this->create($owner, $shop, ['name' => 'Low', 'sku' => 'L', 'low_stock_threshold' => 10, 'opening_stock' => 10]);
        $this->create($owner, $shop, ['name' => 'Fine', 'sku' => 'F', 'low_stock_threshold' => 10, 'opening_stock' => 11]);
        $this->create($owner, $shop, ['name' => 'NoAlert', 'sku' => 'N', 'low_stock_threshold' => 0, 'opening_stock' => 1]);

        $by = collect($this->api($owner, $shop)->getJson('/api/products')->json('data'))->keyBy('name');
        $this->assertTrue($by['Low']['is_low']);
        $this->assertFalse($by['Fine']['is_low']);
        $this->assertFalse($by['NoAlert']['is_low'], 'a threshold of 0 means no alert');
    }

    public function test_categories_can_be_managed_and_deleting_one_uncategorises_its_products(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        [$cashier] = $this->shopWithMember(Role::Cashier, $shop);

        $id = $this->api($owner, $shop)->postJson('/api/categories', ['name' => 'Drinks'])->assertCreated()->json('id');
        $this->api($owner, $shop)->postJson('/api/categories', ['name' => 'Drinks'])->assertUnprocessable();
        $this->api($owner, $shop)->patchJson("/api/categories/{$id}", ['name' => 'Beverages'])->assertOk();
        $productId = $this->create($owner, $shop, ['category_id' => $id])->json('id');

        $this->api($owner, $shop)->getJson('/api/categories')->assertJsonPath('0.name', 'Beverages')->assertJsonPath('0.products_count', 1);
        $this->api($cashier, $shop)->postJson('/api/categories', ['name' => 'Nope'])->assertForbidden();

        $this->api($owner, $shop)->deleteJson("/api/categories/{$id}")->assertNoContent();
        $this->assertNull(Product::find($productId)->category_id);
    }

    public function test_products_and_categories_are_scoped_to_their_shop(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        [$other, $otherShop] = $this->shopWithMember();
        $id = $this->create($other, $otherShop)->json('id');
        $cat = $this->api($other, $otherShop)->postJson('/api/categories', ['name' => 'X'])->json('id');

        $this->api($owner, $shop)->getJson("/api/products/{$id}")->assertNotFound();
        $this->api($owner, $shop)->patchJson("/api/products/{$id}", ['name' => 'Hijack'])->assertNotFound();
        $this->api($owner, $shop)->postJson("/api/products/{$id}/archive")->assertNotFound();
        $this->api($owner, $shop)->patchJson("/api/categories/{$cat}", ['name' => 'Hijack'])->assertNotFound();
        $this->api($owner, $shop)->getJson('/api/products')->assertJsonCount(0, 'data');
        $this->assertSame('Sugar 1kg', Product::find($id)->name);
    }

    public function test_stock_after_creating_selling_and_receiving_returns_reconciles(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $id = $this->create($owner, $shop, ['opening_stock' => 20])->json('id');
        $this->api($owner, $shop)->postJson('/api/sales', [
            'items' => [['product_id' => $id, 'quantity' => 6]], 'payments' => [['method' => 'CASH', 'amount' => 27000]],
        ])->assertCreated();

        $this->assertEquals(14.0, app(StockService::class)->current($shop->id, $id));
        $this->api($owner, $shop)->getJson("/api/products/{$id}")->assertJsonPath('stock', 14);
    }
}
