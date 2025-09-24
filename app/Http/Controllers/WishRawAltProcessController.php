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

        // eligible
        $eligible = DB::table('wish_rows_alt')
            ->whereDate('op_date', $targetDate)
            ->where('row_status', 'VALID')
            ->count();

        $processed = 0; $skipped = 0;

        DB::table('wish_rows_alt')
            ->whereDate('op_date', $targetDate)
            ->where('row_status', 'VALID')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use (&$processed, &$skipped, $targetDate) {

                $priceMap = [
                    '1.67'=>2.247,'3.03'=>3.932,'3.79'=>4.72,'4.50'=>5.61,
                    '7.58'=>9.00,'10.00'=>12.36,'15.15'=>18.00,'22.73'=>27.00,'77.28'=>90.00,
                ];

                foreach ($rows as $row) {
                    $service = strtoupper(trim($row->service ?? ''));
                    $desc    = (string)($row->description ?? '');
                    $debit   = $row->debit  !== null ? round((float)$row->debit,  2) : null;
                    $credit  = $row->credit !== null ? round((float)$row->credit, 2) : null;

                    $isTopupCreditOnly = ($service === 'TOPUP' && $debit === null && $credit !== null);
                    $isBillsDebitOnly  = (
                        in_array($service, ['ALFA BILLS','TOUCH BILLS'], true) &&
                        $debit !== null && $debit > 0 && $credit === null
                    );
                    $isDirectOps = in_array($service, ['ALFA','TOUCH'], true) && $debit !== null && $debit > 0;

                    if (!($isTopupCreditOnly || $isBillsDebitOnly || $isDirectOps)) {
                        $skipped++;
                        continue;
                    }

                    DB::transaction(function () use (
                        $row,$service,$desc,$debit,$credit,$isTopupCreditOnly,$isBillsDebitOnly,$isDirectOps,
                        $priceMap,$targetDate,&$processed,&$skipped
                    ) {
                        // اقفل رصيد ليرة
                        DB::table('balances')->where('provider','mb_wish_lb')->lockForUpdate()->get();

                        // TOPUP: زيادة
                        if ($isTopupCreditOnly) {
                            $ok = DB::table('balances')->where('provider','mb_wish_lb')->update([
                                'balance' => DB::raw('balance + '.sprintf('%.2f',(float)$credit)),
                                'updated_at' => now(),
                            ]);
                            if ($ok < 1) { $skipped++; return; }

                            DB::table('wish_rows_alt')->where('id',$row->id)->update([
                                'row_status'=>'INVALID','updated_at'=>now(),
                            ]);
                            $processed++;
                            return;
                        }

                        // BILLS: خصم
                        if ($isBillsDebitOnly) {
                            $ok = DB::table('balances')->where('provider','mb_wish_lb')->update([
                                'balance' => DB::raw('balance - '.sprintf('%.2f',(float)$debit)),
                                'updated_at' => now(),
                            ]);
                            if ($ok < 1) { $skipped++; return; }

                            DB::table('wish_rows_alt')->where('id',$row->id)->update([
                                'row_status'=>'INVALID','updated_at'=>now(),
                            ]);
                            $processed++;
                            return;
                        }

                        // ALFA/TOUCH: خصم debit ثم معالجة "كرت واحد"
                        $ok = DB::table('balances')->where('provider','mb_wish_lb')->update([
                            'balance' => DB::raw('balance - '.sprintf('%.2f',(float)$debit)),
                            'updated_at' => now(),
                        ]);
                        if ($ok < 1) { $skipped++; return; }

                        // استخرج أول قيمة $ من الوصف
                        if (!preg_match('/\b(?:ALFA|TOUCH)\b.*?\$\s*([0-9]+(?:\.[0-9]+)?)/i', $desc, $m)) {
                            DB::table('wish_rows_alt')->where('id',$row->id)->update([
                                'row_status'=>'INVALID','updated_at'=>now(),
                            ]);
                            $processed++;
                            return;
                        }
                        $voucherVal = number_format((float)$m[1], 2, '.', ''); // "4.50"..."77.28"
                        $provider   = ($service === 'ALFA') ? 'alfa' : 'mtc';

                        // طابق كرت واحد مع days_transfers لهذا اليوم والمزوّد
                        $dayRows = DB::table('days_transfers')
                            ->whereDate('op_date', $targetDate)
                            ->where('provider', $provider)
                            ->whereIn('status', ['OPEN','PENDING_RECON'])
                            ->orderBy('id')
                            ->lockForUpdate()
                            ->get(['id','expected_vouchers','status']);

                        $settled = false;
                        foreach ($dayRows as $drow) {
                            $exp = $drow->expected_vouchers ? json_decode($drow->expected_vouchers, true) : [];
                            if (!is_array($exp) || empty($exp)) continue;

                            $new = [];
                            $removedOnce = false;
                            foreach ($exp as $ev) {
                                $k = number_format((float)$ev, 2, '.', '');
                                if (!$removedOnce && $k === $voucherVal) {
                                    $removedOnce = true;
                                    continue; // حذف نسخة واحدة
                                }
                                $new[] = (float)$k;
                            }

                            if ($removedOnce) {
                                $newStatus = empty($new) ? 'RECONCILED' : $drow->status;
                                DB::table('days_transfers')->where('id',$drow->id)->update([
                                    'expected_vouchers' => json_encode(array_values($new)),
                                    'status'            => $newStatus,
                                    'reconciled_at'     => $newStatus === 'RECONCILED' ? now() : null,
                                    'updated_at'        => now(),
                                ]);
                                $settled = true;
                                break;
                            }
                        }

                        // إن لم تُسوَّ، أضِف ربح الكرت إلى my_balance
                        if (!$settled && isset($priceMap[$voucherVal])) {
                            DB::table('balances')->where('provider','my_balance')->lockForUpdate()->get();
                            DB::table('balances')->where('provider','my_balance')->update([
                                'balance'    => DB::raw('balance + '.sprintf('%.2f', $priceMap[$voucherVal])),
                                'updated_at' => now(),
                            ]);
                        }

                        // علّم سطر wish كمنتهٍ
                        DB::table('wish_rows_alt')->where('id',$row->id)->update([
                            'row_status'=>'INVALID','updated_at'=>now(),
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
