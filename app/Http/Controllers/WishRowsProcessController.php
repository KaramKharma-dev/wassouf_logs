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
            $debit   = $row->debit  !== null ? (float)$row->debit  : null;
            $credit  = $row->credit !== null ? (float)$row->credit : null;

            $isW2W_or_QR_debitOnly   = (in_array($service, ['W2W','QR COLLECT']) && $debit !== null && $credit === null);
            $isW2W_or_TOPUP_creditOnly = (in_array($service, ['W2W','TOPUP'])     && $credit !== null && $debit === null);

            // NEW: TikTok (debit only)
            $isTiktok = ($service === 'TIKTOK' && $debit !== null && $credit === null);

            // NEW: Currency Exchange (debit only, to LBP)
            $isCurrencyEx = ($service === 'CURRENCY EXCHANGE' && $debit !== null && $credit === null);

            // NEW: iTunes (any text contains ITUNES) and RAZER (debit only)
            $isItunes = (str_contains($service, 'ITUNES') && $debit !== null && $credit === null);
            $isRazer  = ($service === 'RAZER' && $debit !== null && $credit === null);

            if (!($isW2W_or_QR_debitOnly || $isW2W_or_TOPUP_creditOnly || $isTiktok || $isCurrencyEx || $isItunes || $isRazer)) {
                $skipped++; continue;
            }

            DB::transaction(function () use ($row, $debit, $credit, $isW2W_or_QR_debitOnly, $isW2W_or_TOPUP_creditOnly, $isTiktok, $isCurrencyEx, $isItunes, $isRazer, &$processed) {

                // أقفال الأرصدة المستعملة
                $providersToLock = ['mb_wish_us','my_balance'];
                if ($isCurrencyEx) { $providersToLock = ['mb_wish_us','mb_wish_lb']; }
                DB::table('balances')->whereIn('provider', $providersToLock)->lockForUpdate()->get();

                if ($isW2W_or_QR_debitOnly) {
                    // mb_wish_us -= debit, my_balance += debit
                    DB::table('balances')->where('provider','mb_wish_us')
                        ->update(['balance' => DB::raw('balance - '.sprintf('%.2f',$debit))]);
                    DB::table('balances')->where('provider','my_balance')
                        ->update(['balance' => DB::raw('balance + '.sprintf('%.2f',$debit))]);

                } elseif ($isW2W_or_TOPUP_creditOnly) {
                    // mb_wish_us += credit, my_balance -= (credit - 1%)
                    $myDelta = $credit - ($credit * 0.01);
                    DB::table('balances')->where('provider','mb_wish_us')
                        ->update(['balance' => DB::raw('balance + '.sprintf('%.2f',$credit))]);
                    DB::table('balances')->where('provider','my_balance')
                        ->update(['balance' => DB::raw('balance - '.sprintf('%.2f',$myDelta))]);

                } elseif ($isTiktok) {
                    // خصم debit من mb_wish_us، وإضافة قيمة مقربة على my_balance
                    $award = $this->tiktokBucket($debit); // أقرب سلّة أعلى
                    DB::table('balances')->where('provider','mb_wish_us')
                        ->update(['balance' => DB::raw('balance - '.sprintf('%.2f',$debit))]);
                    DB::table('balances')->where('provider','my_balance')
                        ->update(['balance' => DB::raw('balance + '.sprintf('%.2f',$award))]);

                } elseif ($isCurrencyEx) {
                    // خصم من mb_wish_us, إضافة إلى mb_wish_lb = debit * rate
                    $rate = $this->extractLbpRate($row->description ?? '');
                    $lbp  = $debit * $rate;
                    DB::table('balances')->where('provider','mb_wish_us')
                        ->update(['balance' => DB::raw('balance - '.sprintf('%.2f',$debit))]);
                    DB::table('balances')->where('provider','mb_wish_lb')
                        ->update(['balance' => DB::raw('balance + '.sprintf('%.2f',$lbp))]);

                } elseif ($isItunes || $isRazer) {
                    // خصم debit من mb_wish_us، إضافة على my_balance: +1 أقل من 50، وإلا +2
                    $bonus = ($debit < 50) ? 1.00 : 2.00;
                    $toAdd = $debit + $bonus;
                    DB::table('balances')->where('provider','mb_wish_us')
                        ->update(['balance' => DB::raw('balance - '.sprintf('%.2f',$debit))]);
                    DB::table('balances')->where('provider','my_balance')
                        ->update(['balance' => DB::raw('balance + '.sprintf('%.2f',$toAdd))]);
                }

                // علّم السطر كمُعالج
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

    /**
     * TikTok bucket: اختيار أقرب سلة أعلى.
     * أمثلة مطلوبة: 10.39 → 12 ، 16..19 → 20 ، 93..99 → 100.
     */
    private function tiktokBucket(float $debit): float
    {
        $buckets = [12,15,20,25,30,40,50,60,75,100,150,200];
        foreach ($buckets as $b) {
            if ($debit <= $b - 0.0001) return (float)$b;
        }
        // لو أكبر من آخر سلة, قرّب لأعلى 50
        return (float)(ceil($debit / 50) * 50);
    }

    /**
     * استخراج سعر LBP من نص مثل:
     * "USD TO LBP-1 USD?89,500 LBP" → 89500
     */
    private function extractLbpRate(string $desc): float
    {
        // التقط أول رقم كبير مع فواصل
        if (preg_match('/([0-9][0-9.,\s]+)/', $desc, $m)) {
            $num = preg_replace('/[^0-9]/', '', $m[1]); // إزالة الفواصل
            if ($num !== '') {
                return (float)$num;
            }
        }
        return 0.0;
    }
}
