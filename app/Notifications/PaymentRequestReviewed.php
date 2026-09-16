<?php

namespace App\Notifications;

use App\Models\PaymentRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emailed to business owners when the super admin approves or declines
 * their uploaded payment receipt.
 */
class PaymentRequestReviewed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public PaymentRequest $request)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $plan = $this->request->plan?->name ?? 'your plan';
        $url = rtrim(config('app.frontend_url'), '/') . '/settings';

        if ($this->request->status === 'approved') {
            $subscription = $this->request->subscription;

            return (new MailMessage)
                ->subject("Your {$plan} plan is now active")
                ->greeting("Hi {$notifiable->name},")
                ->line("We received your payment and your {$plan} plan is now active" . ($subscription ? ' until ' . $subscription->ends_at->format('M d, Y') : '') . '.')
                ->action('Open FlowCRM', $url)
                ->line('Thank you for using FlowCRM.');
        }

        return (new MailMessage)
            ->subject('We could not approve your payment')
            ->greeting("Hi {$notifiable->name},")
            ->line("Your payment for the {$plan} plan was declined for this reason:")
            ->line('"' . $this->request->decline_reason . '"')
            ->line('Please check the details and upload a new receipt.')
            ->action('Go to billing', $url);
    }
}
