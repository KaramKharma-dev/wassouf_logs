<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DaysTransfer extends Model
{
    protected $table = 'days_transfers';
    protected $fillable = [
        'op_date','sender_number','receiver_number','amount_usd','months_count','price',
        'provider','status','sum_incoming_usd','expected_vouchers','expectation_rule'
    ];
    protected $casts = [
        'expected_vouchers'=>'array',
        'amount_usd'=>'decimal:2',
        'sum_incoming_usd'=>'decimal:2',
        'price'=>'decimal:3',
    ];
}
