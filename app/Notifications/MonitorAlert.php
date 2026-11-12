<?php

namespace App\Notifications;

use App\Support\Mail\BrandMail;
use App\Support\Mail\Unsubscribes;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MonitorAlert extends Notification
{
    use Queueable;

    /**
     * @param  list<string>  $lines
     */
    public function __construct(
        public string $subject,
        public array $lines,
        public string $url,
        public bool $bad,
        public string $actionLabel = 'Open the monitor',
        public ?int $workspaceId = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->subject)->greeting($this->subject);

        if ($this->bad) {
            $mail->error();
        }
        foreach ($this->lines as $line) {
            $mail->line($line);
        }

        $mail->action($this->actionLabel, $this->url);
        $mail = BrandMail::apply($mail, [
            'image' => $this->bad ? 'down' : 'up',
            'heading' => $this->subject,
            'paragraphs' => array_values(array_filter($this->lines, fn ($line) => filled($line))),
            'button' => ['label' => $this->actionLabel, 'url' => $this->url],
        ]);

        $email = $notifiable->email ?? null;

        return $this->workspaceId !== null && is_string($email)
            ? Unsubscribes::attach($mail, Unsubscribes::url($email, 'alerts', $this->workspaceId), 'Stop alert emails from this workspace')
            : $mail;
    }
}
