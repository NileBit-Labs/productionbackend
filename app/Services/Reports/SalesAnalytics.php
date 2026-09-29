<?php

namespace App\Services\Reports;

use App\Models\Product;
use App\Models\Sale;
use App\Models\Shop;
use App\Support\DiscountAllocation;
use App\Support\ReportRange;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * How the sales, profit and dashboard figures are worked out. The rules:
 *
 *  - A sale counts on the local day it happened (Africa/Kampala by default),
 *    and only while it stands: a voided sale is treated as never having
 *    happened and is listed separately.
 *  - A refund counts on the day it is given, not the day of the original
 *    sale, so yesterday's closed figures never change retrospectively.
 *  - Net sales = sales - refunds. Discounts are already out of every figure.
 *  - Cost of goods uses the cost frozen onto each sale line when it was sold.
 *    A refunded line that goes back on the shelf gives its cost back; one
 *    that does not (damaged, opened) stays a cost.
 *  - Gross profit = net sales - cost of goods.
 *    Operating profit = gross profit - expenses (by the date on the expense).
 *  - Per-product figures spread order-level discounts over the lines
 *    (DiscountAllocation), so they add up to exactly the net sales.
 */
class SalesAnalytics
{
    /** @return array<string, int|float> */
    public function summary(Shop $shop, ReportRange $range): array
    {
        $sold = $this->sales($shop, $range)
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(total), 0) as gross, COALESCE(SUM(discount), 0) as discounts, COALESCE(SUM(amount_due), 0) as credit')
            ->first();

