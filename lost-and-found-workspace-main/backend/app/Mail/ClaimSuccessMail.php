<?php

namespace App\Mail;

use App\Models\Item;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ClaimSuccessMail extends Mailable
{
    use Queueable, SerializesModels;

    public Item $item;
    public string $verifiedBy;

    public function __construct(Item $item, string $verifiedBy = 'VLM 智慧視覺驗證')
    {
        $this->item = $item;
        $this->verifiedBy = $verifiedBy;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "【校園AI尋物通】認領成功通知 - {$this->item->title}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.claim_success',
        );
    }
}
