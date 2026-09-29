<?php

namespace Tests\Feature\Production;

use App\Enums\Role;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class ProductionSetupTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    private function api($user, $shop)
    {
        return $this->actingAs($user, 'sanctum')->withHeaders($this->shopHeader($shop));
    }

    public function test_raw_materials_need_no_selling_price_but_finished_goods_do(): void
    {
        [$owner, $shop] = $this->shopWithMember();

        $this->api($owner, $shop)->postJson('/api/products', ['name' => 'Flour', 'kind' => 'raw_material', 'base_unit' => 'kg'])
            ->assertCreated()->assertJsonPath('kind', 'raw_material')->assertJsonPath('selling_price', 0);

        $this->api($owner, $shop)->postJson('/api/products', ['name' => 'Bread', 'kind' => 'finished_good', 'base_unit' => 'loaf'])
            ->assertUnprocessable()->assertJsonValidationErrors('selling_price');

        // Products made without a kind are sellable, as they always were.
        $this->api($owner, $shop)->postJson('/api/products', ['name' => 'Soda', 'base_unit' => 'bottle', 'selling_price' => 1500])
            ->assertCreated()->assertJsonPath('kind', 'finished_good');

        $this->api($owner, $shop)->postJson('/api/products', ['name' => 'X', 'kind' => 'gizmo', 'base_unit' => 'kg', 'selling_price' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('kind');
    }

    public function test_products_and_inventory_can_be_filtered_by_kind(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $this->productWithStock($shop, $owner, attributes: ['name' => 'Milk', 'kind' => 'raw_material']);
        $this->productWithStock($shop, $owner, attributes: ['name' => 'Tub', 'kind' => 'packaging']);
        $this->productWithStock($shop, $owner, attributes: ['name' => 'Yoghurt 500ml', 'kind' => 'finished_good']);

        $this->api($owner, $shop)->getJson('/api/products?kind=raw_material')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Milk');
        $this->api($owner, $shop)->getJson('/api/products?kind=raw_material,packaging')->assertJsonCount(2, 'data');
        $this->api($owner, $shop)->getJson('/api/products')->assertJsonCount(3, 'data');
        $this->api($owner, $shop)->getJson('/api/inventory?kind=finished_good')->assertJsonPath('summary.items', 1);
        $this->api($owner, $shop)->getJson('/api/pos/products')->assertJsonCount(1);
    }

    public function test_a_shop_starts_with_common_units_and_can_add_its_own(): void
    {
        [$owner, $shop] = $this->shopWithMember();

        $symbols = collect($this->api($owner, $shop)->getJson('/api/measurement-units')->assertOk()->json())->pluck('symbol');
        $this->assertTrue($symbols->contains('kg') && $symbols->contains('ml') && $symbols->contains('pcs'));

        $this->api($owner, $shop)->postJson('/api/measurement-units', ['name' => 'Tray', 'symbol' => 'tray', 'dimension' => 'count'])->assertCreated();
        $this->api($owner, $shop)->postJson('/api/measurement-units', ['name' => 'Kilo', 'symbol' => 'kg'])->assertUnprocessable();

        // A unit something is measured in can't be removed.
        $kg = collect($this->api($owner, $shop)->getJson('/api/measurement-units')->json())->firstWhere('symbol', 'kg');
        $this->productWithStock($shop, $owner, attributes: ['base_unit' => 'kg', 'kind' => 'raw_material']);
        $this->api($owner, $shop)->deleteJson("/api/measurement-units/{$kg['id']}")->assertUnprocessable();

        $tray = collect($this->api($owner, $shop)->getJson('/api/measurement-units')->json())->firstWhere('symbol', 'tray');
        $this->api($owner, $shop)->deleteJson("/api/measurement-units/{$tray['id']}")->assertNoContent();
    }

    public function test_expense_categories_are_configurable_and_carry_a_default_type(): void
    {
        [$owner, $shop] = $this->shopWithMember();

        $list = collect($this->api($owner, $shop)->getJson('/api/expense-categories')->assertOk()->json());
        $this->assertSame('direct_labour', $list->firstWhere('name', 'Production labour')['default_type']);
        $this->assertSame('operating', $list->firstWhere('name', 'Electricity')['default_type']);

        $id = $this->api($owner, $shop)->postJson('/api/expense-categories', ['name' => 'Gas', 'default_type' => 'direct_production'])
            ->assertCreated()->assertJsonPath('is_active', true)->json('id');
        $this->api($owner, $shop)->postJson('/api/expense-categories', ['name' => 'Gas'])->assertUnprocessable();

        $this->api($owner, $shop)->patchJson("/api/expense-categories/{$id}", ['is_active' => false])->assertOk();
        $this->assertNotContains('Gas', $this->api($owner, $shop)->getJson('/api/expenses')->json('categories'));
        $this->assertContains('Gas', collect($this->api($owner, $shop)->getJson('/api/expense-categories?include_inactive=1')->json())->pluck('name'));
    }

    public function test_recipes_are_editable_archivable_and_audited(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $flour = $this->productWithStock($shop, $owner, cost: 3000, attributes: ['name' => 'Flour', 'kind' => 'raw_material', 'base_unit' => 'kg']);
        $yeast = $this->productWithStock($shop, $owner, cost: 20000, attributes: ['name' => 'Yeast', 'kind' => 'raw_material', 'base_unit' => 'kg']);

        $id = $this->api($owner, $shop)->postJson('/api/recipes', [
            'name' => 'White bread', 'yield_quantity' => 40, 'yield_unit' => 'loaf',
            'items' => [['product_id' => $flour->id, 'quantity' => 20]],
        ])->assertCreated()->assertJsonPath('estimated_cost', 60000)->assertJsonPath('estimated_cost_per_yield_unit', 1500)->json('id');

        $this->api($owner, $shop)->postJson('/api/recipes', [
            'name' => 'White bread', 'yield_quantity' => 1, 'yield_unit' => 'loaf', 'items' => [['product_id' => $flour->id, 'quantity' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors('name');

        $this->api($owner, $shop)->postJson('/api/recipes', [
            'name' => 'Twice', 'yield_quantity' => 1, 'yield_unit' => 'loaf',
            'items' => [['product_id' => $flour->id, 'quantity' => 1], ['product_id' => $flour->id, 'quantity' => 2]],
        ])->assertUnprocessable()->assertJsonValidationErrors('items.1.product_id');

        $this->api($owner, $shop)->patchJson("/api/recipes/{$id}", [
            'items' => [['product_id' => $flour->id, 'quantity' => 20], ['product_id' => $yeast->id, 'quantity' => 0.5]],
        ])->assertOk()->assertJsonCount(2, 'items')->assertJsonPath('estimated_cost', 70000);

        $this->assertTrue(AuditLog::where('action', 'recipe.update')->where('entity_id', $id)->exists());

        $this->api($owner, $shop)->postJson("/api/recipes/{$id}/archive")->assertOk()->assertJsonPath('status', 'archived');
        $this->api($owner, $shop)->getJson('/api/recipes')->assertJsonCount(0, 'data');
        $this->api($owner, $shop)->postJson('/api/production/batches', ['recipe_id' => $id])->assertUnprocessable()->assertJsonValidationErrors('recipe_id');

        $this->api($owner, $shop)->postJson("/api/recipes/{$id}/restore")->assertOk()->assertJsonPath('status', 'active');
        $this->api($owner, $shop)->postJson('/api/production/batches', ['recipe_id' => $id, 'planned_yield' => 80])->assertCreated()
            ->assertJsonPath('inputs.0.planned_quantity', 40)
            ->assertJsonPath('inputs.1.planned_quantity', 1)
            ->assertJsonPath('yield_unit', 'loaf');
    }

    public function test_a_draft_can_be_rescaled_and_is_audited(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $flour = $this->productWithStock($shop, $owner, attributes: ['name' => 'Flour', 'kind' => 'raw_material', 'base_unit' => 'kg']);
        $recipe = $this->api($owner, $shop)->postJson('/api/recipes', [
            'name' => 'Bread', 'yield_quantity' => 10, 'yield_unit' => 'loaf', 'items' => [['product_id' => $flour->id, 'quantity' => 5]],
        ])->json('id');

        $batch = $this->api($owner, $shop)->postJson('/api/production/batches', ['recipe_id' => $recipe])->assertJsonPath('inputs.0.planned_quantity', 5)->json('id');

        $this->api($owner, $shop)->patchJson("/api/production/batches/{$batch}", ['planned_yield' => 30])->assertOk()
            ->assertJsonPath('planned_yield', 30)->assertJsonPath('inputs.0.planned_quantity', 15);

        $this->assertTrue(AuditLog::where('action', 'production.update')->where('entity_id', $batch)->exists());

        // A batch without a recipe needs a name.
        $this->api($owner, $shop)->postJson('/api/production/batches', [])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->api($owner, $shop)->postJson('/api/production/batches', ['name' => 'Trial run', 'responsible_user_id' => 999999])
            ->assertUnprocessable()->assertJsonValidationErrors('responsible_user_id');
    }

    public function test_standalone_wastage_leaves_stock_is_idempotent_and_cannot_exceed_stock(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $bottles = $this->productWithStock($shop, $owner, cost: 400, stock: 50, attributes: ['name' => 'Bottle', 'kind' => 'packaging']);
        $payload = ['product_id' => $bottles->id, 'quantity' => 5, 'reason' => 'Crushed in delivery', 'idempotency_key' => 'w-1'];

        $this->api($owner, $shop)->postJson('/api/wastage', $payload)->assertCreated()
            ->assertJsonPath('stage', 'packaging')->assertJsonPath('total_cost', 2000);
        $this->api($owner, $shop)->postJson('/api/wastage', $payload)->assertOk();

        $this->api($owner, $shop)->postJson('/api/wastage', ['product_id' => $bottles->id, 'quantity' => 46, 'reason' => 'Too many'])
            ->assertUnprocessable()->assertJsonValidationErrors('quantity');

        $this->api($owner, $shop)->getJson('/api/inventory/movements?type=WASTAGE')->assertJsonCount(1, 'data')->assertJsonPath('data.0.quantity_delta', -5);
        $this->api($owner, $shop)->getJson('/api/wastage')->assertJsonPath('total_cost', 2000)->assertJsonCount(1, 'records.data');
        $this->assertTrue(AuditLog::where('action', 'wastage.record')->exists());

        [$cashier] = $this->shopWithMember(Role::Cashier, $shop);
        $this->api($cashier, $shop)->postJson('/api/wastage', $payload)->assertForbidden();
    }

    public function test_managers_see_production_on_the_dashboard_without_cost(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        [$manager] = $this->shopWithMember(Role::Manager, $shop);
        $this->productWithStock($shop, $owner, stock: 0, attributes: ['name' => 'Sugar', 'kind' => 'raw_material']);

        $this->api($manager, $shop)->getJson('/api/reports/dashboard')->assertOk()
            ->assertJsonPath('production.low_inputs.0.name', 'Sugar')
            ->assertJsonMissingPath('production.production_cost_today');

        $this->api($owner, $shop)->getJson('/api/reports/dashboard')->assertJsonPath('production.production_cost_today', 0);
    }
}
