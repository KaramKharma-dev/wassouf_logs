<?php

namespace App\Services;

use App\Models\DaysTransfer;
use App\Models\UsdInMsg;
use App\Models\Balance;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DaysTopupService
{
    public function ingestUsdMsg(string $msisdn, string $provider, float $amount, Carbon $ts): void
    {
        // تحقق قيمة الرسالة
        $allowed = Config::get('days_topup.allowed_msg_values', []);
        if (!in_array($amount, $allowed, true)) {
            // تخزينها ممكن كرسالة "غير مصنّفة" إذا رغبت لاحقاً
            return;
        }

        DB::transaction(function () use ($msisdn, $provider, $amount, $ts) {
            // خزّن الرسالة الخام للرجوع
            UsdInMsg::create([
                'msisdn'      => $msisdn,
                'provider'    => $provider,
                'amount'      => $amount,
                'received_at' => $ts,
            ]);

            $now = $ts->copy();
            $windowHours = (int) Config::get('days_topup.window_hours', 5);
            $windowEndsAt = $now->copy()->addHours($windowHours);

            // ابحث عن جلسة مفتوحة لنفس الرقم+المزوّد
            $session = DaysTransfer::query()
                ->where('receiver_number', $msisdn)
                ->where('provider', $provider)
                ->where('status', 'OPEN')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if (!$session) {
                // أنشئ جلسة جديدة
                $session = DaysTransfer::create([
                    'sender_number'   => $msisdn,      // يمكن لاحقاً التمييز بين sender/receiver حسب تدفقك
                    'receiver_number' => $msisdn,
                    'provider'        => $provider,
                    'amount_usd'      => 0,
                    'sum_incoming_usd'=> 0,
                    'months_count'    => 0,
                    'price'           => 0,
                    'status'          => 'OPEN',
                    'window_started_at'=> $now,
                    'window_ends_at'   => $windowEndsAt,
                ]);
            }

            // راكم المجموع وحدّث نافذة الانتهاء إن لزم
            $session->sum_incoming_usd = bcadd((string)$session->sum_incoming_usd, (string)$amount, 2);

            // لا تمدد النافذة. الإغلاق يتم بعد 5 ساعات من أول رسالة.
            $session->save();
        });
    }

    public function closeExpiredSessions(?Carbon $now = null): int
    {
        $now = $now ?: now();
        $count = 0;

        // اجلب الجلسات التي انتهت نافذتها وما زالت OPEN
        $sessions = DaysTransfer::query()
            ->where('status', 'OPEN')
            ->whereNotNull('window_ends_at')
            ->where('window_ends_at', '<=', $now)
            ->orderBy('id')
            ->get();

        foreach ($sessions as $s) {
            DB::transaction(function () use ($s) {
                $sum = (float) $s->sum_incoming_usd;
                [$months, $rule] = $this->classifyMonths($sum);

                // حدد الكروت المتوقعة
                $expected = $this->buildExpectedVouchers($s->provider, $months, $sum);

                // حدّث السعر وفق التسعير
                $pricing = Config::get('days_topup.pricing', []);
                $price = isset($pricing[$months]) ? (float)$pricing[$months] : 0.0;

                // حدّث السجل
                $s->months_count      = $months;
                $s->price             = $price;
                $s->expectation_rule  = $rule;
                $s->expected_vouchers = $expected;
                $s->status            = 'PENDING_RECON';
                $s->save();

                // تأثير مالي لحظي:
                // 1) زيد رصيد مزود الدولار (alfa|mtc) بقيمة sum
                Balance::adjust($s->provider, +$sum);

                // 2) زيد my_balance بسعر البيع المحسوب
                Balance::adjust('my_balance', +$price);
            });

            $count++;
        }

        return $count;
    }

    private function classifyMonths(float $sum): array
    {
        // حدودك:
        // 3 ≤ sum ≤ 6 => 1 شهر
        // >6 && <21  => 3 أشهر
        // >21 && <35.5 => 6 أشهر
        // ≥35.5 => 12 شهر
        if ($sum >= 3.00 && $sum <= 6.00) {
            return [1, 'RANGE_1M'];
        } elseif ($sum > 6.00 && $sum < 21.00) {
            return [3, 'RANGE_3M'];
        } elseif ($sum > 21.00 && $sum < 35.50) {
            return [6, 'RANGE_6M'];
        } else {
            return [12, 'RANGE_12M'];
        }
    }

    private function buildExpectedVouchers(string $provider, int $months, float $sum): array
    {
        // نطابق بالمبالغ الدقيقة إن موجودة، وإلا نرجع قائمة فارغة كمؤشّر "توقع عام"
        $map = Config::get("days_topup.vouchers.$provider", []);
        $key = (string) $months;
        if (!isset($map[$key])) return [];

        // نحاول مطابقة sum بالمفتاح النصي بالضبط
        $bucket = $map[$key]; // مثال: ['18.00'=>[4.5,7.58,7.58], '21.00'=>[22.73]]
        $sumKey = number_format($sum, 2, '.', '');
        if (array_key_exists($sumKey, $bucket)) {
            return $bucket[$sumKey];
        }

        // حالات 1 شهر: 3.00 أو 6.00 فقط
        // باقي الحالات إن لم تطابق بالضبط نعيد []
        return [];
    }
}
