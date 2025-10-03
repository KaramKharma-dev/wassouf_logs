<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CashEntryResource\Pages\ListCashEntries;
use App\Models\CashEntry;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CashEntryResource extends Resource
{
    protected static ?string $model = CashEntry::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';
    protected static ?string $navigationLabel = 'قبض/دفع';
    protected static ?string $pluralLabel = 'قيود القبض والدفع';
    protected static ?string $modelLabel = 'قيد نقدي';

    public static function canCreate(): bool
    {
        return false; // ← يمنع زر "إنشاء"
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#')->sortable(),
                Tables\Columns\TextColumn::make('created_at')->label('التاريخ')->dateTime('Y-m-d H:i')->sortable(),
                Tables\Columns\BadgeColumn::make('entry_type')->label('النوع')
                    ->colors(['success' => 'RECEIPT', 'danger' => 'PAYMENT'])
                    ->formatStateUsing(fn (string $state) => $state === 'RECEIPT' ? 'قبض' : 'دفع')
                    ->sortable(),
                Tables\Columns\TextColumn::make('amount')->label('المبلغ')->numeric(2)->sortable(),
                Tables\Columns\TextColumn::make('description')->label('الوصف')->limit(60)->searchable(),
                Tables\Columns\ImageColumn::make('image_path')->label('الصورة')->disk('public')->height(40)->width(40)->circular(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([])
            ->actions([])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCashEntries::route('/'),
        ];
    }
}
