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
  ->chunkById(200, function ($rows) use (&$processed, &$skipped, $targetDate) {

    // خرائط مساعدة
    $voucherToSale = [
      '1.67'  => 2.247,
      '3.03'  => 3.932,
      '3.79'  => 4.72,
      '4.50'  => 5.61,
      '7.58'  => 9.00,
      '10.00' => 12.36,
      '15.15' => 18.00,
      '22.73' => 27.00,
      '77.28' => 90.00,
    ];
    $touchIsMtc = ['TOUCH' => 'mtc', 'ALFA' => 'alfa'];

    foreach ($rows as $row) {
      $service = strtoupper(trim($row->service ?? ''));
      $desc    = (string)($row->description ?? '');
      $debit   = $row->debit  !== null ? round((float)$row->debit,  2) : null;
      $credit  = $row->credit !== null ? round((float)$row->credit, 2) : null;

      // شرط سابق: TOPUP مع credit فقط ⇒ زيادة mb_wish_lb
      $isTopupCreditOnly = ($service === 'TOPUP' && $debit === null && $credit !== null);

      // شرط سابق: BILLS خصم mb_wish_lb (debit فقط)
      $isBillsDebitOnly = (
        in_array($service, ['ALFA BILLS','TOUCH BILLS'], true)
        && $debit !== null && $debit > 0 && $credit === null
      );

      // شرط جديد: ALFA أو TOUCH
      $isDirectOps = in_array($service, ['ALFA','TOUCH'], true) && $debit !== null && $debit > 0;

      if (!($isTopupCreditOnly || $isBillsDebitOnly || $isDirectOps)) {
        $skipped++;
        continue;
      }

      DB::transaction(function () use (
        $row, $service, $desc, $debit, $credit, $isTopupCreditOnly, $isBillsDebitOnly, $isDirectOps,
        $voucherToSale, $touchIsMtc, $targetDate, &$processed, &$skipped
      ) {
        // 0) قفل رصيد ليرة
        DB::table('balances')->where('provider','mb_wish_lb')->lockForUpdate()->get();

        // 1) TOPUP credit-only ⇒ زيادة
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

        // 2) BILLS debit-only ⇒ خصم
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

        // 3) ALFA / TOUCH:
        // 3.1 خصم debit من mb_wish_lb
        $ok = DB::table('balances')->where('provider','mb_wish_lb')->update([
          'balance' => DB::raw('balance - '.sprintf('%.2f',(float)$debit)),
          'updated_at' => now(),
        ]);
        if ($ok < 1) { $skipped++; return; }

        // 3.2 استخرِج كل الكروت من الوصف مثل: "$7.58" "USD 4.50" ...
        preg_match_all('/(?:USD|\$)\s*([0-9]+(?:\.[0-9]+)?)/i', $desc, $m);
        $vouchers = array_map(fn($x)=> number_format((float)$x, 2, '.', ''), $m[1] ?? []);
        if (empty($vouchers)) {
          // لا كروت مفهومة، اعتبر السطر مُعالَج لأن الخصم تم
          DB::table('wish_rows_alt')->where('id',$row->id)->update([
            'row_status'=>'INVALID','updated_at'=>now(),
          ]);
          $processed++;
          return;
        }

        // 3.3 حدّد المزوّد من الخدمة
        $provider = $touchIsMtc[$service] ?? null; // 'alfa' أو 'mtc'
        if (!$provider) { $skipped++; return; }

        // 3.4 جلب سطور days_transfers لذات اليوم والمزوّد لتطابق الكروت
        $dayRows = DB::table('days_transfers')
          ->whereDate('op_date', $targetDate)
          ->where('provider', $provider)
          ->whereIn('status', ['OPEN','PENDING_RECON'])
          ->orderBy('id')
          ->lockForUpdate()
          ->get(['id','expected_vouchers','status']);

        // حول قائمة الكروت إلى multiset
        $pool = []; // '7.58' => count
        foreach ($vouchers as $v) { $pool[$v] = ($pool[$v] ?? 0) + 1; }

        // 3.5 طابق واحذف من expected_vouchers
        foreach ($dayRows as $drow) {
          $exp = $drow->expected_vouchers ? json_decode($drow->expected_vouchers, true) : [];
          if (!is_array($exp) || empty($exp)) continue;

          $new = [];
          foreach ($exp as $ev) {
            $k = number_format((float)$ev, 2, '.', '');
            if (isset($pool[$k]) && $pool[$k] > 0) {
              // استهلك كرت مطابق
              $pool[$k]--;
            } else {
              $new[] = (float)$k; // أبقِ غير المطابق
            }
          }

          // إذا تغيّر شيء احفظ
          if (count($new) !== count($exp)) {
            $newStatus = empty($new) ? 'RECONCILED' : $drow->status;
            DB::table('days_transfers')->where('id',$drow->id)->update([
              'expected_vouchers' => json_encode(array_values($new)),
              'status'            => $newStatus,
              'reconciled_at'     => $newStatus === 'RECONCILED' ? now() : null,
              'updated_at'        => now(),
            ]);
          }
        }

        // 3.6 أي كروت بقيت غير مطابقة ⇒ حولها ربحًا إلى my_balance بحسب التسعيرة
        $extraDelta = 0.0;
        foreach ($pool as $vk => $cnt) {
          if ($cnt < 1) continue;
          if (!isset($voucherToSale[$vk])) continue; // تجاهل غير المعروفة
          $extraDelta += $voucherToSale[$vk] * $cnt;
        }
        if ($extraDelta > 0) {
          DB::table('balances')->where('provider','my_balance')->lockForUpdate()->get();
          DB::table('balances')->where('provider','my_balance')->update([
            'balance'    => DB::raw('balance + '.sprintf('%.2f',$extraDelta)),
            'updated_at' => now(),
          ]);
        }

        // 3.7 علّم سطر الـwish كـ INVALID
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
