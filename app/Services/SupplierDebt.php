<?php

namespace App\Services;

use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\SupplierLedgerEntry;
use Illuminate\Support\Collection;

/**
 * Works out which purchases are still (partly) unpaid. Payments to a supplier
 * are taken as a running total against the supplier, so they are applied to
 * the oldest purchases first - the way a shopkeeper would tick off a notebook.
 */
class SupplierDebt
{
    /**
     * @param  array<int>  $supplierIds
     * @return array<int, array<int, array<string, mixed>>> supplier id => open purchases
     */
    public function openPurchases(array $supplierIds): array
    {
        if ($supplierIds === []) {
            return [];
        }

        $paid = SupplierLedgerEntry::whereIn('supplier_id', $supplierIds)
            ->where('type', SupplierLedger::PAYMENT)
            ->selectRaw('supplier_id, -SUM(amount) as paid')
            ->groupBy('supplier_id')
            ->pluck('paid', 'supplier_id');

        /** @var Collection<int, Collection<int, Purchase>> $purchases */
        $purchases = Purchase::whereIn('supplier_id', $supplierIds)
            ->where('status', 'received')
            ->where('amount_due', '>', 0)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'supplier_id', 'purchase_number', 'amount_due', 'purchase_date', 'created_at'])
            ->groupBy('supplier_id');

        $returned = PurchaseReturn::whereIn('purchase_id', $purchases->flatten()->pluck('id'))->selectRaw('purchase_id, sum(balance_credit) as credit')->groupBy('purchase_id')->pluck('credit', 'purchase_id');

        $result = [];

        foreach ($supplierIds as $supplierId) {
            $remaining = (int) ($paid[$supplierId] ?? 0);
            $open = [];

            foreach ($purchases->get($supplierId, collect()) as $purchase) {
                $due = max(0, $purchase->amount_due - (int) ($returned[$purchase->id] ?? 0));
                $applied = min($remaining, $due);
                $remaining -= $applied;
                $stillOwed = $due - $applied;

                if ($stillOwed > 0) {
                    $open[] = [
                        'purchase_id' => $purchase->id,
                        'purchase_number' => $purchase->purchase_number,
                        'owed' => $stillOwed,
                        'purchase_date' => $purchase->purchase_date->toDateString(),
                    ];
                }
            }

            $result[$supplierId] = $open;
        }

        return $result;
    }

    /** What is still owed on one purchase, after payments have been applied oldest-first. */
    public function owedOn(Purchase $purchase): int
    {
        if ($purchase->status !== 'received' || $purchase->amount_due === 0) {
            return 0;
        }

        foreach ($this->openPurchases([$purchase->supplier_id])[$purchase->supplier_id] ?? [] as $open) {
            if ($open['purchase_id'] === $purchase->id) {
                return $open['owed'];
            }
        }

        return 0;
    }
}
