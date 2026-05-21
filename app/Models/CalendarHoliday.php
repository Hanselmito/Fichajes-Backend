<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class CalendarHoliday extends Model
{
    const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'recurring' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function calendar(): BelongsTo
    {
        return $this->belongsTo(Calendar::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
