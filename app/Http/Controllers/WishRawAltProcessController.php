<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class WishRawAltProcessController extends Controller
{
    public function index(Request $r)
    {
        $date = $r->query('date', Carbon::today()->toDateString());
        return view('wish.alt_process', ['date' => $date]);
    }

    public function run(Request $r)
    {
        $data = $r->validate(['date' => ['required','date']]);
        $targetDate = Carbon::parse($data['date'])->toDateString();

        $eligible = DB::table('wish_rows_alt')
            ->whereDate('op_date', $targetDate)
            ->where('row_status', 'VALID')
            ->whereRaw('UPPER(TRIM(service)) = ?', ['TOPUP'])
            ->whereNull('debit')
            ->whereNotNull('credit')
            ->count();

        $processed = 0; $skipped = 0;

        // نقرأ دفعات لتفادي استهلاك الذاكرة
        DB::table('wish_rows_alt')
            ->whereDate('op_date', $targetDate)
            ->where('row_status', 'VALID')
            ->whereRaw('UPPER(TRIM(service)) = ?', ['TOPUP'])
            ->whereNull('debit')
            ->whereNotNull('credit')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use (&$processed, &$skipped) {

                foreach ($rows as $row) {
                    $credit = (float)$row->credit;

                    DB::transaction(function () use ($row, $credit, &$processed, &$skipped) {
                        // قفل رصيد mb_wish_lb
                        DB::table('balances')
                            ->where('provider', 'mb_wish_lb')
                            ->lockForUpdate()
                            ->get();

                        // زيادة الرصيد
                        $affected = DB::table('balances')
                            ->where('provider', 'mb_wish_lb')
                            ->update([
                                'balance' => DB::raw('balance + '.sprintf('%.2f', $credit))
                            ]);

                        if ($affected < 1) {
                            // لا يوجد رصيد بهذا المزوّد
                            $skipped++;
                            return;
                        }

                        // تعليم السطر كمُعالج
                        DB::table('wish_rows_alt')
                            ->where('id', $row->id)
                            ->update([
                                'row_status' => 'INVALID',
                                'updated_at' => now(),
                            ]);


                        $processed++;
                    });
                }

            });

        return redirect()
            ->route('wish.alt_process.index', ['date' => $targetDate])
            ->with('status', "eligible=$eligible processed=$processed skipped=$skipped");
    }
}
