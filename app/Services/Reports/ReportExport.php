<?php

namespace App\Services\Reports;

use App\Enums\Role;
use App\Models\Shop;
use App\Support\ReportRange;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Builds the role-safe, current-shop datasets shared by the downloadable reports.
 * Nothing here accepts a shop identifier: the route middleware supplies the shop instead.
 */
class ReportExport
{
    public function __construct(
        private SalesAnalytics $sales,
        private StockReport $stock,
        private DebtReport $debt,
    ) {}

    /** @return array<string, mixed> */
    public function summary(Request $request, Shop $shop, Role $role): array
    {
        $range = ReportRange::fromRequest($request, $shop, default: 'month');
        $sales = $this->sales($shop, $range);
        $stock = $this->stock->report($shop, $role === Role::Owner, null, null, 1);

        $data = [
            'range' => $range,
            'sales' => $sales,
            'stock' => $stock,
            'debt' => $this->debt->report($shop),
            'expenses' => $this->expenses($shop, $range),
            'suppliers' => $this->suppliers($shop, $range),
        ];

        if ($role === Role::Owner) {
            $data['profit'] = $this->profit($shop, $range, $sales['summary']);
        }

        return $data;
    }

    /** @return array{summary: array<string, int>, daily: array<int, array<string, mixed>>, payment_methods: array<int, array<string, mixed>>, products: array<int, array<string, mixed>>} */
    public function sales(Shop $shop, ReportRange $range): array
    {
        return [
            'summary' => $this->sales->summary($shop, $range),
            'daily' => $this->sales->daily($shop, $range),
            'payment_methods' => $this->sales->paymentMethods($shop, $range),
            'products' => collect($this->sales->products($shop, $range))
                ->sortByDesc('revenue')->take(15)->values()
                ->map(fn (array $row) => collect($row)->only(['product_id', 'name', 'quantity', 'revenue'])->all())
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function csv(Request $request, Shop $shop, Role $role, string $report): array
    {
        $range = ReportRange::fromRequest($request, $shop, default: 'month');

        return match ($report) {
            'sales' => ['range' => $range, 'rows' => $this->sales($shop, $range)['daily']],
            'stock' => ['range' => $range, 'rows' => $this->stock->report($shop, $role === Role::Owner, null, null, 1)['page']['data']],
            'debt' => ['range' => $range, 'rows' => $this->debt->report($shop)['customers']],
            'profit' => $role === Role::Owner
                ? ['range' => $range, 'rows' => $this->profit($shop, $range, $this->sales->summary($shop, $range))['products']]
                : throw new AuthorizationException,
            default => throw new \InvalidArgumentException('Unknown report export.'),
        };
    }

    /** @return array{total: int, categories: array<int, array{category: string, amount: int}>} */
    private function expenses(Shop $shop, ReportRange $range): array
    {
        $categories = $this->sales->expensesByCategory($shop, $range);

        return ['total' => array_sum(array_column($categories, 'amount')), 'categories' => $categories];
    }

    /** @return array{total_owed: int, owing: array<int, array{name: string, owed: int}>, bought: int} */
    private function suppliers(Shop $shop, ReportRange $range): array
    {
        $owing = DB::table('suppliers')
            ->join('supplier_ledger_entries as ledger', 'ledger.supplier_id', '=', 'suppliers.id')
            ->where('suppliers.shop_id', $shop->id)
            ->groupBy('suppliers.id', 'suppliers.name')
            ->havingRaw('sum(ledger.amount) > 0')
            ->orderByRaw('sum(ledger.amount) desc')
            ->limit(10)
            ->get(['suppliers.name', DB::raw('sum(ledger.amount) as owed')])
            ->map(fn ($row) => ['name' => $row->name, 'owed' => (int) $row->owed]);

        $bought = (int) DB::table('purchases')
            ->where('shop_id', $shop->id)->where('status', 'received')
            ->whereBetween('purchase_date', [$range->from->toDateString(), $range->to->toDateString()])
            ->sum('total');

        return ['total_owed' => (int) $owing->sum('owed'), 'owing' => $owing->all(), 'bought' => $bought];
    }

    /** @param array<string, int> $salesSummary
     * @return array<string, mixed>
     */
    private function profit(Shop $shop, ReportRange $range, array $salesSummary): array
    {
        $daily = $this->sales->daily($shop, $range, withCost: true);
        $expenses = $this->sales->expensesByCategory($shop, $range);
        $cost = array_sum(array_column($daily, 'cost_of_goods'));
        $expenseTotal = array_sum(array_column($expenses, 'amount'));
        $gross = $salesSummary['net_sales'] - $cost;

        return [
            'summary' => [
                'net_sales' => $salesSummary['net_sales'],
                'cost_of_goods' => $cost,
                'gross_profit' => $gross,
                'margin' => SalesAnalytics::margin($gross, $salesSummary['net_sales']),
                'expenses' => $expenseTotal,
                'operating_profit' => $gross - $expenseTotal,
            ],
            'products' => collect($this->sales->products($shop, $range))
                ->map(fn (array $row) => $row + [
                    'profit' => $row['revenue'] - $row['cost'],
                    'margin' => SalesAnalytics::margin($row['revenue'] - $row['cost'], $row['revenue']),
                ])->sortByDesc('profit')->take(50)->values()->all(),
        ];
    }
}
