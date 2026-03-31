<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword as BaseResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class ResetPasswordNotification extends BaseResetPassword
{
    /**
     * Build the reset URL using our named route and work_email.
     * This overrides Laravel's default which uses the 'email' query param
     * and may produce a null URL if the email field doesn't match.
     */
    protected function resetUrl($notifiable): string
    {
        return url(route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->work_email,
        ], false));
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Reset Your Employee Portal Password')
            ->greeting('Hello ' . ($notifiable->name ?? 'there') . ',')
            ->line('You are receiving this email because we received a password reset request for your account.')
            ->action('Reset Password', $this->resetUrl($notifiable))
            ->line('This link will expire in 60 minutes.')
            ->line('If you did not request a password reset, no further action is required.');
    }
}