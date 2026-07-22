<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Password-reset mail with the system-wide bilingual convention:
 * Spanish primary, English secondary (REQUIREMENTS.md §9).
 */
class BilingualResetPassword extends ResetPassword
{
    public function toMail(mixed $notifiable): MailMessage
    {
        $url = url(route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false));

        return (new MailMessage)
            ->subject('Restablecer contraseña / Reset password — AlphaRey')
            ->greeting('Hola / Hello')
            ->line('Recibimos una solicitud para restablecer la contraseña de tu cuenta.')
            ->line('We received a request to reset the password for your account.')
            ->action('Restablecer contraseña / Reset password', $url)
            ->line('Este enlace caduca en 60 minutos. Si no solicitaste el cambio, ignora este correo.')
            ->line('This link expires in 60 minutes. If you did not request a reset, please ignore this email.')
            ->salutation('AlphaRey');
    }
}
