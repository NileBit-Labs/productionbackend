<?php

namespace App\Services\Ask;

use App\Enums\Role;
use App\Models\Product;
use App\Models\Shop;
use App\Services\Reports\DebtReport;
use App\Services\Reports\SalesAnalytics;
use App\Services\Reports\StockReport;
use App\Support\ReportRange;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What "Ask Your Shop" is allowed to look at. Every tool is read-only, is handed the shop the
 * person is signed in to (the model never chooses one), and is built on the same report code the
 * screens use, so the AI can only ever quote figures the person could open themselves.
 * Cost and profit are owner-only here just as they are in Reports; the tool that reports profit
 * is not even offered to a manager.
 */
class ShopTools
{
    private const PERIODS = ['today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month', 'last_7_days', 'last_30_days', 'this_year'];

    private const OWNER_ONLY = ['get_profit_summary'];

    public function __construct(
        private SalesAnalytics $sales,
        private StockReport $stock,
        private DebtReport $debt,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function declarations(Role $role): array
    {
        $when = [
            'period' => ['type' => 'string', 'description' => 'One of: '.implode(', ', self::PERIODS).'. Weeks start on Monday. Default last_30_days.'],
            'from' => ['type' => 'string', 'description' => 'Start date YYYY-MM-DD, for a custom range (use with "to" instead of "period").'],
            'to' => ['type' => 'string', 'description' => 'End date YYYY-MM-DD, inclusive.'],
        ];

        $tools = [
            $this->tool('get_sales_summary', 'Sales for a period: number of sales, net sales (after refunds), average sale, credit given, the best day, and how it compares with the period just before it. Use this first for "how are sales / how are we doing".', $when),
            $this->tool('get_daily_sales', 'Net sales and number of sales per day, week or month over a period, for trends and busiest days.', $when + [
                'group_by' => ['type' => 'string', 'description' => 'day, week or month. Default depends on the length of the period.'],
            ]),
            $this->tool('get_top_products', 'The best (or worst) selling products in a period, ranked by units sold, revenue'.($role === Role::Owner ? ' or profit' : '').'.', $when + [
                'sort_by' => ['type' => 'string', 'description' => 'quantity or revenue'.($role === Role::Owner ? ' or profit' : '').'. Default revenue.'],
                'order' => ['type' => 'string', 'description' => 'top (best sellers, default) or bottom (slowest sellers that still sold something).'],
                'limit' => ['type' => 'integer', 'description' => 'How many to return, 1 to 15. Default 5.'],
            ]),
            $this->tool('get_product_performance', 'How one product (or products whose name contains the text) sold in a period: quantity and revenue'.($role === Role::Owner ? ', cost, profit and margin' : '').'.', $when + [
                'name' => ['type' => 'string', 'description' => 'Part of the product name.'],
            ], ['name']),
            $this->tool('find_product', 'Look up products by name: price, current stock, reorder level and status.'.($role === Role::Owner ? ' Owners may also see cost price and margin.' : ''), [
                'name' => ['type' => 'string', 'description' => 'Part of the product name (at least 2 letters).'],
            ], ['name']),
            $this->tool('get_stock_status', 'Stock health: how many products are out or low, and which products need reordering.'.($role === Role::Owner ? ' Owners may also see stock value.' : ''), [
                'status' => ['type' => 'string', 'description' => 'attention (out or low, default), out, low or all.'],
                'limit' => ['type' => 'integer', 'description' => 'How many products to list, 1 to 20. Default 10.'],
            ]),
            $this->tool('get_payment_methods', 'How customers paid in a period (cash, mobile money, card, bank) and how much was sold on credit.', $when),
            $this->tool('get_sales_by_cashier', 'What each staff member rang up in a period.', $when),
            $this->tool('get_expenses', 'Money spent on expenses in a period, by category, compared with the period before.', $when),
            $this->tool('get_customer_debts', 'Who owes the shop money: the total, how old the debts are, who is overdue and the biggest debtors.', []),
            $this->tool('get_supplier_payables', 'What the shop owes its suppliers, and what it bought from them in a period.', $when),
            $this->tool('get_profit_summary', 'Profit for a period: net sales, cost of goods sold, gross profit, margin, expenses and what is left after expenses; compared with the period before.', $when),
        ];

        return array_values(array_filter($tools, fn (array $t) => $role === Role::Owner || ! in_array($t['name'], self::OWNER_ONLY, true)));
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{result: array<string, mixed>, label: string, visual?: array<string, mixed>}
     *
     * @throws ToolError
     */
    public function run(string $name, array $args, Shop $shop, Role $role): array
    {
        if (! in_array($name, array_column($this->declarations($role), 'name'), true)) {
            throw new ToolError("The tool '{$name}' is not available. Tell the person you can't provide that figure with their access.");
        }

        return match ($name) {
            'get_sales_summary' => $this->salesSummary($args, $shop),
            'get_daily_sales' => $this->dailySales($args, $shop),
            'get_top_products' => $this->topProducts($args, $shop, $role),
            'get_product_performance' => $this->productPerformance($args, $shop, $role),
            'find_product' => $this->findProduct($args, $shop, $role),
            'get_stock_status' => $this->stockStatus($args, $shop, $role),
            'get_payment_methods' => $this->paymentMethods($args, $shop),
            'get_sales_by_cashier' => $this->salesByCashier($args, $shop),
            'get_expenses' => $this->expenses($args, $shop),
            'get_customer_debts' => $this->customerDebts($shop),
            'get_supplier_payables' => $this->supplierPayables($args, $shop),
            'get_profit_summary' => $this->profitSummary($args, $shop),
        };
    }

    // ---- tools -------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function salesSummary(array $args, Shop $shop): array
    {
        $range = $this->range($args, $shop);
        $before = $this->before($range);
        $now = $this->sales->summary($shop, $range);
        $then = $this->sales->summary($shop, $before);
        $daily = $this->sales->daily($shop, $range);

        $active = array_values(array_filter($daily, fn ($d) => $d['sales_count'] > 0));
        usort($active, fn ($a, $b) => $b['net_sales'] <=> $a['net_sales']);
        $day = fn (?array $d) => $d ? ['date' => $d['date'], 'weekday' => CarbonImmutable::parse($d['date'])->format('l'), 'net_sales' => $d['net_sales'], 'sales_count' => $d['sales_count']] : null;

        $out = [
            'result' => [
                'period' => $this->describe($range),
                'sales_count' => $now['sales_count'],
                'net_sales' => $now['net_sales'],
                'gross_sales' => $now['gross_sales'],
                'refunds' => $now['refunds'],
                'average_sale' => $now['average_sale'],
                'discounts_given' => $now['discounts'],
                'credit_given' => $now['credit_given'],
                'voided_sales' => $now['voided_count'],
                'best_day' => $day($active[0] ?? null),
                'slowest_day_with_sales' => count($active) > 1 ? $day($active[count($active) - 1]) : null,
                'previous_period' => ['period' => $this->describe($before), 'net_sales' => $then['net_sales'], 'sales_count' => $then['sales_count']],
                'net_sales_change_percent' => $then['net_sales'] > 0 ? round(($now['net_sales'] - $then['net_sales']) / $then['net_sales'] * 100, 1) : null,
            ],
            'label' => 'Sales summary · '.$this->describe($range),
        ];

        if (count($daily) > 1 && count($daily) <= 31) {
            $out['visual'] = $this->bars('Net sales per day', 'ugx', array_map(fn ($d) => ['label' => CarbonImmutable::parse($d['date'])->format('D j'), 'value' => $d['net_sales']], $daily));
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function dailySales(array $args, Shop $shop): array
    {
        $range = $this->range($args, $shop);
        $days = $this->sales->daily($shop, $range);
        $group = $args['group_by'] ?? (count($days) <= 31 ? 'day' : (count($days) <= 120 ? 'week' : 'month'));

        if (! in_array($group, ['day', 'week', 'month'], true)) {
            throw new ToolError('group_by must be day, week or month.');
        }

        if ($group === 'day' && count($days) > 62) {
            throw new ToolError('That is too many days to list one by one. Use group_by week or month.');
        }

        $rows = [];
        foreach ($days as $d) {
            $date = CarbonImmutable::parse($d['date']);
            [$key, $label] = match ($group) {
                'day' => [$d['date'], $date->format('D j M')],
                'week' => [$date->startOfWeek()->toDateString(), 'Week of '.$date->startOfWeek()->format('j M')],
                'month' => [$date->format('Y-m'), $date->format('M Y')],
            };
            $rows[$key] ??= ['label' => $label, 'net_sales' => 0, 'sales_count' => 0];
            $rows[$key]['net_sales'] += $d['net_sales'];
            $rows[$key]['sales_count'] += $d['sales_count'];
        }
        $rows = array_values($rows);

        return [
            'result' => ['period' => $this->describe($range), 'grouped_by' => $group, 'rows' => $rows],
            'label' => 'Sales by '.$group.' · '.$this->describe($range),
            'visual' => $this->bars('Net sales per '.$group, 'ugx', array_map(fn ($r) => ['label' => $r['label'], 'value' => $r['net_sales']], $rows)),
        ];
    }

    /** @return array<string, mixed> */
    private function topProducts(array $args, Shop $shop, Role $role): array
    {
        $range = $this->range($args, $shop);
        $sortBy = $args['sort_by'] ?? 'revenue';
        $order = $args['order'] ?? 'top';
        $limit = max(1, min(15, (int) ($args['limit'] ?? 5)));

        if (! in_array($sortBy, ['quantity', 'revenue', 'profit'], true) || ! in_array($order, ['top', 'bottom'], true)) {
            throw new ToolError('sort_by must be quantity, revenue or profit; order must be top or bottom.');
        }

        if ($sortBy === 'profit' && $role !== Role::Owner) {
            throw new ToolError("Profit isn't available for this person's access. Rank by revenue or quantity instead.");
        }

        $rows = collect($this->sales->products($shop, $range))
            ->filter(fn ($p) => $p['quantity'] > 0)
            ->map(fn ($p) => $p + ['profit' => $p['revenue'] - $p['cost']]);

        $rows = $order === 'top' ? $rows->sortByDesc($sortBy) : $rows->sortBy($sortBy);

        $shown = $rows->take($limit)->values()->map(function ($p, $i) use ($role) {
            $row = ['rank' => $i + 1, 'name' => $p['name'], 'quantity_sold' => $p['quantity'], 'revenue' => $p['revenue']];

            return $role === Role::Owner ? $row + ['profit' => $p['profit'], 'margin_percent' => SalesAnalytics::margin($p['profit'], $p['revenue'])] : $row;
        })->all();

        $metric = ['quantity' => 'quantity_sold', 'revenue' => 'revenue', 'profit' => 'profit'][$sortBy];

        return [
            'result' => ['period' => $this->describe($range), 'ranked_by' => $sortBy, 'order' => $order, 'products' => $shown, 'products_sold_in_period' => $rows->count()],
            'label' => ($order === 'top' ? 'Best' : 'Slowest').' sellers by '.$sortBy.' · '.$this->describe($range),
            'visual' => $this->ranking(($order === 'top' ? 'Best sellers' : 'Slowest sellers').' by '.$sortBy, $sortBy === 'quantity' ? 'units' : 'ugx', array_map(fn ($p) => ['label' => $p['name'], 'value' => $p[$metric]], $shown)),
        ];
    }

    /** @return array<string, mixed> */
    private function productPerformance(array $args, Shop $shop, Role $role): array
    {
        $term = $this->term($args['name'] ?? '');
        $range = $this->range($args, $shop);

        $rows = collect($this->sales->products($shop, $range))
            ->filter(fn ($p) => str_contains(mb_strtolower($p['name']), mb_strtolower($term)))
            ->sortByDesc('revenue')->take(5)->values()
            ->map(function ($p) use ($role) {
                $row = ['name' => $p['name'], 'quantity_sold' => $p['quantity'], 'revenue' => $p['revenue']];

                return $role === Role::Owner
                    ? $row + ['cost' => $p['cost'], 'profit' => $p['revenue'] - $p['cost'], 'margin_percent' => SalesAnalytics::margin($p['revenue'] - $p['cost'], $p['revenue'])]
                    : $row;
            })->all();

        return [
            'result' => ['period' => $this->describe($range), 'matches' => $rows, 'note' => $rows === [] ? 'No product matching that name sold in this period.' : null],
            'label' => "How “{$term}” sold · ".$this->describe($range),
        ];
    }

    /** @return array<string, mixed> */
    private function findProduct(array $args, Shop $shop, Role $role): array
    {
        $term = $this->term($args['name'] ?? '');

        $products = Product::where('shop_id', $shop->id)
            ->whereRaw('lower(name) like ?', ['%'.mb_strtolower(addcslashes($term, '%_\\')).'%'])
            ->with('category:id,name')->withSum('stockMovements as stock', 'quantity_delta')
            ->orderBy('name')->limit(5)->get();

        $rows = $products->map(fn (Product $p) => [
            'name' => $p->name,
            'sku' => $p->sku,
            'category' => $p->category?->name,
            'unit' => $p->base_unit,
            'selling_price' => $p->selling_price,
            'stock' => round((float) ($p->stock ?? 0), 3),
            'reorder_level' => (float) $p->low_stock_threshold,
            'status' => $p->status,
        ] + ($role === Role::Owner ? [
            'cost_price' => $p->current_cost,
            'margin_percent' => $p->selling_price > 0 ? round(($p->selling_price - $p->current_cost) / $p->selling_price * 100, 1) : null,
        ] : []))->all();

        return [
            'result' => ['matches' => $rows, 'note' => $rows === [] ? 'No product has that name.' : null],
            'label' => "Looked up “{$term}”",
        ];
    }

    /** @return array<string, mixed> */
    private function stockStatus(array $args, Shop $shop, Role $role): array
    {
        $status = $args['status'] ?? 'attention';
        $limit = max(1, min(20, (int) ($args['limit'] ?? 10)));

        if (! in_array($status, ['attention', 'out', 'low', 'all'], true)) {
            throw new ToolError('status must be attention, out, low or all.');
        }

        $summary = $this->stock->report($shop, $role === Role::Owner, null, null, 1)['summary'];
        $fetch = fn (?string $s) => $this->stock->report($shop, false, $s, null, 1)['page']['data'];

        $items = match ($status) {
            'attention' => array_merge($fetch('out'), $fetch('low')),
            'out' => $fetch('out'),
            'low' => $fetch('low'),
            'all' => $fetch(null),
        };

        $shown = array_map(fn ($r) => ['name' => $r['name'], 'unit' => $r['unit'], 'stock' => $r['stock'], 'reorder_level' => $r['low_stock_level'], 'status' => $r['status']], array_slice($items, 0, $limit));

        return [
            'result' => ['summary' => $summary, 'listing' => $status, 'items' => $shown, 'matching' => count($items)],
            'label' => 'Stock status',
            'visual' => $this->ranking($status === 'all' ? 'Stock levels' : 'Running low or out', 'units', array_map(fn ($r) => ['label' => $r['name'], 'value' => max(0, (float) $r['stock'])], $shown)),
        ];
    }

    /** @return array<string, mixed> */
    private function paymentMethods(array $args, Shop $shop): array
    {
        $range = $this->range($args, $shop);
        $methods = $this->sales->paymentMethods($shop, $range);
        $credit = $this->sales->summary($shop, $range)['credit_given'];
        $names = ['CASH' => 'Cash', 'MOBILE_MONEY' => 'Mobile money', 'CARD' => 'Card', 'BANK' => 'Bank', 'OTHER' => 'Other'];

        $rows = array_map(fn ($m) => ['method' => $names[$m['method']] ?? $m['method'], 'amount' => $m['amount']], $methods);
        $points = $rows;
        if ($credit > 0) {
            $points[] = ['method' => 'Sold on credit', 'amount' => $credit];
        }

        return [
            'result' => ['period' => $this->describe($range), 'received_by_method' => $rows, 'sold_on_credit' => $credit],
            'label' => 'Payment methods · '.$this->describe($range),
            'visual' => $this->ranking('How customers paid', 'ugx', array_map(fn ($m) => ['label' => $m['method'], 'value' => $m['amount']], $points)),
        ];
    }

    /** @return array<string, mixed> */
    private function salesByCashier(array $args, Shop $shop): array
    {
        $range = $this->range($args, $shop);
        $rows = array_map(fn ($c) => ['name' => $c['name'], 'sales_count' => $c['sales_count'], 'total_sold' => $c['total']], $this->sales->cashiers($shop, $range));

        return [
            'result' => ['period' => $this->describe($range), 'staff' => $rows],
            'label' => 'Sales by staff · '.$this->describe($range),
            'visual' => $this->ranking('Sold by each person', 'ugx', array_map(fn ($r) => ['label' => $r['name'], 'value' => $r['total_sold']], $rows)),
        ];
    }

    /** @return array<string, mixed> */
    private function expenses(array $args, Shop $shop): array
    {
        $range = $this->range($args, $shop);
        $rows = $this->sales->expensesByCategory($shop, $range);
        $total = array_sum(array_column($rows, 'amount'));
        $before = array_sum(array_column($this->sales->expensesByCategory($shop, $this->before($range)), 'amount'));

        return [
            'result' => [
                'period' => $this->describe($range),
                'total' => $total,
                'by_category' => $rows,
                'previous_period_total' => $before,
                'change_percent' => $before > 0 ? round(($total - $before) / $before * 100, 1) : null,
            ],
            'label' => 'Expenses · '.$this->describe($range),
            'visual' => $this->ranking('Spending by category', 'ugx', array_map(fn ($r) => ['label' => $r['category'], 'value' => $r['amount']], $rows)),
        ];
    }

    /** @return array<string, mixed> */
    private function customerDebts(Shop $shop): array
    {
        $report = $this->debt->report($shop);
        $top = array_map(fn ($c) => ['name' => $c['name'], 'owes' => $c['balance'], 'overdue' => $c['overdue'], 'days_overdue' => $c['days_overdue'], 'oldest_debt_days' => $c['oldest_debt_days']], array_slice($report['customers'], 0, 10));

        return [
            'result' => ['as_of' => $report['as_of'], 'summary' => $report['summary'], 'age_of_debts' => $report['aging'], 'biggest_debtors' => $top],
            'label' => 'Customer debts',
            'visual' => $this->ranking('Who owes the most', 'ugx', array_map(fn ($c) => ['label' => $c['name'], 'value' => $c['owes']], $top)),
        ];
    }

    /** @return array<string, mixed> */
    private function supplierPayables(array $args, Shop $shop): array
    {
        $range = $this->range($args, $shop);

        $owing = DB::table('suppliers')
            ->join('supplier_ledger_entries as l', 'l.supplier_id', '=', 'suppliers.id')
            ->where('suppliers.shop_id', $shop->id)
            ->groupBy('suppliers.id', 'suppliers.name')
            ->havingRaw('sum(l.amount) > 0')
            ->orderByRaw('sum(l.amount) desc')
            ->get(['suppliers.name', DB::raw('sum(l.amount) as balance')])
            ->map(fn ($r) => ['name' => $r->name, 'owed' => (int) $r->balance]);

        $bought = DB::table('purchases')
            ->join('suppliers', 'suppliers.id', '=', 'purchases.supplier_id')
            ->where('purchases.shop_id', $shop->id)->where('purchases.status', 'received')
            ->whereBetween('purchases.purchase_date', [$range->from->toDateString(), $range->to->toDateString()])
            ->groupBy('suppliers.name')->orderByRaw('sum(purchases.total) desc')
            ->get(['suppliers.name', DB::raw('sum(purchases.total) as total')])
            ->map(fn ($r) => ['name' => $r->name, 'bought' => (int) $r->total]);

        return [
            'result' => [
                'total_owed_to_suppliers' => (int) $owing->sum('owed'),
                'suppliers_owed' => $owing->take(10)->values()->all(),
                'period' => $this->describe($range),
                'bought_in_period' => (int) $bought->sum('bought'),
                'bought_from' => $bought->take(10)->values()->all(),
            ],
            'label' => 'Suppliers · '.$this->describe($range),
            'visual' => $this->ranking('What we owe suppliers', 'ugx', $owing->take(10)->map(fn ($r) => ['label' => $r['name'], 'value' => $r['owed']])->values()->all()),
        ];
    }

    /** @return array<string, mixed> */
    private function profitSummary(array $args, Shop $shop): array
    {
        $range = $this->range($args, $shop);
        $figures = function (ReportRange $r) use ($shop) {
            $net = $this->sales->summary($shop, $r)['net_sales'];
            $cost = $this->sales->costOfGoods($shop, $r);
            $expenses = array_sum(array_column($this->sales->expensesByCategory($shop, $r), 'amount'));

            return [
                'net_sales' => $net,
                'cost_of_goods' => $cost,
                'gross_profit' => $net - $cost,
                'margin_percent' => SalesAnalytics::margin($net - $cost, $net),
                'expenses' => $expenses,
                'left_after_expenses' => $net - $cost - $expenses,
            ];
        };

        $now = $figures($range);
        $before = $this->before($range);
        $then = $figures($before);

        return [
            'result' => $now + [
                'period' => $this->describe($range),
                'previous_period' => ['period' => $this->describe($before)] + $then,
                'gross_profit_change_percent' => $then['gross_profit'] > 0 ? round(($now['gross_profit'] - $then['gross_profit']) / $then['gross_profit'] * 100, 1) : null,
            ],
            'label' => 'Profit · '.$this->describe($range),
        ];
    }

    // ---- helpers -----------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $properties
     * @param  array<int, string>  $required
     * @return array<string, mixed>
     */
    private function tool(string $name, string $description, array $properties, array $required = []): array
    {
        $tool = ['name' => $name, 'description' => $description];

        if ($properties !== []) {
            $tool['parameters'] = ['type' => 'object', 'properties' => $properties] + ($required ? ['required' => $required] : []);
        }

        return $tool;
    }

    /** @param  array<string, mixed>  $args */
    private function range(array $args, Shop $shop): ReportRange
    {
        $tz = ReportRange::timezoneFor($shop);
        $today = CarbonImmutable::now($tz)->startOfDay();

        if (isset($args['from']) || isset($args['to'])) {
            $to = isset($args['to']) ? $this->date($args['to'], $tz) : $today;
            $from = isset($args['from']) ? $this->date($args['from'], $tz) : $to;
        } else {
            $period = $args['period'] ?? 'last_30_days';
            [$from, $to] = match ($period) {
                'today' => [$today, $today],
                'yesterday' => [$today->subDay(), $today->subDay()],
                'this_week' => [$today->startOfWeek(), $today],
                'last_week' => [$today->subWeek()->startOfWeek(), $today->subWeek()->endOfWeek()->startOfDay()],
                'this_month' => [$today->startOfMonth(), $today],
                'last_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()->startOfDay()],
                'last_7_days' => [$today->subDays(6), $today],
                'last_30_days' => [$today->subDays(29), $today],
                'this_year' => [$today->startOfYear(), $today],
                default => throw new ToolError('period must be one of: '.implode(', ', self::PERIODS).'.'),
            };
        }

        if ($to->greaterThan($today)) {
            $to = $today;
        }

        if ($from->greaterThan($to)) {
            throw new ToolError('The start date must be on or before the end date, and not in the future.');
        }

        if ($from->diffInDays($to) + 1 > ReportRange::MAX_DAYS) {
            throw new ToolError('Ask about a period of at most a year at a time.');
        }

        return new ReportRange($from, $to, $tz);
    }

    private function date(mixed $value, string $tz): CarbonImmutable
    {
        $date = is_string($value) ? CarbonImmutable::createFromFormat('!Y-m-d', $value, $tz) : false;

        if (! $date || $date->format('Y-m-d') !== $value) {
            throw new ToolError("Dates must be written YYYY-MM-DD; '{$value}' is not.");
        }

        return $date;
    }

    /** The stretch of the same length immediately before this one. */
    private function before(ReportRange $range): ReportRange
    {
        $length = $range->from->diffInDays($range->to) + 1;

        return new ReportRange($range->from->subDays($length), $range->from->subDay(), $range->timezone);
    }

    private function describe(ReportRange $range): string
    {
        if ($range->from->equalTo($range->to)) {
            return $range->from->format('j M Y');
        }

        return $range->from->year === $range->to->year
            ? $range->from->format('j M').' – '.$range->to->format('j M Y')
            : $range->from->format('j M Y').' – '.$range->to->format('j M Y');
    }

    private function term(mixed $value): string
    {
        $term = trim((string) $value);

        if (mb_strlen($term) < 2) {
            throw new ToolError('Give at least 2 letters of the product name.');
        }

        return mb_substr($term, 0, 80);
    }

    /**
     * @param  array<int, array{label: string, value: int|float}>  $points
     * @return array<string, mixed>
     */
    private function bars(string $title, string $unit, array $points): array
    {
        return ['type' => 'bars', 'title' => $title, 'unit' => $unit, 'points' => $points];
    }

    /**
     * @param  array<int, array{label: string, value: int|float}>  $points
     * @return array<string, mixed>
     */
    private function ranking(string $title, string $unit, array $points): array
    {
        return ['type' => 'ranking', 'title' => $title, 'unit' => $unit, 'points' => $points];
    }
}
