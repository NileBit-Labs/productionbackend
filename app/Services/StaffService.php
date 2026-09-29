<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\Shop;
use App\Models\User;
use App\Models\UserShopRole;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Staff accounts for one shop. The rules, in one place:
 *
 *  - Owners can add and manage managers and cashiers; managers can only add
 *    and manage cashiers. Nobody can promote anyone to owner or change an
 *    owner from here.
 *  - Nobody manages their own account here (no locking yourself out, no
 *    self-promotion); people change their own password from their account.
 *  - Deactivating a person signs them out everywhere and stops them logging
 *    in; a password reset does the same, so a lost or shared credential
 *    can be shut off immediately.
 *  - Every change is written to the audit log.
 */
class StaffService
{
    public function __construct(private AuditLogger $audit) {}

    /** @return array<int, array<string, mixed>> */
    public function list(Shop $shop, User $actor): array
    {
        $roles = UserShopRole::where('shop_id', $shop->id)->with('user')->get();

        $lastActive = PersonalAccessToken::where('tokenable_type', User::class)
            ->whereIn('tokenable_id', $roles->pluck('user_id'))
            ->selectRaw('tokenable_id, max(last_used_at) as last_active_at')
            ->groupBy('tokenable_id')
            ->pluck('last_active_at', 'tokenable_id');

        return $roles
            ->sortBy(fn (UserShopRole $r) => [$r->role === Role::Owner ? 0 : ($r->role === Role::Manager ? 1 : 2), strtolower($r->user->name)])
            ->map(fn (UserShopRole $r) => $this->present($r, $actor, $lastActive[$r->user_id] ?? null))
            ->values()
            ->all();
    }

    /**
     * @param  array{name: string, email: string, phone?: ?string, role: string, password: string}  $data
     * @return array<string, mixed>
     */
    public function create(Shop $shop, User $actor, Role $actorRole, array $data): array
    {
        $role = Role::from($data['role']);

        if ($actorRole === Role::Manager && $role !== Role::Cashier) {
            abort(403, 'Managers can only add cashiers.');
        }

        return DB::transaction(function () use ($shop, $actor, $data, $role) {
            $user = User::where('email', $data['email'])->first();

            if ($user) {
                if ($user->organization_id !== $shop->organization_id) {
                    throw ValidationException::withMessages(['email' => 'This email is already registered with another business.']);
                }

                if (UserShopRole::where('shop_id', $shop->id)->where('user_id', $user->id)->exists()) {
                    throw ValidationException::withMessages(['email' => 'This person is already on your staff.']);
                }
            } else {
                $user = User::create([
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'phone' => $data['phone'] ?? null,
                    'password' => $data['password'],
                    'organization_id' => $shop->organization_id,
                    'status' => 'active',
                ]);
            }

            $membership = UserShopRole::create(['user_id' => $user->id, 'shop_id' => $shop->id, 'role' => $role]);

            $this->audit->record($actor, $shop, 'staff.create', $user, null, ['role' => $role->value, 'email' => $user->email]);

            return $this->present($membership->load('user'), $actor, null);
        });
    }

    /**
     * @param  array{role?: string, status?: string}  $changes
     * @return array<string, mixed>
     */
    public function update(Shop $shop, User $actor, Role $actorRole, int $userId, array $changes): array
    {
        return DB::transaction(function () use ($shop, $actor, $actorRole, $userId, $changes) {
            $membership = $this->membership($shop, $userId);
            $this->assertCanManage($actor, $actorRole, $membership);

            $user = $membership->user;

            if (isset($changes['role']) && Role::from($changes['role']) !== $membership->role) {
                if ($actorRole !== Role::Owner) {
                    abort(403, 'Only the owner can change someone\'s role.');
                }

                $before = $membership->role;
                $membership->update(['role' => Role::from($changes['role'])]);
                $this->audit->record($actor, $shop, 'staff.role_change', $user, ['role' => $before->value], ['role' => $changes['role']]);
            }

            if (isset($changes['status']) && $changes['status'] !== $user->status) {
                $user->update(['status' => $changes['status']]);

                if ($changes['status'] === 'inactive') {
                    $user->tokens()->delete();
                }

                $this->audit->record(
                    $actor,
                    $shop,
                    $changes['status'] === 'inactive' ? 'staff.deactivate' : 'staff.activate',
                    $user,
                    ['status' => $changes['status'] === 'inactive' ? 'active' : 'inactive'],
                    ['status' => $changes['status']],
                );
            }

            return $this->present($membership->fresh('user'), $actor, null);
        });
    }

    public function resetPassword(Shop $shop, User $actor, Role $actorRole, int $userId, string $password): void
    {
        DB::transaction(function () use ($shop, $actor, $actorRole, $userId, $password) {
            $membership = $this->membership($shop, $userId);
            $this->assertCanManage($actor, $actorRole, $membership);

            $user = $membership->user;
            $user->update(['password' => $password]);
            $user->tokens()->delete();

            // The new password itself is never written to the log.
            $this->audit->record($actor, $shop, 'staff.password_reset', $user, null, ['signed_out_everywhere' => true]);
        });
    }

    private function membership(Shop $shop, int $userId): UserShopRole
    {
        return UserShopRole::where('shop_id', $shop->id)->where('user_id', $userId)->with('user')->firstOrFail();
    }

    private function assertCanManage(User $actor, Role $actorRole, UserShopRole $target): void
    {
        if ($target->user_id === $actor->id) {
            abort(403, 'You can\'t change your own account here. Use your account settings.');
        }

        if ($target->role === Role::Owner) {
            abort(403, 'The owner\'s account can\'t be changed from here.');
        }

        if ($actorRole === Role::Manager && $target->role !== Role::Cashier) {
            abort(403, 'Managers can only manage cashiers.');
        }
    }

    /** @return array<string, mixed> */
    private function present(UserShopRole $membership, User $actor, mixed $lastActiveAt): array
    {
        $user = $membership->user;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $membership->role->value,
            'status' => $user->status,
            'last_active_at' => $lastActiveAt ? Carbon::parse($lastActiveAt)->toIso8601String() : null,
            'is_you' => $user->id === $actor->id,
        ];
    }
}
