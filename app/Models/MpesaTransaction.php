<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MpesaTransaction extends Model
{
    protected $fillable = [
        'user_id', 'merchant_request_id', 'checkout_request_id', 
        'amount', 'phone', 'mpesa_receipt_number', 'status', 'result_desc'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}