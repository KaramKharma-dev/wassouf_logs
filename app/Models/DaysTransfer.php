<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DaysTransfer extends Model
{
    protected $table = 'days_transfers';
    protected $fillable = [
        'sender_number','receiver_number','amount_usd','months_count','price',
        'provider','status','window_started_at','window_ends_at',
        'sum_incoming_usd','expected_vouchers','expectation_rule','reconciled_at'
    ];
    protected $casts = [
        'expected_vouchers' => 'array',
        'window_started_at' => 'datetime',
        'window_ends_at'    => 'datetime',
        'reconciled_at'     => 'datetime',
        'amount_usd'        => 'decimal:2',
        'sum_incoming_usd'  => 'decimal:2',
        'price'             => 'decimal:3',
    ];

    // نطاق لايجاد جلسة مفتوحة لرقم+مزود
    public function scopeOpenFor($q, string $msisdn, string $provider)
    {
        return $q->where('receiver_number',$msisdn)
                 ->where('provider',$provider)
                 ->where('status','OPEN');
    }
}
