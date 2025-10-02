<?php

namespace App\Filament\Resources\CashEntryResource\Pages;

use App\Filament\Resources\CashEntryResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCashEntry extends EditRecord
{
    protected static string $resource = CashEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
