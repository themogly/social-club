<?php

namespace App\Support;

use App\Models\Organisation;
use App\ViewModels\Rat;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The organisation's STATUTORY identity for documents (prompt 115) — the legal name, tax id (CIF/NIF),
 * registered address and logo a libro de socios, registro de dispensación, convocatoria, acta or report PDF
 * must carry so it evidences WHICH asociación produced it.
 *
 * Distinct from `config('app.name')`, which is the PRODUCT name (fine for UI chrome and transactional email,
 * wrong on a statutory document — a court does not care what the software is called). Promoted out of
 * {@see Rat} (which now delegates here) so every document resolves identity through one place.
 *
 * The name a document PRINTS is the LEGAL name; a statutory document should not be generated at all without
 * one ({@see self::hasLegalName()}), never silently falling back to the trading name as if it were the legal
 * identity.
 */
class OrganisationIdentity
{
    /**
     * The active organisation's identity block for a document view.
     *
     * @return array{name: string, legal_name: ?string, display_name: string, tax_id: ?string, address: ?string, contact_email: ?string, contact_phone: ?string, logo: ?string}
     */
    public static function current(): array
    {
        return self::for(self::activeOrganisation());
    }

    /**
     * @return array{name: string, legal_name: ?string, display_name: string, tax_id: ?string, address: ?string, contact_email: ?string, contact_phone: ?string, logo: ?string}
     */
    public static function for(?Organisation $organisation): array
    {
        $name = filled($organisation?->name) ? (string) $organisation->name : (string) config('app.name');
        $legalName = filled($organisation?->legal_name) ? (string) $organisation->legal_name : null;

        return [
            'name' => $name,
            'legal_name' => $legalName,
            // What a statutory document prints: the legal name, or the trading name only as a last resort.
            'display_name' => $legalName ?? $name,
            'tax_id' => filled($organisation?->tax_id) ? (string) $organisation->tax_id : null,
            'address' => filled($organisation?->address) ? (string) $organisation->address : null,
            'contact_email' => filled($organisation?->contact_email) ? (string) $organisation->contact_email : null,
            'contact_phone' => filled($organisation?->contact_phone) ? (string) $organisation->contact_phone : null,
            'logo' => self::logoDataUri($organisation),
        ];
    }

    /**
     * The club's TRADING name, for UI chrome — one indexed lookup, no logo read and no legal name.
     *
     * Separate from {@see self::current()} deliberately: that assembles a document identity block and reads
     * the logo off disk to build a data URI, which is far too much for a header that renders on every counter
     * screen, all shift. And separate from `config('app.name')`, which is the PRODUCT name — the same mistake
     * {@see self::mailLogo()} records for email: a bar reading "CSC platform" re-brands the club's own
     * terminal as ours. Falls back to the product name only when there is no organisation to ask.
     */
    public static function tradingName(): string
    {
        $id = app(ActiveScope::class)->organisationId();

        $name = $id !== null
            ? Organisation::query()->whereKey($id)->value('name')
            : null;

        return filled($name) ? (string) $name : (string) config('app.name');
    }

    /**
     * True when the active org (or the given one) carries a legal name. A statutory document must refuse to
     * generate without it — the whole point of the document is to name the responsible association.
     */
    public static function hasLegalName(?Organisation $organisation = null): bool
    {
        return filled(($organisation ?? self::activeOrganisation())?->legal_name);
    }

    /**
     * The active org's logo as RAW bytes + mime, for CID-embedding in email ($message->embedData) — email
     * clients strip `data:` URIs, so the PDF path's data URI is wrong for mail. Null when the club has not
     * uploaded a logo, in which case the mail shell shows the club NAME as a text wordmark instead — never the
     * product logo, which renders "CSC platform" and would re-brand the club's email as ours (prompt 150).
     *
     * @return array{data: string, mime: string}|null
     */
    public static function mailLogo(): ?array
    {
        $organisation = self::activeOrganisation();

        if ($organisation === null || blank($organisation->logo_path)) {
            return null;
        }

        try {
            $disk = Storage::disk('public');
            if (! $disk->exists($organisation->logo_path)) {
                return null;
            }

            return ['data' => $disk->get($organisation->logo_path), 'mime' => $disk->mimeType($organisation->logo_path) ?: 'image/png'];
        } catch (Throwable) {
            return null;
        }
    }

    private static function activeOrganisation(): ?Organisation
    {
        $id = app(ActiveScope::class)->organisationId();

        return $id !== null ? Organisation::query()->whereKey($id)->first() : null;
    }

    /**
     * The Reply-To for MEMBER-FACING mail (prompt 159): the club's contact email, in the club's name, so a
     * member replying to their receipt or convocatoria reaches the club rather than the unattended no-reply.
     * Empty when no contact email is set (the mail simply carries no Reply-To). Deliberately NOT applied to
     * operational mail such as the lockdown reactivation link, which must not invite a reply.
     *
     * @return list<Address>
     */
    public static function replyTo(): array
    {
        $organisation = self::activeOrganisation();
        $email = filled($organisation?->contact_email) ? (string) $organisation->contact_email : null;

        if ($email === null) {
            return [];
        }

        $name = filled($organisation->name) ? (string) $organisation->name : (string) config('app.name');

        return [new Address($email, $name)];
    }

    /**
     * The org logo as a base64 data URI so dompdf can embed it (it cannot fetch a remote or disk-relative
     * path). Degrades to null on any problem — a document must still generate without a logo, never throw.
     */
    private static function logoDataUri(?Organisation $organisation): ?string
    {
        if ($organisation === null || blank($organisation->logo_path)) {
            return null;
        }

        try {
            $disk = Storage::disk('public');
            if (! $disk->exists($organisation->logo_path)) {
                return null;
            }

            $mime = $disk->mimeType($organisation->logo_path) ?: 'image/png';

            return 'data:'.$mime.';base64,'.base64_encode($disk->get($organisation->logo_path));
        } catch (Throwable) {
            return null;
        }
    }
}
