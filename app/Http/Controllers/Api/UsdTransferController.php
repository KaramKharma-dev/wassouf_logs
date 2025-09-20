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

        // تحويل إلى "أنصاف دولار" كعداد صحيح
        $halfSteps = (int) round($amount * 2); // 0.5$ = 1 خطوة
        // تحقق أن المبلغ من مضاعفات 0.5$
        if (abs($amount - ($halfSteps / 2)) > 0.00001) {
            return response()->json([
                'ok'=>false,
                'error'=>'amount_must_be_multiple_of_0_5',
                'got'=>$amount
            ], 422);
        }

        // تفكيك greedy إلى 3.0 و 2.5 و 2.0 و 1.5 و 1.0 و 0.5
        // 3.0$ = 6 خطوات، 2.5$ = 5، 2.0$ = 4، 1.5$ = 3، 1.0$ = 2، 0.5$ = 1
        $exp3   = intdiv($halfSteps, 6);
        $rem    = $halfSteps % 6;

        $exp2_5 = 0; $exp2 = 0; $exp1_5 = 0; $exp1 = 0; $exp0_5 = 0;
        switch ($rem) {
            case 5: $exp2_5 = 1; break;
            case 4: $exp2   = 1; break;
            case 3: $exp1_5 = 1; break;
            case 2: $exp1   = 1; break;
            case 1: $exp0_5 = 1; break;
            case 0: default: /* no-op */ break;
        }

        $senderNumber = $data['provider'] === 'mtc' ? '81764824' : '81222749';
        $receiver     = $this->normalizeMsisdn($data['receiver_number']);
        $price        = round($amount * $UNIT_PRICE, 4);

        $payload = [
            'sender_number'         => $senderNumber,
            'receiver_number'       => $receiver,
            'amount_usd'            => $amount,
            'confirmed_amount_usd'  => 0,
            'confirmed_messages'    => 0,
            'fees'                  => 0,
            'price'                 => $price,
            'provider'              => $data['provider'],

            // العدّادات المتوقعة لكل رسالة
            'exp_msg_3'             => $exp3,
            'exp_msg_2_5'           => $exp2_5,
            'exp_msg_2'             => $exp2,
            'exp_msg_1_5'           => $exp1_5,
            'exp_msg_1'             => $exp1,
            'exp_msg_0_5'           => $exp0_5,
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
