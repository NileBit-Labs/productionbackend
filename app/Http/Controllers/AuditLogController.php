<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Support\PerPage;
use App\Support\ReportRange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Owner-only view of the trail of sensitive actions in this shop. */
class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop');
        $range = ReportRange::fromRequest($request, $shop, default: 'month');

        $query = AuditLog::where('shop_id', $shop->id)
            ->whereBetween('created_at', [$range->utcFrom(), $range->utcTo()]);

        if ($request->filled('action')) {
            $query->where('action', $request->query('action'));
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->query('user_id'));
        }

        $page = $query->with('user:id,name')->orderByDesc('id')->paginate(PerPage::from($request));

        return response()->json([
            'range' => $range->toArray(),
            'actions' => AuditLog::where('shop_id', $shop->id)->distinct()->orderBy('action')->pluck('action'),
            'page' => $page->through(fn (AuditLog $log) => [
                'id' => $log->id,
                'created_at' => $log->created_at,
                'action' => $log->action,
                'user' => $log->user ? ['id' => $log->user->id, 'name' => $log->user->name] : null,
                'entity' => class_basename($log->entity_type),
                'entity_id' => $log->entity_id,
                'before' => $log->before_data,
                'after' => $log->after_data,
            ]),
        ]);
    }
}
