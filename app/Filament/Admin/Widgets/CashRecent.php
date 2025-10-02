<?php

namespace App\Filament\Admin\Widgets;

use App\Models\CashEntry;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class CashRecent extends BaseWidget
{
    protected static ?string $heading = 'آخر العمليات';
    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(CashEntry::query()->latest())
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#')->sortable(),
                Tables\Columns\TextColumn::make('created_at')->label('التاريخ')->dateTime('Y-m-d H:i')->sortable(),
                Tables\Columns\BadgeColumn::make('entry_type')->label('النوع')
                    ->colors(['success' => 'RECEIPT', 'danger' => 'PAYMENT'])
                    ->formatStateUsing(fn(string $s) => $s === 'RECEIPT' ? 'قبض' : 'دفع')
                    ->sortable(),
                Tables\Columns\TextColumn::make('amount')->label('المبلغ')->numeric(2)->sortable(),
                Tables\Columns\TextColumn::make('description')->label('الوصف')->limit(50)->toggleable(),
                Tables\Columns\ImageColumn::make('image_path')->label('الصورة')
                    ->disk('public')->height(40)->width(40)->circular()->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10);
    }
}
