<?php

namespace App\Actions\Members;

use App\Models\Member;
use Illuminate\Support\Collection;

/**
 * Search-before-create guard. Given prospective member details, surface existing
 * org members that match on name+DOB, email, phone or document number — so a
 * person is enrolled once, never split across two records (which would split
 * their balance and consumption history).
 */
class FindDuplicateMembers
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return Collection<int, Member>
     */
    public function handle(array $attributes, ?string $ignoreId = null): Collection
    {
        $hasNameDob = filled($attributes['first_name'] ?? null)
            && filled($attributes['last_name'] ?? null)
            && filled($attributes['date_of_birth'] ?? null);

        if (! $hasNameDob && blank($attributes['email'] ?? null) && blank($attributes['phone'] ?? null) && blank($attributes['document_number'] ?? null)) {
            return collect();
        }

        return Member::query()
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->where(function ($query) use ($attributes, $hasNameDob): void {
                if ($hasNameDob) {
                    $query->orWhere(fn ($q) => $q
                        ->where('first_name', $attributes['first_name'])
                        ->where('last_name', $attributes['last_name'])
                        ->whereDate('date_of_birth', $attributes['date_of_birth']));
                }
                if (filled($attributes['email'] ?? null)) {
                    $query->orWhere('email', $attributes['email']);
                }
                if (filled($attributes['phone'] ?? null)) {
                    $query->orWhere('phone', $attributes['phone']);
                }
                if (filled($attributes['document_number'] ?? null)) {
                    $query->orWhere('document_hash', Member::hashDocument($attributes['document_number']));
                }
            })
            ->get();
    }
}
