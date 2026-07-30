<?php

namespace App\Actions\Members;

use App\Actions\RecordAuditLog;
use App\Models\Member;

/**
 * Change a member's declared monthly forecast (centigrams). Audited and versioned;
 * the signed declaration document is (re)generated in prompt 16 from this value.
 */
class UpdateDeclaredForecast
{
    public function handle(Member $member, int $declaredMonthlyCg): Member
    {
        $before = ['declared_monthly_cg' => $member->declared_monthly_cg];

        $member->update(['declared_monthly_cg' => $declaredMonthlyCg]);

        (new RecordAuditLog)->handle('member.forecast.updated', $member, $before, [
            'declared_monthly_cg' => $declaredMonthlyCg,
        ]);

        return $member;
    }
}
