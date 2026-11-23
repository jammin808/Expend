<?php

namespace App\Domain\Mail;

use App\Domain\Documents\DocumentIntake;
use App\Models\Setting;
use RuntimeException;
use Webklex\PHPIMAP\ClientManager;

/**
 * Reads the forwarding mailbox (for example ledger@rohleder.tech) over IMAP. Mail from your own addresses
 * that passes the receiving server's sender checks is taken in; anything else is held for your approval.
 */
final class MailboxFetcher
{
    public function __construct(private DocumentIntake $intake) {}

    public static function configured(): bool
    {
        return (bool) (Setting::get('imap_host') && Setting::get('imap_username') && Setting::get('imap_password'));
    }

    /** @return array{taken:int, held:int} */
    public function fetch(int $limit = 25): array
    {
        if (! self::configured()) {
            throw new RuntimeException('Add the forwarding mailbox details in Settings › Integrations first.');
        }

        $client = (new ClientManager)->make([
            'host' => Setting::get('imap_host'),
            'port' => (int) (Setting::get('imap_port') ?? 993),
            'encryption' => 'ssl',
            'validate_cert' => true,
            'username' => Setting::get('imap_username'),
            'password' => Setting::get('imap_password'),
            'protocol' => 'imap',
        ]);
        $client->connect();

        $taken = $held = 0;
        $messages = $client->getFolder('INBOX')->query()->whereUnseen()->limit($limit)->get();
        foreach ($messages as $message) {
            $header = $message->getHeader();
            $raw = rtrim($header->raw)."\r\n\r\n".$message->getRawBody();
            $from = strtolower((string) ($message->getFrom()[0]->mail ?? ''));
            $reason = self::holdReason($from, $header->raw);

            $this->intake->store($raw, trim((string) $message->getSubject()) ?: 'email', 'message/rfc822', 'email', [
                'from' => $from, 'subject' => (string) $message->getSubject(), 'held' => $reason !== null, 'held_reason' => $reason,
            ]);
            $message->setFlag('Seen');
            $reason ? $held++ : $taken++;
        }
        $client->disconnect();

        return ['taken' => $taken, 'held' => $held];
    }

    /** Why a message should wait for approval, or null to take it in. */
    public static function holdReason(string $from, string $rawHeaders): ?string
    {
        // Long headers are folded over several lines; join them so a pass on a later line still counts.
        $headers = preg_replace('/\r?\n[ \t]+/', ' ', $rawHeaders);
        // The topmost Authentication-Results is the one the receiving server added.
        $auth = preg_match('/^Authentication-Results:(.*)$/mi', $headers, $m) ? $m[1] : null;
        $allowed = array_map(self::normalise(...), (array) Setting::get('allowed_senders'));

        if (in_array(self::normalise($from), $allowed, true)) {
            if ($auth !== null && ! self::passesFor($auth, substr(strrchr(strtolower($from), '@') ?: '', 1))) {
                return 'The receiving server couldn’t confirm the sender (no DMARC pass, and no SPF or DKIM pass for the sender’s own domain).';
            }

            return null;
        }

        $forwardedBy = self::autoForwardedBy($headers, $auth);
        if ($forwardedBy !== null && in_array($forwardedBy, $allowed, true)) {
            return null;
        }

        return "From {$from}, which isn’t one of your sending addresses.";
    }

    /** A check that belongs to the sender's own domain: DMARC passed, or SPF or DKIM passed for that domain. */
    private static function passesFor(string $auth, string $domain): bool
    {
        if (preg_match('/\bdmarc=pass\b/i', $auth)) {
            return true;
        }
        $d = '(?:[a-z0-9-]+\.)*'.preg_quote($domain, '/').'(?![a-z0-9.-])';

        return $domain !== '' && (preg_match('/\bdkim=pass\b[^;]*\bheader\.[di]=@?'.$d.'/i', $auth)
            || preg_match('/\bspf=pass\b[^;]*\bsmtp\.mailfrom=(?:[^\s;@]*@)?'.$d.'/i', $auth));
    }

    /**
     * Gmail's automatic forwarding (a filter with "Forward it to") keeps the original sender, such as the
     * client who sent the PO, but sends from you@gmail.com+caf_=…: the envelope sender becomes
     * you+caf_=ledger=example.com@gmail.com. That counts as yours only if the receiving server's SPF check
     * passed for that domain, which a stranger can't fake.
     */
    private static function autoForwardedBy(string $headers, ?string $auth): ?string
    {
        if (! preg_match('/^Return-Path:\s*<?([a-z0-9._-]+)\+caf_=[^\s<>@]*@([a-z0-9-]+(?:\.[a-z0-9-]+)+)>?/mi', $headers, $m)) {
            return null;
        }
        $domain = preg_quote(strtolower($m[2]), '/');
        $spfPassed = ($auth !== null && preg_match('/\bspf=pass\b[^;]*\bsmtp\.mailfrom=(?:[^\s;@]*@)?'.$domain.'(?![a-z0-9.-])/i', $auth))
            || preg_match('/^Received-SPF:\s*pass\b.*@'.$domain.'(?![a-z0-9.-])/mi', $headers);

        return $spfPassed ? self::normalise($m[1].'@'.$m[2]) : null;
    }

    /** Lower case; Gmail ignores dots and anything after + in the local part. */
    private static function normalise(string $address): string
    {
        [$local, $domain] = array_pad(explode('@', strtolower(trim($address)), 2), 2, '');
        if (in_array($domain, ['gmail.com', 'googlemail.com'], true)) {
            return str_replace('.', '', explode('+', $local)[0]).'@gmail.com';
        }

        return $local.'@'.$domain;
    }
}
