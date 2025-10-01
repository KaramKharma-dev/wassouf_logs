<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashEntry;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
            $q->where('entry_type', $data['type']);
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

    // POST /api/cash-entries/create
    public function store(Request $r)
    {
        $data = $r->validate([
            'description' => ['required','string','max:200'],
            'entry_type'  => ['required', Rule::in(['RECEIPT','PAYMENT'])],
            'amount'      => ['required','numeric','min:0.01'],
        ]);

        $data['entry_type'] = strtoupper($data['entry_type']);

        $entry = CashEntry::create($data);
        return response()->json($entry, 201);
    }

    // POST /api/cash-entries/show/{id}
    public function show(int $id)
    {
        $entry = CashEntry::findOrFail($id);
        return response()->json($entry);
    }

    // POST /api/cash-entries/update/{id}
    public function update(Request $r, int $id)
    {
        $data = $r->validate([
            'description' => ['sometimes','required','string','max:200'],
            'entry_type'  => ['sometimes','required', Rule::in(['RECEIPT','PAYMENT'])],
            'amount'      => ['sometimes','required','numeric','min:0.01'],
        ]);

        if (isset($data['entry_type'])) {
            $data['entry_type'] = strtoupper($data['entry_type']);
        }

        $entry = CashEntry::findOrFail($id);
        $entry->update($data);
        return response()->json($entry);
    }

    // POST /api/cash-entries/delete/{id}
    public function destroy(int $id)
    {
        $entry = CashEntry::findOrFail($id);
        $entry->delete();
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

        $range = function($q) use ($from,$to) {
            if ($from) $q->where('created_at','>=',$from);
            if ($to)   $q->where('created_at','<=',$to);
        };

        $receipts = CashEntry::where('entry_type','RECEIPT')->tap($range)->sum('amount');
        $payments = CashEntry::where('entry_type','PAYMENT')->tap($range)->sum('amount');

        return response()->json([
            'from'           => $from?->toDateTimeString(),
            'to'             => $to?->toDateTimeString(),
            'total_receipts' => round((float)$receipts, 2),
            'total_payments' => round((float)$payments, 2),
            'net'            => round((float)$receipts - (float)$payments, 2),
        ]);
    }
}
