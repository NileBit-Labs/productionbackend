<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Services\Reports\DashboardReport;
use App\Services\Reports\DebtReport;
use App\Services\Reports\ReportExport;
use App\Services\Reports\SalesAnalytics;
use App\Services\Reports\StockReport;
use App\Support\ReportRange;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reports are read-only views over what has already been recorded. The route
 * groups decide who may open each one; the owner-only cost and profit figures
 * are never computed for anyone else.
 */
class ReportController extends Controller
{
    public function dashboard(Request $request, DashboardReport $dashboard): JsonResponse
    {
        return response()->json($dashboard->for($request->attributes->get('shop'), $request->user(), $request->attributes->get('shopRole')));
    }

    public function sales(Request $request, SalesAnalytics $analytics): JsonResponse
    {
        $shop = $request->attributes->get('shop');
        $range = ReportRange::fromRequest($request, $shop, default: 'month');

        $products = collect($analytics->products($shop, $range))->sortByDesc('revenue')->take(15)->values()
            ->map(fn ($p) => ['product_id' => $p['product_id'], 'name' => $p['name'], 'quantity' => $p['quantity'], 'revenue' => $p['revenue']]);

        return response()->json([
            'range' => $range->toArray(),
            'summary' => $analytics->summary($shop, $range),
            'daily' => $analytics->daily($shop, $range),
            'payment_methods' => $analytics->paymentMethods($shop, $range),
            'cashiers' => $analytics->cashiers($shop, $range),
            'products' => $products,
        ]);
    }

    public function profit(Request $request, SalesAnalytics $analytics): JsonResponse
    {
        $shop = $request->attributes->get('shop');
        $range = ReportRange::fromRequest($request, $shop, default: 'month');

        $summary = $analytics->summary($shop, $range);
        $daily = $analytics->daily($shop, $range, withCost: true);
        $expensesByDay = $analytics->expensesByDay($shop, $range);
        $expenses = $analytics->expensesByCategory($shop, $range);

        $cost = array_sum(array_column($daily, 'cost_of_goods'));
        $expenseTotal = array_sum(array_column($expenses, 'amount'));
        $gross = $summary['net_sales'] - $cost;

        $products = collect($analytics->products($shop, $range))
            ->map(fn ($p) => $p + ['profit' => $p['revenue'] - $p['cost'], 'margin' => SalesAnalytics::margin($p['revenue'] - $p['cost'], $p['revenue'])])
            ->sortByDesc('profit')->take(50)->values();

        return response()->json([
            'range' => $range->toArray(),
            'summary' => [
                'net_sales' => $summary['net_sales'],
                'cost_of_goods' => $cost,
                'gross_profit' => $gross,
                'margin' => SalesAnalytics::margin($gross, $summary['net_sales']),
                'expenses' => $expenseTotal,
                'operating_profit' => $gross - $expenseTotal,
            ],
            'daily' => array_map(function (array $d) use ($expensesByDay) {
                $expense = $expensesByDay[$d['date']] ?? 0;
                $profit = $d['net_sales'] - $d['cost_of_goods'];

                return [
                    'date' => $d['date'], 'net_sales' => $d['net_sales'], 'cost_of_goods' => $d['cost_of_goods'],
                    'gross_profit' => $profit, 'expenses' => $expense, 'operating_profit' => $profit - $expense,
                ];
            }, $daily),
            'expenses' => $expenses,
            'products' => $products,
        ]);
    }

