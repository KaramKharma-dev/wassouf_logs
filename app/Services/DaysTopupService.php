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
    public function addMsgByDate(string $msisdn, string $receiver, string $provider, float $amount, Carbon $ts): DaysTransfer
    {
        $allowed = Config::get('days_topup.allowed_msg_values', []);
        $allowedKeys = array_map(fn($v)=> number_format((float)$v, 2, '.', ''), $allowed);
        $amtKey = number_format((float)$amount, 2, '.', '');
        if (!in_array($amtKey, $allowedKeys, true)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['amount'=>'قيمة غير مسموحة']);
        }

        $opDate = $ts->timezone(config('app.timezone'))->toDateString();

        return DB::transaction(function () use ($msisdn,$receiver,$provider,$amount,$ts,$opDate) {
            // خزّن الرسالة
            UsdInMsg::create([
                'msisdn'          => $msisdn,
                'receiver_number' => $receiver,   // جديد
                'provider'        => $provider,
                'amount'          => $amount,
                'received_at'     => $ts,
            ]);

            // سطر اليوم حسب receiver_number
            $row = DaysTransfer::lockForUpdate()
                ->where('receiver_number',$receiver)
                ->where('provider',$provider)
                ->where('op_date',$opDate)
                ->first();

            if (!$row) {
                $row = DaysTransfer::create([
                    'op_date'           => $opDate,
                    'sender_number'     => $msisdn,
                    'receiver_number'   => $receiver,
                    'provider'          => $provider,
                    'amount_usd'        => 0,
                    'sum_incoming_usd'  => 0,
                    'months_count'      => 0,
                    'price'             => 0,
                    'status'            => 'OPEN',
                    'expected_vouchers' => [],
                    'expectation_rule'  => null,
                ]);
            }

            // التراكم والحسابات
            $row->sum_incoming_usd = bcadd((string)$row->sum_incoming_usd, (string)$amount, 2);

            [$months, $vouchers, $rule, $cap] = $this->decideForToday($provider, (float)$row->sum_incoming_usd);
            $row->months_count      = $months;
            $row->price             = $this->priceOf($months);
            $row->expected_vouchers = $vouchers;
            $row->expectation_rule  = $rule;

            $sumNow  = (float)$row->sum_incoming_usd;
            $remain  = max(0, round($cap - $sumNow, 2));
            $sumKey  = number_format($sumNow, 2, '.', '');
            if (in_array($sumKey, ['3.00','6.00'], true)) {
                $remain = 0.00; // شرطك الخاص
            }
            $row->amount_usd = number_format($remain, 2, '.', '');

            $row->save();
            return $row;
        });
    }
    public function ingestSms(string $provider, string $msg, string $receiver, Carbon $ts): DaysTransfer
    {
        [$msisdn, $amount] = $this->parseSms($provider, $msg);

        $allowed = Config::get('days_topup.allowed_msg_values', []);
        $allowedKeys = array_map(fn($v)=> number_format((float)$v, 2, '.', ''), $allowed);
        $amtKey = number_format((float)$amount, 2, '.', '');
        if (!in_array($amtKey, $allowedKeys, true)) {
            throw ValidationException::withMessages(['amount'=>'قيمة غير مسموحة']);
        }

        return $this->addMsgByDate($msisdn, $receiver, $provider, (float)$amount, $ts);
    }

    private function parseSms(string $provider, string $msg): array
    {
        // amount: "USD 3.0" أو "$3.0"
        $amount = null;
        if (preg_match('/(?:USD|\$)\s*([0-9]+(?:\.[0-9]+)?)/i', $msg, $m)) {
            $amount = (float)$m[1];
        } elseif (preg_match('/([0-9]+(?:\.[0-9]+)?)\s*(?:USD|\$)/i', $msg, $m)) {
            $amount = (float)$m[1];
        }

        // msisdn: بعد "mobile number" أو أي +961########
        $msisdn = null;
        if (preg_match('/mobile\s+number\s+(?:\+?961)?\s?(\d{8})/i', $msg, $m2)) {
            $msisdn = $this->normalizeMsisdn($m2[1]);
        } elseif (preg_match('/(?:\+?961)(\d{8})/', $msg, $m3)) {
            $msisdn = $this->normalizeMsisdn($m3[0]);
        }

        if ($msisdn === null || $amount === null) {
            throw ValidationException::withMessages(['msg'=>'غير قادر على استخراج الرقم أو المبلغ من الرسالة']);
        }
        return [$msisdn, $amount];
    }

    private function normalizeMsisdn(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', $raw);
        if (str_starts_with($digits, '961') && strlen($digits) >= 11) return substr($digits, -8);
        if (strlen($digits) >= 8) return substr($digits, -8);
        return $digits;
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
    public function finalizeDayCorrect(string $receiver, string $provider, string $opDate): DaysTransfer
    {
        return DB::transaction(function () use ($receiver,$provider,$opDate) {
            $row = DaysTransfer::lockForUpdate()
                ->where('receiver_number',$receiver)
                ->where('provider',$provider)
                ->where('op_date',$opDate)
                ->firstOrFail();

            \App\Models\Balance::adjust($provider, +(float)$row->sum_incoming_usd);
            \App\Models\Balance::adjust('my_balance', +$this->priceOf((int)$row->months_count));

            $row->status = 'PENDING_RECON'; // أو RECONCILED لو بدك إغلاق نهائي
            $row->save();
            return $row;
        });
    }


    public function finalizeAllForDate(string $provider, string $opDate): int
    {
        $rows = \App\Models\DaysTransfer::where('provider',$provider)
            ->where('op_date',$opDate)
            ->where('status','OPEN')
            ->lockForUpdate()
            ->get();

        $n = 0;
        foreach ($rows as $row) {
            $this->finalizeDayCorrect($row->receiver_number, $provider, $opDate);
            $n++;
        }
        return $n;
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

        // 1 شهر
        if ($s > 0 && $s <= 3.50)                    return [1, [4.5], 'RANGE_1M_<=3.5', 3.50];
        if ($s > 3.50 && $s <= 6.50)                 return [1, [7.58], 'RANGE_1M_>3.5_<=6.5', 6.50];

        if ($provider === 'alfa') {
            // 3 أشهر (alfa)
            if ($s > 6.50 && $s <= 18.00)            return [3, [4.5,7.58,7.58], 'RANGE_3M_ALFA_>6.5_<=18', 18.00];
            if ($s > 18.00 && $s <= 21.00)           return [3, [7.58,7.58,7.58], 'RANGE_3M_ALFA_>18_<=21', 21.00];
            // 6 أشهر (alfa)
            if ($s > 21.00 && $s <= 32.50)           return [6, [4.5,7.58,7.58,7.58,7.58], 'RANGE_6M_ALFA_>21_<=32.5', 32.50];
            if ($s > 32.50 && $s <= 35.50)           return [6, [7.58,7.58,7.58,7.58,7.58], 'RANGE_6M_ALFA_>32.5_<=35.5', 35.50];
        } else { // mtc
            // 3 أشهر (mtc)
            if ($s > 6.50 && $s <= 21.00)            return [3, [22.73], 'RANGE_3M_MTC_>6.5_<=21', 21.00];
            // 6 أشهر (mtc)
            if ($s > 21.00 && $s <= 42.00)           return [6, [22.73,22.73], 'RANGE_6M_MTC_>21_<=42', 42];
        }

        // 12 شهر
        if ($s > 35.50 && $s <= 73.00)               return [12, [77.28], 'RANGE_12M_>35.5_<=73', 73.00];

        // فُل باك بدون كروت + سقف الرينج الأقرب
        if     ($s >= 3.00  && $s <= 6.00)           return [1,  [], 'FALLBACK_1M', 6.00];
        elseif ($s > 6.00   && $s < 21.00)           return [3,  [], 'FALLBACK_3M', 21.00];
        elseif ($s > 21.00  && $s < 35.50)           return [6,  [], 'FALLBACK_6M', 35.50];
        elseif ($s >= 35.50 && $s < 73.00)           return [12, [], 'FALLBACK_12M', 73.00];

        return [0, [], 'OUT_OF_RANGE', 0.00];
    }



}
