<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\WishBatchUploadRequest;
use App\Models\WishBatch;
use App\Models\WishRowRaw;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;
use Illuminate\Support\Str;
use Carbon\Carbon;

class WishBatchController extends Controller
{
    public function store(WishBatchUploadRequest $req)
    {
        $uploaded = $req->file('file');
        $bytes = file_get_contents($uploaded->getRealPath());
        $checksum = hash('sha256', $bytes);

        // منع رفع نفس الكشف مرتين
        $exists = WishBatch::where('checksum', $checksum)->first();
        if ($exists) {
            return response()->json([
                'batch_id' => $exists->id,
                'status'   => $exists->status,
                'message'  => 'already_uploaded',
            ], 200);
        }

        return DB::transaction(function () use ($uploaded, $bytes, $checksum) {
            $batch = WishBatch::create([
                'filename' => $uploaded->getClientOriginalName() ?: ('wish_' . now()->format('Ymd_His') . '.pdf'),
                'checksum' => $checksum,
                'status'   => 'UPLOADED',
            ]);

            // حفظ الملف
            $path = "wish/batches/{$batch->id}/original.pdf";
            Storage::disk('local')->put($path, $bytes);

            // تحليل PDF
            [$issuedOn, $rows] = $this->parsePdf($bytes);

            // إدخال السطور
            $seq = 0; $valid = 0; $invalid = 0;
            foreach ($rows as $r) {
                $seq++;
                $rowHash = hash('sha256', implode('|', [
                    $r['op_date'] ?? '', $r['reference'] ?? '',
                    $r['description'] ?? '', $r['amount'] ?? '',
                    $r['balance_after'] ?? ''
                ]));

                $rowStatus = ($r['op_date'] && $r['reference'] && $r['balance_after'] && $r['amount']) ? 'VALID' : 'INVALID';
                $rowStatus === 'VALID' ? $valid++ : $invalid++;

                WishRowRaw::create([
                    'batch_id'      => $batch->id,
                    'seq_no'        => $seq,
                    'op_date'       => $r['op_date'],
                    'reference'     => $r['reference'],
                    'description'   => $r['description'],
                    'debit'         => $r['direction'] === 'debit'  ? $r['amount'] : null,
                    'credit'        => $r['direction'] === 'credit' ? $r['amount'] : null,
                    'balance_after' => $r['balance_after'],
                    'row_status'    => $rowStatus,
                    'row_hash'      => $rowHash,
                ]);
            }

            // تحديث الدفعة
            $batch->update([
                'issued_on'  => $issuedOn,
                'rows_total' => $valid + $invalid,
                'rows_valid' => $valid,
                'rows_invalid' => $invalid,
                'status'     => $invalid === 0 ? 'PARSED' : 'PARSED_WITH_WARNINGS',
            ]);

            return response()->json([
                'batch_id'      => $batch->id,
                'issued_on'     => optional($issuedOn)?->toDateString(),
                'rows_total'    => $batch->rows_total,
                'rows_valid'    => $batch->rows_valid,
                'rows_invalid'  => $batch->rows_invalid,
                'status'        => $batch->status,
            ], 201);
        });
    }

    /**
     * يُرجع: [issuedOn(Carbon|null), rows(array)]
     * كل عنصر rows: ['op_date'=>Carbon, 'reference'=>string, 'description'=>string, 'amount'=>float, 'direction'=>'debit|credit', 'balance_after'=>float]
     */
    private function parsePdf(string $bytes): array
    {
        $parser = new Parser();
        $pdf = $parser->parseContent($bytes);
        $text = trim($pdf->getText());

        // فتحيّة: Opening balance لاستخراج الرصيد الافتتاحي لبناء اتجاه المبلغ
        $opening = $this->matchOpeningBalance($text); // float|null
        $issuedOn = $this->matchIssuedOn($text);      // Carbon|null

        // استخراج كل الأسطر التي تبدأ بتاريخ dd/mm/yyyy
        $lines = $this->extractOperationLines($text);

        $rows = [];
        $prevBalance = $opening; // قد يكون null إن لم يُلتقط، عندها لا نحسب الاتجاه بالجولة الأولى

        foreach ($lines as $ln) {
            // صيغة عامة: DATE REF DESCRIPTION ... AMOUNT BALANCE
            // نلتقط التاريخ والمرجع أولاً
            if (!preg_match('/^(?<date>\d{2}\/\d{2}\/\d{4})\s+tr:(?<ref>\d+)\s+(?<rest>.+)$/u', $ln, $m)) {
                continue;
            }
            $dateStr = $m['date'];
            $reference = 'tr:' . $m['ref'];
            $rest = $m['rest'];

            // نلتقط آخر رقمين (أو رقم واحد) من نهاية السطر: المبلغ والرصيد
            // الرصيد دائماً آخر رقم عشري
            if (!preg_match('/(?<amount>(\d{1,3}(,\d{3})*|\d+)\.\d{2})\s+(?<balance>(\d{1,3}(,\d{3})*|\d+)\.\d{2})\s*$/u', $rest, $mm)) {
                // إذا لم نجد رقمين، نحاول رقم واحد كرصد فقط
                if (preg_match('/(?<balance>(\d{1,3}(,\d{3})*|\d+)\.\d{2})\s*$/u', $rest, $mb)) {
                    $balanceAfter = $this->num($mb['balance']);
                    $amount = null;
                    $desc = trim(preg_replace('/\s*' . preg_quote($mb[0], '/') . '$/u', '', $rest));
                } else {
                    // سطر غير قابل للاعتماد
                    $balanceAfter = null;
                    $amount = null;
                    $desc = $rest;
                }
            } else {
                $amount = $this->num($mm['amount']);
                $balanceAfter = $this->num($mm['balance']);
                $desc = trim(preg_replace('/\s*' . preg_quote($mm[0], '/') . '$/u', '', $rest));
            }

            // تحديد الاتجاه عبر مقارنة الرصيد السابق إن أمكن
            $direction = null;
            if ($amount !== null && $balanceAfter !== null && $prevBalance !== null) {
                $isDebit  = $this->eq($prevBalance - $amount, $balanceAfter);
                $isCredit = $this->eq($prevBalance + $amount, $balanceAfter);
                if ($isDebit)  $direction = 'debit';
                if ($isCredit) $direction = 'credit';
            }

            // تحديث prevBalance
            if ($balanceAfter !== null) $prevBalance = $balanceAfter;

            $rows[] = [
                'op_date'       => Carbon::createFromFormat('d/m/Y', $dateStr),
                'reference'     => $reference,
                'description'   => $desc,
                'amount'        => $amount,
                'direction'     => $direction, // قد تبقى null في أول سطر إن لم نلتقط opening
                'balance_after' => $balanceAfter,
            ];
        }

        // ملء الاتجاهات المفقودة بشكل تقريبي عبر النظر للسطر التالي
        $rows = $this->backfillDirections($rows);

        return [$issuedOn, $rows];
    }

