<?php

namespace App\Services\Reports;

use App\Enums\BatchStatus;
use App\Enums\ProductKind;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\ProductionLot;
use App\Models\Shop;
use App\Models\WastageRecord;
use App\Services\StockService;
use App\Support\ReportRange;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The production side of the business: what was made and what it cost, what inputs are
 * running low, how much finished stock is on hand, what was lost to wastage, and which
 * batches are close to expiry. Batches count on their production date; only completed
 * batches have a cost.
 */
class ProductionReport
{
    public const EXPIRY_WINDOW_DAYS = 14;

    public function __construct(private StockService $stock) {}

    /** @return array<string, mixed> */
    public function report(Shop $shop, ReportRange $range): array
    {
        $batches = $this->completedBatches($shop, $range);
        $wastage = $this->wastage($shop, $range);

        return [
            'range' => $range->toArray(),
            'summary' => [
                'batches' => $batches->count(),
                'total_cost' => (int) $batches->sum('total_cost'),
                'materials' => (int) $batches->sum('material_cost'),
                'packaging' => (int) $batches->sum('packaging_cost'),
                'direct_labour' => (int) $batches->sum('labour_cost'),
                'direct_expenses' => (int) $batches->sum('direct_expense_cost'),
                'batch_wastage' => (int) $batches->sum('wastage_cost'),
                'wastage_cost' => $wastage['total_cost'],
                'drafts' => ProductionBatch::where('shop_id', $shop->id)->where('status', BatchStatus::Draft)->count(),
            ],
            'products' => $this->outputsByProduct($batches),
            'batches' => $batches->sortByDesc('production_date')->take(50)->map(fn (ProductionBatch $b) => [
                'id' => $b->id,
                'batch_number' => $b->batch_number,
                'name' => $b->name,
                'production_date' => $b->production_date->toDateString(),
                'total_cost' => $b->total_cost,
                'output_quantity' => $b->output_quantity,
                'yield_unit' => $b->yield_unit,
                'planned_yield' => $b->planned_yield,
                'yield_percent' => $b->planned_yield ? round($b->output_quantity / $b->planned_yield * 100, 1) : null,
                'cost_per_yield_unit' => $b->output_quantity > 0 ? (int) round($b->total_cost / $b->output_quantity) : null,
            ])->values(),
            'wastage' => $wastage,
            'stock' => $this->stockByKind($shop),
            'low_inputs' => $this->lowInputs($shop),
            'expiring' => $this->expiring($shop),
        ];
    }

    /**
     * The dashboard's production tile.
     *
     * @return array<string, mixed>
     */
    public function glance(Shop $shop, bool $withCost): array
    {
        $today = ReportRange::today($shop);
        $batches = $this->completedBatches($shop, $today);
        $stock = $this->stockByKind($shop);
        $expiring = $this->expiring($shop);

        $glance = [
            'batches_today' => $batches->count(),
            'made_today' => $this->outputsByProduct($batches)->map(fn ($p) => ['product_id' => $p['product_id'], 'name' => $p['name'], 'quantity' => $p['quantity']])->values(),
            'drafts' => ProductionBatch::where('shop_id', $shop->id)->where('status', BatchStatus::Draft)->count(),
            'low_inputs' => $this->lowInputs($shop, 5),
            'finished_stock_units' => $stock[ProductKind::FinishedGood->value]['units'],
            'expiring_soon' => count($expiring),
            'expiring' => array_slice($expiring, 0, 5),
        ];

        if ($withCost) {
            $glance['production_cost_today'] = (int) $batches->sum('total_cost');
            $glance['wastage_cost_today'] = $this->wastage($shop, $today)['total_cost'];
            $glance['stock_value'] = array_map(fn ($k) => $k['value_at_cost'], $stock);
        }

        return $glance;
    }

