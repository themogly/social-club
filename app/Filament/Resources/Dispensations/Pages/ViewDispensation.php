<?php

namespace App\Filament\Resources\Dispensations\Pages;

use App\Filament\Resources\Dispensations\DispensationResource;
use Filament\Resources\Pages\ViewRecord;

class ViewDispensation extends ViewRecord
{
    protected static string $resource = DispensationResource::class;

    /**
     * No edit — a dispensation is immutable. The only header action is the REFUND (prompt 71), which calls
     * RefundDispensation; a full reversal remains the counter's void (a void + a fresh linked row).
     */
    protected function getHeaderActions(): array
    {
        return [
            DispensationResource::refundAction(),
        ];
    }
}
