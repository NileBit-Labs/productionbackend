<?php

namespace Database\Seeders;

use App\Enums\ExpenseType;
use App\Enums\Role;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Organization;
use App\Models\Shop;
use App\Models\Supplier;
use App\Models\User;
use App\Models\UserShopRole;
use App\Services\InventoryService;
use App\Services\ProductionService;
use App\Services\ProductService;
use App\Services\PurchaseService;
use App\Services\RecipeService;
use App\Services\SaleService;
use App\Services\WastageService;
use App\Support\ShopDefaults;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * A juice manufacturer that has been running for two weeks: suppliers, raw material and
 * packaging purchases, recipes, completed batches in three sizes, sales (cash and credit),
 * direct and operating expenses, and some wastage. Everything goes through the same services
 * as the API, so stock, costs and reports are exactly what the app would produce.
 *
 *   php artisan db:seed --class=ProductionDemoSeeder
 *
 * Log in as demo@production.nilebitlabs.com with DEMO_PASSWORD (default "demo-password").
 * Refuses to run in production unless ALLOW_DEMO_SEED=true, and does nothing if the demo
 * owner already exists.
 */
class ProductionDemoSeeder extends Seeder
{
    public const EMAIL = 'demo@production.nilebitlabs.com';

    public function run(
        ProductService $products,
        PurchaseService $purchases,
        RecipeService $recipes,
        ProductionService $production,
        SaleService $sales,
        WastageService $wastage,
        InventoryService $inventory,
    ): void {
        if (app()->environment('production') && ! filter_var(env('ALLOW_DEMO_SEED', false), FILTER_VALIDATE_BOOLEAN)) {
            $this->command?->warn('Skipping the production demo in the production environment (set ALLOW_DEMO_SEED=true to allow).');

            return;
        }

        if (User::where('email', self::EMAIL)->exists()) {
            $this->command?->info('Production demo already seeded.');

            return;
        }

        DB::transaction(function () use ($products, $purchases, $recipes, $production, $sales, $wastage, $inventory) {
            [$owner, $shop] = $this->business();
            $today = CarbonImmutable::parse($shop->today());
            $p = fn (array $data) => $products->create($shop, $owner, $data)->id;

            ShopDefaults::units($shop);
            ShopDefaults::expenseCategories($shop);

            // Inputs. Raw materials and packaging have no selling price: they are never sold.
            $ids = [
                'mango' => $p(['name' => 'Mangoes', 'kind' => 'raw_material', 'base_unit' => 'kg', 'low_stock_threshold' => 40]),
                'passion' => $p(['name' => 'Passion fruit', 'kind' => 'raw_material', 'base_unit' => 'kg', 'low_stock_threshold' => 30]),
                'sugar' => $p(['name' => 'Sugar', 'kind' => 'raw_material', 'base_unit' => 'kg', 'low_stock_threshold' => 25]),
                'citric' => $p(['name' => 'Citric acid (preservative)', 'kind' => 'raw_material', 'base_unit' => 'kg', 'low_stock_threshold' => 1]),
                'water' => $p(['name' => 'Treated water', 'kind' => 'raw_material', 'base_unit' => 'L']),
                'b300' => $p(['name' => 'Bottle 300ml', 'kind' => 'packaging', 'base_unit' => 'pcs', 'low_stock_threshold' => 150]),
                'b500' => $p(['name' => 'Bottle 500ml', 'kind' => 'packaging', 'base_unit' => 'pcs', 'low_stock_threshold' => 150]),
                'b1l' => $p(['name' => 'Bottle 1L', 'kind' => 'packaging', 'base_unit' => 'pcs', 'low_stock_threshold' => 80]),
                'cap' => $p(['name' => 'Bottle cap', 'kind' => 'packaging', 'base_unit' => 'pcs', 'low_stock_threshold' => 300]),
                'label' => $p(['name' => 'Label', 'kind' => 'packaging', 'base_unit' => 'pcs', 'low_stock_threshold' => 300]),
            ];

            // Finished goods: two product lines in three sizes. Output equivalent is litres per bottle.
            foreach (['Mango Juice' => 'mj', 'Passion Juice' => 'pj'] as $family => $code) {
                foreach ([['300ml', 0.3, 2000], ['500ml', 0.5, 3000], ['1L', 1, 5500]] as [$size, $litres, $price]) {
                    $ids["{$code}{$size}"] = $p([
                        'name' => "{$family} {$size}", 'kind' => 'finished_good', 'family' => $family, 'size_label' => $size,
                        'output_equivalent' => $litres, 'shelf_life_days' => 21, 'base_unit' => 'bottle',
                        'selling_price' => $family === 'Passion Juice' ? $price + 500 : $price, 'low_stock_threshold' => 24,
                    ]);
                }
            }

            $farm = Supplier::create(['shop_id' => $shop->id, 'name' => 'Luwero Fruit Farmers', 'phone' => '+256700000101']);
            $sugarCo = Supplier::create(['shop_id' => $shop->id, 'name' => 'Kakira Sugar Depot', 'phone' => '+256700000102']);
            $packCo = Supplier::create(['shop_id' => $shop->id, 'name' => 'Kampala Packaging Ltd', 'phone' => '+256700000103']);

            $buy = fn (Supplier $supplier, int $daysAgo, array $items, int $paid) => $purchases->receive($shop, $owner, [
                'supplier_id' => $supplier->id,
                'purchase_date' => $today->subDays($daysAgo)->toDateString(),
                'items' => array_map(fn ($i) => ['product_id' => $ids[$i[0]], 'quantity' => $i[1], 'unit_cost' => $i[2]], $items),
                'amount_paid' => $paid,
                'payment_method' => 'MOBILE_MONEY',
            ]);

            $buy($farm, 14, [['mango', 300, 1800], ['passion', 150, 3500]], 500000);
            $buy($sugarCo, 14, [['sugar', 100, 4200], ['citric', 5, 18000]], 510000);
            $buy($packCo, 13, [['b300', 600, 250], ['b500', 600, 320], ['b1l', 300, 480], ['cap', 1500, 40], ['label', 1500, 60]], 400000);
            $buy($farm, 6, [['mango', 200, 2000]], 400000);

            $mango = $recipes->create($shop, $owner, [
                'name' => 'Mango Juice', 'family' => 'Mango Juice', 'yield_quantity' => 100, 'yield_unit' => 'L',
                'instructions' => 'Wash, peel and pulp. Blend with water and sugar, add citric acid, pasteurise at 85°C, fill hot.',
                'items' => [
                    ['product_id' => $ids['mango'], 'quantity' => 60],
                    ['product_id' => $ids['sugar'], 'quantity' => 8],
                    ['product_id' => $ids['citric'], 'quantity' => 0.2],
                    ['product_id' => $ids['water'], 'quantity' => 55],
                ],
            ]);

            $passion = $recipes->create($shop, $owner, [
                'name' => 'Passion Juice', 'family' => 'Passion Juice', 'yield_quantity' => 100, 'yield_unit' => 'L',
                'items' => [
                    ['product_id' => $ids['passion'], 'quantity' => 35],
                    ['product_id' => $ids['sugar'], 'quantity' => 10],
                    ['product_id' => $ids['citric'], 'quantity' => 0.2],
                    ['product_id' => $ids['water'], 'quantity' => 75],
                ],
            ]);

            // Water comes from the tap, so it is stocked once rather than bought.
            $inventory->openingStock($shop, $owner, $ids['water'], 2000, 5, null);

            $runs = [
                // days ago, recipe, code, litres, [300ml, 500ml, 1L], extra mango/passion used, labour, transport
                [12, $mango, 'mj', 100, [100, 60, 40], 3, 40000, 15000],
                [9, $passion, 'pj', 80, [80, 50, 26], 0, 35000, 10000],
                [5, $mango, 'mj', 120, [120, 80, 44], 5, 45000, 15000],
                [2, $passion, 'pj', 60, [60, 40, 18], 1, 30000, 8000],
            ];

            foreach ($runs as [$daysAgo, $recipe, $code, $litres, $bottles, $extra, $labour, $transport]) {
                $batch = $production->plan($shop, $owner, [
                    'recipe_id' => $recipe->id, 'planned_yield' => $litres,
                    'production_date' => $today->subDays($daysAgo)->toDateString(),
                ])['batch'];

                $fruit = $code === 'mj' ? 'mango' : 'passion';
                $inputs = $batch->inputs()->get()->map(fn ($i) => [
                    'product_id' => $i->product_id,
                    'actual_quantity' => $i->product_id === $ids[$fruit] ? $i->planned_quantity + $extra : $i->planned_quantity,
                ])->all();
                $count = array_sum($bottles);

                $production->complete($shop, $owner, $batch->id, [
                    'inputs' => array_merge($inputs, [
                        ['product_id' => $ids['b300'], 'actual_quantity' => $bottles[0]],
                        ['product_id' => $ids['b500'], 'actual_quantity' => $bottles[1]],
                        ['product_id' => $ids['b1l'], 'actual_quantity' => $bottles[2]],
                        ['product_id' => $ids['cap'], 'actual_quantity' => $count],
                        ['product_id' => $ids['label'], 'actual_quantity' => $count],
                    ]),
                    'outputs' => [
                        ['product_id' => $ids["{$code}300ml"], 'quantity' => $bottles[0]],
                        ['product_id' => $ids["{$code}500ml"], 'quantity' => $bottles[1]],
                        ['product_id' => $ids["{$code}1L"], 'quantity' => $bottles[2]],
                    ],
                    'wastage' => [['product_id' => $ids['b500'], 'quantity' => 2, 'reason' => 'Bottles cracked during hot fill']],
                    'direct_expenses' => [
                        ['type' => ExpenseType::DirectLabour->value, 'category' => 'Production labour', 'amount' => $labour, 'description' => 'Casual workers'],
                        ['type' => ExpenseType::DirectProduction->value, 'category' => 'Raw material transport', 'amount' => $transport],
                    ],
                ]);
            }

            // One draft batch waiting to be made tomorrow.
            $production->plan($shop, $owner, ['recipe_id' => $mango->id, 'planned_yield' => 80, 'notes' => 'For the Friday supermarket order']);

            // Sales through the inherited POS, one on credit.
            $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Mama Rose Supermarket', 'phone' => '+256700000201']);
            $sell = fn (array $lines, ?int $customerId = null, bool $pay = true) => $sales->create($shop, $owner, [
                'customer_id' => $customerId,
                'items' => array_map(fn ($l) => ['product_id' => $ids[$l[0]], 'quantity' => $l[1]], $lines),
                'payments' => $pay ? [['method' => 'CASH', 'amount' => array_sum(array_map(fn ($l) => $l[1] * $l[2], $lines))]] : [],
            ]);

            $sell([['mj300ml', 40, 2000], ['mj500ml', 24, 3000], ['mj1L', 12, 5500]]);
            $sell([['pj300ml', 30, 2500], ['pj500ml', 20, 3500], ['pj1L', 6, 6000]]);
            $sell([['mj1L', 24, 5500], ['pj1L', 12, 6000]], $customer->id, pay: false);

            // Overheads, and fruit that spoiled before it could be used.
            foreach ([['Electricity', 180000, 10], ['Water', 45000, 10], ['Delivery', 60000, 3], ['Rent', 600000, 14], ['Marketing', 50000, 7]] as [$category, $amount, $daysAgo]) {
                Expense::create([
                    'shop_id' => $shop->id, 'category' => $category, 'type' => ExpenseType::Operating, 'amount' => $amount,
                    'expense_date' => $today->subDays($daysAgo)->toDateString(), 'recorded_by' => $owner->id,
                ]);
            }

            $wastage->record($shop, $owner, ['product_id' => $ids['mango'], 'quantity' => 12, 'reason' => 'Over-ripe, rotten in storage', 'wastage_date' => $today->subDays(4)->toDateString()]);
            $wastage->record($shop, $owner, ['product_id' => $ids['mj500ml'], 'quantity' => 3, 'reason' => 'Dropped crate', 'wastage_date' => $today->subDay()->toDateString()]);
        });

        $this->command?->info('Production demo ready: '.self::EMAIL);
    }

    /** @return array{0: User, 1: Shop} */
    private function business(): array
    {
        $owner = User::create([
            'name' => 'Demo Owner',
            'email' => self::EMAIL,
            'password' => env('DEMO_PASSWORD', 'demo-password'),
        ]);

        $organization = Organization::create(['name' => 'Nile Fresh Juices', 'owner_user_id' => $owner->id, 'timezone' => 'Africa/Kampala']);
        $owner->update(['organization_id' => $organization->id]);

        $shop = Shop::create([
            'organization_id' => $organization->id,
            'name' => 'Nile Fresh Juices - Factory',
            'business_type' => 'juice_production',
            'phone' => '+256700000100',
            'address' => 'Plot 12, Industrial Area, Kampala',
        ]);

        UserShopRole::create(['user_id' => $owner->id, 'shop_id' => $shop->id, 'role' => Role::Owner]);

        return [$owner, $shop];
    }
}
