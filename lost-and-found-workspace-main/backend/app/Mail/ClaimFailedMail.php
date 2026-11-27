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

    public function __construct(Item $item, string $reason = '此筆認領／尋獲申請已被管理員人工審核駁回，請重新上傳清晰的物品圖片並進行驗證。')
    {
        $this->item = $item;
        $this->reason = $reason;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "【校園AI尋物通】人工審核駁回／請重新上傳圖片驗證通知 - {$this->item->title}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.claim_failed',
        );
    }
}
