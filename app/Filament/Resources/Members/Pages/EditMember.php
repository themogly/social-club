<?php

namespace App\Filament\Resources\Members\Pages;

use App\Actions\Members\SyncMemberScanDocuments;
use App\Filament\Concerns\AuditsResourceChanges;
use App\Filament\Resources\Members\MemberResource;
use App\Models\Member;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditMember extends EditRecord
{
    use AuditsResourceChanges;

    protected static string $resource = MemberResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            ...MemberResource::recordActions(),
            DeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    // A plain member edit (name/email/phone/document) is audited (prompt 48); status transitions have
    // their own audited action. Capture the diff before the save syncs the original away.
    protected function beforeSave(): void
    {
        $this->captureAuditDiff();
    }

    /**
     * Keep the sensitive-scan MemberDocument pointers in sync with any freshly
     * uploaded ID / medical certificate, so they are only ever served through the
     * signed, access-logged URL machinery (never a plain disk URL). Consent is a
     * create-time capture and is deliberately NOT re-written on edit.
     */
    protected function afterSave(): void
    {
        /** @var Member $member */
        $member = $this->record;
        /** @var User|null $actor */
        $actor = Auth::user();

        (new SyncMemberScanDocuments)->handle($member, $actor);

        $this->writeAuditLog('member.updated');
    }
}
