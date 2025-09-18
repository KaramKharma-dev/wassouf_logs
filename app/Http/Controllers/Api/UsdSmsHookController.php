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

        [$chunkAmount, $receiverRaw] = $this->parse($data['provider'], $data['body']);
        if ($chunkAmount === null || $receiverRaw === null) {
            return response()->json(['ok'=>false,'error'=>'parse_failed'], 422);
        }

        $UNIT_PRICE = 1.1236;
        $FEE_PER_MESSAGE = 0.14;

        $receiver = $this->normalizeMsisdn($receiverRaw);
        $chunk = (float) number_format((float)$chunkAmount, 1, '.', ''); // 3.0 أو 1.0...

        // ابحث عن أقدم طلب مفتوح لنفس المستلم والمزوّد
        $transfer = UsdTransfer::where('provider', $data['provider'])
            ->where('receiver_number', $receiver)
            ->whereColumn('confirmed_amount_usd', '<', 'amount_usd')
            ->orderBy('created_at', 'asc')
            ->lockForUpdate()
            ->first();

        // إن لم يوجد، ننشئ طلب يغطي الرسالة الواحدة
        if (!$transfer) {
            $transfer = UsdTransfer::create([
                'sender_number'        => ($data['provider'] === 'mtc' ? '81764824' : '81222749'),
                'receiver_number'      => $receiver,
                'amount_usd'           => $chunk,
                'confirmed_amount_usd' => 0,
                'confirmed_messages'   => 0,
                'fees'                 => 0,
                'price'                => round($chunk * $UNIT_PRICE, 4),
                'provider'             => $data['provider'],
            ]);
        }

        $remaining = max((float)$transfer->amount_usd - (float)$transfer->confirmed_amount_usd, 0.0);
        $apply = min($chunk, $remaining);
        if ($apply <= 0) {
            return response()->json(['ok'=>false,'error'=>'no_remaining_amount_to_confirm'], 422);
        }

        $fee   = $FEE_PER_MESSAGE;
        $price = round($apply * $UNIT_PRICE, 4);

        DB::transaction(function () use ($transfer, $apply, $fee, $price, $receiver, $data) {
            // 1) حدّث التحويل
            $transfer->increment('confirmed_amount_usd', $apply);
            $transfer->increment('confirmed_messages', 1);

            // 2) سجّل إيصال الرسالة
            DB::table('usd_transfer_receipts')->insert([
                'usd_transfer_id'  => $transfer->id,
                'receiver_number'  => $receiver,
                'provider'         => $data['provider'],
                'chunk_amount_usd' => $apply,
                'fee_usd'          => $fee,
                'price_usd'        => $price,
                'raw_body'         => $data['body'],
                'received_at'      => now(),
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);

            // 3) الأرصدة: خصم مزوّد، إضافة لمحفظتي
            Balance::adjust($data['provider'], -1 * ($apply + $fee));
            Balance::adjust('my_balance', $price);
        });

        return response()->json([
            'ok' => true,
            'transfer_id' => $transfer->id,
            'confirmed_now' => $apply
        ], 201);
    }

    private function parse(string $provider, string $body): array
    {
        if ($provider === 'alfa') {
            if (preg_match('/USD\s+(\d+(?:\.\d+)?)\b/i', $body, $m1) &&
                preg_match('/mobile\s+number\s+(\+?961\d{8})/i', $body, $m2)) {
                return [$m1[1], $m2[1]];
            }
        }
        if ($provider === 'mtc') {
            if (preg_match('/\$(\d+(?:\.\d+)?)\b/i', $body, $m1) &&
                preg_match('/mobile\s+number\s+(\+?(?:961)?\d{8})/i', $body, $m2)) {
                return [$m1[1], $m2[1]];
            }
        }
        return [null, null];
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
