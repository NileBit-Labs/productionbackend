<?php

namespace App\Services\Reports;

use App\Models\Product;
use App\Models\Shop;
use App\Services\StockService;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Stock on hand, what is running out, and (for the owner) what it is worth.
 *
 * Quantities come from the stock ledger via StockService. A product is
 * "out" at zero or below, and "low" when it is above zero but at or under its
 * own low-stock level (a level of 0 means no warning is wanted).
 * Value counts only stock actually on the shelf: below zero is worth nothing.
 */
class StockReport
{
    public const PER_PAGE = 50;

    public function __construct(private StockService $stock) {}

    /**
     * The dashboard's view: how many products are out or low, and the ones
     * most in need of reordering (out first, then the lowest).
     *
     * @return array<string, mixed>
     */
    public function glance(Shop $shop, int $limit = 5): array
    {
        $rows = $this->rows($shop);

        $needing = array_values(array_filter($rows, fn ($r) => $r['status'] !== 'ok'));
        usort($needing, fn ($a, $b) => [$a['status'] === 'out' ? 0 : 1, $a['stock'], $a['name']] <=> [$b['status'] === 'out' ? 0 : 1, $b['stock'], $b['name']]);

        return $this->countRows($rows) + [
            'attention' => array_map(
                fn ($r) => array_intersect_key($r, array_flip(['id', 'name', 'unit', 'stock', 'low_stock_level', 'status'])),
                array_slice($needing, 0, $limit),
            ),
        ];
    }

    /**
     * @param  'out'|'low'|'ok'|null  $status
     * @return array<string, mixed>
     */
    public function report(Shop $shop, bool $withValue, ?string $status, ?string $search, int $page): array
    {
        $rows = $this->rows($shop);
        $counts = $this->countRows($rows);

        $summary = $counts + ['in_stock' => $counts['products'] - $counts['out']];

        if ($withValue) {
            $summary['value_at_cost'] = array_sum(array_column($rows, 'value_at_cost'));
            $summary['value_at_retail'] = array_sum(array_column($rows, 'value_at_retail'));
        }

        $shown = array_values(array_filter($rows, function (array $row) use ($status, $search) {
            if ($status && $row['status'] !== $status) {
                return false;
            }

            return ! $search || str_contains(mb_strtolower($row['name'].' '.$row['sku'].' '.$row['barcode']), mb_strtolower($search));
        }));

        $order = ['out' => 0, 'low' => 1, 'ok' => 2];
        usort($shown, fn ($a, $b) => [$order[$a['status']], mb_strtolower($a['name'])] <=> [$order[$b['status']], mb_strtolower($b['name'])]);

        if (! $withValue) {
            $shown = array_map(fn ($row) => array_diff_key($row, array_flip(['value_at_cost', 'value_at_retail'])), $shown);
        }

        $paginator = new LengthAwarePaginator(
            array_slice($shown, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            count($shown),
            self::PER_PAGE,
            $page,
        );

        return ['summary' => $summary, 'page' => $paginator->toArray()];
    }

    /** @return array<int, array<string, mixed>> */
    private function rows(Shop $shop): array
    {
        $levels = $this->stock->levelsForShop($shop->id);

        return Product::where('shop_id', $shop->id)->where('status', 'active')->orderBy('name')->get()
            ->map(function (Product $product) use ($levels) {
                $stock = $levels[$product->id] ?? 0.0;
                $threshold = $product->low_stock_threshold;
                $onShelf = max(0.0, $stock);

                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'sku' => (string) $product->sku,
                    'barcode' => (string) $product->barcode,
                    'unit' => $product->base_unit,
                    'stock' => $stock,
                    'low_stock_level' => $threshold,
                    'status' => $stock <= 0 ? 'out' : ($threshold > 0 && $stock <= $threshold ? 'low' : 'ok'),
                    'value_at_cost' => (int) round($onShelf * $product->current_cost),
                    'value_at_retail' => (int) round($onShelf * $product->selling_price),
                ];
            })->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function countRows(array $rows): array
    {
        return [
            'products' => count($rows),
            'out' => count(array_filter($rows, fn ($r) => $r['status'] === 'out')),
            'low' => count(array_filter($rows, fn ($r) => $r['status'] === 'low')),
        ];
    }
}
