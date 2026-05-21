<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Model;

class Service extends Model
{
    const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function clients(): BelongsToMany
    {
        return $this->belongsToMany(Client::class, 'client_services')
            ->using(ClientService::class);
    }
}
