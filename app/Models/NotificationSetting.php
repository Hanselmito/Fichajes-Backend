<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class NotificationSetting extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'int';

    protected $table = 'notification_settings';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'email_enabled' => 'boolean',
            'push_enabled' => 'boolean',
            'missed_checkin_enabled' => 'boolean',
            'vacation_notifications' => 'boolean',
            'modification_notifications' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}