<?php

namespace App\Notifications;

use App\Models\TopupRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emailed to business owners when the super admin approves or rejects
 * their uploaded wallet top-up receipt.
 */
class TopupRequestReviewed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public TopupRequest $request)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $amount = number_format((float) $this->request->amount, 2);
        $currency = $this->request->currency;
        $url = rtrim(config('app.frontend_url'), '/') . '/billing';

        if ($this->request->status === 'approved') {
            return (new MailMessage)
                ->subject("Wallet top-up approved ({$currency} {$amount})")
                ->greeting("Hi {$notifiable->name},")
                ->line("Your transfer of {$currency} {$amount} has been verified and credited to your workspace wallet.")
                ->line("You can use this balance for usage-based messaging.")
                ->action('View wallet', $url)
                ->line('Thank you for using Crediping.');
        }

        return (new MailMessage)
            ->subject("We could not approve your wallet top-up ({$currency} {$amount})")
            ->greeting("Hi {$notifiable->name},")
            ->line("Your top-up of {$currency} {$amount} was rejected for this reason:")
            ->line('"' . $this->request->rejection_reason . '"')
            ->line('Please review your receipt and resubmit if necessary.')
            ->action('Go to billing', $url);
    }
}
