<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DaysTopupService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Carbon\Carbon;

class DaysDailyController extends Controller
{
    // إضافة رسالة لهذا اليوم
    public function add(Request $r, DaysTopupService $svc)
    {
        $data = $r->validate([
            'msisdn'   => ['required','string','max:20'],
            'provider' => ['required', Rule::in(['alfa','mtc'])],
            'amount'   => ['required','numeric'],
            'ts'       => ['nullable','date'], // يُشتق op_date منه
        ]);
        $ts = isset($data['ts']) ? Carbon::parse($data['ts']) : now();
        $row = $svc->addMsgByDate($data['msisdn'],$data['provider'],(float)$data['amount'],$ts);
        return response()->json(['row'=>$row]);
    }

    // تسوية يدوية لنهاية اليوم (تحديث الأرصدة فقط)
    public function finalize(Request $r, DaysTopupService $svc)
    {
        $data = $r->validate([
            'msisdn'   => ['required','string','max:20'],
            'provider' => ['required', Rule::in(['alfa','mtc'])],
            'op_date'  => ['required','date'], // شكل YYYY-MM-DD
        ]);
        $row = $svc->finalizeDayCorrect($data['msisdn'],$data['provider'],$data['op_date']);
        return response()->json(['row'=>$row]);
    }

    public function finalizeAll(Request $r, \App\Services\DaysTopupService $svc)
    {
        $data = $r->validate([
            'provider' => ['required', \Illuminate\Validation\Rule::in(['alfa','mtc'])],
            'op_date'  => ['required','date'],
        ]);
        $n = $svc->finalizeAllForDate($data['provider'], $data['op_date']);
        return response()->json(['closed_rows'=>$n]);
    }

}
