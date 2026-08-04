<?php

namespace App\Mail;

use App\Models\Convocatoria;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The convocatoria notice sent to ONE member (prompt 88). Carries scalar values (not the model) so the
 * /dev/mail preview and the permanent MailRenderTest render it without a database, and so a queued copy
 * survives a later edit to the source row. IssueConvocatoria sends one of these per member — a separate
 * message each, never a shared To/CC.
 *
 * @param  list<string>  $agenda
 */
class ConvocatoriaMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  list<string>  $agenda
     */
    public function __construct(
        public string $memberName,
        public string $title,
        public string $typeLabel,
        public string $heldAt,
        public ?string $secondCallAt = null,
        public ?string $venue = null,
        public array $agenda = [],
        public ?string $body = null,
        public int $noticeDays = 0,
        public ?int $quorumRequired = null,
    ) {}

    public static function fromConvocatoria(Convocatoria $convocatoria, string $memberName): self
    {
        /** @var list<string> $agenda */
        $agenda = array_values(array_filter(array_map(
            fn ($point): string => trim((string) $point),
            (array) $convocatoria->agenda,
        ), fn (string $p): bool => $p !== ''));

        return new self(
            memberName: $memberName,
            title: $convocatoria->title,
            typeLabel: $convocatoria->type->label(),
            heldAt: $convocatoria->held_at->format('d/m/Y H:i'),
            secondCallAt: $convocatoria->second_call_at?->format('d/m/Y H:i'),
            venue: $convocatoria->venue,
            agenda: $agenda,
            body: $convocatoria->body,
            noticeDays: (int) $convocatoria->notice_days,
            quorumRequired: $convocatoria->quorum_required,
        );
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Convocatoria de asamblea :type — :title', ['type' => $this->typeLabel, 'title' => $this->title]),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.convocatoria', text: 'mail.text.convocatoria');
    }
}
