<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VerificationCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $code;
    public string $actionLabel;
    public string $userName;

    public function __construct(string $code, string $actionLabel, string $userName = '')
    {
        $this->code = $code;
        $this->actionLabel = $actionLabel;
        $this->userName = $userName;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "【校園AI尋物通】{$this->actionLabel}驗證碼：{$this->code}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.verification_code',
        );
    }
}
