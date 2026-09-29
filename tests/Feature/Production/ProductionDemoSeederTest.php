<?php

namespace Tests\Feature\Production;

use App\Models\ProductionBatch;
use App\Models\User;
use Database\Seeders\ProductionDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_demo_business_has_coherent_production_sales_and_profit(): void
    {
        $this->seed(ProductionDemoSeeder::class);
        $this->seed(ProductionDemoSeeder::class); // a second run changes nothing

        $owner = User::where('email', ProductionDemoSeeder::EMAIL)->firstOrFail();
        $shop = $owner->shopRoles()->firstOrFail()->shop;
        $api = fn () => $this->actingAs($owner, 'sanctum')->withHeaders(['X-Shop-Id' => (string) $shop->id]);

        $this->assertSame(4, ProductionBatch::where('status', 'completed')->count());
        $this->assertSame(1, ProductionBatch::where('status', 'draft')->count());

        $production = $api()->getJson('/api/reports/production?from='.now('Africa/Kampala')->subDays(30)->toDateString())->assertOk();
        $this->assertSame(4, $production->json('summary.batches'));
        $this->assertGreaterThan(0, $production->json('summary.total_cost'));
        $this->assertCount(6, $production->json('products'));

        $profit = $api()->getJson('/api/reports/profit?from='.now('Africa/Kampala')->subDays(30)->toDateString())->assertOk()->json('summary');
        $this->assertGreaterThan(0, $profit['net_sales']);
        $this->assertGreaterThan(0, $profit['cost_of_goods']);
        $this->assertSame($profit['net_sales'] - $profit['cost_of_goods'], $profit['gross_profit']);
        $this->assertSame($profit['gross_profit'] - $profit['operating_expenses'] - $profit['wastage_losses'], $profit['net_profit']);
        $this->assertSame(935000, $profit['operating_expenses']);

        $api()->getJson('/api/reports/debt')->assertOk();
        $api()->getJson('/api/reports/dashboard')->assertOk()->assertJsonPath('production.drafts', 1);
    }
}
