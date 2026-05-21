<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class ZoneHoliday extends Model
{
    const UPDATED_AT = null;

    protected $table = 'zone_holidays';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'recurring' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}