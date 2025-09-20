<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UsdTransfer extends Model
{
    protected $table = 'usd_transfers';

    protected $fillable = [
        'sender_number','receiver_number','amount_usd',
        'confirmed_amount_usd','confirmed_messages','fees','price','provider',
        'exp_msg_3','exp_msg_2_5','exp_msg_2','exp_msg_1_5','exp_msg_1','exp_msg_0_5',
    ];

    protected $casts = [
        'amount_usd'            => 'float',
        'confirmed_amount_usd'  => 'float',
        'fees'                  => 'float',
        'price'                 => 'float',

        'exp_msg_3'   => 'integer',
        'exp_msg_2_5' => 'integer',
        'exp_msg_2'   => 'integer',
        'exp_msg_1_5' => 'integer',
        'exp_msg_1'   => 'integer',
        'exp_msg_0_5' => 'integer',
    ];
}
