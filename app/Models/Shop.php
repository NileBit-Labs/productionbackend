<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shop extends Model
{
    protected $fillable = ['organization_id', 'name', 'business_type', 'phone', 'address', 'status'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** Today's date on the shop's own calendar, which is not always today in UTC (Kampala is 3 hours ahead). */
    public function today(): string
    {
        return Carbon::now($this->organization?->timezone ?: 'Africa/Kampala')->toDateString();
    }

    public function userRoles(): HasMany
    {
        return $this->hasMany(UserShopRole::class);
    }
}
