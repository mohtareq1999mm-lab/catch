<?php

namespace Marvel\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Spatie\OneTimePasswords\Models\OneTimePassword;
use Spatie\OneTimePasswords\Notifications\OneTimePasswordNotification as SpatieNotification;

class OneTimePasswordNotification extends SpatieNotification implements ShouldQueue
{
    use Queueable;

    public function __construct(OneTimePassword $oneTimePassword)
    {
        parent::__construct($oneTimePassword);
        $this->onQueue('meem-high');
    }

    public function backoff(): array
    {
        return [300, 900, 1800, 3600];
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->endOfDay()->addMinutes(15);
    }

    public function toMail(object $notifiable)
    {
        return (new MailMessage)
            ->from(env('MAIL_FROM_ADDRESS', 'default@default.com'), config('app.name', 'ChawkBazar'))
            ->subject($this->subject() ?? __('message.your otp code'))
            ->markdown('emails.one-time-passwords', [
                'oneTimePassword' => $this->oneTimePassword,
                'appName' => config('app.name', 'ChawkBazar'),
                'locale' => app()->getLocale()
            ]);
    }

    public function subject(): string
    {
        return __('message.your otp code');
    }
}
