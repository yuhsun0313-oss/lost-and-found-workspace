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
    public string $recipientRole; // 'publisher' | 'claimant'
    public ?string $partnerEmail;

    public function __construct(
        Item $item,
        string $verifiedBy = 'VLM 智慧視覺驗證',
        string $recipientRole = 'publisher',
        ?string $partnerEmail = null
    ) {
        $this->item = $item;
        $this->verifiedBy = $verifiedBy;
        $this->recipientRole = $recipientRole;
        $this->partnerEmail = $partnerEmail;
    }

    public function envelope(): Envelope
    {
        $prefix = $this->recipientRole === 'publisher'
            ? ($this->item->type === 'lost' ? '遺失物品已被拾獲並通過驗證' : '拾獲物品已通過失主認領驗證')
            : '認領／回報人工審核通過通知';

        return new Envelope(
            subject: "【校園AI尋物通】{$prefix} - {$this->item->title}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.claim_success',
        );
    }
}
