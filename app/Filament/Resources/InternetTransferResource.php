<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InternetTransferResource\Pages;
use App\Models\InternetTransfer;
use App\Models\Balance;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Infolists\Infolist;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;

class InternetTransferResource extends Resource
{
    protected static ?string $model = InternetTransfer::class;

    protected static ?string $navigationIcon  = 'heroicon-o-arrows-right-left';
    protected static ?string $navigationLabel = 'تحويلات الإنترنت';
    protected static ?string $pluralLabel     = 'تحويلات الإنترنت';
    protected static ?string $label           = 'تحويل إنترنت';
    protected static ?string $navigationGroup = 'المالية';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('receiver_number')
                ->label('رقم المستلم')
                ->required()
                ->maxLength(20),

            Forms\Components\TextInput::make('quantity_gb')
                ->label('الكمية (GB)')
                ->numeric()
                ->minValue(0.001)
                ->required()
                ->suffix('GB'),

            Forms\Components\Select::make('provider')
                ->label('المزوّد')
                ->options(['alfa'=>'Alfa','mtc'=>'MTC'])
                ->required()
                ->native(false),

            Forms\Components\Select::make('type')
                ->label('النوع')
                ->options(['weekly'=>'Weekly','monthly'=>'Monthly','monthly_internet'=>'Monthly Internet'])
                ->required()
                ->native(false),

