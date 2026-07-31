<?php

namespace App\Exceptions;

use App\Models\Member;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Thrown by a member-creation path when the prospective details match an existing member
 * (name+DOB, email, phone or document). Extends RuntimeException so existing approve-flow
 * catches still handle it. Enrolling one person twice splits their balance + consumption
 * history across two records — the exact thing FindDuplicateMembers guards against.
 */
class DuplicateMemberException extends RuntimeException
{
    /** @param  Collection<int, Member>  $matches */
    public static function forMatches(Collection $matches): self
    {
        $names = $matches->map(fn (Member $m): string => $m->fullName().' ('.$m->member_no.')')->implode(', ');

        return new self(__('Ya existe un socio que coincide: :names. Verifica antes de crear un duplicado.', ['names' => $names]));
    }
}
