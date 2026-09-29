<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Category;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class ProductImportTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    private function import($user, $shop, array $rows, bool $dryRun = false)
    {
        return $this->actingAs($user, 'sanctum')->withHeaders($this->shopHeader($shop))
            ->postJson('/api/products/import', ['rows' => $rows] + ($dryRun ? ['dry_run' => true] : []));
    }

    private function row(array $overrides = []): array
    {
        return $overrides + ['name' => 'Rice 1kg', 'category' => 'Groceries', 'base_unit' => 'kg', 'selling_price' => '5500', 'current_cost' => '4400', 'opening_stock' => '80', 'sku' => 'RIC', 'barcode' => '6001'];
    }

    public function test_a_valid_file_creates_products_categories_and_opening_stock(): void
    {
        [$owner, $shop] = $this->shopWithMember();

        $this->import($owner, $shop, [
            $this->row(),
            $this->row(['name' => 'Salt 500g', 'category' => 'groceries', 'sku' => 'SAL', 'barcode' => '6002', 'opening_stock' => '', 'base_unit' => '']),
            $this->row(['name' => 'Soap', 'category' => 'Household', 'sku' => null, 'barcode' => null, 'opening_stock' => '10']),
        ])->assertCreated()->assertJsonPath('created', 3);

        $this->assertSame(3, Product::count());
        $this->assertSame(2, Category::count(), 'category names match case-insensitively');
        $this->assertSame(2, StockMovement::where('movement_type', 'OPENING_STOCK')->count(), 'no stock row when opening stock is blank');
        $this->assertSame('piece', Product::where('name', 'Salt 500g')->firstOrFail()->base_unit);
        $this->assertSame(4400, Product::where('name', 'Rice 1kg')->firstOrFail()->current_cost);
    }

    public function test_one_bad_row_rejects_the_whole_file_and_writes_nothing(): void
    {
        [$owner, $shop] = $this->shopWithMember();

        $response = $this->import($owner, $shop, [
            $this->row(),
            $this->row(['name' => '', 'sku' => 'B', 'barcode' => 'b']),
            $this->row(['name' => 'Neg', 'sku' => 'C', 'barcode' => 'c', 'selling_price' => '-5']),
        ])->assertUnprocessable();

        $rows = collect($response->json('errors'))->keyBy('row');
        $this->assertSame([2, 3], $rows->keys()->sort()->values()->all());
        $this->assertArrayHasKey('name', $rows[2]['messages']);
        $this->assertArrayHasKey('selling_price', $rows[3]['messages']);
        $this->assertSame(0, Product::count());
        $this->assertSame(0, Category::count());
        $this->assertSame(0, StockMovement::count());
    }

    public function test_duplicates_inside_the_file_and_against_existing_products_are_caught(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        Product::create(['shop_id' => $shop->id, 'name' => 'Existing', 'sku' => 'TAKEN', 'selling_price' => 1]);

        $response = $this->import($owner, $shop, [
            $this->row(['sku' => 'DUP', 'barcode' => '1']),
            $this->row(['name' => 'Second', 'sku' => 'dup', 'barcode' => '2']),
            $this->row(['name' => 'Third', 'sku' => 'TAKEN', 'barcode' => '3']),
        ])->assertUnprocessable();

        $rows = collect($response->json('errors'))->keyBy('row');
        $this->assertStringContainsString('row 1', $rows[2]['messages']['sku'][0]);
        $this->assertArrayHasKey('sku', $rows[3]['messages']);
        $this->assertSame(1, Product::count());
    }

    public function test_a_dry_run_checks_without_writing(): void
    {
        [$owner, $shop] = $this->shopWithMember();

        $this->import($owner, $shop, [$this->row()], dryRun: true)->assertOk()->assertJsonPath('valid', 1)->assertJsonPath('created', 0);
        $this->assertSame(0, Product::count());
    }

    public function test_only_managers_can_import_and_the_file_size_is_capped(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        [$cashier] = $this->shopWithMember(Role::Cashier, $shop);

        $this->import($cashier, $shop, [$this->row()])->assertForbidden();

        $tooMany = array_map(fn ($i) => $this->row(['name' => "P$i", 'sku' => "S$i", 'barcode' => "B$i"]), range(1, 501));
        $this->import($owner, $shop, $tooMany)->assertUnprocessable()->assertJsonValidationErrors('rows');
        $this->assertSame(0, Product::count());
    }

    public function test_imported_products_are_sellable_straight_away(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $this->import($owner, $shop, [$this->row()])->assertCreated();
        $id = Product::firstOrFail()->id;

        $this->actingAs($owner, 'sanctum')->withHeaders($this->shopHeader($shop))->postJson('/api/sales', [
            'items' => [['product_id' => $id, 'quantity' => 2]], 'payments' => [['method' => 'CASH', 'amount' => 11000]],
        ])->assertCreated()->assertJsonPath('items.0.historical_cost', 4400);
    }
}
