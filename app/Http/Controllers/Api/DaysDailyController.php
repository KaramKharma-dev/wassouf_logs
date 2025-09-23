<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DaysTopupService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Carbon\Carbon;

class DaysDailyController extends Controller
{
    public function add(Request $r, DaysTopupService $svc)
    {
        $data = $r->validate([
            'msisdn'          => ['required','string','max:20'], // رقم المُرسل
            'receiver_number' => ['required','string','max:20'], // رقم المستفيد (مفتاح التجميع)
            'provider'        => ['required', Rule::in(['alfa','mtc'])],
            'amount'          => ['required','numeric'],
            'ts'              => ['nullable','date'],
        ]);
        $ts = isset($data['ts']) ? Carbon::parse($data['ts']) : now();
        $row = $svc->addMsgByDate(
            $data['msisdn'],
            $data['receiver_number'],
            $data['provider'],
            (float)$data['amount'],
            $ts
        );
        return response()->json(['row'=>$row]);
    }


    // تسوية يدوية لنهاية اليوم (تحديث الأرصدة فقط)
    public function finalize(Request $r, DaysTopupService $svc)
    {
        $data = $r->validate([
            'receiver_number' => ['required','string','max:20'],
            'provider'        => ['required', Rule::in(['alfa','mtc'])],
            'op_date'         => ['required','date'],
        ]);
        $row = $svc->finalizeDayCorrect($data['receiver_number'],$data['provider'],$data['op_date']);
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
