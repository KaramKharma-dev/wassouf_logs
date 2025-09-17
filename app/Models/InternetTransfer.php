<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InternetTransfer extends Model
{
    protected $fillable = [
        'sender_number','receiver_number','quantity_gb','price',
        'provider','type','status','idempotency_key',
        'confirmed_at','sms_hash','sms_meta',
    ];

    protected $casts = [
        'quantity_gb'  => 'float',
        'confirmed_at' => 'datetime',
        'sms_meta'     => 'array',
    ];
}
