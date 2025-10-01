<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashEntry extends Model
{
    protected $table = 'cash_entries';

    public const TYPE_RECEIPT = 'RECEIPT'; // قبض
    public const TYPE_PAYMENT = 'PAYMENT'; // دفع

    protected $fillable = ['description','entry_type','amount','image_path'];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    // سكوبات مفيدة
    public function scopeReceipts($q){ return $q->where('entry_type', self::TYPE_RECEIPT); }
    public function scopePayments($q){ return $q->where('entry_type', self::TYPE_PAYMENT); }
    public function getImageUrlAttribute(): ?string
    {
        return $this->image_path ? \Storage::disk('public')->url($this->image_path) : null;
    }

}
