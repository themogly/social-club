<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Concerns\ReturnsToList;
use App\Filament\Resources\Orders\OrderResource;
use Filament\Resources\Pages\ViewRecord;

class ViewOrder extends ViewRecord
{
    use ReturnsToList;

    protected static string $resource = OrderResource::class;

    /** No edit action — an order is immutable; a correction is a void plus a fresh row. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
