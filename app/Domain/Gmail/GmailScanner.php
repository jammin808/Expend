<?php

namespace App\Domain\Gmail;

use App\Domain\Documents\AiConfig;
use App\Domain\Documents\DocumentIntake;
use App\Jobs\ReadTravelEmail;
use App\Models\Document;
use App\Models\GmailMessage;
use App\Models\Setting;
use App\Support\UkTaxYear;
use Throwable;

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
            'query' => '(subject:(PO OR "purchase order" OR "call sheet" OR "production pack" OR "crew pack" OR "job sheet" OR "booking form" OR "tech pack" OR "running order" OR invite OR "day sheet" OR "run sheet" OR "crew call" OR "show info" OR "production schedule" OR "job confirmation" OR "booking confirmation" OR pencil OR "work order") OR filename:(PO OR "purchase order" OR callsheet OR "call sheet" OR "production pack")) -in:sent -in:drafts -in:chats',
        ],
        // Work offered before there's a PO: availability checks, holds and pencils, offers, booking and crew requests,
        // and requests for a quote. Words in the subject, or phrases in the body that only an offer of work uses
        // (single words such as "availability" or "pencil" only in the subject, and "dates" only beside a word such as
        // "crew"); "are you free", "can you do" and "would you be interested" in the body only beside a word about the
        // work (crew, LX, show, a day rate), so a drink or a party stays out. Never your own mail, newsletters and
        // promotions, job boards' alerts, machines, a PO email ("could you quote our PO number") or a shop's "your quote
        // request". Tested against sample emails in OpportunityAcquireTest.
        'opportunities' => [
            'label' => 'Work offers: availability checks, holds and bookings',
            'query' => '(subject:(availability OR avail OR "are you available" OR "are you free" OR "you free" OR "can you do" OR pencil OR pencilled OR penciled OR "1st option" OR "2nd option" OR "first option" OR "second option" OR "1st hold" OR "2nd hold" OR "first refusal" OR "soft hold" OR "hold request" OR "booking request" OR "booking enquiry" OR "booking inquiry" OR "crew request" OR "crew needed" OR "crew call" OR "tech needed" OR "techs needed" OR freelance OR freelancer OR "day rate" OR "quote request" OR "request for quote" OR "job offer" OR "work offer" OR "offer of work") OR (subject:dates subject:(crew OR tech OR technician OR freelance OR job OR work OR show OR event)) OR "are you available" OR "would you be available" OR "would you be free" OR "your availability" OR "confirm availability" OR "1st option" OR "2nd option" OR "first refusal" OR "pencil you" OR "pencilled you" OR "penciled you" OR "hold you for" OR "day rate" OR "booking request" OR "crew request" OR "like to book you" OR "like to offer you" OR "book you for" OR "quote me for" OR "quote us for" OR "quote for the job" OR "quote your rate" OR (("are you free" OR "you\'re free" OR "can you do" OR "would you be interested" OR "you free") (crew OR tech OR techs OR technician OR LX OR lighting OR sound OR audio OR video OR rig OR derig OR show OR event OR gig OR "call time" OR "day rate" OR "per day" OR "a day" OR "for the day" OR venue OR stage OR conference OR festival OR awards OR gala OR arena))) -in:sent -in:drafts -in:chats -from:me -category:promotions -category:social -category:forums -from:(noreply OR no-reply OR donotreply OR newsletter OR indeed.com OR linkedin.com OR reed.co.uk OR totaljobs.com OR cv-library.co.uk OR monster.co.uk OR glassdoor.com OR glassdoor.co.uk OR mandy.com OR productionbase.co.uk OR stagejobspro.com OR thestage.co.uk OR talenttrack.com OR facebookmail.com OR eventbrite.com OR mailchimp.com) -subject:(newsletter OR webinar OR digest OR "job alert" OR "jobs alert" OR "new jobs" OR "jobs this week" OR unsubscribe OR sale OR discount OR "your quote request" OR "your booking request")',
        ],
    ];

    /** The searches that find emails about work, read as job documents (not as stays or journeys). */
    public const JOB_SEARCHES = ['job_documents', 'job', 'opportunities'];

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
            $new += (int) ($this->store($id, $search) !== null);
        }

        return ['found' => count($page['ids']), 'new' => $new, 'next' => $page['next']];
    }

    /**
     * Search Gmail for one job's emails (its client, venue, PO number or title around its dates) and keep what's
     * found as documents. Only the first page of results is read.
     *
     * @return array{found:int, new:int, documents:list<int>}
     */
    public function scanQuery(string $query, int $max = 40): array
    {
        $page = GmailClient::search($query, null, $max);
        $docs = [];
        foreach ($page['ids'] as $id) {
            if ($doc = $this->store($id, 'job')) {
                $docs[] = $doc->id;
            }
        }

        return ['found' => count($page['ids']), 'new' => count($docs), 'documents' => $docs];
    }

    /** Fetch and keep one message (with its attachments) unless it's already kept. Null when skipped or failed. */
    public function store(string $id, string $search): ?Document
    {
        $seen = GmailMessage::query()->where('message_id', $id)->first();
        if ($seen && ($seen->document_id || $seen->status !== 'failed')) {
            // Already kept. One first found by a job's own search (a hotel booking that names the venue's town) and
            // now found by the hotel or travel search is read as a stay or journey now: the job search never does.
            if ($seen->document_id && $seen->status === 'stored' && in_array($seen->search, self::JOB_SEARCHES, true) && ! in_array($search, self::JOB_SEARCHES, true)) {
                $seen->update(['search' => $search]);
                ReadTravelEmail::dispatch($seen->id);
            }

            return null; // already read; a message that failed to download is tried again
        }
        $from = $subject = $sentAt = null;
        // One bad message (deleted since the search, too big, unreadable) is logged and skipped, not the whole search.
        try {
            $message = GmailClient::message($id);
            [$from, $subject] = self::headers($message['raw']);
            $sentAt = $message['sent_at'] ? date('Y-m-d H:i:s', $message['sent_at']) : null;
            $document = $this->intake->store($message['raw'], mb_substr($subject ?: 'email', 0, 200).'.eml', 'message/rfc822', 'gmail', [
                'from' => $from, 'subject' => $subject, 'received_at' => $sentAt,
            ]);
        } catch (Throwable $e) {
            GmailMessage::query()->updateOrCreate(['message_id' => $id], [
                'search' => $search, 'subject' => mb_substr((string) $subject, 0, 500) ?: null,
                'email_from' => $from !== null ? mb_substr((string) $from, 0, 300) : null, 'sent_at' => $sentAt,
                'status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000),
            ]);

            return null;
        }
        $logged = GmailMessage::query()->updateOrCreate(['message_id' => $id], [
            'thread_id' => $message['thread_id'], 'search' => $search, 'status' => 'stored', 'error' => null,
            'email_from' => mb_substr((string) $from, 0, 300), 'subject' => mb_substr((string) $subject, 0, 500),
            'sent_at' => $sentAt, 'document_id' => $document->id,
        ]);

        // A booking from a hotel or travel company found by a job's search is read as a stay or journey too.
        $travel = in_array($search, self::JOB_SEARCHES, true) ? self::travelSearchFor($from) : $search;
        if ($travel !== null) {
            if ($travel !== $search) {
                $logged->update(['search' => $travel]);
            }
            ReadTravelEmail::dispatch($logged->id);
        } else {
            // The email itself is read too (attachments already are): POs, job details and offers of work are often in
            // the body. A short "are you free on the 12th?" is read as well. With AI reading off, an obvious offer of
            // work is spotted by its words instead.
            $this->intake->readOrSpot($document, true);
        }

        return $document;
    }

    /** Whether hotel and travel emails without booking markup can be read (needs AI). */
    public static function canUseAi(): bool
    {
        return AiConfig::ready() && Setting::get('ai_extraction') !== 'off';
    }

    /** The hotel or travel search whose senders include this one ("hotels" for premierinn.com), if any. */
    public static function travelSearchFor(?string $from): ?string
    {
        if (! $from || ! preg_match('/@([a-z0-9.-]+)/i', $from, $m)) {
            return null;
        }
        $domain = strtolower(rtrim($m[1], '.'));
        foreach (['hotels', 'travel'] as $search) {
            preg_match('/from:\(([^)]*)\)/i', self::SEARCHES[$search]['query'], $q);
            foreach (preg_split('/\s+OR\s+/i', $q[1] ?? '') ?: [] as $d) {
                $d = strtolower(trim($d));
                if ($d !== '' && ($domain === $d || str_ends_with($domain, '.'.$d))) {
                    return $search;
                }
            }
        }

        return null;
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
