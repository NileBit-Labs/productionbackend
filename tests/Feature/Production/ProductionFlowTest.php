<?php

namespace Tests\Feature\Production;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductionLot;
use App\Models\Shop;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ProductionLotService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * The whole manufacturer journey through the API: buy inputs, write a recipe, make a batch,
 * sell what it made, pay expenses and read cost and profit back.
 *
 * Figures (UGX):
 *   Mango 2,000/kg, sugar 4,000/kg, 500ml bottle 300, 1L bottle 500.
 *   Batch uses 42 kg mango + 5 kg sugar = 104,000 materials; 40 + 30 bottles = 27,000 packaging;
 *   labour 20,000; transport 6,000; 2 broken 1L bottles 1,000 -> batch cost 158,000.
 *   Output 40 x 500ml (20 L) + 30 x 1L (30 L) = 50 L, so 500ml gets 20/50 and 1L 30/50:
 *   63,200 (1,580 each) and 94,800 (3,160 each).
 */
class ProductionFlowTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    private User $owner;

    private Shop $shop;

    /** @var array<string, int> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        [$this->owner, $this->shop] = $this->shopWithMember();
    }

    private function api(?User $user = null)
    {
        return $this->actingAs($user ?? $this->owner, 'sanctum')->withHeaders($this->shopHeader($this->shop));
    }

    private function product(string $key, array $attributes): int
    {
        return $this->ids[$key] = $this->api()->postJson('/api/products', $attributes)->assertCreated()->json('id');
    }

    private function stock(string $key): float
    {
        return app(StockService::class)->current($this->shop->id, $this->ids[$key]);
    }

    /** Inputs, finished sizes, a purchase and a recipe: everything up to planning a batch. */
    private function setUpCatalogue(): void
    {
        $this->product('mango', ['name' => 'Mangoes', 'kind' => 'raw_material', 'base_unit' => 'kg', 'low_stock_threshold' => 20]);
        $this->product('sugar', ['name' => 'Sugar', 'kind' => 'raw_material', 'base_unit' => 'kg']);
        $this->product('bottle500', ['name' => '500ml bottle', 'kind' => 'packaging', 'base_unit' => 'pcs']);
        $this->product('bottle1l', ['name' => '1L bottle', 'kind' => 'packaging', 'base_unit' => 'pcs']);
        $this->product('juice500', [
            'name' => 'Mango Juice 500ml', 'kind' => 'finished_good', 'family' => 'Mango Juice', 'size_label' => '500ml',
            'output_equivalent' => 0.5, 'shelf_life_days' => 30, 'base_unit' => 'bottle', 'selling_price' => 3000,
        ]);
        $this->product('juice1l', [
            'name' => 'Mango Juice 1L', 'kind' => 'finished_good', 'family' => 'Mango Juice', 'size_label' => '1L',
            'output_equivalent' => 1, 'shelf_life_days' => 30, 'base_unit' => 'bottle', 'selling_price' => 5500,
        ]);

        $supplier = Supplier::create(['shop_id' => $this->shop->id, 'name' => 'Farm Co-op']);

        $this->api()->postJson('/api/purchases', [
            'supplier_id' => $supplier->id,
            'items' => [
                ['product_id' => $this->ids['mango'], 'quantity' => 100, 'unit_cost' => 2000],
                ['product_id' => $this->ids['sugar'], 'quantity' => 50, 'unit_cost' => 4000],
                ['product_id' => $this->ids['bottle500'], 'quantity' => 200, 'unit_cost' => 300],
                ['product_id' => $this->ids['bottle1l'], 'quantity' => 100, 'unit_cost' => 500],
            ],
        ])->assertCreated();

        $this->ids['recipe'] = $this->api()->postJson('/api/recipes', [
            'name' => 'Mango Juice', 'family' => 'Mango Juice', 'yield_quantity' => 100, 'yield_unit' => 'L',
            'items' => [
                ['product_id' => $this->ids['mango'], 'quantity' => 80],
                ['product_id' => $this->ids['sugar'], 'quantity' => 10],
            ],
        ])->assertCreated()
            // 80 x 2,000 + 10 x 4,000 at today's prices, for 100 L.
            ->assertJsonPath('estimated_cost', 200000)
            ->assertJsonPath('estimated_cost_per_yield_unit', 2000)
            ->json('id');
    }

    /** @return array<string, mixed> */
    private function completion(): array
    {
        return [
            'inputs' => [
                ['product_id' => $this->ids['mango'], 'actual_quantity' => 42],
                ['product_id' => $this->ids['sugar'], 'actual_quantity' => 5],
                ['product_id' => $this->ids['bottle500'], 'actual_quantity' => 40],
                ['product_id' => $this->ids['bottle1l'], 'actual_quantity' => 30],
            ],
            'outputs' => [
                ['product_id' => $this->ids['juice500'], 'quantity' => 40],
                ['product_id' => $this->ids['juice1l'], 'quantity' => 30],
            ],
            'wastage' => [
                ['product_id' => $this->ids['bottle1l'], 'quantity' => 2, 'reason' => 'Cracked on the filling line'],
            ],
            'direct_expenses' => [
                ['type' => 'direct_labour', 'category' => 'Production labour', 'amount' => 20000],
                ['type' => 'direct_production', 'category' => 'Raw material transport', 'amount' => 6000],
            ],
        ];
    }

    private function planBatch(): int
    {
        return $this->api()->postJson('/api/production/batches', ['recipe_id' => $this->ids['recipe'], 'planned_yield' => 50])
            ->assertCreated()
            ->assertJsonPath('batch_number', 'B-000001')
            ->assertJsonPath('status', 'draft')
            ->assertJsonPath('name', 'Mango Juice')
            // The 100 L recipe is halved for a 50 L batch.
            ->assertJsonPath('inputs.0.planned_quantity', 40)
            ->assertJsonPath('inputs.1.planned_quantity', 5)
            ->json('id');
    }

    public function test_a_manufacturer_buys_produces_sells_and_sees_real_cost_and_profit(): void
    {
        $this->setUpCatalogue();
        $batchId = $this->planBatch();

        // Planning moves no stock.
        $this->assertSame(100.0, $this->stock('mango'));

        $batch = $this->api()->postJson("/api/production/batches/{$batchId}/complete", $this->completion())->assertOk();

        $batch->assertJsonPath('status', 'completed')
            ->assertJsonPath('costs.materials', 104000)
            ->assertJsonPath('costs.packaging', 27000)
            ->assertJsonPath('costs.direct_labour', 20000)
            ->assertJsonPath('costs.direct_expenses', 6000)
            ->assertJsonPath('costs.wastage', 1000)
            ->assertJsonPath('costs.total', 158000)
            ->assertJsonPath('output_quantity', 50)
            ->assertJsonPath('yield_percent', 100)
            ->assertJsonPath('cost_per_yield_unit', 3160)
            ->assertJsonPath('inputs.0.variance', 2)
            ->assertJsonPath('outputs.0.allocated_cost', 63200)
            ->assertJsonPath('outputs.0.unit_cost', 1580)
            ->assertJsonPath('outputs.1.allocated_cost', 94800)
            ->assertJsonPath('outputs.1.unit_cost', 3160)
            ->assertJsonPath('outputs.1.unit_margin', 5500 - 3160)
            ->assertJsonPath('outputs.0.expiry_date', now('Africa/Kampala')->addDays(30)->toDateString())
            ->assertJsonCount(1, 'wastage')
            ->assertJsonCount(2, 'expenses');

        // Inputs went down, finished stock went up, and the finished goods now carry their real cost.
        $this->assertSame(58.0, $this->stock('mango'));
        $this->assertSame(45.0, $this->stock('sugar'));
        $this->assertSame(160.0, $this->stock('bottle500'));
        $this->assertSame(68.0, $this->stock('bottle1l'));
        $this->assertSame(40.0, $this->stock('juice500'));
        $this->assertSame(30.0, $this->stock('juice1l'));
        $this->assertSame(3160, Product::find($this->ids['juice1l'])->current_cost);
        $this->assertSame(2, ProductionLot::where('production_batch_id', $batchId)->count());
        $this->assertSame(30.0, app(ProductionLotService::class)->remaining(ProductionLot::where('production_batch_id', $batchId)->where('product_id', $this->ids['juice1l'])->firstOrFail()));

        $this->assertSame(4, StockMovement::where('reference_id', $batchId)->where('movement_type', 'PRODUCTION_INPUT')->count());
        $this->assertSame(2, StockMovement::where('reference_id', $batchId)->where('movement_type', 'PRODUCTION_OUTPUT')->count());
        $this->assertTrue(AuditLog::where('action', 'production.complete')->where('entity_id', $batchId)->exists());

        // Finished goods are on the till; inputs are not.
        $pos = collect($this->api()->getJson('/api/pos/products')->assertOk()->json())->pluck('id');
        $this->assertTrue($pos->contains($this->ids['juice1l']));
        $this->assertFalse($pos->contains($this->ids['mango']));

        // Sell 10 x 1L through the inherited POS flow.
        $this->api()->postJson('/api/sales', [
            'items' => [['product_id' => $this->ids['juice1l'], 'quantity' => 10]],
            'payments' => [['method' => 'CASH', 'amount' => 55000]],
        ])->assertCreated();
        $this->assertSame(20.0, app(ProductionLotService::class)->remaining(ProductionLot::where('production_batch_id', $batchId)->where('product_id', $this->ids['juice1l'])->firstOrFail()));

        // An operating expense and some fruit that went bad in the store.
        $this->api()->postJson('/api/expenses', ['category' => 'Electricity', 'amount' => 5000, 'expense_date' => $this->shop->today()])->assertCreated();
        $this->api()->postJson('/api/wastage', ['product_id' => $this->ids['mango'], 'quantity' => 3, 'reason' => 'Rotten in storage'])
            ->assertCreated()->assertJsonPath('stage', 'raw_material')->assertJsonPath('total_cost', 6000);

        // Revenue 55,000 - COGS 31,600 = 23,400 gross; less 5,000 operating and 6,000 wastage = 12,400 net.
        // The 26,000 of direct batch expenses is in COGS, not counted a second time as an operating expense.
        $this->api()->getJson('/api/reports/profit')->assertOk()
            ->assertJsonPath('summary.net_sales', 55000)
            ->assertJsonPath('summary.cost_of_goods', 31600)
            ->assertJsonPath('summary.gross_profit', 23400)
            ->assertJsonPath('summary.expenses', 5000)
            ->assertJsonPath('summary.operating_expenses', 5000)
            ->assertJsonPath('summary.wastage_losses', 6000)
            ->assertJsonPath('summary.net_profit', 12400);

        $this->api()->getJson('/api/reports/production')->assertOk()
            ->assertJsonPath('summary.batches', 1)
            ->assertJsonPath('summary.total_cost', 158000)
            ->assertJsonPath('summary.wastage_cost', 7000)
            ->assertJsonPath('wastage.standalone_cost', 6000)
            ->assertJsonPath('wastage.in_batches_cost', 1000)
            ->assertJsonPath('products.0.name', 'Mango Juice 1L')
            ->assertJsonPath('products.0.average_unit_cost', 3160)
            ->assertJsonPath('stock.finished_good.units', 60)
            ->assertJsonPath('stock.raw_material.value_at_cost', 55 * 2000 + 45 * 4000);

        $this->api()->getJson('/api/expenses')->assertOk()
            ->assertJsonPath('total', 31000)
            ->assertJsonPath('operating_total', 5000)
            ->assertJsonPath('direct_total', 26000);

        $this->api()->getJson('/api/reports/dashboard')->assertOk()
            ->assertJsonPath('production.batches_today', 1)
            ->assertJsonPath('production.production_cost_today', 158000);

        // The finished product traces back to its batch.
        $this->api()->getJson('/api/production/batches?product_id='.$this->ids['juice1l'])->assertOk()
            ->assertJsonPath('data.0.batch_number', 'B-000001');
    }

    public function test_a_batch_cannot_use_more_than_is_in_stock_and_nothing_moves(): void
    {
        $this->setUpCatalogue();
        $batchId = $this->planBatch();

        $payload = $this->completion();
        $payload['inputs'][0]['actual_quantity'] = 150;

        $this->api()->postJson("/api/production/batches/{$batchId}/complete", $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('inputs');

        $this->assertSame(100.0, $this->stock('mango'));
        $this->assertSame(0.0, $this->stock('juice1l'));
        $this->assertDatabaseHas('production_batches', ['id' => $batchId, 'status' => 'draft']);
        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_several_sizes_need_an_output_equivalent_to_share_the_cost(): void
    {
        $this->setUpCatalogue();
        Product::whereKey($this->ids['juice500'])->update(['output_equivalent' => null]);
        $batchId = $this->planBatch();

        $this->api()->postJson("/api/production/batches/{$batchId}/complete", $this->completion())
            ->assertUnprocessable()->assertJsonValidationErrors('outputs.0.output_equivalent');

        // It can also be given on the batch itself.
        $payload = $this->completion();
        $payload['outputs'][0]['output_equivalent'] = 0.5;

        $this->api()->postJson("/api/production/batches/{$batchId}/complete", $payload)->assertOk()
            ->assertJsonPath('outputs.0.unit_cost', 1580);
    }

    public function test_only_finished_goods_can_be_outputs_and_inputs_cannot_be_sold(): void
    {
        $this->setUpCatalogue();
        $batchId = $this->planBatch();

        $payload = $this->completion();
        $payload['outputs'] = [['product_id' => $this->ids['sugar'], 'quantity' => 5]];

        $this->api()->postJson("/api/production/batches/{$batchId}/complete", $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('outputs.0.product_id');

        $this->api()->postJson('/api/sales', [
            'items' => [['product_id' => $this->ids['mango'], 'quantity' => 1]],
            'payments' => [['method' => 'CASH', 'amount' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors('items.0.product_id');
    }

    public function test_a_completed_batch_is_final_but_can_be_cancelled_while_its_output_is_unsold(): void
    {
        $this->setUpCatalogue();
        $batchId = $this->planBatch();
        $this->api()->postJson("/api/production/batches/{$batchId}/complete", $this->completion())->assertOk();

        $this->api()->patchJson("/api/production/batches/{$batchId}", ['notes' => 'late edit'])->assertUnprocessable();
        $this->api()->postJson("/api/production/batches/{$batchId}/complete", $this->completion())->assertUnprocessable();

        $this->api()->postJson("/api/production/batches/{$batchId}/cancel", ['reason' => 'Entered twice'])->assertOk()
            ->assertJsonPath('status', 'cancelled');

        // Everything is back where it was before the batch, including the 1L bottles that broke.
        $this->assertSame(100.0, $this->stock('mango'));
        $this->assertSame(100.0, $this->stock('bottle1l'));
        $this->assertSame(0.0, $this->stock('juice1l'));
        $this->assertSame(0, Product::find($this->ids['juice1l'])->current_cost);
        $this->assertSame(2000, Product::find($this->ids['mango'])->current_cost);

        // The money was still spent, so its expenses become ordinary operating ones.
        $this->api()->getJson('/api/expenses')->assertJsonPath('operating_total', 26000)->assertJsonPath('direct_total', 0);
        $this->api()->getJson('/api/reports/production')->assertJsonPath('summary.batches', 0)->assertJsonPath('summary.wastage_cost', 0);
    }

    public function test_a_batch_whose_output_has_been_sold_cannot_be_cancelled(): void
    {
        $this->setUpCatalogue();
        $batchId = $this->planBatch();
        $this->api()->postJson("/api/production/batches/{$batchId}/complete", $this->completion())->assertOk();

        $this->api()->postJson('/api/sales', [
            'items' => [['product_id' => $this->ids['juice500'], 'quantity' => 1]],
            'payments' => [['method' => 'CASH', 'amount' => 3000]],
        ])->assertCreated();

        $this->api()->postJson("/api/production/batches/{$batchId}/cancel", ['reason' => 'Mistake'])
            ->assertUnprocessable()->assertJsonValidationErrors('batch');
        $this->assertSame(39.0, $this->stock('juice500'));
    }

    public function test_a_second_batch_blends_into_the_existing_unit_cost(): void
    {
        $this->setUpCatalogue();

        $first = $this->planBatch();
        $this->api()->postJson("/api/production/batches/{$first}/complete", [
            'inputs' => [['product_id' => $this->ids['mango'], 'actual_quantity' => 10]],
            'outputs' => [['product_id' => $this->ids['juice1l'], 'quantity' => 10]],
        ])->assertOk()->assertJsonPath('outputs.0.unit_cost', 2000);

        $second = $this->api()->postJson('/api/production/batches', ['recipe_id' => $this->ids['recipe']])->assertCreated()->json('id');
        $this->api()->postJson("/api/production/batches/{$second}/complete", [
            'inputs' => [['product_id' => $this->ids['mango'], 'actual_quantity' => 40]],
            'outputs' => [['product_id' => $this->ids['juice1l'], 'quantity' => 20]],
        ])->assertOk()->assertJsonPath('outputs.0.unit_cost', 4000)->assertJsonPath('batch_number', 'B-000002');

        // (10 x 2,000 + 20 x 4,000) / 30
        $this->assertSame(3333, Product::find($this->ids['juice1l'])->current_cost);
    }

    public function test_direct_expenses_can_be_allocated_to_a_draft_batch_but_not_a_finished_one(): void
    {
        $this->setUpCatalogue();
        $batchId = $this->planBatch();

        $this->api()->postJson('/api/expenses', [
            'category' => 'Production labour', 'type' => 'direct_labour', 'amount' => 15000,
            'expense_date' => $this->shop->today(), 'production_batch_id' => $batchId,
        ])->assertCreated()->assertJsonPath('type', 'direct_labour');

        // Overhead is never put on a batch without being called a direct cost.
        $this->api()->postJson('/api/expenses', [
            'category' => 'Rent', 'amount' => 1000, 'expense_date' => $this->shop->today(), 'production_batch_id' => $batchId,
        ])->assertUnprocessable()->assertJsonValidationErrors('production_batch_id');

        $this->api()->postJson('/api/expenses', [
            'category' => 'Production labour', 'type' => 'direct_labour', 'amount' => 1000, 'expense_date' => $this->shop->today(),
        ])->assertUnprocessable()->assertJsonValidationErrors('production_batch_id');

        $this->api()->postJson("/api/production/batches/{$batchId}/complete", [
            'outputs' => [['product_id' => $this->ids['juice1l'], 'quantity' => 50]],
        ])->assertOk()
            // Planned inputs are used when no actuals are given: 40 x 2,000 + 5 x 4,000 + 15,000 labour.
            ->assertJsonPath('costs.direct_labour', 15000)
            ->assertJsonPath('costs.total', 115000)
            ->assertJsonPath('outputs.0.unit_cost', 2300);

        $this->api()->postJson('/api/expenses', [
            'category' => 'Production labour', 'type' => 'direct_labour', 'amount' => 1000,
            'expense_date' => $this->shop->today(), 'production_batch_id' => $batchId,
        ])->assertUnprocessable()->assertJsonValidationErrors('production_batch_id');

        $expenseId = $this->api()->getJson('/api/expenses?type=direct_labour')->json('data.0.id');
        $this->api()->patchJson("/api/expenses/{$expenseId}", ['amount' => 1])->assertUnprocessable();
        $this->api()->patchJson("/api/expenses/{$expenseId}", ['description' => 'Two casuals'])->assertOk();
    }

    public function test_expiring_stock_is_estimated_first_in_first_out(): void
    {
        $this->setUpCatalogue();

        $old = $this->api()->postJson('/api/production/batches', [
            'recipe_id' => $this->ids['recipe'], 'production_date' => now('Africa/Kampala')->subDays(25)->toDateString(),
        ])->json('id');
        $this->api()->postJson("/api/production/batches/{$old}/complete", [
            'inputs' => [['product_id' => $this->ids['mango'], 'actual_quantity' => 10]],
            'outputs' => [['product_id' => $this->ids['juice1l'], 'quantity' => 10]],
        ])->assertOk()->assertJsonPath('outputs.0.expiry_date', now('Africa/Kampala')->addDays(5)->toDateString());

        $new = $this->api()->postJson('/api/production/batches', ['recipe_id' => $this->ids['recipe']])->json('id');
        $this->api()->postJson("/api/production/batches/{$new}/complete", [
            'inputs' => [['product_id' => $this->ids['mango'], 'actual_quantity' => 10]],
            'outputs' => [['product_id' => $this->ids['juice1l'], 'quantity' => 10]],
        ])->assertOk();

        // 4 of the 20 sold leaves 16: the newest 10 are the new batch, so 6 of the old batch remain.
        $this->api()->postJson('/api/sales', [
            'items' => [['product_id' => $this->ids['juice1l'], 'quantity' => 4]],
            'payments' => [['method' => 'CASH', 'amount' => 22000]],
        ])->assertCreated();

        $this->api()->getJson('/api/reports/production')->assertOk()
            ->assertJsonCount(1, 'expiring')
            ->assertJsonPath('expiring.0.batch_number', 'B-000001')
            ->assertJsonPath('expiring.0.estimated_remaining', 6)
            ->assertJsonPath('expiring.0.days_left', 5);
    }

    public function test_cashiers_cannot_see_or_run_production(): void
    {
        $this->setUpCatalogue();
        [$cashier] = $this->shopWithMember(Role::Cashier, $this->shop);

        foreach (['/api/recipes', '/api/production/batches', '/api/wastage', '/api/reports/production', '/api/measurement-units'] as $path) {
            $this->api($cashier)->getJson($path)->assertForbidden();
        }

        $this->api($cashier)->postJson('/api/production/batches', ['recipe_id' => $this->ids['recipe']])->assertForbidden();
        $this->api($cashier)->getJson('/api/reports/dashboard')->assertOk()->assertJsonMissingPath('production');
    }

    public function test_batches_and_recipes_of_another_shop_are_invisible(): void
    {
        $this->setUpCatalogue();
        $batchId = $this->planBatch();
        [$other, $otherShop] = $this->shopWithMember();
        $foreign = fn () => $this->actingAs($other, 'sanctum')->withHeaders($this->shopHeader($otherShop));

        $foreign()->getJson("/api/production/batches/{$batchId}")->assertNotFound();
        $foreign()->postJson("/api/production/batches/{$batchId}/complete", $this->completion())->assertNotFound();
        $foreign()->getJson("/api/recipes/{$this->ids['recipe']}")->assertNotFound();
        $foreign()->postJson('/api/production/batches', ['recipe_id' => $this->ids['recipe']])
            ->assertUnprocessable()->assertJsonValidationErrors('recipe_id');
        $foreign()->postJson('/api/recipes', [
            'name' => 'Stolen', 'yield_quantity' => 1, 'yield_unit' => 'L',
            'items' => [['product_id' => $this->ids['mango'], 'quantity' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors('items.0.product_id');
    }

    public function test_planning_the_same_batch_twice_with_one_key_creates_it_once(): void
    {
        $this->setUpCatalogue();
        $payload = ['recipe_id' => $this->ids['recipe'], 'idempotency_key' => 'plan-1'];

        $first = $this->api()->postJson('/api/production/batches', $payload)->assertCreated()->json('id');
        $this->api()->postJson('/api/production/batches', $payload)->assertOk()->assertJsonPath('id', $first);
        $this->assertDatabaseCount('production_batches', 1);
    }

    public function test_sale_spans_lots_fefo_and_refund_restores_the_original_lots(): void
    {
        $this->setUpCatalogue();
        $first = $this->planBatch();
        $this->api()->postJson("/api/production/batches/{$first}/complete", [
            'inputs' => [['product_id' => $this->ids['mango'], 'actual_quantity' => 10]],
            'outputs' => [['product_id' => $this->ids['juice1l'], 'quantity' => 5]],
        ])->assertOk();
        $second = $this->api()->postJson('/api/production/batches', ['recipe_id' => $this->ids['recipe']])->json('id');
        $this->api()->postJson("/api/production/batches/{$second}/complete", [
            'inputs' => [['product_id' => $this->ids['mango'], 'actual_quantity' => 10]],
            'outputs' => [['product_id' => $this->ids['juice1l'], 'quantity' => 5]],
        ])->assertOk();

        $sale = $this->api()->postJson('/api/sales', [
            'items' => [['product_id' => $this->ids['juice1l'], 'quantity' => 7]],
            'payments' => [['method' => 'CASH', 'amount' => 38500]],
        ])->assertCreated()->json();
        $lots = ProductionLot::whereIn('production_batch_id', [$first, $second])->orderBy('production_batch_id')->get();
        $service = app(ProductionLotService::class);
        $this->assertSame(0.0, $service->remaining($lots[0]));
        $this->assertSame(3.0, $service->remaining($lots[1]));

        $this->api()->postJson("/api/sales/{$sale['id']}/refund", [
            'lines' => [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 7, 'restock' => true]],
            'method' => 'CASH', 'reason' => 'Returned unopened bottles',
        ])->assertCreated();
        $this->assertSame(5.0, $service->remaining($lots[0]->fresh()));
        $this->assertSame(5.0, $service->remaining($lots[1]->fresh()));
    }

    public function test_separate_partial_refunds_restore_source_lots_without_overfilling_the_first_lot(): void
    {
        $this->setUpCatalogue();
        $first = $this->planBatch();
        $this->api()->postJson("/api/production/batches/{$first}/complete", [
            'inputs' => [['product_id' => $this->ids['mango'], 'actual_quantity' => 10]],
            'outputs' => [['product_id' => $this->ids['juice1l'], 'quantity' => 5]],
        ])->assertOk();
        $second = $this->api()->postJson('/api/production/batches', ['recipe_id' => $this->ids['recipe']])->json('id');
        $this->api()->postJson("/api/production/batches/{$second}/complete", [
            'inputs' => [['product_id' => $this->ids['mango'], 'actual_quantity' => 10]],
            'outputs' => [['product_id' => $this->ids['juice1l'], 'quantity' => 5]],
        ])->assertOk();

        $sale = $this->api()->postJson('/api/sales', [
            'items' => [['product_id' => $this->ids['juice1l'], 'quantity' => 7]],
            'payments' => [['method' => 'CASH', 'amount' => 38500]],
        ])->assertCreated()->json();
        $lots = ProductionLot::whereIn('production_batch_id', [$first, $second])->orderBy('production_batch_id')->get();
        $service = app(ProductionLotService::class);
        $this->assertSame(0.0, $service->remaining($lots[0]));
        $this->assertSame(3.0, $service->remaining($lots[1]));

        $this->api()->postJson("/api/sales/{$sale['id']}/refund", [
            'lines' => [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 3, 'restock' => true]],
            'method' => 'CASH', 'reason' => 'Returned unopened bottles',
        ])->assertCreated();
        $this->assertSame(1.0, $service->remaining($lots[0]->fresh()));
        $this->assertSame(5.0, $service->remaining($lots[1]->fresh()));
        $this->api()->postJson("/api/sales/{$sale['id']}/refund", [
            'lines' => [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 4, 'restock' => true]],
            'method' => 'CASH', 'reason' => 'Second partial return',
        ])->assertCreated();
        $this->assertSame(5.0, $service->remaining($lots[0]->fresh()));
        $this->assertSame(5.0, $service->remaining($lots[1]->fresh()));
    }

    public function test_completion_key_replays_without_double_consuming_or_creating_lots(): void
    {
        $this->setUpCatalogue();
        $batch = $this->planBatch();
        $payload = [
            'idempotency_key' => 'completion-1',
            'inputs' => [['product_id' => $this->ids['mango'], 'actual_quantity' => 10]],
            'outputs' => [['product_id' => $this->ids['juice1l'], 'quantity' => 10]],
        ];
        $this->api()->postJson("/api/production/batches/{$batch}/complete", $payload)->assertOk();
        $this->api()->postJson("/api/production/batches/{$batch}/complete", $payload)->assertOk();
        $this->assertSame(90.0, $this->stock('mango'));
        $this->assertSame(10.0, $this->stock('juice1l'));
        $this->assertSame(1, ProductionLot::where('production_batch_id', $batch)->count());
    }

    public function test_cancelling_batch_a_cannot_use_batch_b_stock_after_a_sale(): void
    {
        $this->setUpCatalogue();
        $a = $this->planBatch();
        $this->api()->postJson("/api/production/batches/{$a}/complete", [
            'inputs' => [['product_id' => $this->ids['mango'], 'actual_quantity' => 10]],
            'outputs' => [['product_id' => $this->ids['juice1l'], 'quantity' => 10]],
        ])->assertOk();
        $this->api()->postJson('/api/sales', [
            'items' => [['product_id' => $this->ids['juice1l'], 'quantity' => 8]], 'payments' => [['method' => 'CASH', 'amount' => 44000]],
        ])->assertCreated();
        $b = $this->api()->postJson('/api/production/batches', ['recipe_id' => $this->ids['recipe']])->json('id');
        $this->api()->postJson("/api/production/batches/{$b}/complete", [
            'inputs' => [['product_id' => $this->ids['mango'], 'actual_quantity' => 10]],
            'outputs' => [['product_id' => $this->ids['juice1l'], 'quantity' => 10]],
        ])->assertOk();
        $this->api()->postJson("/api/production/batches/{$a}/cancel", ['reason' => 'Incorrect run'])
            ->assertUnprocessable()->assertJsonValidationErrors('batch');
        $this->assertSame(12.0, $this->stock('juice1l'));
    }
}
