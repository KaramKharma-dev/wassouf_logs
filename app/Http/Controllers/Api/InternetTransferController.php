<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InternetTransfer;
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
            'price'           => ['required','numeric','min:0'],
            'provider'        => ['required', Rule::in(['alfa','mtc'])],
            'type'            => ['nullable','string','max:50'],
        ]);

        // اختيار sender حسب المزود
        $senderNumber = $data['provider'] === 'mtc' ? '81764824' : '81222749';

        // تطبيع رقم المستلم إلى 8 خانات محلية
        $receiver = $this->normalizeMsisdn($data['receiver_number']);

        $payload = [
            'sender_number'   => $senderNumber,
            'receiver_number' => $receiver,
            'quantity_gb'     => (float) $data['quantity_gb'],
            'price'           => (float) $data['price'],
            'provider'        => $data['provider'],
            'type'            => $data['type'] ?? null,
        ];

        return DB::transaction(function () use ($payload) {
            $row = InternetTransfer::create($payload);

            return response()->json([
                'ok' => true,
                'id' => $row->id,
                'msg'=> 'internet transfer stored'
            ], 201);
        });
    }

    private function normalizeMsisdn(string $n): string
    {
        $n = preg_replace('/\D+/', '', $n);

        if (str_starts_with($n, '00961')) {
            $n = substr($n, 5);
        } elseif (str_starts_with($n, '961')) {
            $n = substr($n, 3);
        }

        if (strlen($n) > 8) {
            $n = substr($n, -8);
        }

        return $n;
    }
}
