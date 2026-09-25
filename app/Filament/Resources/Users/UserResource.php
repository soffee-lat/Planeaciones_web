<?php

namespace App\Filament\Resources\Users;

use App\Actions\Commerce\EnsureInternalUnlimitedPlan;
use App\Actions\Commerce\GrantInternalUnlimitedMembership;
use App\Actions\Commerce\RevokeInternalUnlimitedMembership;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Models\User;
use App\Services\Commerce\CurrentCommercialRights;
use App\Services\Commerce\SubscriptionBalance;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $navigationLabel = 'Usuarios';

    protected static ?string $modelLabel = 'Usuario';

    protected static ?string $pluralModelLabel = 'Usuarios';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Nombre')
                ->required()
                ->maxLength(255),
            TextInput::make('email')
                ->label('Correo')
                ->email()
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true),
            Select::make('roles')
                ->label('Roles')
                ->relationship('roles', 'code')
                ->multiple()
                ->preload()
                ->searchable()
                ->disabled(fn (?User $record): bool => $record?->id === auth()->id())
                ->helperText('Por seguridad no puedes quitarte tus propios roles desde esta pantalla.'),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Cuenta')->schema([
                TextEntry::make('name')->label('Nombre'),
                TextEntry::make('email')->label('Correo'),
                TextEntry::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'active' ? 'Activo' : 'Suspendido'),
                TextEntry::make('roles_admin')
                    ->label('Roles')
                    ->state(fn (User $record): string => $record->roles()->orderBy('code')->pluck('code')->implode(', ') ?: 'Sin roles'),
                TextEntry::make('email_verified_at')
                    ->label('Correo verificado')
                    ->state(fn (User $record): string => $record->email_verified_at ? 'Sí · '.$record->email_verified_at->format('d/m/Y H:i') : 'No'),
                TextEntry::make('onboarding_completed_at')
                    ->label('Onboarding')
                    ->state(fn (User $record): string => $record->onboarding_completed_at ? 'Completado · '.$record->onboarding_completed_at->format('d/m/Y H:i') : 'Pendiente'),
                TextEntry::make('created_at')->label('Registrado')->dateTime('d/m/Y H:i'),
            ])->columns(2),

            Section::make('Uso y membresía')->schema([
                TextEntry::make('membership_admin')
                    ->label('Membresía')
                    ->state(fn (User $record): string => static::membershipLabel($record)),
                TextEntry::make('period_admin')
                    ->label('Periodo vigente')
                    ->state(function (User $record): string {
                        $period = static::currentPeriod($record);
                        return $period
                            ? $period->starts_at->format('d/m/Y').' → '.$period->ends_at->format('d/m/Y')
                            : 'Sin periodo vigente';
                    }),
                TextEntry::make('planning_balance_admin')
                    ->label('Unidades de planeación')
                    ->state(function (User $record): string {
                        $period = static::currentPeriod($record);
                        if (! $period) {
                            return 'Sin saldo activo';
                        }
                        if ($period->planVersion?->plan?->code === EnsureInternalUnlimitedPlan::PLAN_CODE) {
                            return 'Ilimitadas para pruebas internas';
                        }
                        $balance = app(SubscriptionBalance::class)->forPeriod($period)['planning'];
                        return "{$balance->available()} disponibles de {$balance->limit}";
                    }),
                TextEntry::make('groups_admin')
                    ->label('Grupos')
                    ->state(fn (User $record): string => (string) $record->groups()->whereNull('archived_at')->count()),
                TextEntry::make('plannings_admin')
                    ->label('Planeaciones')
                    ->state(fn (User $record): string => (string) $record->planningRequests()->count()),
                TextEntry::make('subscriptions_admin')
                    ->label('Suscripciones históricas')
                    ->state(fn (User $record): string => (string) $record->subscriptions()->count()),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('name')->label('Nombre')->searchable()->sortable(),
                TextColumn::make('email')->label('Correo')->searchable()->sortable(),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'active' ? 'success' : 'danger')
                    ->formatStateUsing(fn (string $state): string => $state === 'active' ? 'Activo' : 'Suspendido'),
                TextColumn::make('roles.code')->label('Roles')->badge(),
                TextColumn::make('membership_table')
                    ->label('Membresía')
                    ->state(fn (User $record): string => static::membershipLabel($record))
                    ->badge(),
                TextColumn::make('groups_count')->counts('groups')->label('Grupos'),
                TextColumn::make('planning_requests_count')->counts('planningRequests')->label('Planeaciones'),
                TextColumn::make('created_at')->label('Registro')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('grantUnlimited')
                    ->label('Dar ilimitada')
                    ->icon('heroicon-o-infinity')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Otorgar membresía interna ilimitada')
                    ->modalDescription('Se creará una suscripción interna de prueba con vigencia amplia y sin costo. Si el usuario ya tiene otra suscripción operativa, no se reemplazará automáticamente.')
                    ->visible(fn (User $record): bool => ! static::hasInternalUnlimited($record))
                    ->action(function (User $record): void {
                        try {
                            app(GrantInternalUnlimitedMembership::class)->execute(auth()->user(), $record);
                            Notification::make()->success()
                                ->title('Membresía ilimitada habilitada')
                                ->body('La cuenta ya puede reservar planeaciones para pruebas internas.')
                                ->send();
                        } catch (\RuntimeException $error) {
                            $body = $error->getMessage() === 'INTERNAL_UNLIMITED_CONFLICTING_SUBSCRIPTION'
                                ? 'El usuario ya tiene otra suscripción activa. Revísala antes de sustituirla.'
                                : 'No se pudo otorgar la membresía interna.';
                            Notification::make()->warning()->title('No se pudo habilitar')->body($body)->send();
                        }
                    }),
                Action::make('revokeUnlimited')
                    ->label('Retirar ilimitada')
                    ->icon('heroicon-o-no-symbol')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (User $record): bool => static::hasInternalUnlimited($record))
                    ->action(function (User $record): void {
                        app(RevokeInternalUnlimitedMembership::class)->execute(auth()->user(), $record);
                        Notification::make()->success()->title('Membresía interna retirada')->send();
                    }),
                Action::make('suspend')
                    ->label('Suspender')
                    ->icon('heroicon-o-lock-closed')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (User $record): bool => $record->status === 'active' && $record->id !== auth()->id())
                    ->action(function (User $record): void {
                        $record->forceFill(['status' => 'suspended'])->save();
                        Notification::make()->success()->title('Usuario suspendido')->send();
                    }),
                Action::make('activate')
                    ->label('Activar')
                    ->icon('heroicon-o-lock-open')
                    ->color('success')
                    ->visible(fn (User $record): bool => $record->status === 'suspended')
                    ->action(function (User $record): void {
                        $record->forceFill(['status' => 'active'])->save();
                        Notification::make()->success()->title('Usuario activado')->send();
                    }),
            ]);
    }

    public static function currentPeriod(User $record)
    {
        return app(CurrentCommercialRights::class)->forCustomer($record);
    }

    public static function hasInternalUnlimited(User $record): bool
    {
        return static::currentPeriod($record)?->planVersion?->plan?->code === EnsureInternalUnlimitedPlan::PLAN_CODE;
    }

    public static function membershipLabel(User $record): string
    {
        $period = static::currentPeriod($record);
        if (! $period) {
            return 'Sin plan activo';
        }

        return $period->planVersion?->plan?->code === EnsureInternalUnlimitedPlan::PLAN_CODE
            ? 'Ilimitada interna'
            : (string) ($period->planVersion?->plan?->name ?? 'Plan activo');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'view' => ViewUser::route('/{record}'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
