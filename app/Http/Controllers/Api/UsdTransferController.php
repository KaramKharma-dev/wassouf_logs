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

        // سياسة التسعير
        $UNIT_PRICE = 1.1236;

        // حدود الرسالة و رسوم كل رسالة
        $PER_MESSAGE_MAX = 3.0;     // أقصى مبلغ يمكن إرساله في رسالة واحدة
        $FEE_PER_MESSAGE = 0.14;    // الرسوم لكل رسالة

        // تقبل أي مبلغ موجب
        $amount = (float) $data['amount_usd'];
        if ($amount <= 0) {
            return response()->json([
                'ok' => false,
                'error' => 'amount_must_be_positive'
            ], 422);
        }

        // حساب عدد الرسائل والرسوم الإجمالية
        $messagesCount = (int) ceil($amount / $PER_MESSAGE_MAX);
        $fees = round($messagesCount * $FEE_PER_MESSAGE, 4);

        // حساب السعر النهائي
        $price = round($amount * $UNIT_PRICE, 4);

        // اختيار sender حسب المزود
        $senderNumber = $data['provider'] === 'mtc' ? '81764824' : '81222749';

        // تطبيع رقم المستلم إلى 8 خانات محلية
        $receiver = $this->normalizeMsisdn($data['receiver_number']);

        $payload = [
            'sender_number'   => $senderNumber,
            'receiver_number' => $receiver,
            'amount_usd'      => $amount,
            'fees'            => $fees,
            'price'           => $price,
            'provider'        => $data['provider'],
            // optional: include messages count for auditing
            'meta'            => json_encode(['messages_count' => $messagesCount]),
        ];

        return DB::transaction(function () use ($payload) {

            $row = UsdTransfer::create($payload);

            // خصم من رصيد المزود: amount + fees
            Balance::adjust($payload['provider'], -1 * ($payload['amount_usd'] + $payload['fees']));

            // زيادة محفظتي بالسعر النهائي
            Balance::adjust('my_balance', $payload['price']);

            return response()->json([
                'ok'  => true,
                'id'  => $row->id,
                'msg' => 'stored; balances updated',
            ], 201);
        });
    }

    private function normalizeMsisdn(string $n): string
    {
        // keep digits only
        $n = preg_replace('/\D+/', '', $n);

        // remove prefixes 00961 or 961 if present
        if (str_starts_with($n, '00961')) {
            $n = substr($n, 5);
        } elseif (str_starts_with($n, '961')) {
            $n = substr($n, 3);
        }

        // if longer than 8 take last 8 digits
        if (strlen($n) > 8) {
            $n = substr($n, -8);
        }

        return $n;
    }
}
