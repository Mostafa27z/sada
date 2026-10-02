<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReputationAlertNotification extends Notification
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
        $threshold = $this->data['threshold'] ?? 70;
        $currentScore = $this->data['current_score'] ?? 58;
        $negativeCount = $this->data['negative_count'] ?? 14;
        $recipientName = (is_object($notifiable) && !empty($notifiable->name)) ? $notifiable->name : 'فريق العمل';

        return (new MailMessage)
            ->subject("⚠️ [عاجل] تنبيه تراجع مؤشر السمعة العام لـ {$tenantName} دون {$threshold}%")
            ->view('emails.reputation_alert', [
                'tenantName' => $tenantName,
                'threshold' => $threshold,
                'currentScore' => $currentScore,
                'negativeCount' => $negativeCount,
                'recipientName' => $recipientName,
                'actionUrl' => config('app.frontend_url', 'http://localhost:3000') . '/dashboard/reputation',
            ]);
    }

    public function toArray(object $notifiable): array
    {
        return $this->data;
    }
}
