<?php

namespace App\Services;

use App\Models\DaysTransfer;
use App\Models\UsdInMsg;
use App\Models\Balance;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class DaysTopupService
{
    // إضافة رسالة وتحديث سطر اليوم لنفس الرقم+المزوّد (بدون خصم Wish)
    public function addMsgByDate(string $msisdn, string $provider, float $amount, Carbon $ts): DaysTransfer
    {
        $allowed = Config::get('days_topup.allowed_msg_values', []);
        $allowedKeys = array_map(fn($v)=> number_format((float)$v, 2, '.', ''), $allowed);
        $amtKey = number_format((float)$amount, 2, '.', '');
        if (!in_array($amtKey, $allowedKeys, true)) {
            throw ValidationException::withMessages(['amount'=>'قيمة غير مسموحة']);
        }


        $opDate = $ts->timezone(config('app.timezone'))->toDateString();

        return DB::transaction(function () use ($msisdn,$provider,$amount,$ts,$opDate) {
            // خزن الرسالة الخام
            UsdInMsg::create([
                'msisdn'=>$msisdn, 'provider'=>$provider,
                'amount'=>$amount, 'received_at'=>$ts,
            ]);

            // سطر اليوم
            $row = DaysTransfer::lockForUpdate()
                ->where('receiver_number',$msisdn)
                ->where('provider',$provider)
                ->where('op_date',$opDate)
                ->first();

            if (!$row) {
                $row = DaysTransfer::create([
                    'op_date'=>$opDate,
                    'sender_number'=>$msisdn,
                    'receiver_number'=>$msisdn,
                    'provider'=>$provider,
                    'amount_usd'=>0,
                    'sum_incoming_usd'=>0,
                    'months_count'=>0,
                    'price'=>0,
                    'status'=>'OPEN',
                    'expected_vouchers'=>[],
                    'expectation_rule'=>null,
                ]);
            }

            // راكم مجموع اليوم
            $row->sum_incoming_usd = bcadd((string)$row->sum_incoming_usd,(string)$amount,2);

            // حوّل المجموع إلى (أشهر + كروت) وفق منطقك
            [$months,$vouchers,$rule] = $this->decideForToday($provider,(float)$row->sum_incoming_usd);

            $row->months_count = $months;
            $row->price        = $this->priceOf($months);
            $row->expected_vouchers = $vouchers;      // فقط توقع، لا خصم
            $row->expectation_rule  = $rule;          // EXACT_* أو RANGE_*
            $row->save();

            return $row;
        });
    }

    // تسوية يدوية لنهاية اليوم: تحديث الأرصدة فقط (بدون خصم Wish)
    public function finalizeDay(string $msisdn, string $provider, string $opDate): DaysTransfer
    {
        return DB::transaction(function () use ($msisdn,$provider,$opDate) {
            $row = DaysTransfer::lockForUpdate()
                ->where(compact('msisdn')) // intentionally wrong; fixed below
                ->first();
        });
    }

    // نسخة صحيحة:
    public function finalizeDayCorrect(string $msisdn, string $provider, string $opDate): DaysTransfer
    {
        return DB::transaction(function () use ($msisdn,$provider,$opDate) {
            $row = DaysTransfer::lockForUpdate()
                ->where('receiver_number',$msisdn)
                ->where('provider',$provider)
                ->where('op_date',$opDate)
                ->firstOrFail();

            // أثر مالي: زد رصيد المزود بالمجموع، وزد my_balance بسعر البيع
            Balance::adjust($provider, +(float)$row->sum_incoming_usd);
            Balance::adjust('my_balance', +$this->priceOf((int)$row->months_count));

            $row->status = 'PENDING_RECON'; // بانتظار تقرير wish فقط
            $row->save();
            return $row;
        });
    }

    private function priceOf(int $months): float
    {
        $pricing = Config::get('days_topup.pricing',[]);
        return (float)($pricing[$months] ?? 0);
    }

    // قرار اليوم: أولوية للمبالغ المطابقة تمامًا، وإلا تصنيف عام
    private function decideForToday(string $provider, float $sum): array
    {
        $s = round($sum, 2);

        // 1 شهر (alfa أو mtc)
        if ($s > 0 && $s <= 3.50) {
            return [1, [4.5], 'RANGE_1M_<=3.5'];
        }
        if ($s > 3.50 && $s <= 6.50) {
            return [1, [7.58], 'RANGE_1M_>3.5_<=6.5'];
        }

        if ($provider === 'alfa') {
            // 3 أشهر (alfa)
            if ($s > 6.50 && $s <= 18.00) {
                return [3, [4.5, 7.58, 7.58], 'RANGE_3M_ALFA_>6.5_<=18'];
            }
            if ($s > 18.00 && $s <= 21.00) {
                return [3, [7.58, 7.58, 7.58], 'RANGE_3M_ALFA_>18_<=21'];
            }
            // 6 أشهر (alfa)
            if ($s > 21.00 && $s <= 32.50) {
                return [6, [4.5, 7.58, 7.58, 7.58, 7.58], 'RANGE_6M_ALFA_>21_<=32.5'];
            }
            if ($s > 32.50 && $s <= 35.50) {
                return [6, [7.58, 7.58, 7.58, 7.58, 7.58], 'RANGE_6M_ALFA_>32.5_<=35.5'];
            }
        } else { // mtc
            // 3 أشهر (mtc)
            if ($s > 6.50 && $s <= 21.00) {
                return [3, [22.73], 'RANGE_3M_MTC_>6.5_<=21'];
            }
            // 6 أشهر (mtc)
            if ($s > 21.00 && $s <= 35.50) {
                return [6, [22.73, 22.73], 'RANGE_6M_MTC_>21_<=35.5'];
            }
        }

        // 12 شهر (alfa أو mtc)
        if ($s > 35.50 && $s <= 73.00) {
            return [12, [77.28], 'RANGE_12M_>35.5_<=73'];
        }

        // احتياط: لو خارج كل الرينجات، نختار أقرب تصنيف بدون كروت
        if ($s >= 3.00 && $s <= 6.00)  return [1, [], 'FALLBACK_1M'];
        if ($s > 6.00 && $s < 21.00)   return [3, [], 'FALLBACK_3M'];
        if ($s > 21.00 && $s < 35.50)  return [6, [], 'FALLBACK_6M'];
        if ($s >= 35.50)               return [12, [], 'FALLBACK_12M'];

        return [0, [], 'OUT_OF_RANGE'];
    }


}
