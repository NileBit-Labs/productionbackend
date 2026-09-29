<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Refund;
use App\Models\RefundItem;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shop;
use App\Models\User;
use App\Support\DiscountAllocation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Refunds reverse only what actually happened. The value of each returned
 * item is what was really paid for it (its line total less its share of any
 * order-level discount), refunds are cumulative and can never exceed the
 * sale, and whether goods go back on the shelf is decided per line.
 */
class RefundService
{
    public function __construct(
        private StockService $stock,
        private CustomerLedger $ledger,
        private AuditLogger $audit,
    ) {}

    /**
     * What is still refundable on each line of a sale.
     *
     * @return array<int, array{sale_item_id: int, product_name: string, unit: string, sold: float, refunded: float, remaining: float, net_value: int, remaining_value: int}>
     */
    public function refundable(Sale $sale): array
    {
        $sale->loadMissing('items');
        $net = $this->netValues($sale);

        $done = RefundItem::whereIn('sale_item_id', $sale->items->pluck('id'))
            ->selectRaw('sale_item_id, SUM(quantity) as quantity, SUM(amount) as amount')
            ->groupBy('sale_item_id')
            ->get()
            ->keyBy('sale_item_id');

        return $sale->items->map(function (SaleItem $item) use ($net, $done) {
            $refundedQty = round((float) ($done[$item->id]->quantity ?? 0), 3);
            $refundedAmount = (int) ($done[$item->id]->amount ?? 0);

            return [
                'sale_item_id' => $item->id,
                'product_name' => $item->product_name,
                'unit' => $item->unit,
                'sold' => $item->quantity,
                'refunded' => $refundedQty,
                'remaining' => round($item->quantity - $refundedQty, 3),
                'net_value' => $net[$item->id],
                'remaining_value' => $net[$item->id] - $refundedAmount,
            ];
        })->all();
    }

