<?php

namespace App\Filament\Resources\Members\Pages;

use App\Actions\Members\FindDuplicateMembers;
use App\Actions\Members\RecordMemberConsent;
use App\Actions\Members\SendMemberCard;
use App\Actions\Members\SyncMemberScanDocuments;
use App\Enums\MemberKind;
use App\Filament\Resources\Members\MemberResource;
use App\Models\Member;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\MemberEnrolment;
use App\Support\Settings;
use Filament\Notifications\Notification;
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
    /**
     * Search-before-create: block the save when the entered details match an existing member (name+DOB,
     * email, phone or document) unless the operator has explicitly acknowledged it — enrolling a person
     * twice would split their balance and consumption history. The `acknowledge_duplicate` toggle
     * (create-only, virtual) is the deliberate override for a genuine same-name other person.
     */
    protected function beforeCreate(): void
    {
        if ($this->data['acknowledge_duplicate'] ?? false) {
            return;
        }

        $duplicates = (new FindDuplicateMembers)->handle([
            'first_name' => $this->data['first_name'] ?? null,
            'last_name' => $this->data['last_name'] ?? null,
            'date_of_birth' => $this->data['date_of_birth'] ?? null,
            'email' => $this->data['email'] ?? null,
            'phone' => $this->data['phone'] ?? null,
            'document_number' => $this->data['document_number'] ?? null,
        ]);

        if ($duplicates->isEmpty()) {
            return;
        }

        Notification::make()
            ->title(__('Posible socio duplicado'))
            ->body(__('Ya existe un socio que coincide: :names. Marca «crear de todas formas» si es una persona distinta.', [
                'names' => $duplicates->map(fn (Member $m): string => $m->fullName().' ('.$m->member_no.')')->implode(', '),
            ]))
            ->warning()
            ->persistent()
            ->send();

        $this->halt();
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $organisationId = app(ActiveScope::class)->organisationId();
        if ($organisationId !== null) {
            // The SAME enrolment defaults ApproveApplication fills, from one shared source, so the
            // carencia rule / active status / member-number generation can never drift between the
            // two ways a member is enrolled (prompt 37). `+=` keeps anything already in $data (none
            // of these are form fields), matching the previous `??=`. When org is null the row can't
            // persist anyway (organisation_id is NOT NULL), so guarding all four here is equivalent.
            $data += MemberEnrolment::defaults($organisationId);
        }

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

        // Send the QR card automatically (prompt 85) — queued; a member with no email is skipped cleanly.
        (new SendMemberCard)->handle($member);
    }
}