    private function extractOperationLines(string $text): array
    {
        $lines = preg_split('/\R/u', $text);
        $ops = [];
        foreach ($lines as $ln) {
            $ln = trim(preg_replace('/\s+/u', ' ', $ln));
            if ($ln === '') continue;

            // تجاهل رؤوس/فوتر وملخص الإغلاق
            if (preg_match('/^(Statement Of Account|Full Name:|Phone Number:|Account No:|Address:|Whish Card No:|Currency:)/u', $ln)) continue;
            if (preg_match('/^(OPENING BALANCE|TOTAL AMOUNT \/ CLOSING BALANCE)/u', $ln)) continue;
            if (preg_match('/^WHISH MONEY SAL|^Ground Floor,|^Issued on /u', $ln)) continue;
            if (preg_match('/^From \d{2}\/\d{2}\/\d{4} Till \d{2}\/\d{2}\/\d{4}$/u', $ln)) continue;

            // الأسطر العمليات تبدأ بتاريخ
            if (preg_match('/^\d{2}\/\d{2}\/\d{4}\s+tr:\d+/u', $ln)) {
                $ops[] = $ln;
            }
        }
        return $ops;
    }

    private function matchOpeningBalance(string $text): ?float
    {
        if (preg_match('/OPENING BALANCE\s+((\d{1,3}(,\d{3})*|\d+)\.\d{2})/u', $text, $m)) {
            return $this->num($m[1]);
        }
        return null;
    }

    private function matchIssuedOn(string $text): ?Carbon
    {
        // مثال: "Issued on Fri Sep 19, 2025"
        if (preg_match('/Issued on\s+([A-Za-z]{3}\s+[A-Za-z]{3}\s+\d{1,2},\s+\d{4})/u', $text, $m)) {
            try { return Carbon::parse($m[1]); } catch (\Throwable $e) { return null; }
        }
        return null;
    }

    private function backfillDirections(array $rows): array
    {
        for ($i = 0; $i < count($rows); $i++) {
            if ($rows[$i]['direction'] || $rows[$i]['amount'] === null) continue;
            // ننظر للرصد السابق واللاحق إذا وُجدا
            $prev = $i > 0 ? $rows[$i-1] : null;
            $cur  = $rows[$i];
            $next = $i+1 < count($rows) ? $rows[$i+1] : null;
            if ($prev && $prev['balance_after'] !== null && $cur['balance_after'] !== null) {
                $isDebit  = $this->eq($prev['balance_after'] - $cur['amount'], $cur['balance_after']);
                $isCredit = $this->eq($prev['balance_after'] + $cur['amount'], $cur['balance_after']);
                if ($isDebit)  $rows[$i]['direction'] = 'debit';
                if ($isCredit) $rows[$i]['direction'] = 'credit';
            } elseif ($next && $cur['balance_after'] !== null && $next['balance_after'] !== null) {
                // محاولة عبر السطر التالي
                $isDebit  = $this->eq($cur['balance_after'] + $cur['amount'], $next['balance_after']);
                $isCredit = $this->eq($cur['balance_after'] - $cur['amount'], $next['balance_after']);
                if ($isDebit)  $rows[$i]['direction'] = 'debit';
                if ($isCredit) $rows[$i]['direction'] = 'credit';
            }
        }
        return $rows;
    }

    private function num(string $s): float
    {
        return (float) str_replace(',', '', $s);
    }

    private function eq($a, $b): bool
    {
        return abs(((float)$a) - ((float)$b)) < 0.01;
    }
}
