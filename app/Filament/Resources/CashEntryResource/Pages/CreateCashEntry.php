<?php

namespace App\Filament\Resources\CashEntryResource\Pages;

use App\Filament\Resources\CashEntryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCashEntry extends CreateRecord
{
    protected static string $resource = CashEntryResource::class;

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'تمت إضافة الحركة';
    }
}
