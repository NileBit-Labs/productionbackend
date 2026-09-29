<?php

namespace Database\Seeders;

use App\Enums\MovementType;
use App\Models\Category;
use App\Models\Product;
use App\Models\Shop;
use App\Models\StockMovement;
use Illuminate\Database\Seeder;

/**
 * Local-dev demo data: a realistic catalogue with opening stock for every shop
 * that has no products yet. Never run against a real shop's data - it skips
 * shops that already have products, but it is for development only.
 *
 *   php artisan db:seed --class=DemoCatalogSeeder
 */
class DemoCatalogSeeder extends Seeder
{
    /** name, category, base unit, price, cost, stock, low-stock level, barcode, alternate units */
    private const PRODUCTS = [
        ['Sugar 1kg', 'Groceries', 'kg', 4500, 3800, 60, 15, '6001001', [['sack', 50, 210000]]],
        ['Rice 1kg', 'Groceries', 'kg', 5500, 4400, 80, 20, '6001002', [['sack', 25, 130000]]],
        ['Maize Flour 2kg', 'Groceries', 'piece', 7000, 5800, 40, 10, '6001003', []],
        ['Cooking Oil 1L', 'Groceries', 'piece', 8500, 7200, 36, 12, '6001004', [['carton', 12, 96000]]],
        ['Salt 500g', 'Groceries', 'piece', 1000, 700, 50, 15, '6001005', []],
        ['Spaghetti 500g', 'Groceries', 'piece', 3500, 2800, 30, 10, '6001006', []],
        ['Tea Leaves 250g', 'Groceries', 'piece', 4000, 3200, 24, 8, '6001007', []],
        ['Eggs', 'Groceries', 'piece', 500, 380, 120, 30, '6001008', [['tray', 30, 13500]]],
        ['Bread (Large)', 'Groceries', 'piece', 4500, 3800, 20, 6, '6001009', []],
        ['Soda 500ml', 'Beverages', 'piece', 1500, 1100, 96, 24, '6002001', [['crate', 24, 33000]]],
        ['Bottled Water 1.5L', 'Beverages', 'piece', 2000, 1400, 48, 12, '6002002', [['crate', 12, 22000]]],
        ['Fresh Milk 500ml', 'Beverages', 'piece', 1800, 1400, 30, 10, '6002003', []],
        ['Passion Juice 1L', 'Beverages', 'piece', 4500, 3600, 18, 6, '6002004', []],
        ['Laundry Soap Bar', 'Household', 'piece', 3000, 2300, 40, 10, '6003001', []],
        ['Detergent 1kg', 'Household', 'piece', 9000, 7400, 16, 5, '6003002', []],
        ['Toilet Paper (4 pack)', 'Household', 'piece', 6000, 4800, 22, 8, '6003003', []],
        ['Matchbox', 'Household', 'piece', 500, 300, 100, 20, '6003004', []],
        ['Candle', 'Household', 'piece', 700, 450, 60, 15, '6003005', []],
        ['Toothpaste 100ml', 'Personal Care', 'piece', 5500, 4300, 20, 6, '6004001', []],
        ['Bathing Soap', 'Personal Care', 'piece', 2500, 1800, 45, 12, '6004002', []],
        ['Body Lotion 400ml', 'Personal Care', 'piece', 12000, 9500, 12, 4, '6004003', []],
        ['Exercise Book', 'Stationery', 'piece', 1500, 1000, 70, 20, '6005001', []],
        ['Ballpoint Pen', 'Stationery', 'piece', 500, 300, 150, 40, '6005002', []],
        ['Phone Charger', 'Electronics', 'piece', 15000, 10500, 8, 3, '6006001', []],
    ];

    public function run(): void
    {
        foreach (Shop::with('organization')->get() as $shop) {
            if (Product::where('shop_id', $shop->id)->exists()) {
                continue;
            }

            $ownerId = $shop->organization->owner_user_id;
            $categories = [];

            foreach (self::PRODUCTS as [$name, $category, $unit, $price, $cost, $stock, $low, $barcode, $units]) {
                $categories[$category] ??= Category::firstOrCreate(['shop_id' => $shop->id, 'name' => $category]);

                $product = Product::create([
                    'shop_id' => $shop->id,
                    'category_id' => $categories[$category]->id,
                    'name' => $name,
                    'sku' => strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $name), 0, 3)).'-'.substr($barcode, -3),
                    'barcode' => $barcode,
                    'base_unit' => $unit,
                    'selling_price' => $price,
                    'current_cost' => $cost,
                    'low_stock_threshold' => $low,
                ]);

                foreach ($units as [$unitName, $conversion, $unitPrice]) {
                    $product->units()->create([
                        'unit_name' => $unitName,
                        'conversion_to_base_unit' => $conversion,
                        'selling_price' => $unitPrice,
                    ]);
                }

                StockMovement::create([
                    'shop_id' => $shop->id,
                    'product_id' => $product->id,
                    'quantity_delta' => $stock,
                    'unit_cost' => $cost,
                    'movement_type' => MovementType::OpeningStock,
                    'reason' => 'Demo opening stock',
                    'performed_by' => $ownerId,
                ]);
            }
        }
    }
}
