<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InternetTransfer;
use App\Models\Balance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class InternetTransferController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'receiver_number' => ['required','string','max:20'],
            'quantity_gb'     => ['required','numeric','min:0.001'],
            'provider'        => ['required', Rule::in(['alfa','mtc'])],
            'type'            => ['required','string','max:50'], // weekly/monthly/monthly_internet
        ]);

        $senderNumber = $data['provider'] === 'mtc' ? '81764824' : '81222749';
        $receiver = $this->normalizeMsisdn($data['receiver_number']);
        $quantity = (float) $data['quantity_gb'];
        $type = $data['type'];
        $provider = $data['provider'];

        // جدول الأسعار
        $pricing = [
            'monthly' => [
                'alfa' => [
                    1   => ['deduct'=>3.5,  'add'=>4],
                    7   => ['deduct'=>9,    'add'=>10],
                    22  => ['deduct'=>14.5, 'add'=>16],
                    44  => ['deduct'=>21,   'add'=>24],
                    77  => ['deduct'=>41,   'add'=>35],
                    111 => ['deduct'=>40,   'add'=>45],
                    444 => ['deduct'=>129,  'add'=>135],
                ],
                'mtc' => [
                    1   => ['deduct'=>3.5,  'add'=>4],
                    7   => ['deduct'=>9,    'add'=>10],
                    22  => ['deduct'=>14.5, 'add'=>16],
                    44  => ['deduct'=>21,   'add'=>24],
                    77  => ['deduct'=>41,   'add'=>35],
                    111 => ['deduct'=>40,   'add'=>45],
                    444 => ['deduct'=>129,  'add'=>135],
                ],
            ],
            'monthly_internet' => [
                'alfa' => [
                    1   => ['deduct'=>3.5,  'add'=>4],
                    7   => ['deduct'=>9,    'add'=>10],
                    22  => ['deduct'=>14.5, 'add'=>16],
                    44  => ['deduct'=>21,   'add'=>24],
                    77  => ['deduct'=>41,   'add'=>35],
                    111 => ['deduct'=>40,   'add'=>45],
                    444 => ['deduct'=>129,  'add'=>135],
                ],
                'mtc' => [
                    1   => ['deduct'=>3.5,  'add'=>4],
                    7   => ['deduct'=>9,    'add'=>10],
                    22  => ['deduct'=>14.5, 'add'=>16],
                    44  => ['deduct'=>21,   'add'=>24],
                    77  => ['deduct'=>41,   'add'=>35],
                    111 => ['deduct'=>40,   'add'=>45],
                    444 => ['deduct'=>129,  'add'=>135],
                ],
            ],
            'weekly' => [
                'alfa' => [
                    0.5  => ['deduct'=>1.67, 'add'=>1.91],
                    1.5  => ['deduct'=>2.34, 'add'=>2.64],
                    5    => ['deduct'=>5,    'add'=>5.617],
                ],
                'mtc' => [
                    0.5  => ['deduct'=>1.67, 'add'=>1.91],
                    1.5  => ['deduct'=>2.34, 'add'=>2.64],
                    5    => ['deduct'=>5,    'add'=>5.617],
                ],
            ],
        ];

        // التحقق من وجود السعر
        if (!isset($pricing[$type][$provider][$quantity])) {
            return response()->json([
                'ok' => false,
                'error' => 'quantity_not_allowed',
                'allowed' => array_keys($pricing[$type][$provider])
            ], 422);
        }

        $deduct = $pricing[$type][$provider][$quantity]['deduct'];
        $price  = $pricing[$type][$provider][$quantity]['add'];

        $payload = [
            'sender_number'   => $senderNumber,
            'receiver_number' => $receiver,
            'quantity_gb'     => $quantity,
            'price'           => $price,
            'provider'        => $provider,
            'type'            => $type,
        ];

        return DB::transaction(function () use ($payload, $deduct) {
            $row = InternetTransfer::create($payload);

            Balance::adjust($payload['provider'], -$deduct);
            Balance::adjust('my_balance', $payload['price']);

            return response()->json([
                'ok' => true,
                'id' => $row->id,
                'msg'=> 'internet transfer stored; balances updated'
            ], 201);
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
