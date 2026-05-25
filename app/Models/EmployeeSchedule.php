<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class EmployeeSchedule extends Model
{
    public $incrementing = false;

    protected $keyType = 'int';

    protected $table = 'employee_schedules';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'daily_hours' => 'decimal:2',
            'is_working_day' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }
}