<?php

namespace App\Notifications;

use App\Support\Mail\BrandMail;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EmailChanged extends Notification
{
    use Queueable;

    public function __construct(public string $name, public string $newEmail) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $line = "The email on your ServerOS account was changed to {$this->newEmail}. ServerOS emails, sign-in and password resets go there now.";
        $support = (string) config('mail.support.address');
        $note = "If you didn’t do this, email {$support} straight away and we’ll help you get your account back.";

        return BrandMail::apply((new MailMessage)
            ->subject('Your ServerOS email was changed')
            ->greeting("Hi {$this->name},")
            ->line($line)
            ->line($note), [
                'image' => 'verify',
                'heading' => 'Your email was changed',
                'paragraphs' => [$line],
                'note' => $note,
            ]);
    }
}
