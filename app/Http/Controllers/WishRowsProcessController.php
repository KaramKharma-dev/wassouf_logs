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
            $service = strtoupper(trim($row->service ?? ''));
            $debit   = $row->debit  !== null ? (float)$row->debit  : null;
            $credit  = $row->credit !== null ? (float)$row->credit : null;

            $case1 = (in_array($service, ['W2W','QR COLLECT']) && $debit !== null && $credit === null);
            $case2 = (in_array($service, ['W2W','TOPUP']) && $credit !== null && $debit === null);

            if (!($case1 || $case2)) { $skipped++; continue; }

            DB::transaction(function () use ($row, $case1, $debit, $case2, $credit, &$processed) {
                DB::table('balances')->whereIn('provider',['mb_wish_us','my_balance'])->lockForUpdate()->get();

                if ($case1) {
                    DB::table('balances')->where('provider','mb_wish_us')
                        ->update(['balance' => DB::raw('balance - '.sprintf('%.2f',$debit))]);
                    DB::table('balances')->where('provider','my_balance')
                        ->update(['balance' => DB::raw('balance + '.sprintf('%.2f',$debit))]);
                } else { // W2W أو TOPUP مع credit فقط
                    $myDelta = $credit - ($credit * 0.01);
                    DB::table('balances')->where('provider','mb_wish_us')
                        ->update(['balance' => DB::raw('balance + '.sprintf('%.2f',$credit))]);
                    DB::table('balances')->where('provider','my_balance')
                        ->update(['balance' => DB::raw('balance - '.sprintf('%.2f',$myDelta))]);
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
}
