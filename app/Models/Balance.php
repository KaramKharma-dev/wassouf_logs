<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Balance extends Model
{
    protected $table = 'balances';
    protected $fillable = ['provider','balance'];
    protected $casts = ['balance' => 'decimal:4'];

    public static function adjust(string $provider, float $delta): void
    {
        DB::table('balances')
          ->where('provider', $provider)
          ->update(['balance' => DB::raw("balance + ($delta)"), 'updated_at' => now()]);
    }
}
