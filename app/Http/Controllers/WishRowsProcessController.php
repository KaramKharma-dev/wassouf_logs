<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class WishRowsProcessController extends Controller
{
    // إعدادات العمولة
    private float $feeRate = 0.01; // 1%

    public function index(Request $r)
    {
        $date = $r->query('date', Carbon::today()->toDateString());
        return view('wish.process', ['date' => $date]);
    }

    public function run(Request $r)
    {
        $data = $r->validate(['date' => ['required','date']]);
        $targetDate = Carbon::parse($data['date'])->toDateString();

        $eligible = DB::table('wish_rows_raw')
            ->whereDate('op_date', $targetDate)
            ->where('row_status', 'VALID')
            ->count();

        $processed = 0; $skipped = 0;

        $rows = DB::table('wish_rows_raw')
            ->whereDate('op_date', $targetDate)
            ->where('row_status', 'VALID')
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            $serviceRaw = trim($row->service ?? '');
            $service = strtoupper($serviceRaw);
            $descRaw = trim($row->description ?? '');
            $desc = strtoupper($descRaw);

            $debit   = $row->debit  !== null ? (float)$row->debit  : null;
            $credit  = $row->credit !== null ? (float)$row->credit : null;

            $isW2W_or_QR_debitOnly      = (in_array($service, ['W2W','QR COLLECT','WEDDING GIFT']) && $debit !== null && $credit === null);
            $isW2W_or_TOPUP_creditOnly  = (in_array($service, ['W2W','TOPUP','WEDDING GIFT'])     && $credit !== null && $debit === null);

            // PAY BY CARD:
            // 1) TIKTOK (debit only)
            $isTiktok = ($service === 'PAY BY CARD' && str_contains($desc, 'TIKTOK') && $debit !== null && $credit === null);
            // 2) غير TIKTOK (debit only) → mb_wish_us -= debit, my_balance += debit*1.10
            $isPayByCardOther = ($service === 'PAY BY CARD' && !str_contains($desc, 'TIKTOK') && $debit !== null && $credit === null);

            // Currency Exchange (debit only, to LBP)
            $isCurrencyEx = ($service === 'CURRENCY EXCHANGE' && $debit !== null && $credit === null);

            // ITUNES* / RAZER / ROBLOX / FREE FIRE / PSN (debit only)
            $isItunes   = (str_contains($service, 'ITUNES') && $debit !== null && $credit === null);
            $isRazer    = ($service === 'RAZER' && $debit !== null && $credit === null);
            $isRoblox   = ($service === 'ROBLOX' && $debit !== null && $credit === null);
            $isFreefire = ($service === 'FREE FIRE' && $debit !== null && $credit === null);
            $isPsn      = ($service === 'PSN' && $debit !== null && $credit === null);

            // TOUCH / ALFA (debit only, description holds $amount)
            $isTouchOrAlfa = (in_array($service, ['TOUCH','ALFA']) && $debit !== null && $credit === null);

            $isAnghami = ($service === 'ANGHAMI' && $debit !== null && $credit === null);

            // CABLEVISION / COLLECTION / CASH OUT / WHISH COLLECT (debit only) → mb_wish_us -= debit, my_balance += debit
            $isCablevision  = ($service === 'CABLEVISION'     && $debit !== null && $credit === null);
            $isCollection   = ($service === 'COLLECTION'      && $debit !== null && $credit === null);
            $isCashout      = ($service === 'CASH OUT'        && $debit !== null && $credit === null);
            $isWishCollect  = ($service === 'WHISH COLLECT'   && $debit !== null && $credit === null);

            // REVERSED W2W (credit only) → mb_wish_us += credit, my_balance -= credit
            $isReversedW2W  = ($service === 'REVERSED W2W'    && $credit !== null && $debit === null);

            if (!($isW2W_or_QR_debitOnly || $isW2W_or_TOPUP_creditOnly || $isTiktok || $isPayByCardOther || $isCurrencyEx
                || $isItunes || $isRoblox || $isFreefire || $isPsn || $isRazer || $isTouchOrAlfa || $isAnghami
                || $isCablevision || $isCollection || $isCashout || $isWishCollect || $isReversedW2W)) {
                $skipped++; continue;
            }

            DB::transaction(function () use (
                $row, $debit, $credit, $descRaw,
                $isW2W_or_QR_debitOnly, $isW2W_or_TOPUP_creditOnly, $isTiktok, $isPayByCardOther, $isCurrencyEx,
                $isItunes, $isRoblox, $isPsn, $isFreefire, $isRazer, $isTouchOrAlfa, $isAnghami,
                $isCablevision, $isCollection, $isCashout, $isWishCollect, $isReversedW2W,
                &$processed, &$skipped
            ) {
                // اختيار الأقفال
                if ($isCurrencyEx) {
                    $providersToLock = ['mb_wish_us','mb_wish_lb'];
                } elseif ($isCablevision || $isCollection || $isCashout || $isWishCollect) {
                    $providersToLock = ['mb_wish_us','my_balance'];
                } else {
                    $providersToLock = ['mb_wish_us','my_balance'];
                }
                DB::table('balances')->whereIn('provider', $providersToLock)->lockForUpdate()->get();

                if ($isW2W_or_QR_debitOnly) {
                    DB::table('balances')->where('provider','mb_wish_us')
                        ->update(['balance' => DB::raw('balance - '.sprintf('%.2f',$debit))]);
                    DB::table('balances')->where('provider','my_balance')
                        ->update(['balance' => DB::raw('balance + '.sprintf('%.2f',$debit))]);

                } elseif ($isW2W_or_TOPUP_creditOnly) {
                    // payout = floor( credit / 1.01 ) إلى السنت
                    $payout = $this->payoutFloorFromCredit($credit, $this->feeRate);

                    DB::table('balances')->where('provider','mb_wish_us')
                        ->update(['balance' => DB::raw('balance + '.sprintf('%.2f',$credit))]);
                    DB::table('balances')->where('provider','my_balance')
                        ->update(['balance' => DB::raw('balance - '.sprintf('%.2f',$payout))]);

                } elseif ($isTiktok) {
                    DB::table('balances')->where('provider','mb_wish_us')
                        ->update(['balance' => DB::raw('balance - '.sprintf('%.2f',$debit))]);
                    DB::table('balances')->where('provider','my_balance')
                        ->update(['balance' => DB::raw('balance + '.sprintf('%.2f',$debit))]);

                } elseif ($isPayByCardOther) {
                    // mb_wish_us -= debit ، my_balance += debit * 1.10
                    DB::table('balances')->where('provider','mb_wish_us')
                        ->update(['balance' => DB::raw('balance - '.sprintf('%.2f', $debit))]);
                    $toAdd = $debit * 1.10;
                    DB::table('balances')->where('provider','my_balance')
                        ->update(['balance' => DB::raw('balance + '.sprintf('%.2f', $toAdd))]);

                } elseif ($isCurrencyEx) {
                    $rate = $this->extractLbpRate($row->description ?? '');
                    if ($rate <= 0) { $skipped++; return; }
                    $lbp = $debit * $rate;

                    DB::table('balances')->where('provider','mb_wish_us')
                        ->update(['balance' => DB::raw('balance - '.sprintf('%.2f', $debit))]);

                    DB::table('balances')->where('provider','mb_wish_lb')
                        ->update(['balance' => DB::raw('balance + '.sprintf('%.2f', $lbp))]);

                } elseif ($isRoblox) {
                    // ROBLOX: bonus شرائح
                    $bonus = ($debit >= 10 && $debit < 50) ? 2.00 : (($debit >= 50) ? 4.00 : 0.00);
                    $toAdd = $debit + $bonus;
                    DB::table('balances')->where('provider','mb_wish_us')
                        ->update(['balance' => DB::raw('balance - '.sprintf('%.2f',$debit))]);
                    DB::table('balances')->where('provider','my_balance')
                        ->update(['balance' => DB::raw('balance + '.sprintf('%.2f',$toAdd))]);

                } elseif ($isItunes || $isRazer || $isPsn || $isFreefire) {
                    // باقي الخدمات كما هي
                    $bonus = ($debit < 50) ? 1.00 : 2.00;
                    $toAdd = $debit + $bonus;
                    DB::table('balances')->where('provider','mb_wish_us')
                        ->update(['balance' => DB::raw('balance - '.sprintf('%.2f',$debit))]);
                    DB::table('balances')->where('provider','my_balance')
                        ->update(['balance' => DB::raw('balance + '.sprintf('%.2f',$toAdd))]);

                } elseif ($isTouchOrAlfa) {
                    $amt = $this->extractLeadingUsdAmount($descRaw);
                    if ($amt <= 0) { $skipped++; return; }

                    DB::table('balances')->where('provider','mb_wish_us')
                        ->update(['balance' => DB::raw('balance - '.sprintf('%.2f',$debit))]);

                    if ($this->ne($amt, 7.58)) {
                        DB::table('balances')->where('provider','my_balance')
                            ->update(['balance' => DB::raw('balance + 9.00')]);
                    } elseif ($this->ne($amt, 4.5)) {
                        DB::table('balances')->where('provider','my_balance')
                            ->update(['balance' => DB::raw('balance + 5.61')]);
                    } else {
                        // غير معروف
                    }

                } elseif ($isAnghami) {
                    DB::table('balances')->where('provider','mb_wish_us')
                        ->update(['balance' => DB::raw('balance - '.sprintf('%.2f',$debit))]);
                    $toAdd = $debit + 1.00;
                    DB::table('balances')->where('provider','my_balance')
                        ->update(['balance' => DB::raw('balance + '.sprintf('%.2f',$toAdd))]);

                } elseif ($isCablevision || $isCollection || $isCashout || $isWishCollect) {
                    // mb_wish_us -= debit ، my_balance += debit
                    DB::table('balances')->where('provider','mb_wish_us')
                        ->update(['balance' => DB::raw('balance - '.sprintf('%.2f',$debit))]);
                    DB::table('balances')->where('provider','my_balance')
                        ->update(['balance' => DB::raw('balance + '.sprintf('%.2f',$debit))]);

                } elseif ($isReversedW2W) {
                    // mb_wish_us += credit ، my_balance -= credit
                    DB::table('balances')->where('provider','mb_wish_us')
                        ->update(['balance' => DB::raw('balance + '.sprintf('%.2f',$credit))]);
                    DB::table('balances')->where('provider','my_balance')
                        ->update(['balance' => DB::raw('balance - '.sprintf('%.2f',$credit))]);
                }

                DB::table('wish_rows_raw')->where('id',$row->id)->update([
                    'row_status' => 'INVALID',
                    'updated_at' => now(),
                ]);

                $processed++;
            });
        }

        return redirect()
            ->route('wish.process.index', ['date' => $targetDate])
            ->with('status', "eligible=$eligible processed=$processed skipped=$skipped");
    }


    private function tiktokBucket(float $debit): float
    {
        $buckets = [12,15,20,25,30,40,50,60,75,100,150,200];
        foreach ($buckets as $b) {
            if ($debit <= $b - 0.0001) return (float)$b;
        }
        return (float)(ceil($debit / 50) * 50);
    }

    private function extractLbpRate(string $desc): float
    {
        if (preg_match('/USD\s*TO\s*LBP.*?([0-9][0-9,\.]*)\s*LBP/i', $desc, $m)) {
            $num = preg_replace('/[^0-9]/', '', $m[1]);
            if ($num !== '') return (float)$num;
        }
        return 0.0;
    }

    // يلتقط الرقم بعد رمز $ في بداية الوصف
    private function extractLeadingUsdAmount(string $desc): float
    {
        if (preg_match('/\$\s*([0-9]+(?:\.[0-9]+)?)/', $desc, $m)) {
            return (float)$m[1];
        }
        return 0.0;
    }

    // payout = floor( credit / (1+feeRate) ) إلى السنت
    private function payoutFloorFromCredit(float $credit, float $feeRate): float
    {
        if ($credit <= 0) return 0.0;
        $p = floor(($credit / (1.0 + $feeRate)) * 100.0 + 1e-9) / 100.0;
        return (float) number_format($p, 2, '.', '');
    }

    // مقارنة بفروقات عشرية صغيرة
    private function ne(float $a, float $b, float $eps = 0.02): bool
    {
        return abs($a - $b) <= $eps;
    }
}