    public function stock(Request $request, StockReport $stock): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['out', 'low', 'ok'])],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        return response()->json($stock->report(
            $request->attributes->get('shop'),
            withValue: $request->attributes->get('shopRole') === Role::Owner,
            status: $data['status'] ?? null,
            search: isset($data['q']) ? trim($data['q']) : null,
            page: (int) ($data['page'] ?? 1),
        ));
    }

    public function debt(Request $request, DebtReport $debt): JsonResponse
    {
        return response()->json($debt->report($request->attributes->get('shop')));
    }

    public function exportPdf(Request $request, ReportExport $export): Response
{
    $shop = $request->attributes->get('shop');
    $role = $request->attributes->get('shopRole');
    $data = $export->summary($request, $shop, $role);
    $range = $data['range'];

    $filename = $this->filename($shop->name, 'summary', $range);

    $logoPath = resource_path('images/nilebit-pos-icon.svg');
    $logoDataUri = null;

    if (is_file($logoPath)) {
        $logo = file_get_contents($logoPath);

        if ($logo !== false) {
            $logoDataUri = 'data:image/svg+xml;base64,'.base64_encode($logo);
        }
    }

    $pdf = Pdf::loadView('reports.summary-pdf', [
        'shop' => $shop,
        'role' => $role,
        'range' => $range,
        'report' => $data,
        'generatedAt' => now($range->timezone),
        'logoDataUri' => $logoDataUri,
        'reportTitle' => 'Business Overview',
    ])
        ->setPaper('a4', 'portrait')
        ->setOption('isPhpEnabled', true);

    return $pdf->download($filename.'.pdf');
}

    public function exportCsv(Request $request, ReportExport $export): Response
    {
        $data = $request->validate(['report' => ['required', Rule::in(['sales', 'stock', 'debt', 'profit'])]]);
        $shop = $request->attributes->get('shop');
        $report = $data['report'];
        $payload = $export->csv($request, $shop, $request->attributes->get('shopRole'), $report);
        $range = $payload['range'];
        $filename = $this->filename($shop->name, $report, $range).'.csv';
        [$headers, $rows] = $this->csvRows($report, $payload['rows'], $shop->name, $range, $request->attributes->get('shopRole'));

        return response()->streamDownload(function () use ($headers, $rows): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function filename(string $shop, string $report, ReportRange $range): string
    {
        return 'nilebit-pos-'.Str::slug($shop ?: 'shop').'-'.$report.'-'.$range->from->toDateString().'-to-'.$range->to->toDateString();
    }

    /** @param array<int, array<string, mixed>> $source
     * @return array{0: array<int, string>, 1: array<int, array<int, string|int|float|null>>}
     */
    private function csvRows(string $report, array $source, string $shop, ReportRange $range, Role $role): array
    {
        $context = [$shop, ucfirst($report), $range->from->toDateString().' to '.$range->to->toDateString(), now($range->timezone)->toIso8601String()];
        $prefix = ['Shop', 'Report', 'Period', 'Generated at'];

        return match ($report) {
            'sales' => [array_merge($prefix, ['Date', 'Sales', 'Gross sales (UGX)', 'Refunds (UGX)', 'Net sales (UGX)']), array_map(fn (array $r) => array_merge($context, [$r['date'], $r['sales_count'], $r['gross_sales'], $r['refunds'], $r['net_sales']]), $source)],
            'stock' => $role === Role::Owner
                ? [array_merge($prefix, ['Product', 'SKU', 'On hand', 'Unit', 'Low-stock level', 'Status', 'Value at cost (UGX)']), array_map(fn (array $r) => array_merge($context, [$r['name'], $r['sku'], $r['stock'], $r['unit'], $r['low_stock_level'], $r['status'], $r['value_at_cost']]), $source)]
                : [array_merge($prefix, ['Product', 'SKU', 'On hand', 'Unit', 'Low-stock level', 'Status']), array_map(fn (array $r) => array_merge($context, [$r['name'], $r['sku'], $r['stock'], $r['unit'], $r['low_stock_level'], $r['status']]), $source)],
            'debt' => [array_merge($prefix, ['Customer', 'Phone', 'Owes (UGX)', 'Unpaid sales', 'Oldest debt (days)', 'Overdue (UGX)', 'Days overdue']), array_map(fn (array $r) => array_merge($context, [$r['name'], $r['phone'], $r['balance'], $r['open_sales'], $r['oldest_debt_days'], $r['overdue'], $r['days_overdue']]), $source)],
            'profit' => [array_merge($prefix, ['Product', 'Quantity sold', 'Revenue (UGX)', 'Cost (UGX)', 'Profit (UGX)', 'Margin (%)']), array_map(fn (array $r) => array_merge($context, [$r['name'], $r['quantity'], $r['revenue'], $r['cost'], $r['profit'], $r['margin']]), $source)],
        };
    }
}
