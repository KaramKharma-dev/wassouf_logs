<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class WishRowsProcessController extends Controller
{
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

            // PAY BY CARD + description contains TIKTOK (debit only)
            $isTiktok = ($service === 'PAY BY CARD' && str_contains($desc, 'TIKTOK') && $debit !== null && $credit === null);

            // Currency Exchange (debit only, to LBP)
            $isCurrencyEx = ($service === 'CURRENCY EXCHANGE' && $debit !== null && $credit === null);

            // ITUNES* or RAZER (debit only)
            $isItunes = (str_contains($service, 'ITUNES') && $debit !== null && $credit === null);
            $isRazer  = ($service === 'RAZER' && $debit !== null && $credit === null);
            $isRoblox  = ($service === 'ROBLOX' && $debit !== null && $credit === null);

            // TOUCH / ALFA (debit only, description holds $amount)
            $isTouchOrAlfa = (in_array($service, ['TOUCH','ALFA']) && $debit !== null && $credit === null);

            $isAnghami = ($service === 'ANGHAMI' && $debit !== null && $credit === null);

            // CABLEVISION (debit only) → mb_wish_lb -= debit, my_balance += debit
            $isCablevision = ($service === 'CABLEVISION' && $debit !== null && $credit === null);

            // COLLECTION (same as CABLEVISION)
            $isCollection = ($service === 'COLLECTION' && $debit !== null && $credit === null);

            // COLLECTION (same as CABLEVISION)
            $isCashout = ($service === 'CASH OUT' && $debit !== null && $credit === null);

            if (!($isW2W_or_QR_debitOnly || $isW2W_or_TOPUP_creditOnly || $isTiktok || $isCurrencyEx || $isItunes ||  $isRoblox || $isRazer || $isTouchOrAlfa || $isAnghami || $isCablevision || $isCollection || $isCashout)) {
                $skipped++; continue;
            }

            DB::transaction(function () use ($row, $debit, $credit, $descRaw,
                $isW2W_or_QR_debitOnly, $isW2W_or_TOPUP_creditOnly, $isTiktok, $isCurrencyEx, $isItunes, $isRoblox, $isRazer, $isTouchOrAlfa, $isAnghami, $isCablevision, $isCollection, $isCashout,
                &$processed, &$skipped) {

                // اختر القفل حسب الحالة
                if ($isCurrencyEx) {
                    $providersToLock = ['mb_wish_us','mb_wish_lb'];
                } elseif ($isCablevision || $isCollection || $isCashout) {
                    $providersToLock = ['mb_wish_lb','my_balance'];
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
                    $myDelta = $credit - ($credit * 0.01);
                    DB::table('balances')->where('provider','mb_wish_us')
                        ->update(['balance' => DB::raw('balance + '.sprintf('%.2f',$credit))]);
                    DB::table('balances')->where('provider','my_balance')
                        ->update(['balance' => DB::raw('balance - '.sprintf('%.2f',$myDelta))]);

                } elseif ($isTiktok) {
                    $award = $this->tiktokBucket($debit);
                    DB::table('balances')->where('provider','mb_wish_us')
                        ->update(['balance' => DB::raw('balance - '.sprintf('%.2f',$debit))]);
                    DB::table('balances')->where('provider','my_balance')
                        ->update(['balance' => DB::raw('balance + '.sprintf('%.2f',$award))]);

                } elseif ($isCurrencyEx) {
                    $rate = $this->extractLbpRate($row->description ?? '');
                    if ($rate <= 0) { $skipped++; return; }
                    $lbp = $debit * $rate;

                    DB::table('balances')->where('provider','mb_wish_us')
                        ->update(['balance' => DB::raw('balance - '.sprintf('%.2f', $debit))]);

                    DB::table('balances')->where('provider','mb_wish_lb')
                        ->update(['balance' => DB::raw('balance + '.sprintf('%.2f', $lbp))]);

                } elseif ($isItunes || $isRazer || $isRoblox) {
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
                        // unknown mapping
                    }

                } elseif ($isAnghami) {
                    DB::table('balances')->where('provider','mb_wish_us')
                        ->update(['balance' => DB::raw('balance - '.sprintf('%.2f',$debit))]);
                    $toAdd = $debit + 1.00;
                    DB::table('balances')->where('provider','my_balance')
                        ->update(['balance' => DB::raw('balance + '.sprintf('%.2f',$toAdd))]);

                } elseif ($isCablevision || $isCollection || $isCashout) {
                    // mb_wish_lb -= debit
                    DB::table('balances')->where('provider','mb_wish_lb')
                        ->update(['balance' => DB::raw('balance - '.sprintf('%.2f',$debit))]);
                    // my_balance += debit
                    DB::table('balances')->where('provider','my_balance')
                        ->update(['balance' => DB::raw('balance + '.sprintf('%.2f',$debit))]);

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

    // يلتقط الرقم بعد رمز $ في بداية الوصف: "TOUCH $7.58-+961..." أو "ALFA $4.5-+961..."
    private function extractLeadingUsdAmount(string $desc): float
    {
        if (preg_match('/\$\s*([0-9]+(?:\.[0-9]+)?)/', $desc, $m)) {
            return (float)$m[1];
        }
        return 0.0;
    }

    // مقارنة بفروقات عشرية صغيرة
    private function ne(float $a, float $b, float $eps = 0.02): bool
    {
        return abs($a - $b) <= $eps;
    }
}
