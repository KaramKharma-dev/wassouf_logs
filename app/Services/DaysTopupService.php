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
        // أقفل أي جلسات انتهت نافذتها حتى لحظة هذه الرسالة
        $this->closeExpiredSessions($ts);

        // تحقق القيم المسموحة
        $allowed = Config::get('days_topup.allowed_msg_values', []);
        if (!in_array($amount, $allowed, true)) {
            return;
        }

        DB::transaction(function () use ($msisdn, $provider, $amount, $ts) {
            // خزّن الرسالة
            UsdInMsg::create([
                'msisdn'      => $msisdn,
                'provider'    => $provider,
                'amount'      => $amount,
                'received_at' => $ts,
            ]);

            $now = $ts->copy();
            $windowHours  = (int) Config::get('days_topup.window_hours', 5);
            $windowEndsAt = $now->copy()->addHours($windowHours);

            // جلسة مفتوحة لنفس الرقم+المزوّد
            $session = DaysTransfer::query()
                ->where('receiver_number', $msisdn)
                ->where('provider', $provider)
                ->where('status', 'OPEN')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if (!$session) {
                $session = DaysTransfer::create([
                    'sender_number'     => $msisdn,
                    'receiver_number'   => $msisdn,
                    'provider'          => $provider,
                    'amount_usd'        => 0,
                    'sum_incoming_usd'  => 0,
                    'months_count'      => 0,
                    'price'             => 0,
                    'status'            => 'OPEN',
                    'window_started_at' => $now,
                    'window_ends_at'    => $windowEndsAt,
                ]);
            }

            // تراكم
            $session->sum_incoming_usd = bcadd((string)$session->sum_incoming_usd, (string)$amount, 2);
            $session->save();

            // أقفل بعد الإدخال أيضاً إن وُجد ما انتهى الآن
            $this->closeExpiredSessions($ts);
        });
    }

    public function closeExpiredSessions(?Carbon $now = null, bool $force = false): int
    {
        $now = $now ?: now();

        $q = DaysTransfer::query()->where('status','OPEN');
        if (!$force) {
            $q->whereNotNull('window_ends_at')->where('window_ends_at','<=',$now);
        }

        $sessions = $q->orderBy('id')->get();
        $count = 0;

        foreach ($sessions as $s) {
            DB::transaction(function () use ($s) {
                $sum = (float) $s->sum_incoming_usd;

                [$months, $rule] = $this->classifyMonths($sum);
                $expected = $this->buildExpectedVouchers($s->provider, $months, $sum);

                $pricing = Config::get('days_topup.pricing', []);
                $price   = isset($pricing[$months]) ? (float)$pricing[$months] : 0.0;

                // حدّث السجل
                $s->months_count      = $months;
                $s->price             = $price;
                $s->expectation_rule  = $rule;
                $s->expected_vouchers = $expected;
                $s->status            = 'PENDING_RECON';
                $s->save();

                // تحديث الأرصدة
                Balance::adjust($s->provider, +$sum);
                Balance::adjust('my_balance', +$price);
            });
            $count++;
        }
        return $count;
    }

    private function classifyMonths(float $sum): array
    {
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
        $map = Config::get("days_topup.vouchers.$provider", []);
        $key = (string) $months;
        if (!isset($map[$key])) return [];

        $sumKey = number_format($sum, 2, '.', '');
        return $map[$key][$sumKey] ?? [];
    }
}
