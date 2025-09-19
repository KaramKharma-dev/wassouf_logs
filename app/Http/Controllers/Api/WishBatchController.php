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
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

class WishBatchController extends Controller
{
    public function store(WishBatchUploadRequest $req)
    {
        $uploaded = $req->file('file');
        $bytes = file_get_contents($uploaded->getRealPath());
        $checksum = hash('sha256', $bytes);

        // منع رفع نفس الملف
        if ($exists = WishBatch::where('checksum', $checksum)->first()) {
            return response()->json([
                'batch_id' => $exists->id,
                'status'   => $exists->status,
                'message'  => 'already_uploaded',
            ], 200);
        }

        return DB::transaction(function () use ($uploaded, $bytes, $checksum) {
            $batch = WishBatch::create([
                'filename' => $uploaded->getClientOriginalName() ?: ('wish_' . now()->format('Ymd_His') . '.' . strtolower($uploaded->getClientOriginalExtension())),
                'checksum' => $checksum,
                'status'   => 'UPLOADED',
            ]);

            // حفظ الأصل
            $path = "wish/batches/{$batch->id}/original." . strtolower($uploaded->getClientOriginalExtension());
            Storage::disk('local')->put($path, $bytes);

            // اختيار المحلل حسب الامتداد
            $ext = strtolower($uploaded->getClientOriginalExtension());
            if (in_array($ext, ['xlsx','xls'])) {
                [$issuedOn, $rows] = $this->parseSpreadsheet($bytes);
                $rawText = null;
            } else {
                [$issuedOn, $rows, $rawText] = $this->parsePdf($bytes);
            }

            // Debug نصّي عند صفر صفوف
            if (count($rows) === 0 && $rawText !== null) {
                $dbgPath = "wish/batches/{$batch->id}/extracted_debug.txt";
                Storage::disk('local')->put($dbgPath, $rawText ?: '[empty]');
            }

            // إدخال السطور
            $seq = 0; $valid = 0; $invalid = 0;
            foreach ($rows as $r) {
                $seq++;
                $rowHash = hash('sha256', implode('|', [
                    optional($r['op_date'])->toDateString() ?? '',
                    $r['reference'] ?? '',
                    $r['description'] ?? '',
                    $r['amount'] ?? '',
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
                    'debit'         => ($r['direction'] ?? null) === 'debit'  ? $r['amount'] : null,
                    'credit'        => ($r['direction'] ?? null) === 'credit' ? $r['amount'] : null,
                    'balance_after' => $r['balance_after'],
                    'row_status'    => $rowStatus,
                    'row_hash'      => $rowHash,
                ]);
            }

            // تحديث ملخص الدفعة
            $batch->update([
                'issued_on'    => $issuedOn,
                'rows_total'   => $valid + $invalid,
                'rows_valid'   => $valid,
                'rows_invalid' => $invalid,
                'status'       => $invalid === 0 ? 'PARSED' : 'PARSED_WITH_WARNINGS',
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
     * PDF: يُرجع [issuedOn(Carbon|null), rows(array), rawText(string)]
     * كل row: ['op_date'=>Carbon,'reference'=>string,'description'=>string,'amount'=>float,'direction'=>'debit|credit','balance_after'=>float]
     */
    private function parsePdf(string $bytes): array
    {
        $parser = new Parser();
        $pdf = $parser->parseContent($bytes);
        $text = trim($pdf->getText());

        $opening = $this->matchOpeningBalance($text); // float|null
        $issuedOn = $this->matchIssuedOn($text);      // Carbon|null
        $lines = $this->extractOperationLines($text);

        $rows = [];
        $prevBalance = $opening;

        foreach ($lines as $ln) {
            if (!preg_match('/^(?<date>\d{2}\/\d{2}\/\d{4})\s+tr:(?<ref>\d+)\s+(?<rest>.+)$/u', $ln, $m)) {
                continue;
            }
            $dateStr = $m['date'];
            $reference = 'tr:' . $m['ref'];
            $rest = $m['rest'];

            if (!preg_match('/(?<amount>(\d{1,3}(,\d{3})*|\d+)\.\d{2})\s+(?<balance>(\d{1,3}(,\d{3})*|\d+)\.\d{2})\s*$/u', $rest, $mm)) {
                // رصيد فقط
                if (preg_match('/(?<balance>(\d{1,3}(,\d{3})*|\d+)\.\d{2})\s*$/u', $rest, $mb)) {
                    $balanceAfter = $this->num($mb['balance']);
                    $amount = null;
                    $desc = trim(preg_replace('/\s*' . preg_quote($mb[0], '/') . '$/u', '', $rest));
                } else {
                    $balanceAfter = null;
                    $amount = null;
                    $desc = $rest;
                }
            } else {
                $amount = $this->num($mm['amount']);
                $balanceAfter = $this->num($mm['balance']);
                $desc = trim(preg_replace('/\s*' . preg_quote($mm[0], '/') . '$/u', '', $rest));
            }

            $direction = null;
            if ($amount !== null && $balanceAfter !== null && $prevBalance !== null) {
                $isDebit  = $this->eq($prevBalance - $amount, $balanceAfter);
                $isCredit = $this->eq($prevBalance + $amount, $balanceAfter);
                if ($isDebit)  $direction = 'debit';
                if ($isCredit) $direction = 'credit';
            }

            if ($balanceAfter !== null) $prevBalance = $balanceAfter;

            $rows[] = [
                'op_date'       => Carbon::createFromFormat('d/m/Y', $dateStr),
                'reference'     => $reference,
                'description'   => $desc,
                'amount'        => $amount,
                'direction'     => $direction,
                'balance_after' => $balanceAfter,
            ];
        }

        $rows = $this->backfillDirections($rows);

        if (count($rows) === 0) {
            $rows = $this->fallbackMultilineScan($text, $opening);
            $rows = $this->backfillDirections($rows);
        }

        return [$issuedOn, $rows, $text];
    }

    /**
     * XLSX/XLS: يُرجع [issuedOn|null, rows]
     */
    private function parseSpreadsheet(string $bytes): array
    {
        $tmp = tmpfile();
        fwrite($tmp, $bytes);
        $path = stream_get_meta_data($tmp)['uri'];

        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $sheet = $reader->load($path)->getActiveSheet();

        // البحث عن الهيدر
        $headerRow = null;
        $headers = [];
        foreach ($sheet->getRowIterator() as $row) {
            $cells = [];
            foreach ($row->getCellIterator() as $cell) {
                $cells[] = trim((string)$cell->getFormattedValue());
            }
            $line = implode(' | ', $cells);
            if (stripos($line, 'Date') !== false && stripos($line, 'Reference') !== false && stripos($line, 'Service') !== false) {
                $headerRow = $row->getRowIndex();
                $headers = $cells;
                break;
            }
        }
        if (!$headerRow) {
            $headerRow = 1;
            $headers = ['Date','Reference','Service Description','Debit','Credit','Balance'];
        }

        // خريطة الأعمدة
        $map = ['date'=>null,'ref'=>null,'desc'=>null,'debit'=>null,'credit'=>null,'bal'=>null];
        foreach ($headers as $i => $h) {
            $hh = strtolower(preg_replace('/\s+/', ' ', trim($h)));
            if (str_contains($hh,'date')) $map['date']=$i;
            if (str_contains($hh,'reference')) $map['ref']=$i;
            if (str_contains($hh,'service') || str_contains($hh,'description')) $map['desc']=$i;
            if (str_contains($hh,'debit')) $map['debit']=$i;
            if (str_contains($hh,'credit')) $map['credit']=$i;
            if (str_contains($hh,'balance')) $map['bal']=$i;
        }

        $rows = [];
        $opening = null;
        $prevBalance = $opening;

        for ($r = $headerRow + 1; $r <= $sheet->getHighestRow(); $r++) {
            $get = function($idx) use ($sheet, $r) {
                if ($idx === null) return null;
                $col = Coordinate::stringFromColumnIndex($idx + 1); // A,B,C...
                return trim((string)$sheet->getCell($col . $r)->getFormattedValue());
            };


            $dateStr = $get($map['date']);
            $ref     = $get($map['ref']);
            $desc    = $get($map['desc']);
            $debit   = $get($map['debit']);
            $credit  = $get($map['credit']);
            $bal     = $get($map['bal']);

            if ($dateStr === '' && $ref === '' && $desc === '') continue;
            if (stripos($desc, 'TOTAL AMOUNT') !== false || stripos($desc, 'OPENING BALANCE') !== false) continue;

            $ref = $ref ? ('tr:' . preg_replace('/^\D*/','', $ref)) : null;
            $amountStr = $debit !== '' ? $debit : ($credit !== '' ? $credit : null);
            $direction = $debit !== '' ? 'debit' : ($credit !== '' ? 'credit' : null);

            $amount = $amountStr !== null ? (float)str_replace([',',' '], '', $amountStr) : null;
            $balanceAfter = $bal !== null && $bal !== '' ? (float)str_replace([',',' '], '', $bal) : null;

            $opDate = null;
            if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', (string)$dateStr)) {
                $opDate = Carbon::createFromFormat('d/m/Y', $dateStr);
            } elseif (is_numeric($dateStr)) {
                $base = Carbon::create(1899,12,30);
                $opDate = (clone $base)->addDays((int)$dateStr);
            }

            if (!$ref || !$balanceAfter) continue;

            if (!$direction && $amount !== null && $prevBalance !== null) {
                if ($this->eq($prevBalance - $amount, $balanceAfter)) $direction = 'debit';
                if ($this->eq($prevBalance + $amount, $balanceAfter)) $direction = 'credit';
            }
            if ($balanceAfter !== null) $prevBalance = $balanceAfter;

            $rows[] = [
                'op_date'       => $opDate,
                'reference'     => $ref,
                'description'   => $desc,
                'amount'        => $amount,
                'direction'     => $direction,
                'balance_after' => $balanceAfter,
            ];
        }

        return [null, $rows];
    }

    private function extractOperationLines(string $text): array
    {
        $lines = preg_split('/\R/u', $text);
        $ops = [];
        foreach ($lines as $ln) {
            $ln = trim(preg_replace('/\s+/u', ' ', $ln));
            if ($ln === '') continue;
            if (preg_match('/^(Statement Of Account|Full Name:|Phone Number:|Account No:|Address:|Whish Card No:|Currency:)/u', $ln)) continue;
            if (preg_match('/^(OPENING BALANCE|TOTAL AMOUNT \/ CLOSING BALANCE)/u', $ln)) continue;
            if (preg_match('/^WHISH MONEY SAL|^Ground Floor,|^Issued on /u', $ln)) continue;
            if (preg_match('/^From \d{2}\/\d{2}\/\d{4} Till \d{2}\/\d{2}\/\d{4}$/u', $ln)) continue;
            if (preg_match('/^\d{2}\/\d{2}\/\d{4}\s+tr:\d+/u', $ln)) $ops[] = $ln;
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
        if (preg_match('/Issued on\s+([A-Za-z]{3}\s+[A-Za-z]{3}\s+\d{1,2},\s+\d{4})/u', $text, $m)) {
            try { return Carbon::parse($m[1]); } catch (\Throwable $e) { return null; }
        }
        return null;
    }

    private function fallbackMultilineScan(string $text, ?float $opening): array
    {
        $flat = preg_replace('/\s+/u', ' ', $text);
        $re = '/(?P<date>\d{2}\/\d{2}\/\d{4})\s+tr:(?P<ref>\d+)\s+(?P<desc>.+?)\s+(?P<amount>(\d{1,3}(,\d{3})*|\d+)\.\d{2})\s+(?P<bal>(\d{1,3}(,\d{3})*|\d+)\.\d{2})/u';

        $rows = [];
        $prevBalance = $opening;
        if (preg_match_all($re, $flat, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $dateStr  = $m['date'];
                $ref      = 'tr:' . $m['ref'];
                $desc     = trim($m['desc']);
                $amount   = (float) str_replace(',', '', $m['amount']);
                $balance  = (float) str_replace(',', '', $m['bal']);

                $direction = null;
                if ($prevBalance !== null) {
                    if ($this->eq($prevBalance - $amount, $balance)) $direction = 'debit';
                    if ($this->eq($prevBalance + $amount, $balance)) $direction = 'credit';
                }
                $prevBalance = $balance;

                $rows[] = [
                    'op_date'       => Carbon::createFromFormat('d/m/Y', $dateStr),
                    'reference'     => $ref,
                    'description'   => $desc,
                    'amount'        => $amount,
                    'direction'     => $direction,
                    'balance_after' => $balance,
                ];
            }
        }
        return $rows;
    }

    private function backfillDirections(array $rows): array
    {
        for ($i = 0; $i < count($rows); $i++) {
            if (($rows[$i]['direction'] ?? null) || $rows[$i]['amount'] === null) continue;
            $prev = $i > 0 ? $rows[$i-1] : null;
            $cur  = $rows[$i];
            $next = $i+1 < count($rows) ? $rows[$i+1] : null;

            if ($prev && $prev['balance_after'] !== null && $cur['balance_after'] !== null) {
                if ($this->eq($prev['balance_after'] - $cur['amount'], $cur['balance_after'])) $rows[$i]['direction'] = 'debit';
                if ($this->eq($prev['balance_after'] + $cur['amount'], $cur['balance_after'])) $rows[$i]['direction'] = 'credit';
            } elseif ($next && $cur['balance_after'] !== null && $next['balance_after'] !== null) {
                if ($this->eq($cur['balance_after'] + $cur['amount'], $next['balance_after'])) $rows[$i]['direction'] = 'debit';
                if ($this->eq($cur['balance_after'] - $cur['amount'], $next['balance_after'])) $rows[$i]['direction'] = 'credit';
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
