<?php

namespace App\Mail;

use App\Models\Contract;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContractExpiringMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Contract $contract, public int $daysLeft)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('contracts.expiry_mail_subject', [
                'number' => $this->contract->contract_number,
                'days'   => $this->daysLeft,
            ])
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.contract-expiring');
    }
}
