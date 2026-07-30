<?php

namespace App\Filament\Resources\MembershipTiers\Pages;

use App\Filament\Resources\MembershipTiers\MembershipTierResource;
use Filament\Resources\Pages\CreateRecord;

class CreateMembershipTier extends CreateRecord
{
    protected static string $resource = MembershipTierResource::class;

    /**
     * Money lives as integer cents in default_fee_cents; the form edits euros.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['default_fee_cents'] = (int) round(((float) ($data['default_fee_eur'] ?? 0)) * 100);
        unset($data['default_fee_eur']);

        return $data;
    }
}
