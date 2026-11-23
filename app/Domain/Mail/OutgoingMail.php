<?php

namespace App\Domain\Mail;

use App\Models\Setting;
use Throwable;

/**
 * How the app sends email (password reset links and test emails), set in Settings › Integrations:
 *
 *   server  this web server's own mail program (Hostinger's sendmail); no setup, but more likely to land in spam
 *   smtp    your own mailbox, for example smtp.hostinger.com with the mailbox's address and password (recommended)
 *   off     nothing is sent
 *
 * With nothing chosen, the live site uses "server" so a password reset always works; elsewhere the .env setting
 * is left alone (it writes emails to the log).
 */
final class OutgoingMail
{
    public const METHODS = ['server', 'smtp', 'off'];

    public static function method(): ?string
    {
        $method = Setting::get('mail_method');
        if (in_array($method, self::METHODS, true)) {
            return $method === 'smtp' && ! self::smtpReady() ? 'server' : $method;
        }

        return app()->isProduction() ? 'server' : null;
    }

    public static function smtpReady(): bool
    {
        return Setting::get('smtp_host') && Setting::get('smtp_username') && Setting::get('smtp_password');
    }

    /** Point Laravel's mailer at the chosen method. Runs when the mailer is first used. */
    public static function apply(): void
    {
        try {
            $method = self::method();
        } catch (Throwable) {
            return; // no database yet (for example while installing): keep the .env setting
        }

        if ($method === 'smtp') {
            $port = (int) (Setting::get('smtp_port') ?: 465);
            config(['mail.default' => 'smtp', 'mail.mailers.smtp' => [
                'transport' => 'smtp', 'scheme' => $port === 465 ? 'smtps' : 'smtp',
                'host' => Setting::get('smtp_host'), 'port' => $port,
                'username' => Setting::get('smtp_username'), 'password' => Setting::get('smtp_password'), 'timeout' => 20,
            ]]);
        } elseif ($method === 'server') {
            config(['mail.default' => 'sendmail', 'mail.mailers.sendmail' => [
                'transport' => 'sendmail', 'path' => ini_get('sendmail_path') ?: '/usr/sbin/sendmail -t -i',
            ]]);
        } elseif ($method === 'off') {
            config(['mail.default' => 'log']);
        }

        if ($method !== null) {
            config(['mail.from.address' => self::from(), 'mail.from.name' => Setting::get('mail_from_name') ?: config('app.name')]);
        }
    }

    /**
     * The From address: as set, else the mailbox you send through. From the web server it has to be on your own
     * domain (your business email if it is, otherwise noreply@): mail claiming to be from a Gmail address but
     * sent from another server is treated as forged and blocked.
     */
    public static function from(): string
    {
        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
        $domain = implode('.', array_slice(explode('.', $host), -2)) ?: 'localhost';
        if ($set = Setting::get('mail_from_address')) {
            return $set;
        }
        if (self::method() === 'smtp') {
            return (string) Setting::get('smtp_username');
        }
        $business = (string) Setting::get('business_email');

        return str_ends_with(strtolower($business), '@'.$domain) ? $business : 'noreply@'.$domain;
    }
}
