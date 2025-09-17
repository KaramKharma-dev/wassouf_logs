<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InternetTransfer;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InternetTransferController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'receiver_number' => ['required','string','max:20'],
            'quantity_gb'     => ['required','numeric','min:0.001'],
            'provider'        => ['required', Rule::in(['alfa','mtc'])],
            'type'            => ['required', Rule::in(['weekly','monthly','monthly_internet'])],
            'idempotency_key' => ['nullable','string','max:64'], // يتطلب عمود بجدول internet_transfers
        ]);

        $receiver = $this->normalizeMsisdn($data['receiver_number']);
        $quantity = (float) $data['quantity_gb'];
        $type     = $data['type'];
        $provider = $data['provider'];
        $senderNumber = $provider === 'mtc' ? '81764824' : '81222749';

        // ملاحظة مهمة: مفاتيح PHP لا تدعم float كمفاتيح بشكل موثوق، استخدم نصوصًا.
        $pricing = [
            'monthly' => [
                'alfa' => [
                    '1'   => ['deduct'=>3.5,  'add'=>4],
                    '7'   => ['deduct'=>9,    'add'=>10],
                    '22'  => ['deduct'=>14.5, 'add'=>16],
                    '44'  => ['deduct'=>21,   'add'=>24],
                    '77'  => ['deduct'=>41,   'add'=>35],
                    '111' => ['deduct'=>40,   'add'=>45],
                    '444' => ['deduct'=>129,  'add'=>135],
                ],
                'mtc' => [
                    '1'   => ['deduct'=>3.5,  'add'=>4],
                    '7'   => ['deduct'=>9,    'add'=>10],
                    '22'  => ['deduct'=>14.5, 'add'=>16],
                    '44'  => ['deduct'=>21,   'add'=>24],
                    '77'  => ['deduct'=>41,   'add'=>35],
                    '111' => ['deduct'=>40,   'add'=>45],
                    '444' => ['deduct'=>129,  'add'=>135],
                ],
            ],
            'monthly_internet' => [
                'alfa' => [
                    '1'   => ['deduct'=>3.5,  'add'=>4],
                    '7'   => ['deduct'=>9,    'add'=>10],
                    '22'  => ['deduct'=>14.5, 'add'=>16],
                    '44'  => ['deduct'=>21,   'add'=>24],
                    '77'  => ['deduct'=>41,   'add'=>35],
                    '111' => ['deduct'=>40,   'add'=>45],
                    '444' => ['deduct'=>129,  'add'=>135],
                ],
                'mtc' => [
                    '1'   => ['deduct'=>3.5,  'add'=>4],
                    '7'   => ['deduct'=>9,    'add'=>10],
                    '22'  => ['deduct'=>14.5, 'add'=>16],
                    '44'  => ['deduct'=>21,   'add'=>24],
                    '77'  => ['deduct'=>41,   'add'=>35],
                    '111' => ['deduct'=>40,   'add'=>45],
                    '444' => ['deduct'=>129,  'add'=>135],
                ],
            ],
            'weekly' => [
                'alfa' => [
                    '0.5' => ['deduct'=>1.67, 'add'=>1.91],
                    '1.5' => ['deduct'=>2.34, 'add'=>2.64],
                    '5'   => ['deduct'=>5,    'add'=>5.617],
                ],
                'mtc' => [
                    '0.5' => ['deduct'=>1.67, 'add'=>1.91],
                    '1.5' => ['deduct'=>2.34, 'add'=>2.64],
                    '5'   => ['deduct'=>5,    'add'=>5.617],
                ],
            ],
        ];

        // حوّل الكمية إلى مفتاح نصي ثابت مثل "0.5" أو "5"
        $qtyKey = rtrim(rtrim(sprintf('%.3f', $quantity), '0'), '.');

        if (!isset($pricing[$type][$provider][$qtyKey])) {
            return response()->json([
                'ok' => false,
                'error' => 'quantity_not_allowed',
                'allowed' => array_keys($pricing[$type][$provider] ?? []),
            ], 422);
        }

        $price  = $pricing[$type][$provider][$qtyKey]['add'];
        // $deduct = $pricing[$type][$provider][$qtyKey]['deduct']; // يستخدم لاحقًا عند التحويل إلى COMPLETED

        // منع التكرار عبر idempotency_key إن وُجد
        if ($request->filled('idempotency_key')) {
            $existing = InternetTransfer::where('idempotency_key', $request->string('idempotency_key'))->first();
            if ($existing) {
                return response()->json([
                    'ok'     => true,
                    'id'     => $existing->id,
                    'status' => $existing->status,
                    'msg'    => 'duplicate ignored by idempotency_key',
                ], 200);
            }
        }

        // إنشاء سجل بحالة PENDING فقط. لا تعديل أرصدة الآن.
        $row = InternetTransfer::create([
            'sender_number'   => $senderNumber,
            'receiver_number' => $receiver,
            'quantity_gb'     => $quantity,
            'price'           => $price,
            'provider'        => $provider,
            'type'            => $type,
            'status'          => 'PENDING',            // يتطلب عمود status
            'idempotency_key' => $request->input('idempotency_key'),
        ]);

        return response()->json([
            'ok'     => true,
            'id'     => $row->id,
            'status' => $row->status,
            'msg'    => 'internet transfer created; waiting for SMS confirmation',
        ], 201);
    }

    private function normalizeMsisdn(string $n): string
    {
        $n = preg_replace('/\D+/', '', $n);
        if (str_starts_with($n, '00961')) $n = substr($n, 5);
        elseif (str_starts_with($n, '961')) $n = substr($n, 3);
        if (strlen($n) > 8) $n = substr($n, -8);
        return $n;
    }

    public function smsCallback(Request $request)
    {
        $data = $request->validate([
            'provider'     => ['required', Rule::in(['alfa','mtc'])],
            'raw_sms'      => ['required','string','max:2000'],
            'sms_sender'   => ['nullable','string','max:100'],
            'tx_id'        => ['nullable','integer'],              // إن وُجد
            'msisdn'       => ['nullable','string','max:20'],      // إن استخرجته MacroDroid
            'quantity_gb'  => ['nullable','numeric','min:0.001'],  // إن استخرجته MacroDroid
        ]);

        $provider = $data['provider'];
        $rawSms   = $data['raw_sms'];
        $smsHash  = sha1($provider.'|'.$rawSms);

        // جدول الأسعار نفسه المستخدم في store()
        $pricing = [
            'monthly' => [
                'alfa' => [
                    '1'=>['deduct'=>3.5,'add'=>4],'7'=>['deduct'=>9,'add'=>10],
                    '22'=>['deduct'=>14.5,'add'=>16],'44'=>['deduct'=>21,'add'=>24],
                    '77'=>['deduct'=>41,'add'=>35],'111'=>['deduct'=>40,'add'=>45],
                    '444'=>['deduct'=>129,'add'=>135],
                ],
                'mtc' => [
                    '1'=>['deduct'=>3.5,'add'=>4],'7'=>['deduct'=>9,'add'=>10],
                    '22'=>['deduct'=>14.5,'add'=>16],'44'=>['deduct'=>21,'add'=>24],
                    '77'=>['deduct'=>41,'add'=>35],'111'=>['deduct'=>40,'add'=>45],
                    '444'=>['deduct'=>129,'add'=>135],
                ],
            ],
            'monthly_internet' => [
                'alfa' => [
                    '1'=>['deduct'=>3.5,'add'=>4],'7'=>['deduct'=>9,'add'=>10],
                    '22'=>['deduct'=>14.5,'add'=>16],'44'=>['deduct'=>21,'add'=>24],
                    '77'=>['deduct'=>41,'add'=>35],'111'=>['deduct'=>40,'add'=>45],
                    '444'=>['deduct'=>129,'add'=>135],
                ],
                'mtc' => [
                    '1'=>['deduct'=>3.5,'add'=>4],'7'=>['deduct'=>9,'add'=>10],
                    '22'=>['deduct'=>14.5,'add'=>16],'44'=>['deduct'=>21,'add'=>24],
                    '77'=>['deduct'=>41,'add'=>35],'111'=>['deduct'=>40,'add'=>45],
                    '444'=>['deduct'=>129,'add'=>135],
                ],
            ],
            'weekly' => [
                'alfa' => [
                    '0.5'=>['deduct'=>1.67,'add'=>1.91],
                    '1.5'=>['deduct'=>2.34,'add'=>2.64],
                    '5'  =>['deduct'=>5,'add'=>5.617],
                ],
                'mtc' => [
                    '0.5'=>['deduct'=>1.67,'add'=>1.91],
                    '1.5'=>['deduct'=>2.34,'add'=>2.64],
                    '5'  =>['deduct'=>5,'add'=>5.617],
                ],
            ],
        ];

        // استخراج msisdn والكمية إن لم تُرسل من MacroDroid
        $msisdn = isset($data['msisdn']) ? $this->normalizeMsisdn($data['msisdn']) : $this->extractMsisdnFromSms($rawSms);
        $qty    = isset($data['quantity_gb']) ? (float)$data['quantity_gb'] : $this->extractQtyGbFromSms($rawSms);

        // لا تُوقف إذا تعذّر الاستخراج. سنحاول المطابقة بالمتاح.
        return DB::transaction(function () use ($provider, $rawSms, $smsHash, $msisdn, $qty, $pricing, $data) {
            // Idempotency على مستوى نص الـSMS
            if (InternetTransfer::where('sms_hash', $smsHash)->lockForUpdate()->exists()) {
                return response()->json(['ok'=>true,'msg'=>'duplicate sms ignored'], 200);
            }

            // اختيار العملية المرشّحة
            $q = InternetTransfer::where('status','PENDING')
                ->where('provider',$provider)
                ->orderByDesc('id')
                ->lockForUpdate();

            if (!empty($data['tx_id'])) {
                $q->where('id', $data['tx_id']);
            }
            if ($msisdn) {
                $q->where('receiver_number', $msisdn);
            }
            $candidate = $q->first();

            // fallback: لو لم نجد، جرّب مطابقة بالكمية إن متاحة
            if (!$candidate && $qty !== null) {
                $qtyFilter = function ($qq) use ($qty) {
                    $min = $qty - 0.001; $max = $qty + 0.001;
                    $qq->whereBetween('quantity_gb', [$min,$max]);
                };
                $candidate = InternetTransfer::where('status','PENDING')
                    ->where('provider',$provider)
                    ->when($msisdn, fn($qq)=>$qq->where('receiver_number',$msisdn))
                    ->where(function($qq) use ($qtyFilter){ $qtyFilter($qq); })
                    ->orderByDesc('id')
                    ->lockForUpdate()
                    ->first();
            }

            if (!$candidate) {
                return response()->json([
                    'ok'=>false,'error'=>'no_pending_match',
                    'hint'=>'send tx_id or parsed msisdn/qty'
                ], 404);
            }

            // احسب المفاتيح
            $qtyKey = rtrim(rtrim(sprintf('%.3f', (float)$candidate->quantity_gb), '0'), '.');
            $type   = $candidate->type;
            $prov   = $candidate->provider;

            if (!isset($pricing[$type][$prov][$qtyKey])) {
                return response()->json(['ok'=>false,'error'=>'pricing_not_found'], 422);
            }

            $deduct = $pricing[$type][$prov][$qtyKey]['deduct'];
            $price  = $pricing[$type][$prov][$qtyKey]['add'];

            // قيود مالية + إقفال العملية
            Balance::adjust($prov, -$deduct);
            Balance::adjust('my_balance', $price);

            $candidate->status       = 'COMPLETED';
            $candidate->confirmed_at = now();
            $candidate->sms_hash     = $smsHash;
            $candidate->sms_meta     = [
                'sender' => $data['sms_sender'] ?? null,
                'raw'    => $rawSms,
                'parsed' => ['msisdn'=>$msisdn,'quantity_gb'=>$qty],
            ];
            $candidate->price        = $price; // تثبيت السعر النهائي
            $candidate->save();

            return response()->json([
                'ok'=>true,
                'id'=>$candidate->id,
                'status'=>$candidate->status,
                'msg'=>'transfer confirmed and balances updated'
            ], 200);
        });
    }

    private function extractMsisdnFromSms(string $text): ?string
    {
        if (preg_match('/(\d{8})\b/', $text, $m)) {
            return $this->normalizeMsisdn($m[1]);
        }
        return null;
    }

    private function extractQtyGbFromSms(string $text): ?float
    {
        // يلتقط 0.5 أو 5 أو 22 GB. يدعم MB بتحويل إلى GB.
        if (preg_match('/([0-9]+(?:\.[0-9]+)?)\s*(GB|Mb|MB)\b/i', $text, $m)) {
            $n = (float)$m[1];
            $u = strtoupper($m[2]);
            if ($u === 'MB' || $u === 'MB') {
                return round($n/1024, 3);
            }
            return round($n, 3);
        }
        return null;
    }
}
