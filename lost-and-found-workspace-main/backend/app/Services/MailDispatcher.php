<?php

namespace App\Services;

use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class MailDispatcher
{
    /**
     * 統一寄信派送器：
     * 1. 若設定 BREVO_API_KEY（推薦 Render 免費層使用，免網域即可透過 HTTPS 寄給任意收件者）
     * 2. 若設定 GMAIL_WEBHOOK_URL（透過 Google Apps Script 呼叫本人 Gmail 寄給任意收件者）
     * 3. 否則使用 Laravel Mailer（smtp / resend / log）
     *
     * @return array{sent: bool, error: string|null, driver: string}
     */
    public static function send(string $toEmail, Mailable $mailable): array
    {
        $toEmail = trim($toEmail);
        if (empty($toEmail) || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return ['sent' => false, 'error' => '收件人 Email 格式無效', 'driver' => 'none'];
        }

        $subject = $mailable->envelope()->subject ?? '【校園AI尋物通】系統通知';
        $html = $mailable->render();
        $fromAddress = config('mail.from.address') ?: env('MAIL_FROM_ADDRESS', 'noreply@example.com');
        $fromName = config('mail.from.name') ?: '校園AI尋物通';
        if (str_contains((string) $fromName, '?') || trim((string) $fromName) === '') {
            $fromName = '校園AI尋物通';
        }

        // 1. Brevo HTTP API（HTTPS 443，免費每日 300 封，只需驗證單一寄件者 Gmail 即可寄給任何使用者）
        $brevoKey = trim((string) env('BREVO_API_KEY', ''));
        if ($brevoKey !== '') {
            $senderEmail = trim((string) env('BREVO_SENDER_EMAIL', $fromAddress));
            try {
                $resp = Http::withHeaders([
                    'api-key' => $brevoKey,
                    'accept' => 'application/json',
                    'content-type' => 'application/json',
                ])->timeout(15)->post('https://api.brevo.com/v3/smtp/email', [
                    'sender' => [
                        'name' => $fromName,
                        'email' => $senderEmail,
                    ],
                    'to' => [
                        ['email' => $toEmail],
                    ],
                    'subject' => $subject,
                    'htmlContent' => $html,
                ]);

                if ($resp->successful()) {
                    return ['sent' => true, 'error' => null, 'driver' => 'brevo'];
                }

                $err = 'Brevo API 錯誤 (' . $resp->status() . '): ' . $resp->body();
                Log::error($err, ['to' => $toEmail]);
                return ['sent' => false, 'error' => $err, 'driver' => 'brevo'];
            } catch (\Throwable $e) {
                Log::error('Brevo 寄信例外: ' . $e->getMessage(), ['to' => $toEmail]);
                return ['sent' => false, 'error' => $e->getMessage(), 'driver' => 'brevo'];
            }
        }

        // 2. Google Apps Script Gmail Webhook（HTTPS 443，直接從你的 Gmail 帳號寄給任意使用者）
        $webhookUrl = trim((string) env('GMAIL_WEBHOOK_URL', ''));
        if ($webhookUrl !== '') {
            try {
                $payload = [
                    'to' => $toEmail,
                    'subject' => $subject,
                    'html' => $html,
                    'fromName' => $fromName,
                ];
                $secret = trim((string) env('GMAIL_WEBHOOK_SECRET', ''));
                if ($secret !== '') {
                    $payload['secret'] = $secret;
                }

                $resp = Http::timeout(20)->post($webhookUrl, $payload);
                if ($resp->successful()) {
                    $json = $resp->json();
                    if (!is_array($json) || ($json['ok'] ?? true) === true) {
                        return ['sent' => true, 'error' => null, 'driver' => 'gmail_webhook'];
                    }
                    $err = 'Gmail Webhook 回應失敗: ' . ($json['error'] ?? $resp->body());
                    Log::error($err, ['to' => $toEmail]);
                    return ['sent' => false, 'error' => $err, 'driver' => 'gmail_webhook'];
                }

                $err = 'Gmail Webhook HTTP 錯誤 (' . $resp->status() . '): ' . $resp->body();
                Log::error($err, ['to' => $toEmail]);
                return ['sent' => false, 'error' => $err, 'driver' => 'gmail_webhook'];
            } catch (\Throwable $e) {
                Log::error('Gmail Webhook 寄信例外: ' . $e->getMessage(), ['to' => $toEmail]);
                return ['sent' => false, 'error' => $e->getMessage(), 'driver' => 'gmail_webhook'];
            }
        }

        // 3. Laravel 預設 Mailer (smtp / resend / log)
        $driver = (string) config('mail.default', 'log');
        try {
            Mail::to($toEmail)->send($mailable);
            return ['sent' => true, 'error' => null, 'driver' => $driver];
        } catch (\Throwable $e) {
            $rawMsg = $e->getMessage();
            // 針對 Resend 沙盒限制（onboarding@resend.dev 只能寄給本人信箱）給予明確診斷提示
            if (str_contains($rawMsg, 'only send testing emails to your own email')
                || str_contains($rawMsg, 'verify a domain')) {
                $friendly = 'Resend 測試寄件者 (onboarding@resend.dev) 僅允許寄信給 Resend 帳號本人信箱，無法寄給其他使用者。請改用 BREVO_API_KEY、GMAIL_WEBHOOK_URL、SMTP 或綁定自有網域的 Resend 寄件地址。';
                Log::error($friendly, ['to' => $toEmail, 'raw' => $rawMsg]);
                return ['sent' => false, 'error' => $friendly, 'driver' => $driver];
            }

            Log::error("Laravel Mail ({$driver}) 寄信失敗: {$rawMsg}", ['to' => $toEmail]);
            return ['sent' => false, 'error' => $rawMsg, 'driver' => $driver];
        }
    }
}
