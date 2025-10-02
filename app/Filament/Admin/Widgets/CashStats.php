<?php

namespace App\Filament\Admin\Widgets;

use App\Models\CashEntry;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class CashStats extends BaseWidget
{
    protected static ?string $heading = 'ملخص النقدية';
    protected static bool $isLazy = false;

    protected function getStats(): array
    {
        $totals = CashEntry::selectRaw("
            SUM(CASE WHEN entry_type='RECEIPT' THEN amount ELSE 0 END) AS receipts,
            SUM(CASE WHEN entry_type='PAYMENT' THEN amount ELSE 0 END) AS payments
        ")->first();

        $receipts = (float) ($totals->receipts ?? 0);
        $payments = (float) ($totals->payments ?? 0);
        $net      = $receipts - $payments;

        $series = CashEntry::selectRaw("
            DATE(created_at) AS d,
            SUM(CASE WHEN entry_type='RECEIPT' THEN amount ELSE 0 END) AS r,
            SUM(CASE WHEN entry_type='PAYMENT' THEN amount ELSE 0 END) AS p
        ")
        ->where('created_at', '>=', now()->subDays(30))
        ->groupBy('d')
        ->orderBy('d')
        ->get();

        $receiptsSeries = $series->map(fn($row)=> (float)$row->r)->all();
        $paymentsSeries = $series->map(fn($row)=> (float)$row->p)->all();
        $netSeries      = $series->map(fn($row)=> (float)$row->r - (float)$row->p)->all();

        return [
            Stat::make('إجمالي القبض', number_format($receipts, 2))
                ->description('آخر 30 يوم')
                ->chart($receiptsSeries),
            Stat::make('إجمالي الدفع', number_format($payments, 2))
                ->description('آخر 30 يوم')
                ->chart($paymentsSeries),
            Stat::make('الصافي', number_format($net, 2))
                ->description('قبض - دفع')
                ->chart($netSeries),
        ];
    }
}
