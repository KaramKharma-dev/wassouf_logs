<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WishRowRaw extends Model
{
    protected $table = 'wish_rows_raw';

    protected $fillable = [
            'batch_id','seq_no','op_date','reference','service','description',
            'debit','credit','balance_after','row_status','row_hash',
        ];


    protected $casts = [
        'op_date'       => 'date',
        'debit'         => 'decimal:2',
        'credit'        => 'decimal:2',
        'balance_after' => 'decimal:2',
    ];

    public function batch(): BelongsTo {
        return $this->belongsTo(WishBatch::class, 'batch_id');
    }
}
