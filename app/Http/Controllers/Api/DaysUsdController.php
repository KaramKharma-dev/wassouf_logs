<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DaysTopupService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Carbon\Carbon;

class DaysUsdController extends Controller
{
    public function ingest(Request $r, DaysTopupService $svc)
    {
        $data = $r->validate([
            'msisdn'   => ['required','string','max:20'],
            'provider' => ['required', Rule::in(['alfa','mtc'])],
            'amount'   => ['required','numeric'],
            'ts'       => ['nullable','date'],
        ]);

        $ts = isset($data['ts']) ? Carbon::parse($data['ts']) : now();
        $svc->ingestUsdMsg($data['msisdn'], $data['provider'], (float)$data['amount'], $ts);

        return response()->json(['ok'=>true]);
    }
}
