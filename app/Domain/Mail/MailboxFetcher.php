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
            throw new RuntimeException('Add the forwarding mailbox details in Settings › Intake first.');
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
        $allowed = array_map('strtolower', array_map('trim', (array) Setting::get('allowed_senders')));
        if (! in_array($from, $allowed, true)) {
            return "From {$from}, which isn’t one of your sending addresses.";
        }
        if (preg_match('/^Authentication-Results:.*$/mi', $rawHeaders, $m) && ! preg_match('/\b(dmarc|spf|dkim)=pass\b/i', $m[0])) {
            return 'The receiving server couldn’t confirm the sender (no SPF, DKIM or DMARC pass).';
        }

        return null;
    }
}
