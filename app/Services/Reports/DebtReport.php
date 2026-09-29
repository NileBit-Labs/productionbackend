<?php

namespace App\Services\Reports;

use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\Shop;
use App\Services\CustomerDebt;
use App\Support\ReportRange;
use Carbon\CarbonImmutable;

/**
 * Who owes the shop money and for how long.
 *
 * What a customer owes is their ledger balance (only people who owe count;
 * a customer who has overpaid is not a debtor). The age of that debt comes
 * from their open credit sales, with repayments taken off the oldest first,
 * and "overdue" means a due date the customer agreed that has now passed.
 */
class DebtReport
{
    public const BUCKETS = ['0-30', '31-60', '61-90', '90+'];

    public const LIMIT = 500;

    public function __construct(private CustomerDebt $debt) {}

    /**
     * The dashboard's view: the totals and the biggest debtors to chase.
     *
     * @return array<string, mixed>
     */
    public function glance(Shop $shop, int $limit = 4): array
    {
        $report = $this->report($shop);

        return $report['summary'] + [
            'top' => array_map(
                fn ($c) => array_intersect_key($c, array_flip(['id', 'name', 'balance', 'overdue', 'days_overdue'])),
                array_slice($report['customers'], 0, $limit),
            ),
        ];
    }

    /** @return array<string, mixed> */
    public function report(Shop $shop): array
    {
        $today = ReportRange::today($shop);
        $todayDate = $today->from;

        $balances = CustomerLedgerEntry::where('shop_id', $shop->id)
            ->selectRaw('customer_id, SUM(amount) as balance')
            ->groupBy('customer_id')
            ->havingRaw('SUM(amount) > 0')
            ->pluck('balance', 'customer_id')
            ->map(fn ($balance) => (int) $balance);

        $customers = Customer::where('shop_id', $shop->id)->whereIn('id', $balances->keys())->get()->keyBy('id');
        $open = $this->debt->openSales($balances->keys()->all());

        $aging = array_fill_keys(self::BUCKETS, 0);
        $overdueTotal = 0;
        $rows = [];

        foreach ($balances as $customerId => $balance) {
            $sales = $open[$customerId] ?? [];
            $overdue = 0;
            $daysOverdue = 0;
            $oldest = null;

            foreach ($sales as $sale) {
                $soldOn = CarbonImmutable::parse($sale['sold_at'])->setTimezone($today->timezone)->startOfDay();
                $age = (int) $soldOn->diffInDays($todayDate);
                $aging[$this->bucket($age)] += $sale['owed'];
                $oldest = $oldest === null || $soldOn->lessThan($oldest) ? $soldOn : $oldest;

                if ($sale['due_date'] && $sale['due_date'] < $todayDate->toDateString()) {
                    $overdue += $sale['owed'];
                    $daysOverdue = max($daysOverdue, (int) CarbonImmutable::parse($sale['due_date'], $today->timezone)->diffInDays($todayDate));
                }
            }

            $overdueTotal += $overdue;
            $customer = $customers[$customerId];

            $rows[] = [
                'id' => $customer->id,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'balance' => $balance,
                'open_sales' => count($sales),
                'oldest_debt_days' => $oldest ? (int) $oldest->diffInDays($todayDate) : null,
                'overdue' => $overdue,
                'days_overdue' => $daysOverdue,
            ];
        }

        usort($rows, fn ($a, $b) => [$b['balance'], $a['name']] <=> [$a['balance'], $b['name']]);

        return [
            'as_of' => $todayDate->toDateString(),
            'summary' => [
                'total_owed' => (int) $balances->sum(),
                'customers_owing' => $balances->count(),
                'overdue' => $overdueTotal,
            ],
            'aging' => array_map(fn ($bucket) => ['bucket' => $bucket, 'amount' => $aging[$bucket]], self::BUCKETS),
            'customers' => array_slice($rows, 0, self::LIMIT),
        ];
    }

    private function bucket(int $ageDays): string
    {
        return match (true) {
            $ageDays <= 30 => '0-30',
            $ageDays <= 60 => '31-60',
            $ageDays <= 90 => '61-90',
            default => '90+',
        };
    }
}
