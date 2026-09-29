<?php

namespace App\Services;

use App\Models\Shift;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cash accounting for a till session. Expected cash is the opening float plus
 * the cash this cashier took in, minus the cash they paid back out
 * (refunds, voids), over the shift's time window.
 *
 * Payments to suppliers are shop money, not till money, so they are left out.
 *
 * A sale's cash is timed by when the *sale happened*, not when it reached
 * the server, so a sale made offline during the shift and synced after it
 * closes still belongs to that shift.
 */
class ShiftService
{
    public function __construct(private AuditLogger $audit) {}

    public function open(Shop $shop, User $cashier, int $openingCash): Shift
    {
        try {
            // Its own transaction, so the expected clash on the one-open-shift
            // index is rolled back cleanly (PostgreSQL refuses further queries
            // in a transaction that has hit a constraint error).
            return DB::transaction(fn () => Shift::create([
                'shop_id' => $shop->id,
                'cashier_id' => $cashier->id,
                'opening_cash' => $openingCash,
                'opened_at' => now(),
            ]));
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['shift' => 'You already have a shift open. Close it before opening another.']);
        }
    }

    public function current(Shop $shop, User $cashier): ?Shift
    {
        return Shift::where('shop_id', $shop->id)->where('cashier_id', $cashier->id)->whereNull('closed_at')->first();
    }

    public function close(Shop $shop, User $closedBy, Shift $shift, int $actualCash, ?string $note): Shift
    {
        return DB::transaction(function () use ($shop, $closedBy, $shift, $actualCash, $note) {
            $locked = Shift::where('shop_id', $shop->id)->lockForUpdate()->findOrFail($shift->id);

            if ($locked->closed_at) {
                throw ValidationException::withMessages(['shift' => 'This shift is already closed.']);
            }

            $closedAt = now();
            $expected = $this->summary($locked, $closedAt)['expected_cash'];

            $locked->update([
                'expected_cash' => $expected,
                'actual_cash' => $actualCash,
                'variance' => $actualCash - $expected,
                'closed_at' => $closedAt,
                'close_note' => $note,
            ]);

            $this->audit->record($closedBy, $shop, 'shift.close', $locked, null, [
                'cashier_id' => $locked->cashier_id,
                'expected_cash' => $expected,
                'actual_cash' => $actualCash,
                'variance' => $actualCash - $expected,
                'note' => $note,
            ]);

            return $locked;
        });
    }

    /**
     * @return array{cash_sales: int, cash_repayments: int, cash_refunds: int, other_methods: array<int, array<string, mixed>>, expected_cash: int}
     */
    public function summary(Shift $shift, $until = null): array
    {
        $until ??= $shift->closed_at ?? now();

        $happenedAt = "(case when payments.direction = 'in' and payments.sale_id is not null then sales.created_at else payments.created_at end)";
        $source = "(case when payments.sale_id is null then 'account' else 'sale' end)";

        $rows = DB::table('payments')
            ->leftJoin('sales', 'sales.id', '=', 'payments.sale_id')
            ->where('payments.shop_id', $shift->shop_id)
            ->where('payments.recorded_by', $shift->cashier_id)
            ->whereNull('payments.supplier_id')
            ->whereRaw("$happenedAt >= ?", [$shift->opened_at])
            ->whereRaw("$happenedAt <= ?", [$until])
            ->selectRaw("payments.method, payments.direction, $source as source, sum(payments.amount) as total")
            ->groupBy('payments.method', 'payments.direction', DB::raw($source))
            ->get();

        $sum = fn (callable $filter) => (int) $rows->filter($filter)->sum('total');

        $cashSales = $sum(fn ($r) => $r->method === 'CASH' && $r->direction === 'in' && $r->source === 'sale');
        $cashRepayments = $sum(fn ($r) => $r->method === 'CASH' && $r->direction === 'in' && $r->source === 'account');
        $cashRefunds = $sum(fn ($r) => $r->method === 'CASH' && $r->direction === 'out');

        $other = $rows->where('method', '!=', 'CASH')->groupBy('method')->map(fn ($group, $method) => [
            'method' => $method,
            'in' => (int) $group->where('direction', 'in')->sum('total'),
            'out' => (int) $group->where('direction', 'out')->sum('total'),
        ])->values()->all();

        return [
            'cash_sales' => $cashSales,
            'cash_repayments' => $cashRepayments,
            'cash_refunds' => $cashRefunds,
            'other_methods' => $other,
            'expected_cash' => $shift->opening_cash + $cashSales + $cashRepayments - $cashRefunds,
        ];
    }
}
