<?php

namespace App\Mail;

use App\Models\Item;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ClaimFailedMail extends Mailable
{
    use Queueable, SerializesModels;

    public Item $item;
    public string $reason;

    public function __construct(Item $item, string $reason = '人工審核未通過或已超過 3 天審核期限')
    {
        $this->item = $item;
        $this->reason = $reason;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "【校園AI尋物通】認領失敗／需重新驗證通知 - {$this->item->title}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.claim_failed',
        );
    }
}
