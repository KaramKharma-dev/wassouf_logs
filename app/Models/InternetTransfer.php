<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InternetTransfer extends Model
{
    protected $table = 'internet_transfers';

    protected $fillable = [
        'sender_number','receiver_number','quantity_gb','price','provider','type'
    ];

    protected $casts = [
        'quantity_gb' => 'decimal:3',
        'price'       => 'decimal:2',
    ];
}
