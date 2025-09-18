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
    private const UNIT_PRICE = 1.1236;  // سعر الدولار الواحد
    private const FEE_PER_MSG = 0.14;   // رسم كل رسالة
    private const SENDER_ALFA = '81222749';
    private const SENDER_MTC  = '81764824';

    public function store(Request $request)
    {
        $data = $request->validate([
            'provider' => ['required', Rule::in(['alfa','mtc'])],
            'body'     => ['required','string','min:10'],
        ]);

        // 1) Parse
        [$chunkAmount, $receiverRaw] = $this->parse($data['provider'], $data['body']);
        if ($chunkAmount === null || $receiverRaw === null) {
            return response()->json(['ok'=>false,'error'=>'parse_failed'], 422);
        }

        // 2) Normalize
        $receiver = $this->normalizeMsisdn($receiverRaw);
        $chunk = (float) number_format((float)$chunkAmount, 1, '.', ''); // 3.0/2.0/1.0
        if (!in_array($chunk, [3.0, 2.0, 1.0], true)) {
            return response()->json(['ok'=>false,'error'=>'unsupported_chunk'], 422);
        }
        $key = $chunk == 3.0 ? 'exp_msg_3' : ($chunk == 2.0 ? 'exp_msg_2' : 'exp_msg_1');

        // 3) TX: طابق تحويل يتوقع نفس الجزء، مع تحديث ذري يحمي من النزول تحت الصفر
        $result = DB::transaction(function () use ($data, $receiver, $chunk, $key) {

            // أ) ابحث عن تحويل مفتوح يتوقّع نفس قيمة الرسالة
            $transfer = UsdTransfer::where('provider', $data['provider'])
                ->where('receiver_number', $receiver)
                ->whereColumn('confirmed_amount_usd','<','amount_usd')
                ->where($key,'>',0)
                ->orderBy('created_at','asc')
                ->lockForUpdate()
                ->first();

            // ب) إن لم يوجد، أنشئ تحويل جديد بنفس قيمة الرسالة (failsafe منطقي)
            if (!$transfer) {
                $transfer = UsdTransfer::create([
                    'sender_number'        => $data['provider'] === 'mtc' ? self::SENDER_MTC : self::SENDER_ALFA,
                    'receiver_number'      => $receiver,
                    'amount_usd'           => $chunk,
                    'confirmed_amount_usd' => 0,
                    'confirmed_messages'   => 0,
                    'fees'                 => 0,
                    'price'                => round($chunk * self::UNIT_PRICE, 4),
                    'provider'             => $data['provider'],
                    'exp_msg_3'            => $chunk == 3.0 ? 1 : 0,
                    'exp_msg_2'            => $chunk == 2.0 ? 1 : 0,
                    'exp_msg_1'            => $chunk == 1.0 ? 1 : 0,
                ]);
            }

            // ج) احسب الجزء المطبق
            $remaining = max((float)$transfer->amount_usd - (float)$transfer->confirmed_amount_usd, 0.0);
            $apply = min($chunk, $remaining);
            if ($apply <= 0) {
                return ['error' => 'no_remaining_amount_to_confirm'];
            }

            $fee   = self::FEE_PER_MSG;
            $price = round($apply * self::UNIT_PRICE, 4);

            // د) تحديث ذري بشرط العدّاد > 0
            $updated = UsdTransfer::where('id', $transfer->id)
                ->where($key, '>', 0)
                ->update([
                    'confirmed_amount_usd' => DB::raw("confirmed_amount_usd + {$apply}"),
                    'confirmed_messages'   => DB::raw('confirmed_messages + 1'),
                    'fees'                 => DB::raw("fees + {$fee}"),
                    $key                   => DB::raw("{$key} - 1"),
                    'updated_at'           => now(),
                ]);

            // هـ) إن فشل بسبب سباق، جرّب تحويلًا آخر يتوقّع نفس الجزء
            if ($updated === 0) {
                $other = UsdTransfer::where('provider', $data['provider'])
                    ->where('receiver_number', $receiver)
                    ->whereColumn('confirmed_amount_usd','<','amount_usd')
                    ->where($key,'>',0)
                    ->orderBy('created_at','asc')
                    ->lockForUpdate()
                    ->first();

                if (!$other) {
                    return ['error' => 'no_transfer_expects_this_chunk'];
                }

                $ok = UsdTransfer::where('id', $other->id)
                    ->where($key, '>', 0)
                    ->update([
                        'confirmed_amount_usd' => DB::raw("confirmed_amount_usd + {$apply}"),
                        'confirmed_messages'   => DB::raw('confirmed_messages + 1'),
                        'fees'                 => DB::raw("fees + {$fee}"),
                        $key                   => DB::raw("{$key} - 1"),
                        'updated_at'           => now(),
                    ]);

                if ($ok === 0) {
                    return ['error' => 'race_conflict_chunk_counter'];
                }

                $transfer = $other; // التحويل الذي تم تحديثه فعليًا
            }

            // و) إيصال الرسالة
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

            // ز) الأرصدة
            Balance::adjust($data['provider'], -1 * ($apply + $fee));
            Balance::adjust('my_balance', $price);

            return [
                'transfer_id'   => $transfer->id,
                'confirmed_now' => $apply,
            ];
        });

        if (isset($result['error'])) {
            return response()->json(['ok'=>false,'error'=>$result['error']], 422);
        }

        return response()->json(['ok'=>true] + $result, 201);
    }

    // -------- Helpers --------

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
        if (str_starts_with($n, '00961')) {
            $n = substr($n, 5);
        } elseif (str_starts_with($n, '961')) {
            $n = substr($n, 3);
        }
        if (strlen($n) > 8) {
            $n = substr($n, -8);
        }
        return $n;
    }
}
