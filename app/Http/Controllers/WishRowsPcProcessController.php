<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class WishRowsPcProcessController extends Controller
{
    public function index(Request $r)
    {
        $date = $r->query('date', Carbon::today()->toDateString());
        return view('wish.pc_upload', ['date' => $date]); // نعيد نفس صفحة الرفع
    }

    public function run(Request $r)
    {
        $data = $r->validate(['date' => ['required','date']]);
        $targetDate = Carbon::parse($data['date'])->toDateString();

        $eligible = DB::table('wish_rows_pc')
            ->whereDate('op_date', $targetDate)
            ->where('row_status', 'VALID')
            ->count();

        $processed = 0; $skipped = 0; $errors = [];

        $rows = DB::table('wish_rows_pc')
            ->whereDate('op_date', $targetDate)
            ->where('row_status', 'VALID')
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            $debit  = $row->debit  !== null ? (float)$row->debit  : null;
            $credit = $row->credit !== null ? (float)$row->credit : null;

            // لازم واحدة منهم فقط
            if (!(($debit !== null && $credit === null) || ($debit === null && $credit !== null))) {
                $skipped++; continue;
            }

            try {
                DB::transaction(function () use ($row, $debit, $credit, &$processed) {
                    // أقفال
                    DB::table('balances')->whereIn('provider', ['mb_wish_us','my_balance'])
                        ->lockForUpdate()->get();

                    if ($debit !== null) {
                        // debit → mb_wish_us -= debit ، my_balance += debit
                        DB::table('balances')->where('provider','mb_wish_us')
                            ->update(['balance' => DB::raw('balance - '.sprintf('%.2f',$debit))]);
                        DB::table('balances')->where('provider','my_balance')
                            ->update(['balance' => DB::raw('balance + '.sprintf('%.2f',$debit))]);

                    } elseif ($credit !== null) {
                        // credit → mb_wish_us += credit ، my_balance -= credit
                        DB::table('balances')->where('provider','mb_wish_us')
                            ->update(['balance' => DB::raw('balance + '.sprintf('%.2f',$credit))]);
                        DB::table('balances')->where('provider','my_balance')
                            ->update(['balance' => DB::raw('balance - '.sprintf('%.2f',$credit))]);
                    }

                    // علّم السطر
                    DB::table('wish_rows_pc')->where('id',$row->id)->update([
                        'row_status' => 'PROCESSED',
                        'updated_at' => now(),
                    ]);

                    $processed++;
                });
            } catch (\Throwable $e) {
                $skipped++;
                $errors[] = "row#{$row->id}: ".$e->getMessage();
            }
        }

        return redirect()
            ->route('wish.pc.process.index', ['date' => $targetDate])
            ->with('status', "eligible=$eligible processed=$processed skipped=$skipped")
            ->with('result', [
                'date' => $targetDate,
                'eligible' => $eligible,
                'processed' => $processed,
                'skipped' => $skipped,
                'errors' => $errors,
            ]);
    }
}
