<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OperationalMailNotification extends Notification
{
    use Queueable;

    /** @param array{title:string,body:string,url:?string} $payload */
    public function __construct(private array $payload) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage())
            ->subject($this->payload['title'])
            ->greeting('Hola ' . trim((string) ($notifiable->name ?? '')))
            ->line($this->payload['body']);

        if (is_string($this->payload['url']) && $this->payload['url'] !== '') {
            $message->action('Abrir Planeaciones', url($this->payload['url']));
        }

        return $message->line('Este aviso corresponde a una acción de tu cuenta en Planeaciones.');
    }
}
