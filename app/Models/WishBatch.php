<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WishBatch extends Model
{
    protected $fillable = [
        'filename','checksum','statement_from','statement_to','issued_on',
        'status','sink','currency','rows_total','rows_valid','rows_invalid',
        ];


    protected $casts = [
        'statement_from' => 'date',
        'statement_to'   => 'date',
        'issued_on'      => 'date',
    ];

    public function rows(): HasMany {
        return $this->hasMany(WishRowRaw::class, 'batch_id');
    }
}
