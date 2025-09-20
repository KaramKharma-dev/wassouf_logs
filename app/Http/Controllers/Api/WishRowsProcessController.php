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

        $processed = 0; $skipped = 0;

        DB::table('wish_rows_raw')
            ->whereDate('op_date', $targetDate)
            ->where('row_status', 'VALID')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use (&$processed, &$skipped) {
                foreach ($rows as $row) {
                    $service = strtoupper(trim($row->service ?? ''));
                    $debit   = $row->debit  !== null ? (float)$row->debit  : null;
                    $credit  = $row->credit !== null ? (float)$row->credit : null;

                    $case1 = (in_array($service, ['W2W','QR COLLECT']) && $debit !== null && $credit === null);
                    $case2 = ($service === 'W2W' && $credit !== null && $debit === null);

                    if (!($case1 || $case2)) { $skipped++; continue; }

                    DB::transaction(function () use ($row, $case1, $debit, $case2, $credit, &$processed) {
                        // قفل أرصدة الهدف
                        DB::table('balances')->whereIn('provider',['mb_wish_us','my_balance'])->lockForUpdate()->get();

                        if ($case1) {
                            // mb_wish_us -= debit, my_balance += debit
                            DB::table('balances')->where('provider','mb_wish_us')
                                ->update(['balance' => DB::raw('balance - '.sprintf('%.2f',$debit))]);
                            DB::table('balances')->where('provider','my_balance')
                                ->update(['balance' => DB::raw('balance + '.sprintf('%.2f',$debit))]);
                        } else {
                            // mb_wish_us += credit, my_balance -= (credit - 1%)
                            $myDelta = $credit - ($credit * 0.01); // 99%
                            DB::table('balances')->where('provider','mb_wish_us')
                                ->update(['balance' => DB::raw('balance + '.sprintf('%.2f',$credit))]);
                            DB::table('balances')->where('provider','my_balance')
                                ->update(['balance' => DB::raw('balance - '.sprintf('%.2f',$myDelta))]);
                        }

                        // تعليم السطر كـ “تم” باستخدام INVALID كما طلبت بدون تغيير السكيمة
                        DB::table('wish_rows_raw')->where('id',$row->id)->update([
                            'row_status' => 'INVALID',
                            'updated_at' => now(),
                        ]);

                        $processed++;
                    });
                }
            });

        return back()->with('status', "processed=$processed skipped=$skipped date={$targetDate}");
    }
}
