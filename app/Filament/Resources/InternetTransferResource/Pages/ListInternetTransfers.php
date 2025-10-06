<?php

namespace App\Filament\Resources\InternetTransferResource\Pages;

use App\Filament\Resources\InternetTransferResource;
use Filament\Resources\Pages\ListRecords;

class ListInternetTransfers extends ListRecords
{
    protected static string $resource = InternetTransferResource::class;

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\CreateAction::make(),
        ];
    }
}
