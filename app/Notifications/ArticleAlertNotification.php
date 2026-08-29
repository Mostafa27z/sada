<?php

namespace App\Notifications;

use App\Models\Alert;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ArticleAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected Alert $alert)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('تنبيه رصد جديد: ' . $this->alert->title)
            ->greeting('مرحباً ' . $notifiable->name)
            ->line($this->alert->message)
            ->action('عرض التنبيه في المنصة', config('sada.frontend_url') . '/alerts/' . $this->alert->id)
            ->line('شكراً لاستخدامك منصة صدى للرصد الإعلامي.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'alert_id' => $this->alert->id,
            'title' => $this->alert->title,
            'message' => $this->alert->message,
            'type' => $this->alert->type,
            'article_id' => $this->alert->article_id,
        ];
    }
}
