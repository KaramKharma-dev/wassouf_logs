<?php

namespace App\Filament\Resources\CashEntryResource\Pages;

use App\Filament\Resources\CashEntryResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCashEntries extends ListRecords
{
    protected static string $resource = CashEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('إضافة حركة'),
        ];
    }
}
