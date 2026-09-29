<?php

namespace App\Services\Reports;

use App\Enums\Role;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\User;
use App\Support\ReportRange;

/**
 * The home screen. What people see depends on their role: owners get the
 * whole picture including profit, managers the same without cost and profit,
 * and cashiers only what they rang up themselves.
 */
class DashboardReport
{
    public function __construct(
        private SalesAnalytics $sales,
        private StockReport $stock,
        private DebtReport $debt,
    ) {}

    /** @return array<string, mixed> */
    public function for(Shop $shop, User $user, Role $role): array
    {
        $today = ReportRange::today($shop);

        if ($role === Role::Cashier) {
            return [
                'role' => $role->value,
                'date' => $today->from->toDateString(),
                'today' => $this->sales->forCashier($shop, $today, $user->id),
                'recent_sales' => $this->recentSales($shop, $user->id),
            ];
        }

        $fortnight = ReportRange::lastDays($shop, 14);
        $days = $this->sales->daily($shop, $fortnight);
        $lastWeek = array_slice($days, 7);
        $weekBefore = array_slice($days, 0, 7);
        $summary = $this->sales->summary($shop, $today);

        $todayFigures = [
            'net_sales' => $summary['net_sales'],
            'sales_count' => $summary['sales_count'],
            'average_sale' => $summary['average_sale'],
            'refunds' => $summary['refunds'],
            'credit_given' => $summary['credit_given'],
            'expenses' => array_sum(array_column($this->sales->expensesByCategory($shop, $today), 'amount')),
        ];

        if ($role === Role::Owner) {
            $todayFigures['gross_profit'] = $summary['net_sales'] - $this->sales->costOfGoods($shop, $today);
        }

        $week = ReportRange::lastDays($shop, 7);
        $top = collect($this->sales->products($shop, $week))->sortByDesc('revenue')->take(5)->values()
            ->map(fn ($p) => ['product_id' => $p['product_id'], 'name' => $p['name'], 'quantity' => $p['quantity'], 'revenue' => $p['revenue']])
            ->all();

        return [
            'role' => $role->value,
            'date' => $today->from->toDateString(),
            'today' => $todayFigures,
            'yesterday_net_sales' => $lastWeek[5]['net_sales'],
            'week' => [
                'net_sales' => array_sum(array_column($lastWeek, 'net_sales')),
                'previous_net_sales' => array_sum(array_column($weekBefore, 'net_sales')),
            ],
            'series' => array_map(fn ($d) => ['date' => $d['date'], 'net_sales' => $d['net_sales'], 'sales_count' => $d['sales_count']], $lastWeek),
            'top_products' => $top,
            'payment_methods' => $this->sales->paymentMethods($shop, $today),
            'stock' => $this->stock->glance($shop),
            'debt' => $this->debt->glance($shop),
            'recent_sales' => $this->recentSales($shop, null),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function recentSales(Shop $shop, ?int $cashierId): array
    {
        return Sale::where('shop_id', $shop->id)
            ->when($cashierId, fn ($q) => $q->where('cashier_id', $cashierId))
            ->with('cashier:id,name', 'customer:id,name')
            ->orderByDesc('created_at')->orderByDesc('id')->limit(6)->get()
            ->map(fn (Sale $sale) => [
                'id' => $sale->id,
                'sale_number' => $sale->sale_number,
                'total' => $sale->total,
                'status' => $sale->status,
                'cashier' => $sale->cashier?->name,
                'customer' => $sale->customer?->name,
                'created_at' => $sale->created_at->toIso8601String(),
            ])->all();
    }
}