    /**
     * Wastage that happened outside any batch. It is not part of any batch's cost, so the
     * profit report takes it off separately.
     */
    public function standaloneWastageCost(Shop $shop, ReportRange $range): int
    {
        return (int) WastageRecord::where('shop_id', $shop->id)->whereNull('production_batch_id')
            ->whereBetween('wastage_date', [$range->from->toDateString(), $range->to->toDateString()])
            ->sum('total_cost');
    }

    /**
     * Finished stock close to (or past) its expiry, batch by batch.
     *
     * Lot movements, rather than aggregate product stock, are authoritative.
     *
     * @return array<int, array<string, mixed>>
     */
    public function expiring(Shop $shop, int $days = self::EXPIRY_WINDOW_DAYS): array
    {
        $today = CarbonImmutable::parse($shop->today());
        $horizon = $today->addDays($days)->toDateString();

        $rows = [];

        $lots = ProductionLot::where('shop_id', $shop->id)->whereNotNull('expiry_date')
            ->where('expiry_date', '<=', $horizon)
            ->with(['movements', 'product:id,name,base_unit,size_label', 'batch:id,batch_number', 'output:id,unit_cost'])
            ->orderBy('expiry_date')->orderBy('production_date')->orderBy('id')->get();

        foreach ($lots as $lot) {
            $remaining = round($lot->produced_quantity + $lot->movements->sum('quantity_delta'), 3);
            if ($remaining <= 0) continue;
            $expiry = $lot->expiry_date->toDateString();
            $rows[] = [
                'product_id' => $lot->product_id,
                'product_name' => $lot->product->name,
                'size_label' => $lot->product->size_label,
                'unit' => $lot->product->base_unit,
                'batch_id' => $lot->production_batch_id,
                'batch_number' => $lot->batch->batch_number,
                'expiry_date' => $expiry,
                'days_left' => (int) $today->diffInDays(CarbonImmutable::parse($expiry), false),
                'expired' => $expiry < $today->toDateString(),
                'remaining_quantity' => $remaining,
                // Kept for API compatibility; it is no longer an estimate.
                'estimated_remaining' => $remaining,
                'value_at_cost' => (int) round($remaining * $lot->output->unit_cost),
            ];
        }

        usort($rows, fn ($a, $b) => [$a['expiry_date'], $a['product_name']] <=> [$b['expiry_date'], $b['product_name']]);

        return $rows;
    }

    /** @return array<string, array{products: int, units: float, value_at_cost: int, low: int, out: int}> */
    public function stockByKind(Shop $shop): array
    {
        $levels = $this->stock->levelsForShop($shop->id);
        $result = [];

        foreach (ProductKind::cases() as $kind) {
            $result[$kind->value] = ['products' => 0, 'units' => 0.0, 'value_at_cost' => 0, 'low' => 0, 'out' => 0];
        }

        foreach (Product::where('shop_id', $shop->id)->where('status', 'active')->get(['id', 'kind', 'current_cost', 'low_stock_threshold']) as $product) {
            $stock = $levels[$product->id] ?? 0.0;
            $row = &$result[$product->kindOrDefault()->value];
            $row['products']++;
            $row['units'] = round($row['units'] + max($stock, 0), 3);
            $row['value_at_cost'] += (int) round(max($stock, 0) * $product->current_cost);
            $row['out'] += $stock <= 0 ? 1 : 0;
            $row['low'] += $stock > 0 && $product->low_stock_threshold > 0 && $stock <= $product->low_stock_threshold ? 1 : 0;
            unset($row);
        }

        return $result;
    }

