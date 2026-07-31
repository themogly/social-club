<?php

namespace App\Filament\Resources\Members\Pages;

use App\Actions\Members\RecordMemberConsent;
use App\Actions\Members\SyncMemberScanDocuments;
use App\Enums\MemberKind;
use App\Enums\MemberStatus;
use App\Filament\Resources\Members\MemberResource;
use App\Models\Member;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\MemberNumber;
use App\Support\Settings;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class CreateMember extends CreateRecord
{
    protected static string $resource = MemberResource::class;

    /**
     * System-managed lifecycle fields are set here, never exposed on the form: a fresh
     * member gets a generated member_no, becomes ACTIVE, and gets a joined_at + carencia
     * end date (mirrors the application-approval path in ApproveApplication).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $organisationId = app(ActiveScope::class)->organisationId();
        if ($organisationId !== null) {
            $data['member_no'] ??= MemberNumber::next($organisationId);
        }
        $data['status'] ??= MemberStatus::ACTIVE->value;
        $data['joined_at'] ??= now();
        $data['carencia_ends_at'] ??= now()->addDays((int) Settings::get('carencia_days', 15));

        // Temporary members: same onboarding, plus an auto-expiry computed from the window.
        // Marking someone temporary NEVER shortens any compliance check — only list visibility
        // and retention timing (prompt 31). The toggle is a virtual field, mapped then dropped.
        if (! empty($data['is_temporary'])) {
            $data['kind'] = MemberKind::TEMPORARY->value;
            $data['temporary_expires_at'] = Carbon::parse($data['joined_at'])
                ->addDays((int) Settings::get('temporary_window_days', 30));
        }
        unset($data['is_temporary']);

        return $data;
    }

    /**
     * After the socio is persisted: capture the RGPD consent as a versioned
     * ConsentRecord (the form's consent checkbox is `->accepted()`, so a successful
     * submit means consent was given), and mirror any uploaded ID/medical scans into
     * MemberDocument rows so they are only ever served via signed, access-logged URLs.
     */
    protected function afterCreate(): void
    {
        /** @var Member $member */
        $member = $this->record;
        /** @var User|null $actor */
        $actor = Auth::user();

        (new RecordMemberConsent)->handle($member, 'membership', request()->ip());
        (new SyncMemberScanDocuments)->handle($member, $actor);
    }
}
