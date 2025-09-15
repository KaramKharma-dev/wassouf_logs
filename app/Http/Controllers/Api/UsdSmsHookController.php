<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use App\Models\UsdTransfer;
use App\Models\Balance;

class UsdSmsHookController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'provider' => ['required', Rule::in(['alfa','mtc'])],
            'body'     => ['required','string','min:10'],
        ]);

        $SENDER_NUMBER = $data['provider'] === 'mtc' ? '81764824' : '81222749';
        $FEES = 0.14;

        // جدول الأسعار بالضبط كما زوّدتني
        $priceTable = [
            3.0 => 3.37,
            2.5 => 2.81,
            2.0 => 2.247,
            1.5 => 1.685,
            1.0 => 1.123,
            0.5 => 0.561,
        ];

        [$amount, $receiver] = $this->parse($data['provider'], $data['body']);
        if ($amount === null || $receiver === null) {
            return response()->json(['ok'=>false,'error'=>'parse_failed'], 422);
        }

        // تطبيع خانة عشرية واحدة
        $amount = (float) number_format((float)$amount, 1, '.', '');
        if (!array_key_exists($amount, $priceTable)) {
            return response()->json(['ok'=>false,'error'=>'amount_usd_not_allowed','allowed'=>array_keys($priceTable)], 422);
        }
        $price = $priceTable[$amount];
        $receiver = $this->normalizeMsisdn($receiver);

        $payload = [
            'sender_number'   => $SENDER_NUMBER,
            'receiver_number' => $receiver,
            'amount_usd'      => $amount,
            'fees'            => $FEES,
            'price'           => $price,
            'provider'        => $data['provider'],
        ];

        return DB::transaction(function () use ($payload) {
            $row = UsdTransfer::create($payload);
            Balance::adjust($payload['provider'], -1 * ($payload['amount_usd'] + $payload['fees']));
            Balance::adjust('my_balance', $payload['price']);
            return response()->json(['ok'=>true,'id'=>$row->id], 201);
        });
    }

    private function parse(string $provider, string $body): array
    {
        if ($provider === 'alfa') {
            if (preg_match('/USD\s+(\d+(?:\.\d+)?)\b/i', $body, $m1) &&
                preg_match('/mobile\s+number\s+(\+?(?:961)?\d{8})/i', $body, $m2)) {
                return [$m1[1], $m2[1]];
            }
        }

        if ($provider === 'mtc') {
            // يقبل $3 أو $3.0 ويقبل رقم 8 خانات محلي أو مع 961/+961/00961
            if (preg_match('/\$(\d+(?:\.\d+)?)/i', $body, $m1) &&
                preg_match('/mobile\s+number\s+(\+?(?:961)?\d{8})/i', $body, $m2)) {
                return [$m1[1], $m2[1]];
            }
        }

        return [null, null];
    }


    private function normalizeMsisdn(string $n): string
    {
        // أرقام فقط
        $n = preg_replace('/\D+/', '', $n);

        // إزالة بادئات لبنان إن وجدت: +961 / 00961 / 961
        if (str_starts_with($n, '00961')) {
            $n = substr($n, 5);
        } elseif (str_starts_with($n, '961')) {
            $n = substr($n, 3);
        }

        // في جميع الأحوال خزّن آخر 8 خانات إذا كانت أطول
        if (strlen($n) > 8) {
            $n = substr($n, -8);
        }

        return $n; // مثال: 96181222748 -> 81222748
    }

}
