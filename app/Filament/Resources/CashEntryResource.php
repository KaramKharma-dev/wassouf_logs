<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CashEntryResource\Pages;
use App\Models\CashEntry;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Infolists\Infolist;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ImageEntry;
use Illuminate\Support\Facades\Storage;

class CashEntryResource extends Resource
{
    protected static ?string $model = CashEntry::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';
    protected static ?string $navigationLabel = 'الحركات النقدية';
    protected static ?string $pluralLabel = 'الحركات النقدية';
    protected static ?string $label = 'حركة نقدية';
    protected static ?string $navigationGroup = 'المالية';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('amount')
                ->label('المبلغ')
                ->numeric()
                ->required()
                ->suffix('USD'),

            Forms\Components\Select::make('entry_type')
                ->label('النوع')
                ->options([
                    'RECEIPT' => 'قبض',
                    'PAYMENT' => 'دفع',
                ])
                ->required()
                ->native(false),

            Forms\Components\TextInput::make('description')
                ->label('الوصف')
                ->maxLength(200)
                ->required(),

            Forms\Components\FileUpload::make('image_path')
                ->label('صورة إيصال')
                ->disk('public')
                ->directory('cash_entries')
                ->image()
                ->downloadable()
                ->previewable(true)
                ->openable()
                ->nullable(),

            Forms\Components\DateTimePicker::make('created_at')
                ->label('تاريخ الإدخال')
                ->seconds(false)
                ->default(now())
                ->required(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\ImageColumn::make('image_path')
                ->label('الصورة')
                ->disk('public')
                ->height(48)
                ->url(fn ($record) => $record->image_path
                    ? Storage::disk('public')->url($record->image_path)
                    : null
                )
                ->openUrlInNewTab(),

            Tables\Columns\TextColumn::make('amount')
                ->label('المبلغ')
                ->numeric(2)
                ->sortable()
                ->badge(),

            Tables\Columns\TextColumn::make('entry_type')
                ->label('النوع')
                ->formatStateUsing(fn (string $state) => $state === 'RECEIPT' ? 'قبض' : 'دفع')
                ->badge()
                ->colors([
                    'success' => fn (string $state): bool => $state === 'RECEIPT',
                    'danger'  => fn (string $state): bool => $state === 'PAYMENT',
                ]),

            Tables\Columns\TextColumn::make('description')
                ->label('الوصف')
                ->limit(40)
                ->tooltip(fn ($record) => $record->description),

            Tables\Columns\TextColumn::make('created_at')
                ->label('التاريخ')
                ->dateTime('Y-m-d H:i')
                ->sortable(),
        ])
        ->filters([
            Tables\Filters\SelectFilter::make('entry_type')
                ->label('النوع')
                ->options(['RECEIPT' => 'قبض', 'PAYMENT' => 'دفع']),
            Tables\Filters\Filter::make('date')
                ->form([
                    Forms\Components\DatePicker::make('from')->label('من'),
                    Forms\Components\DatePicker::make('to')->label('إلى'),
                ])
                ->query(function ($query, array $data) {
                    return $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                        ->when($data['to'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d));
                }),
        ])
        ->actions([
            Tables\Actions\ViewAction::make(),
            Tables\Actions\EditAction::make(),
            Tables\Actions\DeleteAction::make(),
        ])
        ->bulkActions([
            Tables\Actions\DeleteBulkAction::make(),
        ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            ImageEntry::make('image_path')
                ->label('الصورة')
                ->disk('public')
                ->url(fn ($record) => $record->image_path
                    ? Storage::disk('public')->url($record->image_path)
                    : null
                )
                ->openUrlInNewTab()
                ->hiddenLabel(),

            TextEntry::make('amount')->label('المبلغ'),
            TextEntry::make('entry_type')->label('النوع')
                ->formatStateUsing(fn (string $state) => $state === 'RECEIPT' ? 'قبض' : 'دفع'),
            TextEntry::make('description')->label('الوصف'),
            TextEntry::make('created_at')->label('التاريخ')->dateTime('Y-m-d H:i'),
        ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListCashEntries::route('/'),
            'create' => Pages\CreateCashEntry::route('/create'),
            'view'   => Pages\ViewCashEntry::route('/{record}'),
            'edit'   => Pages\EditCashEntry::route('/{record}/edit'),
        ];
    }
}
