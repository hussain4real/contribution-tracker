<?php

namespace App\Notifications;

use App\Models\Contribution;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ContributionReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     *
     * @param  'reminder'|'follow_up'  $type
     */
    public function __construct(
        public Contribution $contribution,
        public string $type = 'reminder',
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $subject = $this->type === 'follow_up'
            ? "Follow-up: Your {$this->contribution->period_label} contribution is due today"
            : "Reminder: Your {$this->contribution->period_label} contribution is due soon";

        return (new MailMessage)
            ->subject($subject)
            ->markdown('mail.contribution-reminder', [
                'contribution' => $this->contribution,
                'type' => $this->type,
                'userName' => $notifiable->name,
                'familyName' => $this->contribution->family->name,
            ]);
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'contribution_id' => $this->contribution->id,
            'family_name' => $this->contribution->family->name,
            'period_label' => $this->contribution->period_label,
            'amount_owed' => $this->contribution->balance,
            'due_date' => $this->contribution->due_date->toDateString(),
            'type' => $this->type,
        ];
    }
}
