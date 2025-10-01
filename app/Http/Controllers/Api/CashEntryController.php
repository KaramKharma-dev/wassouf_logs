<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashEntry;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class CashEntryController extends Controller
{
    // POST /api/cash-entries/list
    public function list(Request $r)
    {
        $data = $r->validate([
            'type'     => ['nullable', Rule::in(['RECEIPT','PAYMENT'])],
            'from'     => ['nullable','date'],
            'to'       => ['nullable','date'],
            'q'        => ['nullable','string','max:200'],
            'per_page' => ['nullable','integer','min:1','max:200'],
        ]);

        $q = CashEntry::query();

        if (!empty($data['type'])) {
            $q->where('entry_type', strtoupper($data['type']));
        }
        if (!empty($data['from'])) {
            $q->where('created_at', '>=', Carbon::parse($data['from'])->startOfDay());
        }
        if (!empty($data['to'])) {
            $q->where('created_at', '<=', Carbon::parse($data['to'])->endOfDay());
        }
        if (!empty($data['q'])) {
            $q->where('description', 'like', '%'.$data['q'].'%');
        }

        $perPage = $data['per_page'] ?? 20;
        return response()->json($q->orderBy('id','desc')->paginate($perPage));
    }

    // POST /api/cash-entries/create  (يدعم multipart لرفع صورة)
    public function store(Request $r)
    {
        $data = $r->validate([
            'description' => ['required','string','max:200'],
            'entry_type'  => ['required', Rule::in(['RECEIPT','PAYMENT'])],
            'amount'      => ['required','numeric','min:0.01'],
            'image'       => ['nullable','image','mimes:jpeg,png,webp','max:4096'],
        ]);

        $data['entry_type'] = strtoupper($data['entry_type']);
        $amount = (float)$data['amount'];
        $entry  = null;

        DB::transaction(function () use (&$entry, $data, $amount, $r) {
            if ($r->hasFile('image')) {
                $data['image_path'] = $r->file('image')->store('cash_entries', 'public');
            }

            $entry = CashEntry::create($data);

            $delta = ($data['entry_type'] === 'RECEIPT') ? +$amount : -$amount;

            $row = DB::table('balances')->where('provider','my_balance')->lockForUpdate()->first();
            if (!$row) {
                DB::table('balances')->insert([
                    'provider'   => 'my_balance',
                    'balance'    => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            DB::table('balances')->where('provider','my_balance')->update([
                'balance'    => DB::raw('balance + '.sprintf('%.2f', $delta)),
                'updated_at' => now(),
            ]);
        });

        return response()->json($entry, 201);
    }

    // POST /api/cash-entries/show/{id}
    public function show(int $id)
    {
        $entry = CashEntry::findOrFail($id);
        return response()->json($entry);
    }

    // POST /api/cash-entries/update/{id}  (يدعم استبدال الصورة)
    public function update(Request $r, int $id)
    {
        $data = $r->validate([
            'description' => ['sometimes','required','string','max:200'],
            'entry_type'  => ['sometimes','required', Rule::in(['RECEIPT','PAYMENT'])],
            'amount'      => ['sometimes','required','numeric','min:0.01'],
            'image'       => ['sometimes','nullable','image','mimes:jpeg,png,webp','max:4096'],
        ]);

        $entry = CashEntry::findOrFail($id);

        $oldType   = $entry->entry_type;
        $oldAmount = (float)$entry->amount;
        $oldDelta  = ($oldType === 'RECEIPT') ? +$oldAmount : -$oldAmount;

        $newType   = isset($data['entry_type']) ? strtoupper($data['entry_type']) : $oldType;
        $newAmount = isset($data['amount']) ? (float)$data['amount'] : $oldAmount;
        $newDelta  = ($newType === 'RECEIPT') ? +$newAmount : -$newAmount;
        $diff      = $newDelta - $oldDelta;

        DB::transaction(function () use ($entry, $r, $data, $newType, $diff) {
            if ($r->hasFile('image')) {
                if ($entry->image_path) {
                    Storage::disk('public')->delete($entry->image_path);
                }
                $data['image_path'] = $r->file('image')->store('cash_entries', 'public');
            }

            $update = $data;
            $update['entry_type'] = $newType;
            $entry->update($update);

            $row = DB::table('balances')->where('provider','my_balance')->lockForUpdate()->first();
            if (!$row) {
                DB::table('balances')->insert([
                    'provider'   => 'my_balance',
                    'balance'    => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            DB::table('balances')->where('provider','my_balance')->update([
                'balance'    => DB::raw('balance + '.sprintf('%.2f', $diff)),
                'updated_at' => now(),
            ]);
        });

        return response()->json($entry);
    }

    // POST /api/cash-entries/delete/{id}
    public function destroy(int $id)
    {
        $entry = CashEntry::findOrFail($id);

        $delta = ($entry->entry_type === 'RECEIPT')
            ? -(float)$entry->amount
            : +(float)$entry->amount;

        DB::transaction(function () use ($entry, $delta) {
            $row = DB::table('balances')->where('provider','my_balance')->lockForUpdate()->first();
            if (!$row) {
                DB::table('balances')->insert([
                    'provider'   => 'my_balance',
                    'balance'    => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            DB::table('balances')->where('provider','my_balance')->update([
                'balance'    => DB::raw('balance + '.sprintf('%.2f', $delta)),
                'updated_at' => now(),
            ]);

            if ($entry->image_path) {
                Storage::disk('public')->delete($entry->image_path);
            }

            $entry->delete();
        });

        return response()->json(['deleted' => true]);
    }

    // POST /api/cash-entries/stats
    public function stats(Request $r)
    {
        $data = $r->validate([
            'from' => ['nullable','date'],
            'to'   => ['nullable','date'],
        ]);

        $from = !empty($data['from']) ? Carbon::parse($data['from'])->startOfDay() : null;
        $to   = !empty($data['to'])   ? Carbon::parse($data['to'])->endOfDay()   : null;

        $receiptsQ = CashEntry::where('entry_type','RECEIPT');
        $paymentsQ = CashEntry::where('entry_type','PAYMENT');

        if ($from) { $receiptsQ->where('created_at','>=',$from); $paymentsQ->where('created_at','>=',$from); }
        if ($to)   { $receiptsQ->where('created_at','<=',$to);   $paymentsQ->where('created_at','<=',$to);   }

        $receipts = (float)$receiptsQ->sum('amount');
        $payments = (float)$paymentsQ->sum('amount');

        return response()->json([
            'from'           => $from?->toDateTimeString(),
            'to'             => $to?->toDateTimeString(),
            'total_receipts' => round($receipts, 2),
            'total_payments' => round($payments, 2),
            'net'            => round($receipts - $payments, 2),
        ]);
    }
}
