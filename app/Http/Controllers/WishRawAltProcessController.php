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

        // 1) كل السطور VALID بهذا التاريخ = eligible
        $eligible = DB::table('wish_rows_alt')
            ->whereDate('op_date', $targetDate)
            ->where('row_status', 'VALID')
            ->count();

        $processed = 0; $skipped = 0;

        // 2) امشِ على كل VALID، وقرّر داخل الحلقة
        DB::table('wish_rows_alt')
            ->whereDate('op_date', $targetDate)
            ->where('row_status', 'VALID')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use (&$processed, &$skipped) {

                foreach ($rows as $row) {
                    $service = strtoupper(trim($row->service ?? ''));
                    $debit   = $row->debit  !== null ? (float)$row->debit  : null;
                    $credit  = $row->credit !== null ? (float)$row->credit : null;

                    // الشرط الوحيد المطلوب حاليًا
                    $isTopupCreditOnly = ($service === 'TOPUP' && $debit === null && $credit !== null);

                    if (!$isTopupCreditOnly) {
                        // لم تتم المعالجة
                        $skipped++;
                        continue;
                    }

                    // معالجة السطر
                    DB::transaction(function () use ($row, $credit, &$processed, &$skipped) {
                        // قفل رصيد LBP
                        DB::table('balances')
                            ->where('provider', 'mb_wish_lb')
                            ->lockForUpdate()
                            ->get();

                        // زيادة الرصيد
                        $affected = DB::table('balances')
                            ->where('provider', 'mb_wish_lb')
                            ->update([
                                'balance' => DB::raw('balance + '.sprintf('%.2f', (float)$credit))
                            ]);

                        if ($affected < 1) {
                            // لا يوجد صف رصيد للمزوّد
                            $skipped++;
                            return;
                        }

                        // تعليم السطر كـ INVALID (حسب طلبك)
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
