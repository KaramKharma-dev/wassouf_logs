<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InternetTransfer;
use App\Models\Balance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            'idempotency_key' => ['nullable','string','max:64'],
        ]);

        $receiver = $this->normalizeMsisdn($data['receiver_number']);
        $quantity = (float) $data['quantity_gb'];
        $type     = $data['type'];
        $provider = $data['provider'];
        $senderNumber = $provider === 'mtc' ? '81764824' : '81222749';

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

        $qtyKey = rtrim(rtrim(sprintf('%.3f', $quantity), '0'), '.');
        if (!isset($pricing[$type][$provider][$qtyKey])) {
            return response()->json([
                'ok'=>false,
                'error'=>'quantity_not_allowed',
                'allowed'=>array_keys($pricing[$type][$provider] ?? []),
            ], 422);
        }
        $price = $pricing[$type][$provider][$qtyKey]['add'];

        if ($request->filled('idempotency_key')) {
            $existing = InternetTransfer::where('idempotency_key', $request->string('idempotency_key'))->first();
            if ($existing) {
                return response()->json([
                    'ok'=>true,'id'=>$existing->id,'status'=>$existing->status,'msg'=>'duplicate ignored by idempotency_key'
                ], 200);
            }
        }

        $row = InternetTransfer::create([
            'sender_number'   => $senderNumber,
            'receiver_number' => $receiver,
            'quantity_gb'     => $quantity,
            'price'           => $price,
            'provider'        => $provider,
            'type'            => $type,
            'status'          => 'PENDING',
            'idempotency_key' => $request->input('idempotency_key'),
        ]);

        return response()->json([
            'ok'=>true,'id'=>$row->id,'status'=>$row->status,
            'msg'=>'internet transfer created; waiting for SMS confirmation'
        ], 201);
    }

    public function smsCallback(Request $request)
    {
        $data = $request->validate([
            'provider'   => ['required', Rule::in(['alfa','mtc'])],
            'raw_sms'    => ['required','string','max:2000'],
            'sms_sender' => ['nullable','string','max:100'],
        ]);

        $provider = $data['provider'];
        $rawSms   = $data['raw_sms'];
        $smsHash  = sha1($provider.'|'.$rawSms);

        // 1) امنع التكرار على مستوى نص الرسالة
        return DB::transaction(function () use ($provider, $rawSms, $smsHash, $data) {

            if (InternetTransfer::where('sms_hash', $smsHash)->lockForUpdate()->exists()) {
                return response()->json(['ok'=>true,'msg'=>'duplicate sms ignored'], 200);
            }

            // 2) استخرج الرقم والكمية من نص الرسالة فقط
            $msisdn = $this->extractMsisdnFromSms($rawSms);          // مثال يلتقط 8111465 أو 03231922
            $qty    = $this->extractQtyGbFromSms($rawSms);           // مثال يلتقط 22 من "22GB"

            if (!$msisdn) {
                return response()->json(['ok'=>false,'error'=>'msisdn_not_found_in_sms'], 422);
            }
            if ($qty === null) {
                return response()->json(['ok'=>false,'error'=>'quantity_gb_not_found_in_sms'], 422);
            }

            // 3) ابحث سجل PENDING يطابق "تمامًا" الرقم والكمية لنفس المزوّد
            $candidate = InternetTransfer::where('status','PENDING')
                ->where('provider',$provider)
                ->where('receiver_number', $msisdn)
                ->whereBetween('quantity_gb', [$qty-0.001, $qty+0.001]) // تطابق كميات float
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if (!$candidate) {
                return response()->json([
                    'ok'=>false,
                    'error'=>'no_pending_match_exact',
                    'hint'=>'receiver_number and quantity_gb must both match a PENDING row'
                ], 404);
            }

            // 4) احسب الخصم والسعر من نوع العملية والمزوّد والكمية
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

            $qtyKey = rtrim(rtrim(sprintf('%.3f', (float)$candidate->quantity_gb), '0'), '.');
            $type   = $candidate->type;
            $prov   = $candidate->provider;

            if (!isset($pricing[$type][$prov][$qtyKey])) {
                return response()->json(['ok'=>false,'error'=>'pricing_not_found'], 422);
            }

            $deduct = $pricing[$type][$prov][$qtyKey]['deduct'];
            $price  = $pricing[$type][$prov][$qtyKey]['add'];

            // 5) قيود مالية + إقفال العملية
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
            $candidate->price        = $price;
            $candidate->save();

            return response()->json([
                'ok'=>true,'id'=>$candidate->id,'status'=>$candidate->status,
                'msg'=>'transfer confirmed and balances updated'
            ], 200);
        });
    }

    private function normalizeMsisdn(string $n): string
    {
        $n = preg_replace('/\D+/', '', $n);
        if (str_starts_with($n, '00961')) $n = substr($n, 5);
        elseif (str_starts_with($n, '961')) $n = substr($n, 3);
        if (strlen($n) > 8) $n = substr($n, -8);
        return $n;
    }

    private function extractMsisdnFromSms(string $text): ?string
    {
        // أولوية للنمط "number <digits>"
        if (preg_match('/number\s+(\d{7,8})\b/i', $text, $m)) {
            return $this->normalizeMsisdn($m[1]);
        }
        // احتياط: أي 7–8 أرقام مستقلة
        if (preg_match('/\b(\d{7,8})\b/', $text, $m)) {
            return $this->normalizeMsisdn($m[1]);
        }
        return null;
        }

    private function extractQtyGbFromSms(string $text): ?float
    {
        // يلتقط فقط GB كما في الرسالة
        if (preg_match('/([0-9]+(?:\.[0-9]+)?)\s*GB\b/i', $text, $m)) {
            return round((float)$m[1], 3);
        }
        return null;
    }
}
