<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationController extends Controller
{
    public function update(Request $request, AuditLogger $audit): JsonResponse
    {
        $organization = $request->user()->organization;
        abort_unless($organization && $organization->owner_user_id === $request->user()->id, 403);
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $before = $organization->only('name');
        $organization->update($data);
        $audit->record($request->user(), $request->attributes->get('shop'), 'organization.update', $organization, $before, $data);

        return response()->json($organization);
    }
}
