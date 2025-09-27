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

        $receiver      = $this->normalizeMsisdn($data['receiver_number']);
        $quantity      = (float) $data['quantity_gb'];
        $type          = $data['type'];
        $provider      = $data['provider'];
        $senderNumber  = $provider === 'mtc' ? '81764824' : '81222749';

        $pricing = [
            'monthly' => [
                'alfa' => [
                    '1'=>['deduct'=>3.5,'add'=>4],'7'=>['deduct'=>9,'add'=>10],
                    '22'=>['deduct'=>14.5,'add'=>16],'44'=>['deduct'=>21,'add'=>24],
                    '77'=>['deduct'=>31,'add'=>35],'111'=>['deduct'=>40,'add'=>45],
                    '444'=>['deduct'=>129,'add'=>145],
                ],
                'mtc' => [
                    '1'=>['deduct'=>3.5,'add'=>4],'7'=>['deduct'=>9,'add'=>10],
                    '22'=>['deduct'=>14.5,'add'=>16],'44'=>['deduct'=>21,'add'=>24],
                    '77'=>['deduct'=>31,'add'=>35],'111'=>['deduct'=>40,'add'=>45],
                    '444'=>['deduct'=>129,'add'=>145],
                ],
            ],
            'monthly_internet' => [
                'alfa' => [
                    '1'=>['deduct'=>3.5,'add'=>4],'7'=>['deduct'=>9,'add'=>10],
                    '22'=>['deduct'=>14.5,'add'=>16],'44'=>['deduct'=>21,'add'=>24],
                    '77'=>['deduct'=>31,'add'=>35],'111'=>['deduct'=>40,'add'=>45],
                    '444'=>['deduct'=>129,'add'=>145],
                ],
                'mtc' => [
                    '1'=>['deduct'=>3.5,'add'=>4],'7'=>['deduct'=>9,'add'=>10],
                    '22'=>['deduct'=>14.5,'add'=>16],'44'=>['deduct'=>21,'add'=>24],
                    '77'=>['deduct'=>31,'add'=>35],'111'=>['deduct'=>40,'add'=>45],
                    '444'=>['deduct'=>129,'add'=>145],
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

        return DB::transaction(function () use ($provider, $rawSms, $smsHash, $data) {

            // NEW: اكتشاف رسائل الفشل “cannot receive … as an Alfa Gift”
            if ($this->isFailureSms($rawSms)) {
                $msisdn = $this->extractMsisdnFromSms($rawSms);
                $qty    = $this->extractQtyGbFromSms($rawSms); // قد تكون غير موجودة في رسالة الفشل

                if (!$msisdn) {
                    return response()->json(['ok'=>false,'error'=>'msisdn_not_found_in_failure_sms'], 422);
                }

                $pendingQuery = InternetTransfer::where('status','PENDING')
                    ->where('provider',$provider)
                    ->where('receiver_number', $msisdn)
                    ->orderByDesc('id')
                    ->lockForUpdate();

                if ($qty !== null) {
                    $pendingQuery->whereBetween('quantity_gb', [$qty-0.001, $qty+0.001]);
                }

                $candidate = $pendingQuery->first();

                if ($candidate) {
                    $candidate->status   = 'FAILED';
                    $candidate->sms_hash = $smsHash;
                    $candidate->sms_meta = [
                        'sender' => $data['sms_sender'] ?? null,
                        'raw'    => $rawSms,
                        'parsed' => ['msisdn'=>$msisdn,'quantity_gb'=>$qty],
                        'reason' => 'cannot_receive_gift',
                    ];
                    $candidate->save();

                    return response()->json([
                        'ok'=>true,'id'=>$candidate->id,'status'=>'FAILED',
                        'msg'=>'pending matched -> failed; no balance changes'
                    ], 200);
                }

                // لا يوجد Pending مطابق: نسجل صف FAILED للمراجعة فقط
                // محاولة استنتاج النوع والسعر للتوثيق
                $pricing = $this->pricingMatrix();
                [$pickedType, $deduct, $price] = $this->resolvePriceAndType($pricing, $provider, $qty ?? 0.0, $rawSms);

                $row = new InternetTransfer();
                $row->sender_number   = $this->pickSenderNumber($provider, $data['sms_sender'] ?? null);
                $row->receiver_number = $msisdn;
                $row->quantity_gb     = $qty ?? 0.0;
                if ($price !== null) $row->price = $price;
                $row->provider        = $provider;
                if ($pickedType !== null) $row->type = $pickedType;
                $row->status          = 'FAILED';
                $row->sms_hash        = $smsHash;
                $row->sms_meta        = [
                    'sender' => $data['sms_sender'] ?? null,
                    'raw'    => $rawSms,
                    'parsed' => ['msisdn'=>$msisdn,'quantity_gb'=>$qty],
                    'reason' => 'cannot_receive_gift',
                    'auto_failed' => true,
                ];
                $row->save();

                return response()->json([
                    'ok'=>true,'id'=>$row->id,'status'=>'FAILED',
                    'msg'=>'no pending -> created failed; no balance changes'
                ], 201);
            }

            // 1) استخرج الرقم والكمية لحالات النجاح
            $msisdn = $this->extractMsisdnFromSms($rawSms);
            $qty    = $this->extractQtyGbFromSms($rawSms);

            if (!$msisdn) {
                return response()->json(['ok'=>false,'error'=>'msisdn_not_found_in_sms'], 422);
            }
            if ($qty === null) {
                return response()->json(['ok'=>false,'error'=>'quantity_gb_not_found_in_sms'], 422);
            }

            // 2) إن وُجد PENDING مطابق اقفله كـ COMPLETED
            $candidate = InternetTransfer::where('status','PENDING')
                ->where('provider',$provider)
                ->where('receiver_number', $msisdn)
                ->whereBetween('quantity_gb', [$qty-0.001, $qty+0.001])
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            $pricing = $this->pricingMatrix();

            if ($candidate) {
                $qtyKey = rtrim(rtrim(sprintf('%.3f', (float)$candidate->quantity_gb), '0'), '.');
                $type   = $candidate->type;
                $prov   = $candidate->provider;

                if (!isset($pricing[$type][$prov][$qtyKey])) {
                    return response()->json(['ok'=>false,'error'=>'pricing_not_found'], 422);
                }

                $deduct = $pricing[$type][$prov][$qtyKey]['deduct'];
                $price  = $pricing[$type][$prov][$qtyKey]['add'];

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
                    'msg'=>'pending matched -> completed, balances updated'
                ], 200);
            }

            // لا Pending: إنشاء COMPLETED جديد
            [$pickedType, $deduct, $price] = $this->resolvePriceAndType($pricing, $provider, $qty, $rawSms);
            if ($pickedType === null) {
                return response()->json(['ok'=>false,'error'=>'pricing_not_found_for_qty'], 422);
            }

            Balance::adjust($provider, -$deduct);
            Balance::adjust('my_balance', $price);

            $row = new InternetTransfer();
            $row->sender_number   = $this->pickSenderNumber($provider, $data['sms_sender'] ?? null);
            $row->receiver_number = $msisdn;
            $row->quantity_gb     = $qty;
            $row->price           = $price;
            $row->provider        = $provider;
            $row->type            = $pickedType;
            $row->status          = 'COMPLETED';
            $row->confirmed_at    = now();
            $row->sms_hash        = $smsHash;
            $row->sms_meta        = [
                'sender' => $data['sms_sender'] ?? null,
                'raw'    => $rawSms,
                'parsed' => ['msisdn'=>$msisdn,'quantity_gb'=>$qty],
                'auto_completed' => true,
            ];
            $row->save();

            return response()->json([
                'ok'=>true,'id'=>$row->id,'status'=>$row->status,
                'msg'=>'no pending -> created completed, balances updated'
            ], 201);
        });
    }

    // ==== Helpers ====

    private function pricingMatrix(): array
    {
        return [
            'monthly' => [
                'alfa' => [
                    '1'=>['deduct'=>3.5,'add'=>4],'7'=>['deduct'=>9,'add'=>10],
                    '22'=>['deduct'=>14.5,'add'=>16],'44'=>['deduct'=>21,'add'=>24],
                    '77'=>['deduct'=>31,'add'=>35],'111'=>['deduct'=>40,'add'=>45],
                    '444'=>['deduct'=>129,'add'=>145],
                ],
                'mtc' => [
                    '1'=>['deduct'=>3.5,'add'=>4],'7'=>['deduct'=>9,'add'=>10],
                    '22'=>['deduct'=>14.5,'add'=>16],'44'=>['deduct'=>21,'add'=>24],
                    '77'=>['deduct'=>31,'add'=>35],'111'=>['deduct'=>40,'add'=>45],
                    '444'=>['deduct'=>129,'add'=>145],
                ],
            ],
            'monthly_internet' => [
                'alfa' => [
                    '1'=>['deduct'=>3.5,'add'=>4],'7'=>['deduct'=>9,'add'=>10],
                    '22'=>['deduct'=>14.5,'add'=>16],'44'=>['deduct'=>21,'add'=>24],
                    '77'=>['deduct'=>31,'add'=>35],'111'=>['deduct'=>40,'add'=>45],
                    '444'=>['deduct'=>129,'add'=>145],
                ],
                'mtc' => [
                    '1'=>['deduct'=>3.5,'add'=>4],'7'=>['deduct'=>9,'add'=>10],
                    '22'=>['deduct'=>14.5,'add'=>16],'44'=>['deduct'=>21,'add'=>24],
                    '77'=>['deduct'=>31,'add'=>35],'111'=>['deduct'=>40,'add'=>45],
                    '444'=>['deduct'=>129,'add'=>145],
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
    }

    private function resolvePriceAndType(array $pricing, string $provider, float $qty, string $rawSms): array
    {
        $qtyKey = rtrim(rtrim(sprintf('%.3f', (float)$qty), '0'), '.');

        $hintWeekly  = preg_match('/\bweekly\b/i', $rawSms) || in_array($qtyKey, ['0.5','1.5','5'], true);
        $hintMbb     = preg_match('/\bmbb\b/i', $rawSms) || preg_match('/internet\s*line|mbb/i', $rawSms);
        $candidates  = [];

        if ($hintWeekly)        $candidates[] = 'weekly';
        if ($hintMbb)           $candidates[] = 'monthly_internet';
        $candidates[] = 'monthly';
        $candidates[] = 'weekly';
        $candidates[] = 'monthly_internet';

        foreach (array_unique($candidates) as $type) {
            if (isset($pricing[$type][$provider][$qtyKey])) {
                $p = $pricing[$type][$provider][$qtyKey];
                return [$type, $p['deduct'], $p['add']];
            }
        }
        return [null, null, null];
    }

    private function pickSenderNumber(string $provider, ?string $smsSender): string
    {
        if ($smsSender && preg_match('/\d{7,15}/', $smsSender, $m)) {
            return $this->normalizeMsisdn($m[0]);
        }
        return $provider === 'mtc' ? '81764824' : '81222749';
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
        if (preg_match('/number\s+(\d{7,8})\b/i', $text, $m)) {
            return $this->normalizeMsisdn($m[1]);
        }
        if (preg_match('/\b(\d{7,8})\b/', $text, $m)) {
            return $this->normalizeMsisdn($m[1]);
        }
        return null;
    }

    private function extractQtyGbFromSms(string $text): ?float
    {
        if (preg_match('/([0-9]+(?:\.[0-9]+)?)\s*GB\b/i', $text, $m)) {
            return round((float)$m[1], 3);
        }
        return null;
    }

    // NEW: كاشف الفشل
    private function isFailureSms(string $text): bool
    {
        // مثال الرسالة: "the number 70287364 ... cannot receive the 7GB Mobile Internet as an Alfa Gift"
        return (bool) preg_match('/cannot\s+receive.*Alfa\s+Gift/i', $text);
    }
}
