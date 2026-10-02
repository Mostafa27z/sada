<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MorningDigestNotification extends Notification
{
    use Queueable;

    public function __construct(
        public array $data = []
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $tenantName = $this->data['tenant_name'] ?? 'منظمتكم الموقرة';
        $date = $this->data['date'] ?? now()->format('Y-m-d');
        $totalArticles = $this->data['articles_count'] ?? 42;
        $totalInteractions = $this->data['interactions_count'] ?? 1250;
        $positivePct = $this->data['positive_pct'] ?? 74;
        $neutralPct = $this->data['neutral_pct'] ?? 18;
        $negativePct = $this->data['negative_pct'] ?? 8;
        $recipientName = (is_object($notifiable) && !empty($notifiable->name)) ? $notifiable->name : 'فريق العمل';

        return (new MailMessage)
            ->subject("☀️ التقرير والملخص الصباحي اليومي لـ {$tenantName} | {$date}")
            ->view('emails.morning_digest', [
                'tenantName' => $tenantName,
                'date' => $date,
                'articlesCount' => $totalArticles,
                'interactionsCount' => $totalInteractions,
                'positivePct' => $positivePct,
                'neutralPct' => $neutralPct,
                'negativePct' => $negativePct,
                'recipientName' => $recipientName,
                'actionUrl' => config('app.frontend_url', 'http://localhost:3000') . '/dashboard',
            ]);
    }

    public function toArray(object $notifiable): array
    {
        return $this->data;
    }
}
