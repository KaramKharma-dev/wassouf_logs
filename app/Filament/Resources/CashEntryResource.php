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

            Forms\Components\Select::make('type')
                ->label('النوع')
                ->options([
                    'in'  => 'قبض',
                    'out' => 'دفع',
                ])
                ->native(false)
                ->required(),

            Forms\Components\TextInput::make('reference')
                ->label('مرجع')
                ->maxLength(100),

            Forms\Components\Textarea::make('note')
                ->label('ملاحظة')
                ->rows(3),

            // إن كانت تحفظ كملف على disk=public
            Forms\Components\FileUpload::make('image_path')
                ->label('صورة إيصال')
                ->disk('public')             // storage/app/public
                ->directory('cash_entries')  // مجلد التخزين
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

            Tables\Columns\TextColumn::make('type')
                ->label('النوع')
                ->formatStateUsing(fn(string $state) => $state === 'in' ? 'قبض' : 'دفع')
                ->badge()
                ->colors([
                    'success' => fn($state) => $state === 'in',
                    'danger'  => fn($state) => $state === 'out',
                ]),

            Tables\Columns\TextColumn::make('reference')
                ->label('مرجع')
                ->limit(24)
                ->tooltip(fn($record) => $record->reference),

            Tables\Columns\TextColumn::make('created_at')
                ->label('التاريخ')
                ->dateTime('Y-m-d H:i')
                ->sortable(),
        ])
        ->filters([
            Tables\Filters\SelectFilter::make('type')
                ->label('النوع')
                ->options(['in' => 'قبض', 'out' => 'دفع']),
            Tables\Filters\Filter::make('date')
                ->form([
                    Forms\Components\DatePicker::make('from')->label('من'),
                    Forms\Components\DatePicker::make('to')->label('إلى'),
                ])
                ->query(function ($query, array $data) {
                    return $query
                        ->when($data['from'] ?? null, fn($q, $d) => $q->whereDate('created_at', '>=', $d))
                        ->when($data['to'] ?? null, fn($q, $d) => $q->whereDate('created_at', '<=', $d));
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
            TextEntry::make('type')->label('النوع')
                ->formatStateUsing(fn(string $s) => $s === 'in' ? 'قبض' : 'دفع'),
            TextEntry::make('reference')->label('مرجع'),
            TextEntry::make('note')->label('ملاحظة'),
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
