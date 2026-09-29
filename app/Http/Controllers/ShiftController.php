<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Shift;
use App\Services\ShiftService;
use App\Support\PerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShiftController extends Controller
{
    public function open(Request $request, ShiftService $shifts): JsonResponse
    {
        $data = $request->validate(['opening_cash' => ['required', 'integer', 'min:0', 'max:1000000000000']]);

        $shift = $shifts->open($request->attributes->get('shop'), $request->user(), $data['opening_cash']);

        return response()->json($this->present($request, $shifts, $shift), 201);
    }

    public function current(Request $request, ShiftService $shifts): JsonResponse
    {
        $shift = $shifts->current($request->attributes->get('shop'), $request->user());

        return response()->json(['shift' => $shift ? $this->present($request, $shifts, $shift) : null]);
    }

    public function close(Request $request, ShiftService $shifts, int $shift): JsonResponse
    {
        $data = $request->validate([
            'actual_cash' => ['required', 'integer', 'min:0', 'max:1000000000000'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $model = $this->find($request, $shift);
        $closed = $shifts->close($request->attributes->get('shop'), $request->user(), $model, $data['actual_cash'], $data['note'] ?? null);

        return response()->json($this->present($request, $shifts, $closed->load('cashier:id,name'), forceDetail: true));
    }

    public function index(Request $request): JsonResponse
    {
        $query = Shift::where('shop_id', $request->attributes->get('shop')->id)
            ->with('cashier:id,name')
            ->orderByDesc('opened_at')
            ->orderByDesc('id');

        if (! $this->isManager($request)) {
            $query->where('cashier_id', $request->user()->id);
        }

        return response()->json($query->paginate(PerPage::from($request, 25))->through(fn (Shift $s) => $this->hideCounts($request, $s)->toArray()));
    }

    public function show(Request $request, ShiftService $shifts, int $shift): JsonResponse
    {
        return response()->json($this->present($request, $shifts, $this->find($request, $shift)->load('cashier:id,name')));
    }

    private function find(Request $request, int $id): Shift
    {
        $query = Shift::where('shop_id', $request->attributes->get('shop')->id);

        if (! $this->isManager($request)) {
            $query->where('cashier_id', $request->user()->id);
        }

        return $query->findOrFail($id);
    }

    private function isManager(Request $request): bool
    {
        return in_array($request->attributes->get('shopRole'), [Role::Owner, Role::Manager], true);
    }

    /**
     * The count is blind: while a shift is open the cashier isn't shown what
     * the till "should" hold, so they count what is really there. Owners and
     * managers see it throughout, and everyone sees it once it is closed.
     */
    private function hideCounts(Request $request, Shift $shift): Shift
    {
        if (! $shift->closed_at && ! $this->isManager($request)) {
            $shift->makeHidden(['expected_cash', 'variance', 'actual_cash']);
        }

        return $shift;
    }

    /** @return array<string, mixed> */
    private function present(Request $request, ShiftService $shifts, Shift $shift, bool $forceDetail = false): array
    {
        $summary = $shifts->summary($shift);
        $blind = ! $shift->closed_at && ! $this->isManager($request) && ! $forceDetail;

        if ($blind) {
            unset($summary['expected_cash']);
        }

        $out = $this->hideCounts($request, $shift)->toArray() + ['summary' => $summary];

        // Sales made offline and synced after the shift closed still count
        // toward it; show when the figure has moved since the close.
        if ($shift->closed_at && ! $blind) {
            $out['expected_cash_now'] = $summary['expected_cash'];
            $out['changed_since_close'] = $summary['expected_cash'] - $shift->expected_cash;
        }

        return $out;
    }
}
