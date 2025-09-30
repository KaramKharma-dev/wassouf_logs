<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class WishRowsPcLbProcessController extends Controller
{
    public function index(Request $r)
    {
        $date = $r->query('date', Carbon::today()->toDateString());
        return view('wish.pclb', ['date' => $date]); // سنضيف الـBlade لاحقًا
    }

    public function run(Request $r)
    {
        $data = $r->validate(['date' => ['required','date']]);
        $targetDate = Carbon::parse($data['date'])->toDateString();

        $eligible = DB::table('wish_rows_pc_lb')
            ->whereDate('op_date', $targetDate)
            ->where('row_status', 'VALID')
            ->count();

        $processed = 0; $skipped = 0; $errors = [];

        $rows = DB::table('wish_rows_pc_lb')
            ->whereDate('op_date', $targetDate)
            ->where('row_status', 'VALID')
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            $debit  = $row->debit  !== null ? (float)$row->debit  : null;
            $credit = $row->credit !== null ? (float)$row->credit : null;

            if (!(($debit !== null && $credit === null) || ($debit === null && $credit !== null))) {
                $skipped++; continue;
            }

            try {
                DB::transaction(function () use ($row, $debit, $credit, &$processed) {
                    DB::table('balances')->whereIn('provider', ['mb_wish_lb','my_balance'])
                        ->lockForUpdate()->get();

                    if ($debit !== null) {
                        DB::table('balances')->where('provider','mb_wish_lb')
                            ->update(['balance' => DB::raw('balance - '.sprintf('%.2f',$debit))]);
                        DB::table('balances')->where('provider','my_balance')
                            ->update(['balance' => DB::raw('balance + '.sprintf('%.2f',$debit))]);
                    } else {
                        DB::table('balances')->where('provider','mb_wish_lb')
                            ->update(['balance' => DB::raw('balance + '.sprintf('%.2f',$credit))]);
                        DB::table('balances')->where('provider','my_balance')
                            ->update(['balance' => DB::raw('balance - '.sprintf('%.2f',$credit))]);
                    }

                    DB::table('wish_rows_pc_lb')->where('id',$row->id)->update([
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
            ->route('wish.pclb.process.index', ['date' => $targetDate])
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
