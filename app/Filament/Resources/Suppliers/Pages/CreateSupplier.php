<?php

namespace App\Filament\Resources\Suppliers\Pages;

use App\Filament\Concerns\ReturnsToList;
use App\Filament\Resources\Suppliers\SupplierResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSupplier extends CreateRecord
{
    use ReturnsToList;

    protected static string $resource = SupplierResource::class;
}
