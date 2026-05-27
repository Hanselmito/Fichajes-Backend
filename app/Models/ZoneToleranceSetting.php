<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class ZoneToleranceSetting extends Model
{
    public $incrementing = false;

    protected $keyType = 'int';

    protected $table = 'zone_tolerance_settings';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'notify_coordinator' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }
}