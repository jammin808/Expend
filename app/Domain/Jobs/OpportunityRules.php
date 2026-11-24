<?php

namespace App\Domain\Jobs;

use App\Domain\Audit\AuditLog;
use App\Domain\Documents\Grounding;
use App\Domain\Gmail\GmailScanner;
use App\Models\Document;
use App\Models\GmailMessage;
use App\Models\Opportunity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Work offers spotted by their words alone, for emails the AI doesn't read (reading with AI is off or only on demand,
 * or there's no API key). Only the obvious ones count: someone asking if you're free, pencilling you in, asking to
 * book you or for a quote, or telling you you're booked. Each is proposed with low confidence and the words that
 * matched quoted from the email: an enquiry, or a booking for you to check. Nothing is ever made a job, declined or
 * answered here. If the email is read with AI later, that reading updates the same opportunity (it has the email's
 * fingerprint), and its checked details replace the rules' guesses.
 *
 * Not counted: newsletters and mailing lists (unless it's a crew call with its dates and a venue or rate), job
 * boards, machines (no-reply), your own replies, a meeting or a drink ("are you free for a quick call?"), a party
 * ("are you free on Saturday?" with nothing about work, from someone you've never worked with), PO and invoice
 * emails ("could you quote our PO number"), a hotel's or restaurant's "thank you for your booking request", and work
 * whose dates have all passed. A cancellation or release in the conversation of one already found is added to its
 * evidence for you to see: the rules never close one themselves.
 */
final class OpportunityRules
{
    /** Words that on their own mean someone is asking you about work. */
    public const ASKING = [
        '\b(?:check|confirm|let (?:me|us) know|send (?:me|us)|what(?:\'s|’s| is)|any update on) (?:of )?your availability\b',
        '\bavail(?:ability)? (?:check|request)\b',
        '\b(?:1st|2nd|3rd|first|second|third) (?:option|hold|pencil)\b',
        '\bfirst refusal\b',
        '\bpencil(?:led|ed)? you\b',
        '\bpencil you in\b',
        '\b(?:hold|put) you (?:on hold|on a hold|for|down for|in for)\b',
        '\bbooking (?:request|enquiry|inquiry)\b',
        '\bcrew (?:request|needed|wanted|required)\b',
        '\b(?:like|want|love|hoping) to (?:book|offer) you\b',
        '\b(?:can|could) (?:we|i) book you\b',
        '\boffer you (?:the|a|some) (?:job|work|gig|shift|role|position|day|days|booking)\b',
        '\b(?:could|can|would) you (?:please )?quote (?:(?:me|us) )?(?:for (?:the|this|a|an|our) (?:job|event|show|gig|work|day|days)|a price|your (?:day )?rate)\b',
        '\bquote (?:(?:me|us) )?for (?:the|this|a|our) (?:job|event|show|gig|work)\b',
        '\bquote request\b',
    ];

    /**
     * Questions that ask about work only when the message is about work (crew, a show, a rate) or comes from someone
     * you already work with: "are you free on Saturday?" is as often a party, and "would you be interested" a sales pitch.
     */
    public const ASKING_IF_WORK = [
        '\bare you (?:free|available)\b',
        '\b(?:any|is there any) chance you(?:\'re|’re| are| would be| might be| could be)? (?:free|available)\b',
        '\bwould you be (?:free|available|interested)\b',
        '\b(?:could|can) you be available\b',
        '\bcan you do (?:the |a )?(?:\d|mon|tue|wed|thu|fri|sat|sun|next|this|these|those)\b',
    ];

    /** Words that say a message is about this kind of work. */
    public const WORK_WORDS = '(?:\b(?:crew|crewing|tech|techs|technician|technicians|lx|lighting|sound|audio|video|vision|av|rig|rigging|de-?rig|get[- ]?in|get[- ]?out|load[- ]?in|load[- ]?out|show|shows|event|events|gig|gigs|call time|day rate|stage|stagehand|stagehands|production|venue|arena|conference|festival|awards|gala|concert|tour|exhibition|expo|operator|op|engineer|freelance|freelancer|shift)\b|£\s?\d)';

    /** Words that tell you you're booked: a booking confirmation, a crew pack or call sheet, a PO. */
    public const CONFIRMING = [
        '\byou(?:\'re|’re| are) (?:now |all )?(?:confirmed|booked)(?: in)? (?:for|on|at)\b',
        '\b(?:we(?:\'ve|’ve| have)|i(?:\'ve|’ve| have)) (?:now |just )?(?:booked|confirmed) you\b',
        '\bconfirm(?:ing)? (?:that )?you(?:\'re|’re| are) (?:booked|confirmed)\b',
        '\b(?:booking|job|crew|work) confirmation\b',
    ];

    /** Words in an email's subject that suggest work, so that it's worth reading with AI when that's on. */
    public const SUBJECT_WORDS = '\b(?:availability|avail|available|pencil|pencilled|penciled|hold|option|first refusal|booking request|booking enquiry|crew (?:call|request|needed)|freelancer?|day rate|quote|offer|are you free|can you do)\b';

    /** Words that may confirm work (or bring its paperwork), worth reading with AI in any email but a hotel's or shop's. */
    private const CONFIRM_WORDS = '\b(?:confirm(?:ed|ing|ation)?|you(?:\'re|’re| are) (?:now |all )?booked|booked you|booking confirmation|crew pack|call ?sheet|purchase order|PO\s*(?:no\.?|number|#)?\s*:?\s*[A-Z]{0,4}-?\d{2,})\b';

    /** Your own purchase or plans, not work: "your booking is confirmed", "your table", "your stay". */
    private const YOUR_PURCHASE = '\byour (?:booking|reservation|stay|order|ticket|tickets|journey|trip|room|table|parcel|delivery|appointment|purchase|rental|hire|policy|account)\b';

    /**
     * Asked about something that isn't work: a call or a drink, or an invitation or sales pitch ("would you be
     * interested in coming to our demo day?").
     */
    private const NOT_WORK = '\b(?:(?:free|available) (?:for|to) (?:a |an )?(?:quick |short |brief )?(?:call|chat|catch[- ]?up|coffee|drink|drinks|pint|meeting|zoom|teams|meet|lunch|dinner|beer|walk)'
        .'|interested in (?:coming|attending|joining us|visiting|seeing|trying|buying|hearing|signing up|our (?:new|latest|range|products?|services?|demo|open day|webinar|showroom|course|training))'
        .'|demo day|open day|open evening|webinar|showroom|trade show|free lunch|special offer|discount|% off)\b';

    /** The work is off, or already settled elsewhere. */
    private const CLOSING = '\b(?:cancel(?:l?ed|lation|ling)?|postponed|called off|pulled|fallen through|fell through|not (?:going ahead|happening)|no longer (?:need|require|going ahead|happening)|(?:gone|going|went) (?:with|to) (?:someone|somebody|another|a different|their|our|the other|in-?house)|stand(?:ing)? you down|stood you down|releas(?:e|ed|ing) (?:you|the|your) (?:hold|pencil|option|dates?)|release you\b|let(?:ting)? (?:your|the|you) (?:\w+ ){0,2}(?:hold|option|pencil)s? go|won[\'’]?t (?:be needing|need) you|don[\'’]?t need you)';

    /** "We've had a cancellation", "postponed to 9-10 April": news that comes before asking you, not calling it off. */
    private const NOT_CLOSING = '\b(?:(?:had|got|have) (?:a |an |some )?(?:last[- ]minute )?(?:cancellation|drop[- ]?out)s?|(?:been |was |is |are |have been )?(?:postponed|moved|rescheduled) (?:to|until|till)\b)';

    /** Job boards and mailing services: their alerts are adverts sent to everyone, not work offered to you. */
    public const JOB_BOARDS = [
        'indeed.com', 'linkedin.com', 'reed.co.uk', 'totaljobs.com', 'cv-library.co.uk', 'monster.co.uk', 'glassdoor.com', 'glassdoor.co.uk',
        'mandy.com', 'productionbase.co.uk', 'stagejobspro.com', 'thestage.co.uk', 'talenttrack.com', 'facebookmail.com', 'eventbrite.com', 'mailchimp.com',
    ];

    /** Free email services: anyone can have an address there, so only the same address is the same person. */
    public const FREE_MAIL = ['gmail.com', 'googlemail.com', 'hotmail.com', 'hotmail.co.uk', 'outlook.com', 'live.com', 'live.co.uk', 'msn.com', 'yahoo.com',
        'yahoo.co.uk', 'icloud.com', 'me.com', 'mac.com', 'aol.com', 'btinternet.com', 'sky.com', 'virginmedia.com', 'protonmail.com', 'proton.me', 'mail.com', 'gmx.com', 'gmx.co.uk'];

    /** Words in the newest message that may move work along: a booking, a hold, or calling it off. */
    private const STAGE_WORDS = '\b(?:confirm(?:ed|ing|ation)?|booked|cancel(?:l?ed|lation|ling)?|postponed|releas(?:e|ed|ing)|gone (?:with|to)|on hold|pencil(?:led|ed)?)\b';

    /**
     * Whether an email is worth reading with AI for work it may offer: words that ask about work anywhere in it, a
     * subject that suggests it, a newest message that confirms work (you're booked, a booking confirmation, a crew
     * pack, a PO in the email itself), a reply whose newest message confirms or calls off work, or a reply in a Gmail
     * conversation that already has an opportunity (however short, it may say where things stand). A hotel's or
     * travel company's booking, and your own purchases ("your booking is confirmed"), don't count; nor does a note
     * whose attachments are read anyway.
     */
    public static function worthReading(Document $email): bool
    {
        $text = (string) $email->text;
        $subject = (string) $email->email_subject;
        $newest = self::newestMessage($text, $subject);

        return self::firstMatch([...self::ASKING, ...self::ASKING_IF_WORK], $text) !== null
            || (bool) preg_match('/'.self::SUBJECT_WORDS.'/iu', $subject)
            || (self::isReply($subject) && preg_match('/'.self::STAGE_WORDS.'/iu', $newest))
            || self::confirmsWork($email, $subject, $newest)
            || self::inKnownThread($email);
    }

    /** Words that confirm work in the subject or newest message, from someone who isn't a hotel or travel company. */
    private static function confirmsWork(Document $email, string $subject, string $newest): bool
    {
        if (! preg_match('/'.self::CONFIRM_WORDS.'/iu', $subject."\n".$newest) || preg_match('/'.self::YOUR_PURCHASE.'/iu', $newest)
            || GmailScanner::travelSearchFor($email->email_from) !== null) {
            return false;
        }

        // A short note with the PO or booking attached: the attachment is read, the note needn't be.
        return ! $email->children()->where('kind', '!=', 'email')->exists();
    }

    /** Another email in the same Gmail conversation is already evidence for an opportunity. */
    public static function inKnownThread(Document $email): bool
    {
        $thread = GmailMessage::query()->where('document_id', $email->parent_id ?? $email->id)->value('thread_id');
        if (! $thread) {
            return false;
        }
        if (Opportunity::query()->where('thread_key', 'gmail:'.mb_substr($thread, 0, 120))->exists()) {
            return true;
        }
        $others = GmailMessage::query()->where('thread_id', $thread)->whereNotNull('document_id')->where('document_id', '!=', $email->parent_id ?? $email->id)
            ->pluck('document_id')->all();

        return (new self)->withDocuments($others) !== null;
    }

    /**
     * Spot an obvious work offer (or booking) in an email nobody will read with AI, and propose it. Returns the
     * opportunity it belongs to (a new one, or the one already found for this email or its conversation, with this
     * email's words added to its evidence), or null when it isn't an obvious offer of work. A message that calls off
     * work already found is added to that one's evidence (and it's returned), but never closes it.
     */
    public function spot(Document $email): ?Opportunity
    {
        $telegram = $email->source === 'telegram' && $email->kind !== 'email' && str_starts_with((string) $email->mime, 'text/');
        if (($email->kind !== 'email' && ! $telegram) || $email->status === 'held' || $email->text_status !== 'extracted' || trim((string) $email->text) === '') {
            return null;
        }
        $top = $email->parent_id ? ($email->parent ?? $email) : $email;
        $text = (string) $email->text;
        $subject = self::subjectLine($text) ?? (string) $email->email_subject;
        $newest = $telegram ? $text : self::newestMessage($text, $subject);
        $reply = self::isReply($subject);

        $bulk = $telegram ? null : $this->bulk($email, $top, $text);
        if ($bulk === 'machine') {
            return null;
        }
        $own = Opportunities::ownAddresses();
        // Who's asking: this email's sender (for one you forwarded, the original sender in it), else the email it came in.
        $sender = Opportunities::senderOf($email, $own);
        $senderDoc = $email;
        if ($sender === null && $top->id !== $email->id) {
            [$sender, $senderDoc] = [Opportunities::senderOf($top, $own), $top];
        }
        if ($sender === null && $telegram && trim((string) $email->email_from) !== '') {
            // A message forwarded in Telegram: who it came from, by name only.
            $line = trim((string) strtok($text, "\n"));
            $sender = ['name' => mb_substr(trim((string) $email->email_from), 0, 120), 'email' => null, 'quote' => $line,
                'verified' => str_starts_with($line, 'From:') && Grounding::quoteFound($text, $line)];
        }
        if ($sender === null) {
            return null; // only you, or a machine: nobody is asking you
        }
        if ($sender['name'] === null && $sender['email'] !== null && preg_match('/^\s*"?([^"<>@]+?)"?\s*<\s*'.preg_quote($sender['email'], '/').'\s*>/i', (string) $senderDoc->email_from, $m)) {
            $sender['name'] = mb_substr(trim($m[1]), 0, 120); // the name the email's own From header gives the address
        }

        // What it says: the words asking about work (in the newest message, or the subject of an email that isn't a
        // reply: "Re: Avail check" isn't asking again), or telling you you're booked.
        $asked = $this->asking($newest, $reply ? null : $subject, $sender['email'], $closing);
        $status = 'enquiry';
        if ($asked === null && $closing === null && ($asked = $this->confirming($email, $newest, $reply ? null : $subject)) !== null) {
            $status = 'confirmed';
        }
        $sentAt = CarbonImmutable::instance($top->received_at ?? $email->received_at ?? now());
        if ($asked === null) {
            // Not asking: it may call off work already found in the same conversation.
            $closing ??= self::closingSentence($newest);

            return $closing !== null ? $this->calledOff($email, $top, $sender, $closing, $sentAt) : null;
        }
        $sentence = self::sentence($asked['in'], $asked['at']);

        $dates = self::dates($subject."\n".$newest, $text, $sentAt);
        $today = CarbonImmutable::today()->format('Y-m-d');
        $upcoming = $dates !== [] && max(array_column($dates, 'date')) >= $today;
        if ($bulk === 'list' && (! $upcoming || ! self::venueOrRate($newest))) {
            return null; // a newsletter or a call-out to everyone, with nothing specific
        }

        $verified = $email->text_status === 'extracted' && Grounding::quoteFound($text, $sentence);
        $evidence = [['type' => 'document', 'id' => $email->id, 'field' => 'status', 'value' => $status, 'quote' => mb_substr($sentence, 0, 400), 'verified' => $verified, 'by' => 'rules']];
        foreach ($dates as $d) {
            $evidence[] = ['type' => 'document', 'id' => $email->id, 'field' => 'dates', 'value' => $d['date'], 'quote' => mb_substr($d['quote'], 0, 400), 'verified' => $d['verified'], 'by' => 'rules'];
        }
        $evidence[] = ['type' => 'document', 'id' => $senderDoc->id, 'field' => 'contact', 'value' => trim(($sender['name'] ?? '').($sender['email'] ? ' <'.$sender['email'].'>' : '')),
            'quote' => mb_substr((string) $sender['quote'], 0, 400), 'verified' => $sender['verified'], 'by' => 'rules'];

        if ($existing = $this->existing($email, $top, $sender, $dates)) {
            return $existing['same'] ? $existing['o'] : $this->addNews($existing['o'], $evidence, $dates, $status, $email, $top, $sentAt);
        }
        if (($dates && ! $upcoming) || (! $dates && $sentAt->lt(CarbonImmutable::today()->subDays(60)))) {
            return null; // it's passed: nothing upcoming to propose
        }

        $title = self::subjectTitle($top->email_subject ?? $email->email_subject) ?? 'Possible work'.($sender['name'] ? ' from '.$sender['name'] : '');
        $thread = GmailMessage::query()->where('document_id', $top->id)->value('thread_id');
        $days = array_map(fn ($d) => ['date' => $d['date'], 'kind' => 'work'], $dates);

        $o = DB::transaction(function () use ($top, $email, $title, $sender, $days, $evidence, $thread, $sentAt, $asked, $status, $bulk) {
            $o = Opportunity::query()->create([
                'status' => $status, 'title' => mb_substr($title, 0, 150),
                'contact_name' => $sender['name'], 'contact_email' => $sender['email'],
                'starts_on' => $days[0]['date'] ?? null, 'ends_on' => $days ? $days[count($days) - 1]['date'] : null, 'dates' => $days ?: null,
                'source' => in_array($top->source, ['gmail', 'email', 'telegram', 'upload'], true) ? $top->source : 'upload',
                'found_by' => 'rules', 'confidence' => ($bulk === 'list' ? 5 : 15) + ($days ? 10 : 0) + 5, 'evidence' => $evidence,
                'thread_key' => $thread ? 'gmail:'.mb_substr($thread, 0, 120) : null,
                // The same fingerprint the AI reading gives this email, so reading it later updates this one.
                'fingerprint' => sha1('document:'.$top->id),
                'first_seen_at' => $sentAt, 'last_seen_at' => $sentAt,
                'note' => $bulk === 'list' ? 'Sent to a mailing list, not only to you: check it’s meant for you.' : null,
            ]);
            AuditLog::record('opportunity.found', $o, null, [
                'status' => $status, 'title' => $o->title, 'contact_email' => $o->contact_email, 'dates' => $o->dates,
                'found_by' => 'rules', 'confidence' => $o->confidence, 'matched' => $asked['words'],
                'unchecked' => $o->unverifiedFields(),
            ], 'Spotted by its words (not read with AI) in “'.mb_substr((string) ($top->email_subject ?: $email->original_name), 0, 100).'”'
                .($top->received_at ? ' of '.$top->received_at->format('j M Y') : '').($status === 'confirmed' ? ': it says you’re booked, check it' : ''), 'matcher');

            return $o;
        });
        // A later message in the same conversation, taken in before this one (Gmail lists the newest first), may
        // already have called it off.
        if ($thread) {
            $this->laterNews($o, $thread, $top, $sentAt);
        }

        return $o->fresh();
    }

    /**
     * The words asking about work, if any, in the newest message (or the subject). A question that's only work when
     * the message is about work needs a work word or someone you work with. Not asking: a drink or a call, or the
     * sentence holding the question calls the work off (then $closing is that sentence).
     *
     * @return ?array{words: string, in: string, at: int}
     */
    private function asking(string $newest, ?string $subject, ?string $from, ?string &$closing = null): ?array
    {
        $closing = null;
        foreach ([[self::ASKING, false], [self::ASKING_IF_WORK, true]] as [$patterns, $needsWork]) {
            $found = ($subject !== null ? self::firstMatch($patterns, $subject) : null) ?? self::firstMatch($patterns, $newest);
            if ($found === null) {
                continue;
            }
            $sentence = self::sentence($found['in'], $found['at']);
            if (preg_match('/'.self::NOT_WORK.'/iu', $sentence)) {
                continue; // a drink or a call
            }
            if (self::closes($sentence)) {
                $closing = $sentence; // "the gala's been pulled, so I'm letting your 1st option go"

                return null;
            }
            if ($needsWork && ! preg_match('/'.self::WORK_WORDS.'/iu', ($subject ?? '')."\n".$newest) && ! self::knownSender($from)) {
                continue;
            }

            return $found;
        }

        return null;
    }

    /** Words telling you you're booked, from someone who isn't a hotel or travel company, about work not a purchase. */
    private function confirming(Document $email, string $newest, ?string $subject): ?array
    {
        $found = self::firstMatch(self::CONFIRMING, $newest) ?? ($subject !== null ? self::firstMatch(self::CONFIRMING, $subject) : null);
        if ($found === null || preg_match('/'.self::YOUR_PURCHASE.'/iu', $newest) || GmailScanner::travelSearchFor($email->email_from) !== null
            || ! preg_match('/'.self::WORK_WORDS.'/iu', ($subject ?? '')."\n".$newest)) {
            return null;
        }

        return self::closes(self::sentence($found['in'], $found['at'])) ? null : $found;
    }

    /** Whether a sentence calls work off ("cancelled", "pulled", "standing you down"), leaving out news before an ask. */
    private static function closes(string $sentence): bool
    {
        return (bool) preg_match('/'.self::CLOSING.'/iu', preg_replace('/'.self::NOT_CLOSING.'/iu', ' ', $sentence) ?? $sentence);
    }

    /** The first sentence in a message that calls work off, if any. */
    private static function closingSentence(string $newest): ?string
    {
        $text = preg_replace_callback('/'.self::NOT_CLOSING.'/iu', fn ($m) => str_repeat(' ', strlen($m[0])), $newest) ?? $newest;

        return preg_match('/'.self::CLOSING.'/iu', $text, $m, PREG_OFFSET_CAPTURE) ? self::sentence($newest, $m[0][1]) : null;
    }

    /** Someone you already work with: the contact of work found before, or the sender of an email on one of your jobs. */
    private static function knownSender(?string $email): bool
    {
        if ($email === null || $email === '') {
            return false;
        }

        return Opportunity::query()->where('contact_email', $email)->where('status', '!=', 'dismissed')->exists()
            || Document::query()->whereNotNull('work_job_id')->where('email_from', 'like', '%'.$email.'%')->exists();
    }

    /** A crew call sent to a list counts only with its venue or rate ("at the NEC", "£300/day"). */
    private static function venueOrRate(string $text): bool
    {
        return (bool) preg_match('/£\s?\d|\b(?:day rate|per day|a day)\b|\/\s*day\b/iu', $text)
            || (bool) preg_match('/\b(?:at|venue:?)\s+(?:the\s+)?\p{Lu}[\p{L}\'’&-]+/u', $text);
    }

    private static function isReply(string $subject): bool
    {
        $s = preg_replace('/^\s*(?:(?:fw|fwd)\s*:\s*)+/i', '', $subject) ?? $subject;

        return (bool) preg_match('/^\s*(?:re|aw|sv)\s*(?:\[\d+\]|\(\d+\))?\s*:/i', $s);
    }

    /**
     * The opportunity this email already belongs to: the same email ('same', nothing to add), the same Gmail
     * conversation, or the same conversation by another route (a reply, or a copy forwarded to the mailbox): a subject
     * that says which job it is, from the same person (the same address at a free email service, or the same
     * company's domain), heard about in the last three months, for dates that fit.
     *
     * @param  list<array{date: string}>  $dates
     * @return ?array{o: Opportunity, same: bool}
     */
    private function existing(Document $email, Document $top, array $sender, array $dates = []): ?array
    {
        if ($o = Opportunity::query()->where('fingerprint', sha1('document:'.$top->id))->first()) {
            return ['o' => $o, 'same' => true];
        }
        $ids = [$email->id, $top->id];
        $thread = GmailMessage::query()->where('document_id', $top->id)->value('thread_id');
        if ($thread) {
            $ids = [...$ids, ...GmailMessage::query()->where('thread_id', $thread)->whereNotNull('document_id')->pluck('document_id')->all()];
            if ($o = Opportunity::query()->where('thread_key', 'gmail:'.mb_substr($thread, 0, 120))->orderByDesc('last_seen_at')->first()) {
                return ['o' => $o, 'same' => false];
            }
        }
        if ($o = $this->withDocuments($ids)) {
            return ['o' => $o, 'same' => in_array($email->id, $o->documentIds(), true)];
        }

        $key = Opportunities::subjectKey($top->email_subject ?? $email->email_subject);
        if ($key === null || ($sender['email'] ?? null) === null) {
            return null;
        }
        $domain = strtolower(substr((string) strrchr($sender['email'], '@'), 1));
        $free = in_array($domain, self::FREE_MAIL, true);
        $recent = Opportunity::query()
            ->when($free, fn ($q) => $q->where('contact_email', $sender['email']), fn ($q) => $q->where('contact_email', 'like', '%@'.$domain))
            ->where('last_seen_at', '>=', now()->subDays(90))->orderByDesc('last_seen_at')->limit(30)->get();
        $facts = ['dates' => array_map(fn ($d) => ['date' => $d['date']], $dates)];
        foreach ($recent as $o) {
            if (Opportunities::datesFit($o, $facts) && $o->documents()->contains(fn (Document $d) => Opportunities::subjectKey($d->email_subject) === $key)) {
                return ['o' => $o, 'same' => false];
            }
        }

        return null;
    }

    /**
     * Another email about work already found: its words are added to the evidence (and recorded), and it fills in the
     * days if there were none. A booking moves an open one forward; nothing else changes, and nothing at all once
     * you've decided it, it's a job, or you set that detail yourself.
     *
     * @param  list<array{date: string}>  $dates
     */
    private function addNews(Opportunity $o, array $evidence, array $dates, string $status, Document $email, Document $top, CarbonImmutable $sentAt): Opportunity
    {
        return DB::transaction(function () use ($o, $evidence, $dates, $status, $email, $top, $sentAt) {
            $o = Opportunity::query()->lockForUpdate()->findOrFail($o->id);
            $before = ['status' => $o->status, 'dates' => $o->dates, 'last_seen_at' => $o->last_seen_at?->format('Y-m-d H:i:s')];
            $have = array_flip(array_map(self::evidenceKey(...), $o->evidence ?? []));
            $all = $o->evidence ?? [];
            $added = 0;
            foreach ($evidence as $e) {
                if (! isset($have[self::evidenceKey($e)])) {
                    $all[] = $e;
                    $added++;
                }
            }
            $changes = $added ? ['evidence' => $all] : [];
            if (! $o->last_seen_at || $sentAt->gt($o->last_seen_at)) {
                $changes['last_seen_at'] = $sentAt;
            }
            $locked = in_array($o->status, Opportunity::YOURS, true) || $o->isAccepted();
            if (! $locked && $dates && ! $o->starts_on && ! $o->ownerSet('dates')) {
                $days = array_map(fn ($d) => ['date' => $d['date'], 'kind' => 'work'], $dates);
                $changes += ['dates' => $days, 'starts_on' => $days[0]['date'], 'ends_on' => $days[count($days) - 1]['date']];
            }
            if (! $locked && ! $o->work_job_id && ! $o->ownerSet('status') && isset(Opportunity::STAGES[$o->status])
                && (Opportunity::STAGES[$status] ?? 0) > Opportunity::STAGES[$o->status]) {
                $changes['status'] = $status;
            }
            if (! $changes) {
                return $o;
            }
            $o->update($changes);
            AuditLog::record('opportunity.updated', $o, $before, ['status' => $o->status, 'dates' => $o->dates, 'last_seen_at' => $o->last_seen_at?->format('Y-m-d H:i:s'),
                'evidence_added' => $added], 'Spotted by its words (not read with AI) in “'.mb_substr((string) ($top->email_subject ?: $email->original_name), 0, 100).'”'
                .($top->received_at ? ' of '.$top->received_at->format('j M Y') : '').($locked ? ': evidence only' : ''), 'matcher');

            return $o;
        });
    }

    /**
     * A message calling off work already found in its conversation: added to that one's evidence as "may be off"
     * for you to see (Opportunity::mayBeOff), never closing it. Null when there's nothing it belongs to.
     */
    private function calledOff(Document $email, Document $top, array $sender, string $sentence, CarbonImmutable $sentAt): ?Opportunity
    {
        $found = $this->existing($email, $top, $sender);
        if ($found === null || $found['same']) {
            return $found['o'] ?? null;
        }

        return $this->addNews($found['o'], [['type' => 'document', 'id' => $email->id, 'field' => 'status', 'value' => 'lost', 'quote' => mb_substr($sentence, 0, 400),
            'verified' => $email->text_status === 'extracted' && Grounding::quoteFound((string) $email->text, $sentence), 'by' => 'rules']], [], 'lost', $email, $top, $sentAt);
    }

    /** Later emails in the same Gmail conversation, kept before this one was spotted, that call the work off. */
    private function laterNews(Opportunity $o, string $thread, Document $top, CarbonImmutable $sentAt): void
    {
        $ids = GmailMessage::query()->where('thread_id', $thread)->whereNotNull('document_id')->where('document_id', '!=', $top->id)->pluck('document_id');
        foreach (Document::query()->whereIn('id', $ids)->where('kind', 'email')->whereNull('parent_id')->where('received_at', '>', $sentAt)->orderBy('received_at')->get() as $later) {
            $closing = self::closingSentence(self::newestMessage((string) $later->text, (string) $later->email_subject));
            if ($closing !== null) {
                $this->addNews($o, [['type' => 'document', 'id' => $later->id, 'field' => 'status', 'value' => 'lost', 'quote' => mb_substr($closing, 0, 400),
                    'verified' => $later->text_status === 'extracted' && Grounding::quoteFound((string) $later->text, $closing), 'by' => 'rules']], [], 'lost',
                    $later, $later, CarbonImmutable::instance($later->received_at ?? now()));
            }
        }
    }

    private static function evidenceKey(array $e): string
    {
        return implode('|', [$e['type'] ?? '', $e['id'] ?? '', $e['field'] ?? '', $e['value'] ?? '', Grounding::normalise((string) ($e['quote'] ?? ''))]);
    }

    private function withDocuments(array $ids): ?Opportunity
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (! $ids) {
            return null;
        }

        return Opportunity::query()->where(function ($q) use ($ids) {
            foreach (array_slice($ids, 0, 50) as $id) {
                $q->orWhere('evidence', 'like', '%'.$id.'%');
            }
        })->orderByDesc('last_seen_at')->get()->first(fn (Opportunity $o) => array_intersect($o->documentIds(), $ids) !== []);
    }

    /**
     * Mail from a machine or a job board ('machine': never work offered to you), or sent to a list ('list': a
     * newsletter, or a crewing agency's call-out to its freelancers, which counts only with its dates and a venue or
     * rate). Null for an ordinary email.
     *
     * @return 'machine'|'list'|null
     */
    private function bulk(Document $email, Document $top, string $text): ?string
    {
        $from = strtolower((string) ($email->email_from ?: $top->email_from));
        if (preg_match('/(?:^|[<\s"])(?:no-?reply|do-?not-?reply|newsletters?|news|marketing|mailer|notifications?|alerts?)@/i', $from)) {
            return 'machine';
        }
        foreach (self::JOB_BOARDS as $domain) {
            if (preg_match('/@(?:[a-z0-9-]+\.)*'.preg_quote($domain, '/').'\b/i', $from)) {
                return 'machine';
            }
        }
        if (preg_match('/\b(?:unsubscribe|view (?:this email |it )?in (?:your |a )?browser|manage (?:your )?(?:email )?(?:preferences|subscription)|you(?:\'re|’re| are) receiving this (?:email|because)|update your preferences)\b/iu', $text)) {
            return 'list';
        }
        // The headers of the email as it arrived: a mailing list or bulk mail says so.
        try {
            $raw = $top->stored_path && Storage::disk('local')->exists($top->stored_path) ? (string) Storage::disk('local')->get($top->stored_path) : '';
        } catch (Throwable) {
            $raw = '';
        }
        $head = strstr($raw, "\r\n\r\n", true) ?: (strstr($raw, "\n\n", true) ?: '');
        if (preg_match('/^Auto-Submitted:\s*auto-generated/mi', $head)) {
            return 'machine';
        }

        return preg_match('/^(?:List-Unsubscribe|List-Id|List-Post):|^Precedence:\s*(?:bulk|list|junk)/mi', $head) ? 'list' : null;
    }

    // ---------------------------------------------------------------- reading the words

    /**
     * The first match of any pattern, and where it is. A match right after "your" or "thank you for your" ("thank you
     * for your booking request") is someone answering you, not asking: it's passed over.
     *
     * @return ?array{words: string, in: string, at: int}
     */
    private static function firstMatch(array $patterns, string $text): ?array
    {
        $best = null;
        foreach ($patterns as $p) {
            if (! preg_match_all('/'.$p.'/iu', $text, $all, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($all[0] as [$words, $at]) {
                if (preg_match('/\b(?:your|thanks? (?:you )?for (?:the|your)|thank you for (?:the|your))\s+$/iu', substr($text, max(0, $at - 30), min(30, $at)))) {
                    continue;
                }
                if ($best === null || $at < $best['at']) {
                    $best = ['words' => $words, 'in' => $text, 'at' => $at];
                }

                break;
            }
        }

        return $best;
    }

    /** The email's own Subject line, as it is in its text. */
    private static function subjectLine(string $text): ?string
    {
        $head = strstr($text, "\n\n", true) ?: $text;

        return preg_match('/^Subject:[ \t]*(.+)$/mi', $head, $m) ? trim($m[1]) : null;
    }

    /**
     * The newest message in an email: the part above the quoted earlier messages, or for an email you forwarded, the
     * forwarded message (up to the one it quotes in turn). The whole body when that can't be told apart.
     */
    public static function newestMessage(string $text, ?string $subject = null): string
    {
        $body = str_contains($text, "\n\n") ? substr($text, strpos($text, "\n\n") + 2) : $text;
        $marker = '/^(?:On .{4,300}?wrote:\s*$|-{2,}\s*(?:Original|Forwarded) Message\s*-{2,}.*$|-{5,}\s*Forwarded message\s*-{5,}.*$|_{10,}\s*$|From:[ \t].+\R(?:(?:Sent|Date|To|Cc|Subject):.*\R?){1,4}|>.*$)/mi';
        $parts = preg_split($marker, $body) ?: [$body];
        $newest = $parts[0];
        if (preg_match('/^\s*(?:re:\s*)*(?:fw|fwd)\s*:/i', (string) $subject)) {
            foreach (array_slice($parts, 1) as $part) {
                if (trim($part) !== '') {
                    $newest = $part; // the message you forwarded, up to the one it quotes

                    break;
                }
            }
        }

        return trim($newest) !== '' ? trim($newest) : trim($body);
    }

    /** The sentence (or line) around a position, as it is in the text. */
    private static function sentence(string $text, int $at): string
    {
        $before = substr($text, 0, $at);
        $start = max(
            ($p = strrpos($before, "\n")) === false ? 0 : $p + 1,
            preg_match_all('/[.!?]\s/', $before, $m, PREG_OFFSET_CAPTURE) ? end($m[0])[1] + 2 : 0,
        );
        $end = strlen($text);
        if (preg_match('/[.!?](?=\s|$)|\n/', $text, $m, PREG_OFFSET_CAPTURE, $at)) {
            $end = $m[0][1] + ($m[0][0] === "\n" ? 0 : 1);
        }
        $s = trim(substr($text, $start, $end - $start));
        if (mb_strlen($s) > 300) {
            $s = trim(mb_strcut($text, max($start, $at - 120), 280));
        }

        return preg_replace('/^Subject:\s*/i', '', $s) ?? $s;
    }

    /**
     * The days it's about, from the subject and the newest message: "12-14 March", "Thurs 12th - Sat 14th March",
     * "30 March - 2 April", "12th & 13th March", "12, 13 and 14 March", "March 12-14", "14/03/2027" (DateWords, which
     * also checks quotes, so the two agree). A date with no year is the next one on or after the email was sent.
     * Each comes with the sentence it's in, checked against the email. Dates spread over more than three months are
     * taken as several things at once and left out.
     *
     * @return list<array{date: string, quote: string, verified: bool}>
     */
    public static function dates(string $where, string $text, CarbonImmutable $sentAt): array
    {
        $lines = implode("\n", array_filter(preg_split('/\R/', $where) ?: [], fn ($l) => ! preg_match('/^\s*(?:From|Sent|Date|To|Cc|Received):/i', $l)));
        $found = [];
        foreach (DateWords::spans($lines) as $span) {
            if (preg_match('/\b(?:by|before|no later than|deadline|reply|respond|answer)\b(?:\s+(?:the|this|next|mon|tue|wed|thu|fri|sat|sun)[a-z]*,?)*\s*$/i', substr($lines, max(0, $span['at'] - 40), min(40, $span['at'])))) {
                continue; // the date to answer by ("let me know by Friday 26 February"), not a day of the work
            }
            $quote = self::sentence($lines, $span['at']);
            foreach ($span['parts'] as [$from, $to]) {
                $start = self::day($from, $sentAt);
                $end = $start ? self::day($to, $start, true) : null;
                if (! $start || ! $end || $end->lt($start) || $start->diffInDays($end) > 31) {
                    continue;
                }
                $found[] = ['from' => $start, 'to' => $end, 'quote' => $quote];
            }
        }

        $days = [];
        foreach ($found as $f) {
            for ($d = $f['from']; $d->lte($f['to']); $d = $d->addDay()) {
                $date = $d->format('Y-m-d');
                $days[$date] ??= ['date' => $date, 'quote' => $f['quote'],
                    'verified' => Grounding::quoteFound($text, $f['quote']) && Opportunities::dateCovered($date, $f['quote'])];
            }
        }
        ksort($days);
        if (! $days || count($days) > 62 || CarbonImmutable::parse(array_key_first($days))->diffInDays(CarbonImmutable::parse(array_key_last($days))) > 92) {
            return [];
        }

        return array_values($days);
    }

    /** @param array{0: int, 1: ?int, 2: ?int} $d day, month, year (null: the next one on or after $after) */
    private static function day(array $d, CarbonImmutable $after, bool $onOrAfter = false): ?CarbonImmutable
    {
        [$day, $month, $year] = $d;
        if (! $month || $day < 1 || ! checkdate($month, $day, (int) ($year ?? 2000))) {
            return null;
        }
        if ($year !== null) {
            return checkdate($month, $day, (int) $year) ? CarbonImmutable::create((int) $year, $month, $day)?->startOfDay() : null;
        }
        $y = (int) $after->format('Y');
        $date = checkdate($month, $day, $y) ? CarbonImmutable::create($y, $month, $day) : null;
        // A week's grace before the email (it may be about this weekend, sent on Monday); the end of a range comes
        // after its start.
        if (! $date || $date->lt($onOrAfter ? $after : $after->subDays(7))) {
            $date = checkdate($month, $day, $y + 1) ? CarbonImmutable::create($y + 1, $month, $day) : null;
        }

        return $date?->startOfDay();
    }

    /** An email's subject as a title, without "Re:" and "Fwd:". */
    private static function subjectTitle(?string $subject): ?string
    {
        $s = trim((string) $subject);
        do {
            $was = $s;
            $s = preg_replace('/^\s*(?:re|fw|fwd|aw|wg|tr|sv)\s*(?:\[\d+\]|\(\d+\))?\s*:\s*/iu', '', $s) ?? $s;
            $s = preg_replace('/^\s*\[(?:external|ext)\]\s*/iu', '', $s) ?? $s;
        } while ($s !== $was);

        return $s !== '' ? mb_substr($s, 0, 150) : null;
    }
}
