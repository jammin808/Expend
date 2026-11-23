<?php

namespace App\Domain\Gmail;

use App\Domain\Documents\AiConfig;
use App\Domain\Documents\DocumentIntake;
use App\Jobs\ReadTravelEmail;
use App\Models\GmailMessage;
use App\Models\Setting;
use App\Support\UkTaxYear;

/**
 * Runs the narrow, editable Gmail searches for one tax year and keeps only what matches: each message is
 * stored as a document (with its attachments) and listed in the "what the app read" log. The whole mailbox
 * is never synced.
 */
final class GmailScanner
{
    public const SEARCHES = [
        'hotels' => [
            'label' => 'Hotel bookings',
            'query' => 'from:(booking.com OR hotels.com OR premierinn.com OR travelodge.co.uk OR ihg.com OR expedia.com OR airbnb.com) subject:(confirmation OR confirmed OR booking OR reservation OR receipt OR invoice OR cancelled OR cancellation OR stay OR "check-in")',
        ],
        'travel' => [
            'label' => 'Travel receipts and tickets',
            'query' => 'from:(uber.com OR bolt.eu OR free-now.com OR addisonlee.com OR trainline.com OR lner.co.uk OR gwr.com OR avantiwestcoast.co.uk OR thameslinkrailway.com OR southernrailway.com OR crosscountrytrains.co.uk OR tpexpress.co.uk OR northernrailway.co.uk OR scotrail.co.uk OR nationalexpress.com OR megabus.com OR easyjet.com OR ba.com OR ryanair.com OR jet2.com OR enterprise.co.uk OR enterprise.com OR hertz.co.uk OR hertz.com OR avis.co.uk OR europcar.co.uk OR sixt.co.uk OR zipcar.com OR justpark.com OR ringgo.co.uk OR paybyphone.co.uk OR ncp.co.uk) -subject:(eats OR "uber one" OR newsletter)',
        ],
        'job_documents' => [
            'label' => 'POs, job invites and production packs',
            'query' => 'subject:(PO OR "purchase order" OR "call sheet" OR "production pack" OR "crew pack" OR "job sheet" OR "booking form" OR "tech pack" OR "running order" OR invite)',
        ],
    ];

    public function __construct(private DocumentIntake $intake) {}

    public static function query(string $search): string
    {
        return trim((string) (Setting::get('gmail_query_'.$search) ?: self::SEARCHES[$search]['query']));
    }

    /** The search with the tax year's dates added: two months before (bookings are made early) to a month after. */
    public static function queryFor(string $search, string $taxYear): string
    {
        $year = UkTaxYear::fromLabel($taxYear);
        $after = $year->startsOn()->subMonths(2)->format('Y/m/d');
        $before = $year->endsOn()->addMonth()->format('Y/m/d');

        return self::query($search)." after:{$after} before:{$before}";
    }

    /**
     * Fetch one page of results for one search.
     *
     * @return array{found:int, new:int, next:?string}
     */
    public function scanPage(string $search, string $taxYear, ?string $pageToken = null): array
    {
        $page = GmailClient::search(self::queryFor($search, $taxYear), $pageToken);
        $new = 0;

        foreach ($page['ids'] as $id) {
            if (GmailMessage::query()->where('message_id', $id)->exists()) {
                continue;
            }
            $message = GmailClient::message($id);
            [$from, $subject] = self::headers($message['raw']);

            $document = $this->intake->store($message['raw'], ($subject ?: 'email').'.eml', 'message/rfc822', 'gmail', [
                'from' => $from, 'subject' => $subject,
                'received_at' => $message['sent_at'] ? date('Y-m-d H:i:s', $message['sent_at']) : null,
            ]);
            $logged = GmailMessage::query()->create([
                'message_id' => $id, 'thread_id' => $message['thread_id'], 'search' => $search,
                'email_from' => mb_substr((string) $from, 0, 300), 'subject' => mb_substr((string) $subject, 0, 500),
                'sent_at' => $message['sent_at'] ? date('Y-m-d H:i:s', $message['sent_at']) : null, 'document_id' => $document->id,
            ]);
            $new++;

            if ($search !== 'job_documents') {
                ReadTravelEmail::dispatch($logged->id);
            }
        }

        return ['found' => count($page['ids']), 'new' => $new, 'next' => $page['next']];
    }

    /** Whether hotel and travel emails without booking markup can be read (needs AI). */
    public static function canUseAi(): bool
    {
        return AiConfig::ready() && Setting::get('ai_extraction') !== 'off';
    }

    /** @return array{0:?string, 1:?string} */
    private static function headers(string $raw): array
    {
        $head = preg_replace('/\r?\n[ \t]+/', ' ', strstr($raw, "\r\n\r\n", true) ?: (strstr($raw, "\n\n", true) ?: $raw));
        $get = function (string $name) use ($head): ?string {
            if (! preg_match('/^'.$name.':\s*(.*)$/mi', $head, $m)) {
                return null;
            }

            return trim(iconv_mime_decode($m[1], ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8') ?: $m[1]);
        };

        return [$get('From'), $get('Subject')];
    }
}
