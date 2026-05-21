<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class ModificationRequest extends Model
{
    const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'new_date' => 'date',
            'approved_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(Record::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
