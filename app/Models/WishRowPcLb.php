<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WishRowPcLb extends Model
{
    protected $table = 'wish_rows_pc_lb';

    protected $fillable = [
        'batch_id','seq_no','op_date','reference','service','description',
        'debit','credit','balance_after','row_status','row_hash'
    ];

    protected $casts = [
        'op_date' => 'date:Y-m-d',
        'debit' => 'float',
        'credit' => 'float',
        'balance_after' => 'float',
    ];

    public $timestamps = true;
}