    /**
     * Raw materials and packaging that are out, or at/below their low-stock level.
     *
     * @return array<int, array<string, mixed>>
     */
    public function lowInputs(Shop $shop, int $limit = 50): array
    {
        $products = Product::where('shop_id', $shop->id)->where('status', 'active')
            ->whereIn('kind', [ProductKind::RawMaterial, ProductKind::Packaging])
            ->get(['id', 'name', 'kind', 'base_unit', 'low_stock_threshold']);

        $levels = $this->stock->currentFor($shop->id, $products->pluck('id')->all());
        $rows = [];

        foreach ($products as $product) {
            $stock = $levels[$product->id] ?? 0.0;
            $status = match (true) {
                $stock <= 0 => 'out',
                $product->low_stock_threshold > 0 && $stock <= $product->low_stock_threshold => 'low',
                default => null,
            };

            if ($status) {
                $rows[] = [
                    'product_id' => $product->id,
                    'name' => $product->name,
                    'kind' => $product->kindOrDefault()->value,
                    'unit' => $product->base_unit,
                    'stock' => $stock,
                    'low_stock_level' => $product->low_stock_threshold,
                    'status' => $status,
                ];
            }
        }

        usort($rows, fn ($a, $b) => [$a['status'] === 'out' ? 0 : 1, $a['stock'], $a['name']] <=> [$b['status'] === 'out' ? 0 : 1, $b['stock'], $b['name']]);

        return array_slice($rows, 0, $limit);
    }

    /** @return Collection<int, ProductionBatch> */
    private function completedBatches(Shop $shop, ReportRange $range)
    {
        return ProductionBatch::where('shop_id', $shop->id)
            ->where('status', BatchStatus::Completed)
            ->whereBetween('production_date', [$range->from->toDateString(), $range->to->toDateString()])
            ->with('outputs.product:id,name,size_label,base_unit,selling_price')
            ->get();
    }

    /**
     * @param  Collection<int, ProductionBatch>  $batches
     * @return Collection<int, array<string, mixed>>
     */
    private function outputsByProduct($batches)
    {
        return $batches->flatMap->outputs->groupBy('product_id')->map(function ($outputs, $productId) {
            $product = $outputs->first()->product;
            $quantity = round($outputs->sum('quantity'), 3);
            $cost = (int) $outputs->sum('allocated_cost');
            $unitCost = $quantity > 0 ? (int) round($cost / $quantity) : 0;

            return [
                'product_id' => (int) $productId,
                'name' => $product->name,
                'size_label' => $product->size_label,
                'unit' => $product->base_unit,
                'quantity' => $quantity,
                'production_cost' => $cost,
                'average_unit_cost' => $unitCost,
                'selling_price' => $product->selling_price,
                'unit_margin' => $product->selling_price - $unitCost,
            ];
        })->sortByDesc('production_cost')->values();
    }

    /** @return array{total_cost: int, standalone_cost: int, in_batches_cost: int, by_stage: array<int, array<string, mixed>>, top_products: array<int, array<string, mixed>>} */
    private function wastage(Shop $shop, ReportRange $range): array
    {
        $records = WastageRecord::where('wastage_records.shop_id', $shop->id)->countable()
            ->whereBetween('wastage_date', [$range->from->toDateString(), $range->to->toDateString()])
            ->with('product:id,name,base_unit')
            ->get();

        return [
            'total_cost' => (int) $records->sum('total_cost'),
            'standalone_cost' => (int) $records->whereNull('production_batch_id')->sum('total_cost'),
            'in_batches_cost' => (int) $records->whereNotNull('production_batch_id')->sum('total_cost'),
            'by_stage' => $records->groupBy(fn ($r) => $r->stage->value)
                ->map(fn ($group, $stage) => ['stage' => $stage, 'total_cost' => (int) $group->sum('total_cost'), 'records' => $group->count()])
                ->sortByDesc('total_cost')->values()->all(),
            'top_products' => $records->groupBy('product_id')
                ->map(fn ($group, $id) => [
                    'product_id' => (int) $id,
                    'name' => $group->first()->product->name,
                    'unit' => $group->first()->product->base_unit,
                    'quantity' => round($group->sum('quantity'), 3),
                    'total_cost' => (int) $group->sum('total_cost'),
                ])
                ->sortByDesc('total_cost')->take(10)->values()->all(),
        ];
    }
}
