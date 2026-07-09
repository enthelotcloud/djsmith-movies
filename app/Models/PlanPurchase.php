<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlanPurchase extends Model
{
    protected $fillable = ['user_id', 'plan_name', 'amount_paid', 'duration_minutes', 'expires_at'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }
}