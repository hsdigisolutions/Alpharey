<?php

namespace App\Services\Settings;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;

/**
 * Admin-configured SMTP (Settings → Email, smtp.alpharey.com per
 * DECISIONS.md). The password is encrypted at rest; the audit trail only
 * ever sees ciphertext.
 */
class MailSettings
{
    public function __construct(private SettingsService $settings) {}

    /**
     * @param  array{host: string, port: int, username: ?string, password: ?string, encryption: string, from_name: string, from_address: string}  $values
     */
    public function save(array $values): void
    {
        $this->settings->set('mail.host', $values['host']);
        $this->settings->set('mail.port', $values['port']);
        $this->settings->set('mail.username', $values['username']);
        $this->settings->set('mail.encryption', $values['encryption']);
        $this->settings->set('mail.from_name', $values['from_name']);
        $this->settings->set('mail.from_address', $values['from_address']);

        // Empty password = keep the stored one (edit form never echoes it back)
        if (is_string($values['password']) && $values['password'] !== '') {
            $this->settings->set('mail.password', Crypt::encryptString($values['password']));
        }
    }

    /**
     * Current values for the settings form — the password is never returned,
     * only whether one is stored.
     *
     * @return array<string, mixed>
     */
    public function current(): array
    {
        return [
            'host' => $this->settings->get('mail.host'),
            'port' => $this->settings->get('mail.port'),
            'username' => $this->settings->get('mail.username'),
            'encryption' => $this->settings->get('mail.encryption', 'tls'),
            'from_name' => $this->settings->get('mail.from_name'),
            'from_address' => $this->settings->get('mail.from_address'),
            'has_password' => $this->settings->get('mail.password') !== null,
        ];
    }

    public function isConfigured(): bool
    {
        return is_string($this->settings->get('mail.host'))
            && $this->settings->get('mail.host') !== ''
            && is_string($this->settings->get('mail.from_address'));
    }

    /**
     * Point the runtime mailer at the stored SMTP configuration.
     */
    public function apply(): void
    {
        $password = null;

        $stored = $this->settings->get('mail.password');

        if (is_string($stored) && $stored !== '') {
            try {
                $password = Crypt::decryptString($stored);
            } catch (DecryptException) {
                $password = null;
            }
        }

        $encryption = $this->settings->get('mail.encryption', 'tls');

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => $this->settings->get('mail.host'),
            'mail.mailers.smtp.port' => (int) $this->settings->get('mail.port', 587),
            'mail.mailers.smtp.username' => $this->settings->get('mail.username'),
            'mail.mailers.smtp.password' => $password,
            'mail.mailers.smtp.scheme' => $encryption === 'ssl' ? 'smtps' : null,
            'mail.from.name' => $this->settings->get('mail.from_name', 'Verto5'),
            'mail.from.address' => $this->settings->get('mail.from_address', 'noreply@alpharey.com'),
        ]);

        Mail::purge('smtp');
    }

    public function applyIfConfigured(): void
    {
        if ($this->isConfigured()) {
            $this->apply();
        }
    }
}