    /**
     * Works out a refund without writing anything.
     *
     * @param  array<string, mixed>  $data  lines[]{sale_item_id, quantity, restock}
     * @return array<string, mixed>
     */
    public function plan(Sale $sale, array $data): array
    {
        if ($sale->status !== 'completed') {
            throw ValidationException::withMessages(['sale' => 'Only a completed sale can be refunded.']);
        }

        $remaining = collect($this->refundable($sale))->keyBy('sale_item_id');
        $lines = [];
        $total = 0;
        $seen = [];

        foreach ($data['lines'] as $i => $line) {
            $itemId = (int) $line['sale_item_id'];
            $row = $remaining->get($itemId);

            if (! $row) {
                throw ValidationException::withMessages(["lines.$i.sale_item_id" => 'This item is not on the sale.']);
            }

            if (in_array($itemId, $seen, true)) {
                throw ValidationException::withMessages(["lines.$i.sale_item_id" => 'List each item once.']);
            }
            $seen[] = $itemId;

            $quantity = round((float) $line['quantity'], 3);

            if ($quantity > $row['remaining'] + 0.0005) {
                throw ValidationException::withMessages(["lines.$i.quantity" => "Only {$row['remaining']} of {$row['product_name']} can still be refunded."]);
            }

            // The last of a line takes whatever value is left, so partial
            // refunds always add up to exactly what was paid, with no rounding drift.
            $amount = abs($quantity - $row['remaining']) < 0.0005
                ? $row['remaining_value']
                : (int) round($row['net_value'] * $quantity / $row['sold']);

            $lines[] = [
                'sale_item_id' => $itemId,
                'product_name' => $row['product_name'],
                'quantity' => $quantity,
                'amount' => $amount,
                'restock' => (bool) ($line['restock'] ?? true),
            ];
            $total += $amount;
        }

        if ($total <= 0) {
            throw ValidationException::withMessages(['lines' => 'There is nothing left to refund on this sale.']);
        }

        // Cancel what the customer still owes on this sale first; only the
        // rest is money going back out of the till.
        $debtCredit = 0;

        if ($sale->customer_id && $sale->amount_due > 0) {
            $alreadyCredited = (int) Refund::where('sale_id', $sale->id)->sum('balance_credit');
            $customer = Customer::find($sale->customer_id);
            $debtCredit = max(0, min($total, $sale->amount_due - $alreadyCredited, $this->ledger->balance($customer)));
        }

        return [
            'lines' => $lines,
            'total_refund' => $total,
            'balance_credit' => $debtCredit,
            'cash_refund' => $total - $debtCredit,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public function refund(Shop $shop, User $by, Sale $sale, array $data): Refund
    {
        $key = $data['idempotency_key'] ?? null;

        if ($key && ($existing = $this->findByKey($shop, $key))) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($shop, $by, $sale, $data, $key) {
                $locked = Sale::where('shop_id', $shop->id)->lockForUpdate()->with('items')->findOrFail($sale->id);
                $plan = $this->plan($locked, $data);

                if ($plan['cash_refund'] > 0 && empty($data['method'])) {
                    throw ValidationException::withMessages(['method' => 'Choose how the money is being paid back.']);
                }

                $refund = Refund::create([
                    'shop_id' => $shop->id,
                    'sale_id' => $locked->id,
                    'total_refund' => $plan['total_refund'],
                    'cash_refund' => $plan['cash_refund'],
                    'balance_credit' => $plan['balance_credit'],
                    'method' => $plan['cash_refund'] > 0 ? $data['method'] : null,
                    'reason' => $data['reason'],
                    'approved_by' => $by->id,
                    'idempotency_key' => $key,
                ]);

                $items = $locked->items->keyBy('id');

                foreach ($plan['lines'] as $line) {
                    RefundItem::create([
                        'refund_id' => $refund->id,
                        'sale_item_id' => $line['sale_item_id'],
                        'quantity' => $line['quantity'],
                        'amount' => $line['amount'],
                        'restock' => $line['restock'],
                    ]);

                    if ($line['restock']) {
                        $item = $items[$line['sale_item_id']];
                        $this->stock->record(
                            Product::findOrFail($item->product_id),
                            round($line['quantity'] * $item->unit_conversion, 3),
                            MovementType::SaleReturn,
                            $by,
                            $refund,
                            "Refund R-{$refund->id}: {$data['reason']}",
                            $item->historical_cost,
                        );
                    }
                }

                if ($plan['cash_refund'] > 0) {
                    Payment::create([
                        'shop_id' => $shop->id,
                        'sale_id' => $locked->id,
                        'customer_id' => $locked->customer_id,
                        'amount' => $plan['cash_refund'],
                        'method' => $data['method'],
                        'reference' => "Refund R-{$refund->id}",
                        'direction' => 'out',
                        'recorded_by' => $by->id,
                    ]);
                }

                if ($plan['balance_credit'] > 0) {
                    $customer = Customer::lockForUpdate()->find($locked->customer_id);
                    $this->ledger->record($customer, CustomerLedger::REFUND, -$plan['balance_credit'], $by, $refund, "Refund of {$locked->sale_number}");
                }

                $this->audit->record($by, $shop, 'sale.refund', $locked, null, [
                    'refund_id' => $refund->id,
                    'total' => $plan['total_refund'],
                    'cash' => $plan['cash_refund'],
                    'debt_cancelled' => $plan['balance_credit'],
                    'reason' => $data['reason'],
                ]);

                return $refund->load('items');
            });
        } catch (UniqueConstraintViolationException $e) {
            if ($key && ($existing = $this->findByKey($shop, $key))) {
                return $existing;
            }

            throw $e;
        }
    }

    private function findByKey(Shop $shop, string $key): ?Refund
    {
        return Refund::where('shop_id', $shop->id)->where('idempotency_key', $key)->with('items')->first();
    }

    /**
     * Each line's real value: its line total less its share of any
     * order-level discount, so a full refund equals exactly what was paid.
     *
     * @return array<int, int> sale item id => net value
     */
    private function netValues(Sale $sale): array
    {
        return DiscountAllocation::netValues($sale->items, $sale->discount);
    }
}
