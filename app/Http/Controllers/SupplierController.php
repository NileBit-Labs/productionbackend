<?php

namespace App\Http\Controllers;

use App\Enums\PaymentMethod;
use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use App\Services\AuditLogger;
use App\Services\PurchaseService;
use App\Services\SupplierDebt;
use App\Support\PerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Owner/manager only (see routes): supplier terms and what the shop owes are not for the till. */
class SupplierController extends Controller
{
    private const BALANCE_SQL = '(select coalesce(sum(amount), 0) from supplier_ledger_entries where supplier_ledger_entries.supplier_id = suppliers.id)';

    public function index(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop');

        $base = Supplier::where('shop_id', $shop->id);

        $balances = (clone $base)->withSum('ledgerEntries as balance', 'amount')->get()->pluck('balance');
        $summary = [
            'suppliers' => $balances->count(),
            'owing' => $balances->filter(fn ($b) => (int) $b > 0)->count(),
            'total_owed' => (int) $balances->filter(fn ($b) => (int) $b > 0)->sum(),
        ];

        $query = (clone $base)->withSum('ledgerEntries as balance', 'amount')->orderBy('name');

        match ($request->query('status', 'active')) {
            'inactive' => $query->where('is_active', false),
            'all' => null,
            default => $query->where('is_active', true),
        };

        if ($search = trim((string) $request->query('search', ''))) {
            $like = '%'.mb_strtolower($search).'%';
            $query->where(fn ($q) => $q
                ->whereRaw('lower(name) like ?', [$like])
                ->orWhereRaw('lower(coalesce(phone, \'\')) like ?', [$like]));
        }

        if ($request->boolean('owing')) {
            $query->whereRaw(self::BALANCE_SQL.' > 0');
        }

        return response()->json([
            'summary' => $summary,
            'suppliers' => $query->paginate(PerPage::from($request))->through(fn (Supplier $s) => $this->format($s)),
        ]);
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $shop = $request->attributes->get('shop');
        $data = $request->validate($this->rules());
        $this->assertNameFree($shop->id, $data['name']);

        $supplier = Supplier::create($data + ['shop_id' => $shop->id]);
        $audit->record($request->user(), $shop, 'supplier.create', $supplier, null, ['name' => $supplier->name]);

        return $this->show($request, new SupplierDebt, $supplier->id)->setStatusCode(201);
    }

    public function show(Request $request, SupplierDebt $debt, int $supplier): JsonResponse
    {
        $model = $this->find($request, $supplier);

        return response()->json($this->format($model) + [
            'open_purchases' => $debt->openPurchases([$model->id])[$model->id] ?? [],
        ]);
    }

    public function update(Request $request, AuditLogger $audit, SupplierDebt $debt, int $supplier): JsonResponse
    {
        $shop = $request->attributes->get('shop');
        $model = $this->find($request, $supplier);

        $data = $request->validate($this->rules(partial: true) + ['is_active' => ['sometimes', 'boolean']]);

        if (isset($data['name'])) {
            $this->assertNameFree($shop->id, $data['name'], $model->id);
        }

        $model->fill($data);
        $changes = $model->getDirty();

        if ($changes) {
            $before = array_intersect_key($model->getOriginal(), $changes);
            $model->save();
            $audit->record($request->user(), $shop, 'supplier.update', $model, $before, $changes);
        }

        return $this->show($request, $debt, $model->id);
    }

    public function ledger(Request $request, int $supplier): JsonResponse
    {
        $model = $this->find($request, $supplier);

        $page = SupplierLedgerEntry::where('supplier_ledger_entries.supplier_id', $model->id)
            ->join('users', 'users.id', '=', 'supplier_ledger_entries.recorded_by')
            ->select('supplier_ledger_entries.*', 'users.name as recorded_by_name')
            ->orderByDesc('supplier_ledger_entries.id')
            ->paginate(PerPage::from($request));

        return response()->json($page->through(fn ($e) => [
            'id' => $e->id,
            'type' => $e->type,
            'amount' => $e->amount,
            'note' => $e->note,
            'recorded_by' => $e->recorded_by_name,
            'reference' => $e->reference_type ? ['type' => class_basename($e->reference_type), 'id' => $e->reference_id] : null,
            'created_at' => $e->created_at,
        ]));
    }

    public function pay(Request $request, PurchaseService $purchases, SupplierDebt $debt, int $supplier): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'reference' => ['nullable', 'string', 'max:100'],
        ]);

        $purchases->pay(
            $request->attributes->get('shop'), $request->user(), $supplier,
            $data['amount'], PaymentMethod::from($data['method']), $data['reference'] ?? null,
        );

        return $this->show($request, $debt, $supplier);
    }

    /** @return array<string, array<int, mixed>> */
    private function rules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'min:2', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    private function assertNameFree(int $shopId, string $name, ?int $ignoreId = null): void
    {
        $taken = Supplier::where('shop_id', $shopId)
            ->whereRaw('lower(name) = ?', [mb_strtolower(trim($name))])
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['name' => 'You already have a supplier with this name.']);
        }
    }

    private function find(Request $request, int $id): Supplier
    {
        return Supplier::where('shop_id', $request->attributes->get('shop')->id)
            ->withSum('ledgerEntries as balance', 'amount')
            ->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function format(Supplier $supplier): array
    {
        return [
            'id' => $supplier->id,
            'name' => $supplier->name,
            'phone' => $supplier->phone,
            'email' => $supplier->email,
            'address' => $supplier->address,
            'notes' => $supplier->notes,
            'is_active' => $supplier->is_active,
            'balance' => (int) ($supplier->balance ?? 0),
        ];
    }
}
