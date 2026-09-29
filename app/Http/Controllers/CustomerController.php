<?php

namespace App\Http\Controllers;

use App\Enums\PaymentMethod;
use App\Enums\Role;
use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\Payment;
use App\Services\CustomerDebt;
use App\Services\CustomerLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CustomerController extends Controller
{
    public function index(Request $request, CustomerDebt $debt): JsonResponse
    {
        $shop = $request->attributes->get('shop');

        $query = Customer::where('shop_id', $shop->id)
            ->withSum('ledgerEntries as balance', 'amount')
            ->orderBy('name');

        if ($search = trim((string) $request->query('search', ''))) {
            $like = '%'.mb_strtolower($search).'%';
            $query->where(fn ($q) => $q
                ->whereRaw('lower(name) like ?', [$like])
                ->orWhereRaw('lower(coalesce(phone, \'\')) like ?', [$like]));
        }

        if ($request->boolean('owing')) {
            $query->whereRaw('(select coalesce(sum(amount), 0) from customer_ledger_entries where customer_id = customers.id) > 0');
        }

        $customers = $query->limit(min((int) $request->query('limit', 200), 500))->get();

        $open = $debt->openSales($customers->filter(fn ($c) => (int) $c->balance > 0)->pluck('id')->all());

        $rows = $customers->map(fn (Customer $c) => $this->format($c, $this->isManager($request), $open[$c->id] ?? null));

        if ($request->boolean('owing')) {
            $rows = $rows->sortByDesc('balance');
        }

        return response()->json($rows->values());
    }

    public function store(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30', Rule::unique('customers')->where('shop_id', $shop->id)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if (! $this->isManager($request)) {
            unset($data['notes']);
        }

        $customer = Customer::create($data + ['shop_id' => $shop->id]);
        $customer->balance = 0;

        return response()->json($this->format($customer, $this->isManager($request), []), 201);
    }

    public function show(Request $request, CustomerDebt $debt, int $customer): JsonResponse
    {
        $model = $this->find($request, $customer);
        $open = $debt->openSales([$model->id]);

        return response()->json($this->format($model, $this->isManager($request), $open[$model->id] ?? []));
    }

    public function update(Request $request, int $customer): JsonResponse
    {
        $model = $this->find($request, $customer);
        $shop = $request->attributes->get('shop');

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30', Rule::unique('customers')->where('shop_id', $shop->id)->ignore($model->id)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $model->update($data);

        return response()->json($this->format($this->find($request, $customer), true, []));
    }

    public function ledger(Request $request, int $customer): JsonResponse
    {
        $model = $this->find($request, $customer);

        $running = 0;
        $entries = CustomerLedgerEntry::where('customer_id', $model->id)
            ->orderBy('id')
            ->get()
            ->map(function (CustomerLedgerEntry $entry) use (&$running) {
                $running += $entry->amount;

                return [
                    'id' => $entry->id,
                    'type' => $entry->type,
                    'amount' => $entry->amount,
                    'balance_after' => $running,
                    'note' => $entry->note,
                    'reference_type' => $entry->reference_type ? class_basename($entry->reference_type) : null,
                    'reference_id' => $entry->reference_id,
                    'created_at' => $entry->created_at,
                ];
            });

        return response()->json($entries->reverse()->values());
    }

    public function pay(Request $request, CustomerLedger $ledger, CustomerDebt $debt, int $customer): JsonResponse
    {
        $shop = $request->attributes->get('shop');

        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'reference' => ['nullable', 'string', 'max:100'],
        ]);

        DB::transaction(function () use ($request, $shop, $ledger, $data, $customer) {
            $model = Customer::where('shop_id', $shop->id)->lockForUpdate()->findOrFail($customer);
            $balance = $ledger->balance($model);

            if ($data['amount'] > $balance) {
                throw ValidationException::withMessages([
                    'amount' => $balance > 0
                        ? "This customer only owes {$balance}. Enter that amount or less."
                        : 'This customer does not owe anything.',
                ]);
            }

            $payment = Payment::create([
                'shop_id' => $shop->id,
                'customer_id' => $model->id,
                'amount' => $data['amount'],
                'method' => $data['method'],
                'reference' => $data['reference'] ?? null,
                'direction' => 'in',
                'recorded_by' => $request->user()->id,
            ]);

            $method = ucwords(strtolower(str_replace('_', ' ', $data['method'])));
            $note = $method.(! empty($data['reference']) ? " · {$data['reference']}" : '');

            $ledger->record($model, CustomerLedger::PAYMENT, -$data['amount'], $request->user(), $payment, $note);
        });

        $open = $debt->openSales([$customer]);

        return response()->json($this->format($this->find($request, $customer), $this->isManager($request), $open[$customer] ?? []));
    }

    private function find(Request $request, int $id): Customer
    {
        return Customer::where('shop_id', $request->attributes->get('shop')->id)
            ->withSum('ledgerEntries as balance', 'amount')
            ->findOrFail($id);
    }

    private function isManager(Request $request): bool
    {
        return in_array($request->attributes->get('shopRole'), [Role::Owner, Role::Manager], true);
    }

    /**
     * Cashiers get what they need to complete a sale or take a repayment
     * (who, phone, what is owed) - not notes.
     *
     * @param  array<int, array<string, mixed>>|null  $openSales
     * @return array<string, mixed>
     */
    private function format(Customer $customer, bool $manager, ?array $openSales): array
    {
        $balance = (int) ($customer->balance ?? 0);
        $oldestDue = $openSales === null ? null : collect($openSales)->pluck('due_date')->filter()->min();

        $row = [
            'id' => $customer->id,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'balance' => $balance,
            'oldest_due_date' => $oldestDue,
            'overdue' => $balance > 0 && $oldestDue !== null && $oldestDue < request()->attributes->get('shop')->today(),
        ];

        if ($manager) {
            $row['notes'] = $customer->notes;
            $row['created_at'] = $customer->created_at;
            if ($openSales !== null) {
                $row['open_sales'] = $openSales;
            }
        }

        return $row;
    }
}
