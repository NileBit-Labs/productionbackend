<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Services\StaffService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StaffController extends Controller
{
    public function index(Request $request, StaffService $staff): JsonResponse
    {
        return response()->json($staff->list($request->attributes->get('shop'), $request->user()));
    }

    public function store(Request $request, StaffService $staff): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'role' => ['required', Rule::in([Role::Manager->value, Role::Cashier->value])],
            'password' => ['required', 'string', 'min:8', 'max:100'],
        ]);

        $row = $staff->create($request->attributes->get('shop'), $request->user(), $request->attributes->get('shopRole'), $data);

        return response()->json($row, 201);
    }

    public function update(Request $request, StaffService $staff, int $user): JsonResponse
    {
        $data = $request->validate([
            'role' => ['sometimes', Rule::in([Role::Manager->value, Role::Cashier->value])],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);

        $row = $staff->update($request->attributes->get('shop'), $request->user(), $request->attributes->get('shopRole'), $user, $data);

        return response()->json($row);
    }

    public function resetPassword(Request $request, StaffService $staff, int $user): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string', 'min:8', 'max:100']]);

        $staff->resetPassword($request->attributes->get('shop'), $request->user(), $request->attributes->get('shopRole'), $user, $data['password']);

        return response()->json(['message' => 'Password changed. They have been signed out everywhere.']);
    }
}
