<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AuditLogger
{
    public function record(
        User $user,
        Shop $shop,
        string $action,
        Model $entity,
        ?array $before = null,
        ?array $after = null,
    ): AuditLog {
        return AuditLog::create([
            'organization_id' => $shop->organization_id,
            'shop_id' => $shop->id,
            'user_id' => $user->id,
            'action' => $action,
            'entity_type' => $entity::class,
            'entity_id' => $entity->getKey(),
            'before_data' => $before,
            'after_data' => $after,
        ]);
    }
}
