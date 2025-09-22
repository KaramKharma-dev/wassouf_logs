<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UsdInMsg extends Model
{
    protected $table = 'usd_in_msgs';
    protected $fillable = ['msisdn','provider','amount','received_at'];
    protected $casts = [
        'received_at' => 'datetime',
        'amount'      => 'decimal:2',
    ];
}
