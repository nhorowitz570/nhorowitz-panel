<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WorkflowMessage extends Notification
{
    use Queueable;

    public function __construct(
        public string $subject,
        public string $message,
        public string $from,
        public ?string $replyTo = null,
        public bool $bad = true,
        public ?string $poweredBy = null,
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
        if ($this->replyTo !== null) {
            $mail->replyTo($this->replyTo, $this->from);
        }
        foreach (preg_split('/\R{2,}/', trim($this->message)) ?: [] as $paragraph) {
            if ($paragraph !== '') {
                $mail->line($paragraph);
            }
        }

        $mail->salutation($this->from);
        $mail->line($this->poweredBy !== null
            ? "Sent by the workspace “{$this->from}” with a [ServerOS]({$this->poweredBy}) workflow. ServerOS didn’t write this message."
            : "Sent by the workspace “{$this->from}” with a workflow.");

        return $mail;
    }
}
