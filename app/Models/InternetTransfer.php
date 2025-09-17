<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InternetTransfer extends Model
{
    protected $table = 'internet_transfers';

    protected $fillable = [
        'receiver_number','quantity_gb','provider','type',
        'status','confirmed_at','sms_hash','sms_meta','idempotency_key'
    ];

    protected $casts = [
        'quantity_gb' => 'decimal:3',
        'price'       => 'decimal:2',
        'quantity_gb' => 'float',
        'confirmed_at'=> 'datetime',
        'sms_meta'    => 'array',

    ];
}
