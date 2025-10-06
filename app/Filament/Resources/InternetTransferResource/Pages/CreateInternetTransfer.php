<?php

namespace App\Filament\Resources\InternetTransferResource\Pages;

use App\Filament\Resources\InternetTransferResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str; // ← مهم

class CreateInternetTransfer extends CreateRecord
{
    protected static string $resource = InternetTransferResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // توليد مفتاح Idempotency إذا لم يُرسل
        $data['idempotency_key'] = !empty($data['idempotency_key'])
            ? (string) $data['idempotency_key']
            : (string) Str::uuid();

        // 1) Normalize MSISDN
        $data['receiver_number'] = $this->normalizeMsisdn($data['receiver_number']);

        // 2) Resolve pricing
        $pricing = $this->pricingMatrix();
        $qtyKey  = rtrim(rtrim(sprintf('%.3f', (float)$data['quantity_gb']), '0'), '.');
        $type    = (string) $data['type'];
        $prov    = (string) $data['provider'];

        if (!isset($pricing[$type][$prov][$qtyKey])) {
            throw new \RuntimeException('pricing_not_found: allowed ['.implode(', ', array_keys($pricing[$type][$prov] ?? [])).']');
        }

        // 3) Auto fields
        $data['price']         = (float) $pricing[$type][$prov][$qtyKey]['add'];
        $data['sender_number'] = $prov === 'mtc' ? '81764824' : '81222749';
        $data['status']        = 'PENDING';

        // لا نسمح بإدخال created_at/price/sender_number/status من المستخدم
        unset($data['created_at']);

        return $data;
    }

    // ==== Helpers (نفس منطق الكنترولر) ====

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

    private function normalizeMsisdn(string $n): string
    {
        $n = preg_replace('/\D+/', '', $n);
        if (str_starts_with($n, '00961')) $n = substr($n, 5);
        elseif (str_starts_with($n, '961')) $n = substr($n, 3);
        if (strlen($n) > 8) $n = substr($n, -8);
        return $n;
    }
}
