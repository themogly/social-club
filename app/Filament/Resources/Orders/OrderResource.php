<?php

namespace App\Filament\Resources\Orders;

use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Orders\Schemas\OrderInfolist;
use App\Filament\Resources\Orders\Tables\OrdersTable;
use App\Models\Order;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Oversight of bar / merch orders (ventas de barra). Read-only in the panel: tickets are
 * rung up at the counter (App\Livewire\Counter\BarPos), so there is no create/edit here —
 * only the list and a View page showing the frozen item snapshot and tender split.
 * Authorisation flows through OrderPolicy. Sits in the "Caja" group beside the till
 * sessions, since bar takings share the one drawer.
 */
class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static ?int $navigationSort = 20;

    public static function getNavigationLabel(): string
    {
        return __('Barra y tienda');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Caja');
    }

    public static function getModelLabel(): string
    {
        return __('venta');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ventas');
    }

    /** Tickets are rung up at the counter — never created from the panel. */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return OrderInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OrdersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'view' => ViewOrder::route('/{record}'),
        ];
    }
}
