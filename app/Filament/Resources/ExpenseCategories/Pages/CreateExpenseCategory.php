<?php

namespace App\Filament\Resources\ExpenseCategories\Pages;

use App\Filament\Concerns\ReturnsToList;
use App\Filament\Resources\ExpenseCategories\ExpenseCategoryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateExpenseCategory extends CreateRecord
{
    use ReturnsToList;

    protected static string $resource = ExpenseCategoryResource::class;
}