        $voided = DB::table('sales')->where('shop_id', $shop->id)->where('status', 'voided')
            ->whereBetween('created_at', [$range->utcFrom(), $range->utcTo()])
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(total), 0) as value')
            ->first();

        $refunds = $this->refunds($shop, $range)
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(total_refund), 0) as total')
            ->first();

        $count = (int) $sold->n;
        $gross = (int) $sold->gross;

        return [
            'sales_count' => $count,
            'gross_sales' => $gross,
            'discounts' => (int) $sold->discounts,
            'refund_count' => (int) $refunds->n,
            'refunds' => (int) $refunds->total,
            'net_sales' => $gross - (int) $refunds->total,
            'average_sale' => $count > 0 ? (int) round($gross / $count) : 0,
            'credit_given' => (int) $sold->credit,
            'voided_count' => (int) $voided->n,
            'voided_value' => (int) $voided->value,
        ];
    }

    /**
     * One row per local day in the range, including days with no sales.
     *
     * @return array<int, array{date: string, sales_count: int, gross_sales: int, refunds: int, net_sales: int, cost_of_goods: int}>
     */
    public function daily(Shop $shop, ReportRange $range, bool $withCost = false): array
    {
        $day = $range->localDateSql('created_at');

        $sold = $this->sales($shop, $range)
            ->selectRaw("{$day} as day, COUNT(*) as n, SUM(total) as gross")
            ->groupByRaw($day)->get()->keyBy('day');

        $refunds = $this->refunds($shop, $range)
            ->selectRaw("{$day} as day, SUM(total_refund) as total")
            ->groupByRaw($day)->get()->keyBy('day');

        $costs = $withCost ? $this->costByDay($shop, $range) : [];

        return array_map(function (string $date) use ($sold, $refunds, $costs, $withCost) {
            $gross = (int) ($sold[$date]->gross ?? 0);
            $refunded = (int) ($refunds[$date]->total ?? 0);

            $row = [
                'date' => $date,
                'sales_count' => (int) ($sold[$date]->n ?? 0),
                'gross_sales' => $gross,
                'refunds' => $refunded,
                'net_sales' => $gross - $refunded,
            ];

            if ($withCost) {
                $row['cost_of_goods'] = (int) ($costs[$date] ?? 0);
            }

            return $row;
        }, $range->days());
    }

    /** Cost of the goods sold in the range, net of restocked returns. */
    public function costOfGoods(Shop $shop, ReportRange $range): int
    {
        return (int) array_sum($this->costByDay($shop, $range));
    }

    /**
     * Money actually received, by how it was paid: payments in, less money
     * paid back out (refunds and voids), including customers paying off debt.
     *
     * @return array<int, array{method: string, amount: int}>
     */
    public function paymentMethods(Shop $shop, ReportRange $range): array
    {
        return DB::table('payments')->where('shop_id', $shop->id)
            ->whereBetween('created_at', [$range->utcFrom(), $range->utcTo()])
            // Sales, refunds and voids always refer to their sale. Customer
            // repayments have no sale because they are applied across the
            // customer's oldest debts. Supplier and purchase payments share
            // this append-only table, but are business outgoings rather than
            // retail-sale payment methods and must not alter this split.
            ->where(function (Builder $query) {
                $query->whereNotNull('sale_id')
                    ->orWhere(function (Builder $query) {
                        $query->whereNotNull('customer_id')
                            ->whereNull('supplier_id')
                            ->whereNull('purchase_id');
                    });
            })
            ->selectRaw("method, SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END) as amount")
            ->groupBy('method')->orderByDesc('amount')->get()
            ->map(fn ($row) => ['method' => $row->method, 'amount' => (int) $row->amount])
            ->all();
    }

    /**
     * What each person rang up (before any later refunds).
     *
     * @return array<int, array{user_id: int, name: string, sales_count: int, total: int}>
     */
    public function cashiers(Shop $shop, ReportRange $range): array
    {
        return $this->sales($shop, $range)
            ->join('users', 'users.id', '=', 'sales.cashier_id')
            ->selectRaw('users.id as user_id, users.name as name, COUNT(*) as n, SUM(sales.total) as total')
            ->groupBy('users.id', 'users.name')->orderByDesc('total')->get()
            ->map(fn ($row) => ['user_id' => (int) $row->user_id, 'name' => $row->name, 'sales_count' => (int) $row->n, 'total' => (int) $row->total])
            ->all();
    }

    /**
     * Per-product quantity (in base units), revenue and cost, net of refunds
     * given in the range. Worked out sale by sale so order-level discounts
     * can be shared out exactly.
     *
     * @return array<int, array{product_id: int, name: string, quantity: float, revenue: int, cost: int}>
     */
    public function products(Shop $shop, ReportRange $range): array
    {
        $rows = [];

        Sale::where('shop_id', $shop->id)->where('status', 'completed')
            ->whereBetween('created_at', [$range->utcFrom(), $range->utcTo()])
            ->with('items')
            ->chunkById(500, function ($sales) use (&$rows) {
                foreach ($sales as $sale) {
                    $net = DiscountAllocation::netValues($sale->items, $sale->discount);

                    foreach ($sale->items as $item) {
                        $row = $rows[$item->product_id] ?? ['quantity' => 0.0, 'revenue' => 0, 'cost' => 0];
                        $row['quantity'] += $item->quantity * $item->unit_conversion;
                        $row['revenue'] += $net[$item->id];
                        $row['cost'] += (int) round($item->quantity * $item->historical_cost);
                        $rows[$item->product_id] = $row;
                    }
                }
            });

        $returned = $this->refundedItems($shop, $range)
            ->get(['sale_items.product_id', 'refund_items.quantity', 'refund_items.amount', 'refund_items.restock', 'sale_items.unit_conversion', 'sale_items.historical_cost']);

        foreach ($returned as $line) {
            $row = $rows[$line->product_id] ?? ['quantity' => 0.0, 'revenue' => 0, 'cost' => 0];
            $row['quantity'] -= (float) $line->quantity * (float) $line->unit_conversion;
            $row['revenue'] -= (int) $line->amount;

            if ($line->restock) {
                $row['cost'] -= (int) round((float) $line->quantity * (int) $line->historical_cost);
            }

            $rows[$line->product_id] = $row;
        }

        $names = Product::where('shop_id', $shop->id)->whereIn('id', array_keys($rows))->pluck('name', 'id');

        $result = [];

        foreach ($rows as $productId => $row) {
            $result[] = [
                'product_id' => $productId,
                'name' => $names[$productId] ?? 'Deleted product',
                'quantity' => round($row['quantity'], 3),
                'revenue' => $row['revenue'],
                'cost' => $row['cost'],
            ];
        }

        return $result;
    }

    /** @return array<int, array{category: string, amount: int}> */
    public function expensesByCategory(Shop $shop, ReportRange $range): array
    {
        return DB::table('expenses')->where('shop_id', $shop->id)
            ->whereBetween('expense_date', [$range->from->toDateString(), $range->to->toDateString()])
            ->selectRaw('category, SUM(amount) as amount')->groupBy('category')->orderByDesc('amount')->get()
            ->map(fn ($row) => ['category' => $row->category, 'amount' => (int) $row->amount])
            ->all();
    }

    /** @return array<string, int> local date => expenses that day */
    public function expensesByDay(Shop $shop, ReportRange $range): array
    {
        return DB::table('expenses')->where('shop_id', $shop->id)
            ->whereBetween('expense_date', [$range->from->toDateString(), $range->to->toDateString()])
            ->selectRaw('expense_date as day, SUM(amount) as amount')->groupBy('expense_date')->get()
            ->mapWithKeys(fn ($row) => [substr((string) $row->day, 0, 10) => (int) $row->amount])
            ->all();
    }

    /** Sales rung up by one person, e.g. for a cashier's own view. @return array{sales_count: int, total: int, average_sale: int} */
    public function forCashier(Shop $shop, ReportRange $range, int $userId): array
    {
        $row = $this->sales($shop, $range)->where('cashier_id', $userId)
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(total), 0) as total')->first();

        return [
            'sales_count' => (int) $row->n,
            'total' => (int) $row->total,
            'average_sale' => $row->n > 0 ? (int) round($row->total / $row->n) : 0,
        ];
    }

    public static function margin(int $profit, int $netSales): ?float
    {
        return $netSales > 0 ? round($profit / $netSales * 100, 1) : null;
    }

    private function sales(Shop $shop, ReportRange $range): Builder
    {
        return DB::table('sales')->where('sales.shop_id', $shop->id)->where('sales.status', 'completed')
            ->whereBetween('sales.created_at', [$range->utcFrom(), $range->utcTo()]);
    }

    private function refunds(Shop $shop, ReportRange $range): Builder
    {
        return DB::table('refunds')->where('refunds.shop_id', $shop->id)
            ->whereBetween('refunds.created_at', [$range->utcFrom(), $range->utcTo()]);
    }

    private function refundedItems(Shop $shop, ReportRange $range): Builder
    {
        return DB::table('refund_items')
            ->join('refunds', 'refunds.id', '=', 'refund_items.refund_id')
            ->join('sale_items', 'sale_items.id', '=', 'refund_items.sale_item_id')
            ->where('refunds.shop_id', $shop->id)
            ->whereBetween('refunds.created_at', [$range->utcFrom(), $range->utcTo()]);
    }

    /** @return array<string, int> local date => cost of goods sold that day, net of restocked returns */
    private function costByDay(Shop $shop, ReportRange $range): array
    {
        $soldDay = $range->localDateSql('sales.created_at');
        $sold = $this->sales($shop, $range)
            ->join('sale_items', 'sale_items.sale_id', '=', 'sales.id')
            ->selectRaw("{$soldDay} as day, SUM(ROUND(sale_items.quantity * sale_items.historical_cost)) as cost")
            ->groupByRaw($soldDay)->get();

        $returnedDay = $range->localDateSql('refunds.created_at');
        $returned = $this->refundedItems($shop, $range)->where('refund_items.restock', true)
            ->selectRaw("{$returnedDay} as day, SUM(ROUND(refund_items.quantity * sale_items.historical_cost)) as cost")
            ->groupByRaw($returnedDay)->get();

        $costs = [];

        foreach ($sold as $row) {
            $costs[$row->day] = ($costs[$row->day] ?? 0) + (int) round((float) $row->cost);
        }

        foreach ($returned as $row) {
            $costs[$row->day] = ($costs[$row->day] ?? 0) - (int) round((float) $row->cost);
        }

        return $costs;
    }
}
