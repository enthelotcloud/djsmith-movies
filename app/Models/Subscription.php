<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'status',
        'auto_renew',
        'starts_at',
        'expires_at',
    ];

    /**
     * Ensure dates and booleans are cast correctly when retrieved from the DB.
     */
    protected function casts(): array
    {
        return [
            'auto_renew' => 'boolean',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Relationship back to the User.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}