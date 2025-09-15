<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UsdTransfer;
use App\Models\Balance;
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

        // 2) ثوابت وسياسة التسعير
        $SENDER_NUMBER = '81222749';
        $FEES = 0.14;

        $priceTable = [
            3.0  => 3.3707,
            2.5  => 2.81,
            2.0  => 2.247,
            1.5  => 1.685,
            1.0  => 1.123,
            0.5  => 0.561,
        ];

        $amount = (float) number_format((float)$data['amount_usd'], 1, '.', '');

        if (!array_key_exists($amount, $priceTable)) {
            return response()->json([
                'ok' => false,
                'error' => 'amount_usd_not_allowed',
                'allowed' => array_keys($priceTable),
            ], 422);
        }

        $price = $priceTable[$amount];

        $payload = [
            'sender_number'   => $SENDER_NUMBER,
            'receiver_number' => $data['receiver_number'],
            'amount_usd'      => $amount,
            'fees'            => $FEES,
            'price'           => $price,
            'provider'        => $data['provider'],
        ];

        return DB::transaction(function () use ($payload) {

            $row = UsdTransfer::create($payload);

            Balance::adjust($payload['provider'], -1 * ($payload['amount_usd'] + $payload['fees']));

            Balance::adjust('my_balance', $payload['price']);

            return response()->json([
                'ok'  => true,
                'id'  => $row->id,
                'msg' => 'stored; balances updated',
            ], 201);
        });
    }

}
