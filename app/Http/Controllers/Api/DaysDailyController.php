<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DaysTopupService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Carbon\Carbon;

class DaysDailyController extends Controller
{
    /**
     * إدخال يدوي: msisdn + amount (يبقى كما هو)
     */
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

    /**
     * إدخال من SMS خام: msg + receiver_number + provider
     * أمثلة الرسائل:
     * - alfa: "Dear Subscriber, USD 3.0 ... from the mobile number 96181564049 ..."
     * - mtc : "Dear customer, $3.0 were transferred ... from the mobile number 96181709315."
     */
    public function ingestSms(Request $r, DaysTopupService $svc)
    {
        $data = $r->validate([
            'msg'             => ['required','string','max:1000'],
            'receiver_number' => ['required','string','max:20'],
            'provider'        => ['required', Rule::in(['alfa','mtc'])],
            'ts'              => ['nullable','date'],
        ]);

        $ts  = isset($data['ts']) ? Carbon::parse($data['ts']) : now();
        $row = $svc->ingestSms($data['provider'], $data['msg'], $data['receiver_number'], $ts);

        return response()->json(['row'=>$row]);
    }

    /**
     * تسوية يدوية لنهاية اليوم (تحديث الأرصدة فقط)
     */
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

    /**
     * إقفال كل سطور يوم معيّن لمزوّد محدد
     */
    public function finalizeAll(Request $r, DaysTopupService $svc)
    {
        $data = $r->validate([
            'provider' => ['required', Rule::in(['alfa','mtc'])],
            'op_date'  => ['required','date'],
        ]);

        $n = $svc->finalizeAllForDate($data['provider'], $data['op_date']);

        return response()->json(['closed_rows'=>$n]);
    }
}
