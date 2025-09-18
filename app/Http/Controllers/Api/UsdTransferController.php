<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UsdTransfer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UsdTransferController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'receiver_number' => ['required','string','max:20'],
            'amount_usd'      => ['required','numeric'],
            'provider'        => ['required', Rule::in(['alfa','mtc'])],
        ]);

        $UNIT_PRICE = 1.1236;
        $amount = (float) $data['amount_usd'];
        if ($amount <= 0) {
            return response()->json(['ok'=>false,'error'=>'amount_must_be_positive'], 422);
        }

        $senderNumber = $data['provider'] === 'mtc' ? '81764824' : '81222749';
        $receiver = $this->normalizeMsisdn($data['receiver_number']);
        $price = round($amount * $UNIT_PRICE, 4);

        $payload = [
            'sender_number'         => $senderNumber,
            'receiver_number'       => $receiver,
            'amount_usd'            => $amount,
            'confirmed_amount_usd'  => 0,
            'confirmed_messages'    => 0,
            'fees'                  => 0,      // ستتراكم على مستوى الإيصالات
            'price'                 => $price, // السعر الإجمالي المتوقع
            'provider'              => $data['provider'],
        ];

        return DB::transaction(function () use ($payload) {
            $row = UsdTransfer::create($payload);
            return response()->json(['ok'=>true,'id'=>$row->id,'msg'=>'usd transfer created (pending)'], 201);
        });
    }

    private function normalizeMsisdn(string $n): string
    {
        $n = preg_replace('/\D+/', '', $n);
        if (str_starts_with($n, '00961')) $n = substr($n, 5);
        elseif (str_starts_with($n, '961')) $n = substr($n, 3);
        if (strlen($n) > 8) $n = substr($n, -8);
        return $n;
    }
}