            Forms\Components\TextInput::make('idempotency_key')
                ->label('Idempotency Key')
                ->default(fn () => (string) \Illuminate\Support\Str::uuid())
                ->disabled()
                ->dehydrated(true)
                ->maxLength(64),

        ])->columns(2);
    }


    public static function table(Table $table): Table
    {
        $map = fn ($state) => match (strtolower((string) $state)) {
            'pending','pennding' => 'pending',
            'completed'          => 'completed',
            'failed'             => 'failed',
            default              => null,
        };

        $statusColor = fn ($state) => match ($map($state)) {
            'pending'   => 'warning',
            'completed' => 'success',
            'failed'    => 'danger',
            default     => null,
        };

        $fmtQty = fn ($state) => (function ($v) {
            if ($v === null) return null;
            $v = (float) $v;
            $frac = $v - floor($v);
            if (abs($frac - 0.5) < 1e-6) return number_format($v, 1, '.', ',');
            return number_format($v, 0, '.', ',');
        })($state);

        return $table
            ->columns([
                Tables\Columns\TextColumn::make('receiver_number')
                    ->label('المستلم')
                    ->searchable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('quantity_gb')
                    ->label('الكمية (GB)')
                    ->formatStateUsing($fmtQty)
                    ->badge()
                    ->color('info') // أزرق دائمًا
                    ->sortable(),

                Tables\Columns\TextColumn::make('price')
                    ->label('السعر')
                    ->formatStateUsing(fn ($state) => '$' . number_format((float) $state, 2, '.', ','))
                    ->badge()
                    ->color(fn ($record) => $statusColor($record->status))
                    ->sortable(),

                Tables\Columns\TextColumn::make('type')
                    ->label('النوع')
                    ->searchable()
                    ->limit(20),

                Tables\Columns\TextColumn::make('status')
                    ->label('الحالة')
                    ->formatStateUsing(fn ($state) => ucfirst($map($state)))
                    ->badge()
                    ->color(fn ($state) => $statusColor($state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('التاريخ')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('الحالة')
                    ->options(['pending'=>'Pending','completed'=>'Completed','failed'=>'Failed']),
                Tables\Filters\SelectFilter::make('provider')
                    ->label('المزوّد')
                    ->options(['alfa'=>'Alfa','mtc'=>'MTC']),
                Tables\Filters\Filter::make('date')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('من'),
                        Forms\Components\DatePicker::make('to')->label('إلى'),
                    ])
                    ->query(fn ($query, array $data) =>
                        $query->when($data['from'] ?? null, fn ($q,$d)=>$q->whereDate('created_at','>=',$d))
                              ->when($data['to'] ?? null, fn ($q,$d)=>$q->whereDate('created_at','<=',$d))
                    ),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),

                Tables\Actions\Action::make('confirm')
                    ->label('تأكيد')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn ($record) => in_array(strtoupper((string)$record->status), ['PENDING','PENNDING']))
                    ->requiresConfirmation()
                    ->action(function (InternetTransfer $record) {
                        DB::transaction(function () use ($record) {
                            // نفس الـ pricing والـ qtyKey من الكنترولر
                            $pricing = self::pricingMatrix();
                            $type = (string) $record->type;
                            $prov = (string) $record->provider;
                            $qtyKey = rtrim(rtrim(sprintf('%.3f', (float)$record->quantity_gb), '0'), '.');

                            if (!isset($pricing[$type][$prov][$qtyKey])) {
                                Notification::make()
                                    ->title('pricing_not_found')
                                    ->danger()
                                    ->body('القيمة المسموحة: '.implode(', ', array_keys($pricing[$type][$prov] ?? [])))
                                    ->send();
                                return;
                            }

                            $deduct = (float) $pricing[$type][$prov][$qtyKey]['deduct'];
                            $add    = (float) $pricing[$type][$prov][$qtyKey]['add'];

                            Balance::adjust($prov, -$deduct);
                            Balance::adjust('my_balance', $add);

                            $record->price        = $add;
                            $record->status       = 'COMPLETED';
                            $record->confirmed_at = now();
                            $record->save();
                        });

                        Notification::make()->title('تم التأكيد وتحديث الأرصدة')->success()->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        $norm = fn ($state) => match (strtolower((string) $state)) {
            'pending','pennding' => 'Pending',
            'completed'          => 'Completed',
            'failed'             => 'Failed',
            default              => (string) $state,
        };

        $color = fn ($state) => match (strtolower((string) $state)) {
            'pending','pennding' => 'warning',
            'completed'          => 'success',
            'failed'             => 'danger',
            default              => null,
        };

        $fmtQty = fn ($state) => (function ($v) {
            if ($v === null) return null;
            $v = (float) $v;
            $frac = $v - floor($v);
            if (abs($frac - 0.5) < 1e-6) return number_format($v, 1, '.', ',');
            return number_format($v, 0, '.', ',');
        })($state);

        return $infolist->schema([
            TextEntry::make('sender_number')->label('رقم المرسل'),
            TextEntry::make('receiver_number')->label('رقم المستلم'),
            TextEntry::make('provider')->label('المزوّد'),
            TextEntry::make('type')->label('النوع'),
            TextEntry::make('quantity_gb')->label('الكمية (GB)')->formatStateUsing($fmtQty),
            TextEntry::make('price')->label('السعر')->formatStateUsing(fn ($state) => '$' . number_format((float) $state, 2, '.', ',')),
            TextEntry::make('status')->label('الحالة')->formatStateUsing(fn ($state) => $norm($state))->badge()->color(fn ($state) => $color($state)),
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
            'index'  => Pages\ListInternetTransfers::route('/'),
            'create' => Pages\CreateInternetTransfer::route('/create'),
            'view'   => Pages\ViewInternetTransfer::route('/{record}'),
            'edit'   => Pages\EditInternetTransfer::route('/{record}/edit'),
        ];
    }

    /** نفس التسعير الموجود في InternetTransferController::pricingMatrix() */
    private static function pricingMatrix(): array
    {
        return [
            'monthly' => [
                'alfa' => [
                    '1'=>['deduct'=>3.5,'add'=>4],'7'=>['deduct'=>9,'add'=>10],
                    '22'=>['deduct'=>14.5,'add'=>16],'44'=>['deduct'=>21,'add'=>24],
                    '77'=>['deduct'=>31,'add'=>35],'111'=>['deduct'=>40,'add'=>45],
                    '444'=>['deduct'=>129,'add'=>145],
                ],
                'mtc' => [
                    '1'=>['deduct'=>3.5,'add'=>4],'7'=>['deduct'=>9,'add'=>10],
                    '22'=>['deduct'=>14.5,'add'=>16],'44'=>['deduct'=>21,'add'=>24],
                    '77'=>['deduct'=>31,'add'=>35],'111'=>['deduct'=>40,'add'=>45],
                    '444'=>['deduct'=>129,'add'=>145],
                ],
            ],
            'monthly_internet' => [
                'alfa' => [
                    '1'=>['deduct'=>3.5,'add'=>4],'7'=>['deduct'=>9,'add'=>10],
                    '22'=>['deduct'=>14.5,'add'=>16],'44'=>['deduct'=>21,'add'=>24],
                    '77'=>['deduct'=>31,'add'=>35],'111'=>['deduct'=>40,'add'=>45],
                    '444'=>['deduct'=>129,'add'=>145],
                ],
                'mtc' => [
                    '1'=>['deduct'=>3.5,'add'=>4],'7'=>['deduct'=>9,'add'=>10],
                    '22'=>['deduct'=>14.5,'add'=>16],'44'=>['deduct'=>21,'add'=>24],
                    '77'=>['deduct'=>31,'add'=>35],'111'=>['deduct'=>40,'add'=>45],
                    '444'=>['deduct'=>129,'add'=>145],
                ],
            ],
            'weekly' => [
                'alfa' => [
                    '0.5'=>['deduct'=>1.67,'add'=>1.91],
                    '1.5'=>['deduct'=>2.34,'add'=>2.64],
                    '5'  =>['deduct'=>5,'add'=>5.617],
                ],
                'mtc' => [
                    '0.5'=>['deduct'=>1.67,'add'=>1.91],
                    '1.5'=>['deduct'=>2.34,'add'=>2.64],
                    '5'  =>['deduct'=>5,'add'=>5.617],
                ],
            ],
        ];
    }
}
