<?php

namespace App\Filament\Admin\Resources\CashEntryResource\Pages;

use App\Filament\Admin\Resources\CashEntryResource;
use Filament\Resources\Pages\ListRecords;

class ListCashEntries extends ListRecords
{
    protected static string $resource = CashEntryResource::class;

    protected function getHeaderActions(): array
    {
        return []; // إخفاء زر "إنشاء"
    }
}
