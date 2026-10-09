<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Enums\Role;
use App\Models\Expense;
use App\Models\ProductionBatch;
use App\Models\ProductionLot;
use App\Models\StockMovement;
use App\Models\WastageRecord;
use App\Services\Ask\ShopTools;
use App\Services\ProductionLotService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class UatRemediationTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    public function test_uat_profit_reconciles_without_deducting_batch_wastage_twice(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $this->actingAs($owner, 'sanctum')->withHeaders($this->shopHeader($shop));
        $product = $this->productWithStock($shop, $owner, 75000, 38595);
        $this->postJson('/api/sales', ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'payments' => [['method' => 'CASH', 'amount' => 75000]]])->assertCreated();
        Expense::create(['shop_id' => $shop->id, 'category' => 'Delivery', 'type' => 'operating', 'amount' => 5000, 'expense_date' => $shop->today(), 'recorded_by' => $owner->id]);
        $batch = ProductionBatch::create(['shop_id' => $shop->id, 'batch_number' => 'QA-cost', 'name' => 'Capitalized loss', 'status' => 'completed', 'production_date' => $shop->today(), 'created_by' => $owner->id, 'wastage_cost' => 600, 'total_cost' => 600]);
        foreach ([[$batch->id, 600, 'production'], [null, 5556, 'finished_goods']] as [$batchId, $cost, $stage]) {
            WastageRecord::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'production_batch_id' => $batchId, 'stage' => $stage, 'quantity' => 1, 'unit_cost' => $cost, 'total_cost' => $cost, 'reason' => 'QA loss', 'wastage_date' => $shop->today(), 'recorded_by' => $owner->id]);
        }
        $this->getJson('/api/reports/profit')->assertOk()->assertJsonPath('summary.net_sales', 75000)->assertJsonPath('summary.cost_of_goods', 38595)->assertJsonPath('summary.operating_profit', 31405)->assertJsonPath('summary.wastage_losses', 5556)->assertJsonPath('summary.net_profit', 25849);
        $this->getJson('/api/reports/production')->assertOk()->assertJsonPath('wastage.total_cost', 6156);
        $tool = app(ShopTools::class)->run('get_profit_summary', ['period' => 'today'], $shop, Role::Owner);
        $this->assertSame(25849, $tool['result']['net_profit']);
        $this->getJson('/api/reports/dashboard')->assertOk()->assertJsonPath('today.net_profit', 25849);
    }

    public function test_catalogue_and_checkout_share_explicit_saleability_and_deltas_remove_disabled_items(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $this->actingAs($owner, 'sanctum')->withHeaders($this->shopHeader($shop));
        $hidden = $this->productWithStock($shop, $owner, 1000, 600, 10, ['kind' => 'raw_material']);
        $fruit = $this->productWithStock($shop, $owner, 1000, 600, 10, ['kind' => 'raw_material', 'is_saleable' => true]);
        $zero = $this->productWithStock($shop, $owner, 0, 600);
        $ids = array_column($this->getJson('/api/sync/pull')->assertOk()->json('products'), 'id');
        $this->assertContains($fruit->id, $ids);
        $this->assertNotContains($hidden->id, $ids);
        $this->assertNotContains($zero->id, $ids);
        $this->postJson('/api/sales', ['items' => [['product_id' => $hidden->id, 'quantity' => 1]], 'payments' => [['method' => 'CASH', 'amount' => 1000]]])->assertUnprocessable();
        $this->postJson('/api/sales', ['items' => [['product_id' => $fruit->id, 'quantity' => .5]], 'payments' => [['method' => 'CASH', 'amount' => 500]]])->assertCreated()->assertJsonPath('total', 500);
        $fruit->update(['is_saleable' => false]);
        $rows = $this->getJson('/api/sync/pull?cursor='.urlencode(now()->subMinute()->toIso8601String()))->assertOk()->json('products');
        $this->assertFalse(collect($rows)->firstWhere('id', $fruit->id)['is_saleable']);
    }

    public function test_draft_resume_preserves_cost_inputs_and_completes_exactly_once(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $this->actingAs($owner, 'sanctum')->withHeaders($this->shopHeader($shop));
        $input = $this->productWithStock($shop, $owner, 0, 1000, 10, ['kind' => 'raw_material']);
        $output = $this->productWithStock($shop, $owner, 3000, 0, 0);
        $body = ['name' => 'QA resume', 'production_date' => $shop->today(), 'inputs' => [['product_id' => $input->id, 'planned_quantity' => 2]], 'outputs' => [['product_id' => $output->id, 'quantity' => 2]], 'direct_expenses' => [['type' => 'direct_labour', 'category' => 'Labour', 'amount' => 500]], 'wastage' => [['product_id' => $input->id, 'quantity' => .5, 'reason' => 'QA trimmed fruit']], 'idempotency_key' => 'draft-once'];
        $id = $this->postJson('/api/production/batches', $body)->assertCreated()->json('id');
        $this->postJson('/api/production/batches', $body)->assertOk()->assertJsonPath('id', $id);
        $this->assertSame(10.0, app(StockService::class)->current($shop->id, $input->id));
        $this->patchJson("/api/production/batches/$id", ['name' => 'QA edited'])->assertOk();
        $this->postJson("/api/production/batches/$id/complete", ['inputs' => [['product_id' => $input->id, 'actual_quantity' => 500]], 'idempotency_key' => 'complete-once'])->assertUnprocessable();
        $this->assertDatabaseCount('production_lots', 0);
        $complete = ['idempotency_key' => 'complete-once'];
        $this->postJson("/api/production/batches/$id/complete", $complete)->assertOk()->assertJsonPath('status', 'completed')->assertJsonPath('total_cost', 3000);
        $count = StockMovement::count();
        $this->postJson("/api/production/batches/$id/complete", $complete)->assertOk();
        $this->assertSame($count, StockMovement::count());
        $this->assertSame(1, ProductionLot::count());
        $this->assertSame(7.5, app(StockService::class)->current($shop->id, $input->id));
        $this->patchJson("/api/production/batches/$id", ['name' => 'Illegal edit'])->assertUnprocessable();
    }

    public function test_expired_lots_cannot_be_sold_even_when_aggregate_stock_is_positive(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $this->actingAs($owner, 'sanctum')->withHeaders($this->shopHeader($shop));
        $product = $this->productWithStock($shop, $owner, 1000, 500, 0);
        $id = $this->postJson('/api/production/batches', ['name' => 'QA expired', 'production_date' => now()->subDays(3)->toDateString(), 'expiry_date' => now()->subDay()->toDateString(), 'outputs' => [['product_id' => $product->id, 'quantity' => 2]]])->assertCreated()->json('id');
        $this->postJson("/api/production/batches/$id/complete", [])->assertOk();
        $this->postJson('/api/sales', ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'payments' => [['method' => 'CASH', 'amount' => 1000]]])->assertUnprocessable();
        $this->assertSame(2.0, app(StockService::class)->current($shop->id, $product->id));
        app(StockService::class)->record($product, 1, MovementType::OpeningStock, $owner);
        $this->postJson('/api/sales', ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'payments' => [['method' => 'CASH', 'amount' => 1000]]])->assertCreated();
        $this->assertSame(2.0, app(StockService::class)->current($shop->id, $product->id));
        $this->assertSame(2.0, app(ProductionLotService::class)->remaining(ProductionLot::first()));
    }
}
