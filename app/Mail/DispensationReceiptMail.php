<?php

namespace App\Mail;

use App\Models\Dispensation;
use App\Support\Money;
use App\Support\Weight;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Emails a member their dispensation receipt — worded as a shared-cost CONTRIBUTION (aportación),
 * never a sale (prompt 56). Carries scalar values (not the model) so the /dev/mail preview + render
 * test need no database, and so a queued send can't drift if the record later changes.
 */
class DispensationReceiptMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $memberName,
        public string $dispensedOn,
        public string $grams,
        public string $total,
    ) {}

    public static function fromDispensation(Dispensation $dispensation): self
    {
        $dispensation->loadMissing('lines', 'member');
        $gramsCg = (int) $dispensation->lines->sum(fn ($line): int => $line->grams_cg->centigrams);

        return new self(
            memberName: $dispensation->member?->fullName() ?? __('Socio'),
            dispensedOn: $dispensation->dispensed_at?->format('d/m/Y H:i') ?? '',
            grams: Weight::fromCentigrams($gramsCg)->formatted(),
            total: Money::fromCents($dispensation->total_cents->cents)->formatted(),
        );
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Tu comprobante de aportación'));
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.dispensation-receipt',
            text: 'mail.text.dispensation-receipt',
            with: [
                'memberName' => $this->memberName,
                'dispensedOn' => $this->dispensedOn,
                'grams' => $this->grams,
                'total' => $this->total,
            ],
        );
    }
}
