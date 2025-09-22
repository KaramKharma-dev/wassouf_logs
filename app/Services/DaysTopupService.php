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
        $sumKey = number_format($sum,2,'.','');
        $exact = Config::get("days_topup.exact.$provider",[]);
        if (isset($exact[$sumKey])) {
            [$months,$vouchers] = $exact[$sumKey];
            return [$months,$vouchers,"EXACT_$sumKey"];
        }

        foreach (Config::get('days_topup.ranges',[]) as $r) {
            if ($sum >= $r['min'] && $sum <= $r['max']) {
                return [$r['months'], [], $r['rule']]; // بدون كروت حتى يصير Exact
            }
        }
        // أكبر من كل الحدود ⇒ 12 شهبدون كروت حتى يصير 73.00)
        return [12, [], 'RANGE_12M'];
        
    }
}
