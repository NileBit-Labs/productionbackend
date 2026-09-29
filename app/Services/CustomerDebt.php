<?php

namespace App\Services;

use App\Models\CustomerLedgerEntry;
use App\Models\Refund;
use App\Models\Sale;
use Illuminate\Support\Collection;

/**
 * Works out which credit sales are still (partly) unpaid. Repayments are
 * taken as a running total against the customer, so they are applied to the
 * oldest credit sales first - the way a shopkeeper would tick off a notebook.
 */
class CustomerDebt
{
    /**
     * @param  array<int>  $customerIds
     * @return array<int, array<int, array<string, mixed>>> customer id => open credit sales
     */
    public function openSales(array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }

        $repaid = CustomerLedgerEntry::whereIn('customer_id', $customerIds)
            ->where('type', CustomerLedger::PAYMENT)
            ->selectRaw('customer_id, -SUM(amount) as repaid')
            ->groupBy('customer_id')
            ->pluck('repaid', 'customer_id');

        /** @var Collection<int, Collection<int, Sale>> $sales */
        $sales = Sale::whereIn('customer_id', $customerIds)
            ->where('status', 'completed')
            ->where('amount_due', '>', 0)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'customer_id', 'sale_number', 'amount_due', 'due_date', 'created_at']);

        // Debt cancelled by refunding goods bought on credit came off that
        // sale specifically, so it is taken off before repayments are applied.
        $cancelled = Refund::whereIn('sale_id', $sales->pluck('id'))
            ->selectRaw('sale_id, SUM(balance_credit) as cancelled')
            ->groupBy('sale_id')
            ->pluck('cancelled', 'sale_id');

        $sales = $sales->groupBy('customer_id');

        $result = [];

        foreach ($customerIds as $customerId) {
            $remaining = (int) ($repaid[$customerId] ?? 0);
            $open = [];

            foreach ($sales->get($customerId, collect()) as $sale) {
                $due = max(0, $sale->amount_due - (int) ($cancelled[$sale->id] ?? 0));
                $applied = min($remaining, $due);
                $remaining -= $applied;
                $stillOwed = $due - $applied;

                if ($stillOwed > 0) {
                    $open[] = [
                        'sale_id' => $sale->id,
                        'sale_number' => $sale->sale_number,
                        'owed' => $stillOwed,
                        'due_date' => $sale->due_date?->toDateString(),
                        'sold_at' => $sale->created_at->toIso8601String(),
                    ];
                }
            }

            $result[$customerId] = $open;
        }

        return $result;
    }
}
