<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UsdTransfer extends Model
{
    protected $table = 'usd_transfers';

    protected $fillable = [
        'sender_number',
        'receiver_number',
        'amount_usd',
        'fees',
        'price',
        'provider',
    ];

    protected $casts = [
        'amount_usd' => 'decimal:2',
        'fees'       => 'decimal:2',
        'price'      => 'decimal:2',
    ];
}
