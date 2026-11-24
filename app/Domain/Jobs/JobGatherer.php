<?php

namespace App\Domain\Jobs;

use App\Domain\Audit\AuditLog;
use App\Domain\Gmail\GmailClient;
use App\Domain\Intel\AuditContext;
use App\Domain\Ledger\AllocationWriter;
use App\Domain\Matching\Categoriser;
use App\Domain\Matching\OwnAccounts;
use App\Domain\Matching\Suggester;
use App\Domain\Matching\TransferPairer;
use App\Domain\Travel\TravelMatcher;
use App\Domain\Verification\Verifier;
use App\Jobs\GatherFromGmail;
use App\Models\Allocation;
use App\Models\BankTransaction;
use App\Models\CalendarEvent;
use App\Models\CalendarJobLink;
use App\Models\Category;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\JobDay;
use App\Models\JobFind;
use App\Models\TaxYear;
use App\Models\TravelItem;
use App\Models\WorkJob;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Once you say something is a job, find everything that belongs to it: its other calendar days, the PO and
 * production documents (already in Expend, then searched for in Gmail), payments made during it, stays and
 * journeys, invoices quoting its PO, and the money that looks like its fee.
 *
 * What has clear evidence is linked (as a suggestion where it's a payment, for you to confirm); what might belong
 * is listed on the job for you to link or reject. Nothing you decided is changed, and anything you reject isn't
 * suggested again.
 */
final class JobGatherer
{
    /** @var array<string, array> type:ref => find */
    private array $found = [];

    /** @var array<string, true> */
    private array $rejected = [];

    /**
     * Places that say nothing about which job a document or email is for ("London", "Home", "TBC"): searching for one
     * brings in every email that mentions it, and naming one doesn't make a document the job's.
     */
    public const VAGUE_PLACES = ['home', 'tbc', 'tba', 'tbd', 'online', 'remote', 'various', 'office', 'studio', 'venue', 'uk', 'united kingdom',
        'england', 'scotland', 'wales', 'london', 'manchester', 'birmingham', 'leeds', 'glasgow', 'edinburgh', 'liverpool', 'bristol', 'cardiff',
        'belfast', 'newcastle', 'sheffield', 'nottingham'];

    public function __construct(private JobFinder $finder, private AllocationWriter $writer, private Suggester $suggester) {}

    /**
     * Find and link what belongs to the job. However it was started (a new job, "Search again", the Gmail search
     * that follows), what it links, suggests and takes back on its own is recorded as the matcher's, never as yours.
     *
     * @return array{linked:int, possible:int, gmail:string, summary:string}
     */
    public function gather(WorkJob $job, bool $searchGmail = true): array
    {
        return AuditContext::with('matcher', fn () => $this->gatherNow($job, $searchGmail));
    }

    /** @return array{linked:int, possible:int, gmail:string, summary:string} */
    private function gatherNow(WorkJob $job, bool $searchGmail): array
    {
        $job = $job->fresh(['client', 'days']); // its days are read for every item checked: loaded once
        OwnAccounts::flush();
        $this->found = [];
        $this->rejected = JobFind::query()->where('work_job_id', $job->id)->where('status', 'rejected')->get()
            ->mapWithKeys(fn (JobFind $f) => [$f->type.':'.$f->ref => true])->all();

        try {
            $this->recheckOffDays($job);
        } catch (Throwable $e) {
            report($e);
        }
        foreach (['calendar', 'documents', 'payments', 'income'] as $step) {
            try {
                $this->{$step}($job);
            } catch (Throwable $e) {
                report($e); // one part failing doesn't stop the rest
            }
        }
        try {
            $this->finder->followUp($job);
        } catch (Throwable $e) {
            report($e);
        }
        $this->travelAndInvoices($job);
        $this->save($job);

        $gmail = 'off';
        if ($searchGmail && GmailClient::connected() && self::gmailQuery($job)) {
            $gmail = 'queued';
            GatherFromGmail::dispatch($job->id);
        } elseif ($searchGmail && GmailClient::configured() && ! GmailClient::connected()) {
            $gmail = 'not_connected';
        }
        $state = (array) ($job->gather_state ?? []);
        if ($searchGmail) {
            $state['gmail'] = $gmail;
        }
        $job->forceFill(['gathered_at' => now(), 'gather_state' => $state])->save();

        $linked = collect($this->found)->where('status', 'linked')->count();
        $possible = collect($this->found)->where('status', 'possible')->count();

        return ['linked' => $linked, 'possible' => $possible, 'gmail' => $gmail, 'summary' => self::summary($linked, $possible, $gmail)];
    }

    public static function summary(int $linked, int $possible, string $gmail): string
    {
        $parts = [];
        $parts[] = $linked ? "linked {$linked} ".($linked === 1 ? 'item' : 'items') : 'found nothing certain to link';
        if ($possible) {
            $parts[] = "{$possible} more may belong: check them on the job";
        }
        $text = 'Searched for this job’s documents, POs, payments and calendar days: '.implode('; ', $parts).'.';

        return $text.match ($gmail) {
            'queued' => ' Searching Gmail for its emails and POs now; anything found appears on the job in a minute or two.',
            'not_connected' => ' Gmail isn’t connected, so it wasn’t searched.',
            default => '',
        };
    }

    /** The Gmail search for a job: its client, venue, PO number or title, from four months before to six weeks after. */
    public static function gmailQuery(WorkJob $job): ?string
    {
        $terms = [];
        $quote = fn (string $t) => '"'.str_replace('"', '', trim($t)).'"';
        if ($job->po_number && mb_strlen(trim($job->po_number)) >= 4) {
            $terms[] = $quote($job->po_number);
        }
        if ($job->client?->name && mb_strlen(trim($job->client->name)) >= 3) {
            $terms[] = $quote(trim(preg_replace('/\b(ltd|limited|plc|llp)\b\.?/i', '', $job->client->name)));
        }
        // A place that says nothing about the job ("London", "Home", "TBC") would bring in every email that mentions it.
        $place = trim(explode(',', (string) $job->location)[0]);
        if (mb_strlen($place) >= 4 && ! self::vaguePlace($job->location)) {
            $terms[] = $quote(mb_substr($place, 0, 60));
        }
        $titleWords = preg_split('/\s+/', trim($job->title)) ?: [];
        if (count($titleWords) >= 1 && count($titleWords) <= 6 && mb_strlen($job->title) >= 5) {
            $terms[] = $quote($job->title);
        }
        $terms = array_values(array_unique(array_filter($terms, fn ($t) => mb_strlen($t) > 4)));
        if (! $terms) {
            return null;
        }
        $after = $job->starts_on->copy()->subMonths(4)->format('Y/m/d');
        $before = $job->ends_on->copy()->addWeeks(6)->format('Y/m/d');

        return '('.implode(' OR ', $terms).") after:{$after} before:{$before} -in:chats -in:drafts -in:spam";
    }

    // ---------------------------------------------------------------- steps

    /**
     * Its other days in the calendar. The same entry on one of the job's days is linked; on another day within a week,
     * it's suggested as another day of the job. "Rig - Acme Awards", "Show - Acme Awards" and "De-rig - Acme Awards"
     * count as the same entry (the words for which day it is are left out). An entry naming the job's client, venue,
     * postcode or PO within a week of it is suggested too.
     */
    private function calendar(WorkJob $job): void
    {
        $from = CarbonImmutable::parse($job->starts_on->format('Y-m-d'));
        $to = CarbonImmutable::parse($job->ends_on->format('Y-m-d'));
        $events = CalendarEvent::query()->whereBetween('starts_at', [$from->subDays(8)->format('Y-m-d 00:00:00'), $to->addDays(8)->format('Y-m-d 23:59:59')])->get()
            ->filter(fn (CalendarEvent $e) => $e->day() >= $from->subDays(7)->format('Y-m-d') && $e->day() <= $to->addDays(7)->format('Y-m-d'));
        $links = CalendarJobLink::query()->whereIn('event_key', $events->map->key())->get()->keyBy('event_key');
        $titles = $events->filter(fn (CalendarEvent $e) => ($links[$e->key()] ?? null)?->work_job_id === $job->id)
            ->map(fn ($e) => JobDays::baseTitle($e->summary))->filter(fn ($t) => mb_strlen($t) >= 4)->unique()->all();

        foreach ($events as $e) {
            $link = $links[$e->key()] ?? null;
            $ref = $e->key().'|'.$e->day();
            if ($link?->work_job_id || $link?->not_job || in_array($job->id, $link?->rejected ?? [], true) || isset($this->rejected['calendar:'.$ref])) {
                continue;
            }
            $onDay = $job->worksOn($e->day());
            $same = $titles && in_array(JobDays::baseTitle($e->summary), $titles, true);
            $kind = JobDays::kindFrom((string) $e->summary);
            $which = $kind !== 'work' ? ' (the '.mb_strtolower(JobDay::KINDS[$kind]).')' : '';
            $label = ($e->summary ?: 'Calendar entry').' · '.CarbonImmutable::parse($e->day())->format('D j M');
            if ($same && $onDay) {
                $this->finder->linkCalendar($e, $job, 'rules', 'The same entry as this job’s other days');
                $this->add('calendar', $ref, 'linked', $label, 'The same entry as this job’s other days', $e->day());
            } elseif ($same) {
                $this->add('calendar', $ref, 'possible', $label, 'The same entry on a day that isn’t one of the job’s days yet: another day of it'.$which.'? Linking it adds the day.', $e->day());
            } elseif ($named = $this->mentionsJob($e->text(), $job)) {
                $this->add('calendar', $ref, 'possible', $label, $onDay
                    ? 'On one of the job’s days and mentions '.$named
                    : 'Mentions '.$named.', within a week of the job: another day of it'.$which.'? Linking it adds the day.', $e->day());
            }
        }
    }

    /**
     * POs, production packs, call sheets and emails already in Expend. One is linked when it quotes the job's PO
     * number, or when its content (an email's subject and body, a document's text) gives one of the job's days and
     * names its client, venue, postcode or title. The date an email was sent, in its own or a quoted email's headers,
     * doesn't count, and nor does a venue that could be anywhere ("London"). One that names the job in a weaker way,
     * or also quotes another job's PO, is listed for you to link or reject.
     */
    private function documents(WorkJob $job): void
    {
        $from = $job->starts_on->copy()->subMonths(9)->format('Y-m-d 00:00:00');
        $to = $job->ends_on->copy()->addMonths(4)->format('Y-m-d 23:59:59');
        $jobPo = JobFinder::normalisePo($job->po_number);
        $otherPos = WorkJob::query()->whereKeyNot($job->id)->where('status', '!=', 'cancelled')->whereNotNull('po_number')->pluck('po_number')
            ->filter(fn ($p) => ($n = JobFinder::normalisePo($p)) && $n !== $jobPo && strlen($n) >= 4)->unique()->values();
        Document::query()->whereNull('parent_id')->whereNull('work_job_id')->where('status', '!=', 'held')
            ->where(fn ($q) => $q->whereNull('job_linked_by')->orWhere('job_linked_by', '!=', 'you'))
            ->where(fn ($q) => $q->whereNull('received_at')->orWhereBetween('received_at', [$from, $to]))
            ->with('children:id,parent_id,text')
            ->select(['id', 'kind', 'original_name', 'email_subject', 'email_from', 'text', 'extraction', 'received_at', 'job_rejected', 'job_linked_by', 'work_job_id', 'parent_id'])
            ->chunkById(150, function (Collection $docs) use ($job, $jobPo, $otherPos) {
                foreach ($docs as $doc) {
                    if (in_array($job->id, $doc->job_rejected ?? [], true) || isset($this->rejected['document:'.$doc->id])) {
                        continue;
                    }
                    $attached = $doc->children->pluck('text')->filter()->implode("\n");
                    $text = trim($doc->email_subject."\n".$doc->text."\n".$attached);
                    if ($text === '') {
                        continue;
                    }
                    $ev = Verifier::jobEvidence($text, $job);
                    $label = $doc->email_subject ?: $doc->original_name;
                    $date = $doc->received_at?->format('Y-m-d');
                    if ($ev['ok'] && $ev['why'] === ['the job’s PO number is in it']) {
                        $this->finder->linkDocument($doc, $job, 'rules');
                        $this->add('document', (string) $doc->id, 'linked', $label, 'Linked because the job’s PO number is in it', $date);

                        continue;
                    }
                    // Its own words, without the email headers: the date it was sent isn't the date of the job. An email's
                    // subject is what it's about ("Call sheet - Acme Awards - Wed 14 May"), so it counts.
                    $content = trim($doc->email_subject."\n".self::withoutHeaders((string) $doc->text)."\n".$attached);
                    $dated = collect(array_slice($job->workDates(), 0, 31))->first(fn ($d) => Verifier::dateInText($content, $d));
                    $named = $this->namesJob($content, $job);
                    // Another job's PO in it (or, read from it, a PO that isn't this job's): it may be that job's. Only
                    // asked when it gives the job's date, the one case it can stop a link.
                    $read = $doc->extraction['po']['po_number'] ?? null;
                    $readPo = JobFinder::normalisePo($read);
                    $other = $dated ? ($otherPos->first(fn ($p) => Verifier::poInText($text, $p))
                        ?? ($readPo && $readPo !== $jobPo && ($jobPo || $otherPos->contains(fn ($p) => JobFinder::normalisePo($p) === $readPo)) ? $read : null)) : null;
                    $weak = collect($ev['why'])->first(fn ($w) => str_starts_with($w, 'it mentions '));
                    $mentions = $named ?? ($weak ? mb_substr($weak, mb_strlen('it mentions ')) : null);
                    if ($dated && $named && ! $other) {
                        $this->finder->linkDocument($doc, $job, 'rules');
                        $this->add('document', (string) $doc->id, 'linked', $label, 'Linked because it gives '.CarbonImmutable::parse($dated)->format('j M Y').' and it mentions '.$named, $date);
                    } elseif ($mentions) {
                        $this->add('document', (string) $doc->id, 'possible', $label, match (true) {
                            $dated && $other !== null => 'It mentions '.$mentions.' and gives '.CarbonImmutable::parse($dated)->format('j M Y').', but it also quotes another PO ('.$other.')',
                            (bool) $dated => 'It gives '.CarbonImmutable::parse($dated)->format('j M Y').' and mentions '.$mentions.', but not the job’s client, exact venue or PO',
                            default => 'It mentions '.$mentions.', but not the job’s dates or PO',
                        }, $date);
                    }
                }
            });
    }

    /** Payments made during the job (and the day either side): costs it may have had. Transfers are left out. */
    private function payments(WorkJob $job): void
    {
        $from = $job->starts_on->copy()->subDay()->format('Y-m-d');
        $to = $job->ends_on->copy()->addDay()->format('Y-m-d');
        // Its days and the day either side of each (travel there and back), not the days between a rig and a de-rig.
        $dates = collect($job->workDates())->flatMap(fn ($d) => [CarbonImmutable::parse($d)->subDay()->format('Y-m-d'), $d, CarbonImmutable::parse($d)->addDay()->format('Y-m-d')])->unique()->flip();
        $locked = TaxYear::query()->whereNotNull('locked_at')->pluck('label')->all();
        // A payment with a part that's spending: a hotel paid together with a top-up is still found (its transfer
        // part isn't the job's), but a transfer with only its fee split off isn't a job's cost.
        $txs = OwnAccounts::scopeWithSpendingLines(BankTransaction::query())->with(['allocations.category', 'account'])
            ->where('amount_pence', '<', 0)->whereNotIn('tax_year', $locked)
            ->whereBetween('posted_on', [$from, $job->ends_on->copy()->addDays(3)->format('Y-m-d')])
            ->orderBy('posted_on')->limit(300)->get()
            ->filter(fn (BankTransaction $t) => isset($dates[$t->effectiveDate()->format('Y-m-d')]) && ! TransferPairer::movesMoney($t->allocations));
        // Days another job works, and the days either side of them: a cost then may be that job's, so it's only
        // given to this one automatically on a day that's this job's alone.
        $around = $dates->keys()->flatMap(fn ($d) => [CarbonImmutable::parse($d)->subDay()->format('Y-m-d'), $d, CarbonImmutable::parse($d)->addDay()->format('Y-m-d')])->unique()->values()->all();
        $busy = JobDay::query()->whereIn('on_date', $around)->where('work_job_id', '!=', $job->id)
            ->whereHas('job', fn ($q) => $q->where('status', '!=', 'cancelled'))->pluck('on_date')->map(fn ($d) => substr($d instanceof \DateTimeInterface ? $d->format('Y-m-d') : (string) $d, 0, 10))->flip();
        $nearOther = $busy->keys()->flatMap(fn ($d) => [CarbonImmutable::parse($d)->subDay()->format('Y-m-d'), $d, CarbonImmutable::parse($d)->addDay()->format('Y-m-d')])->flip();

        foreach ($txs as $tx) {
            $ref = (string) $tx->id;
            $a = $tx->allocations;
            // Your own money moving is left out, unless you've decided it as something else (a phone top-up you
            // decided as a phone bill is a cost like any other).
            $yours = $a->contains(fn (Allocation $x) => $x->decided_by === 'you' && $x->kind !== 'transfer');
            if (isset($this->rejected['payment:'.$ref]) || (! $yours && OwnAccounts::reason($tx))) {
                continue;
            }
            $mine = fn () => $tx->fresh('allocations')->allocations->contains(fn (Allocation $x) => $x->work_job_id === $job->id);
            $label = ($tx->counterparty ?: $tx->description).' · '.$tx->effectiveDate()->format('D j M');
            $onDay = $job->worksOn($tx->effectiveDate()->format('Y-m-d'));
            $automatic = $a->isNotEmpty() && $a->every(fn (Allocation $x) => $x->confirmed_at === null && ! $x->is_manual && in_array($x->decided_by, ['matcher', 'ai'], true));

            if ($a->contains(fn (Allocation $x) => $x->work_job_id !== null)) {
                continue; // already a job's (this one or another)
            }
            try {
                if ($a->isEmpty() || ($automatic && $a->every(fn (Allocation $x) => $x->kind === 'personal'))) {
                    // Undecided, or guessed personal before the job existed: suggest again now there's a job that day.
                    if ($a->isNotEmpty()) {
                        $this->writer->clear($tx, 'Looked at again: there’s now a job that day', 'matcher');
                    }
                    $this->suggester->suggestFor(collect([$tx->fresh()]));
                    if ($mine()) {
                        $this->add('payment', $ref, 'linked', $label, 'Suggested as this job’s cost (confirm it in the Inbox)', $tx->effectiveDate()->format('Y-m-d'), $tx->amount_pence);
                    } elseif ($tx->fresh('allocations')->allocations->isEmpty()) {
                        $this->add('payment', $ref, 'possible', $label, ($onDay ? 'Paid on one of the job’s days' : 'Paid the day before or after one of the job’s days').' and not decided yet: was it for it?', $tx->effectiveDate()->format('Y-m-d'), $tx->amount_pence);
                    }
                } elseif ($a->contains('kind', 'business_expense')) {
                    $day = $tx->effectiveDate()->format('Y-m-d');
                    $mineAlone = $onDay ? ! isset($busy[$day]) : ! isset($nearOther[$day]);
                    // Only a job's own costs (kit, crew, travel) go with it on its days: an overhead (software, phone,
                    // insurance, bank charges) paid then is only listed.
                    $jobCost = $a->where('kind', 'business_expense')->every(fn (Allocation $x) => in_array($x->category?->code, Categoriser::JOB_COSTS, true));
                    if ($automatic && $mineAlone && $jobCost) {
                        $lines = $a->map(fn (Allocation $x) => self::line($x, $x->kind === 'business_expense' ? $job->id : null))->all();
                        $this->writer->decide($tx, $lines, 'matcher', false, 'A business cost on '.$job->title.'’s dates');
                        $this->add('payment', $ref, 'linked', $label, 'A business cost on the job’s dates: suggested as its cost (confirm it in the Inbox)', $day, $tx->amount_pence);
                    } else {
                        $this->add('payment', $ref, 'possible', $label, match (true) {
                            ! $jobCost => 'An overhead paid on the job’s dates (software, phone, insurance, bank charges…): link it only if it was for this job',
                            isset($busy[$day]) => 'A business cost with no job, paid on a day you also worked another job: was it for this one?',
                            ! $mineAlone => 'A business cost with no job, paid the day before or after another job’s day too: was it for this one?',
                            default => 'A business cost on the job’s dates with no job: link it?',
                        }, $day, $tx->amount_pence);
                    }
                }
            } catch (InvalidArgumentException) {
                // locked or protected since loaded
            }
        }
    }

    /** Money in that looks like the fee: the agreed amount, or from the client, up to four months after. */
    private function income(WorkJob $job): void
    {
        $words = collect(preg_split('/\s+/', mb_strtoupper((string) $job->client?->name)) ?: [])
            ->reject(fn ($w) => mb_strlen($w) < 3 || in_array($w, ['LTD', 'LIMITED', 'THE', 'AND', 'PLC', 'LLP', 'EVENTS', 'PRODUCTIONS', 'GROUP', 'UK'], true))->values();
        if (! $job->fee_pence && $words->isEmpty()) {
            return;
        }
        $rows = OwnAccounts::scopeNotTransfers(BankTransaction::query())->with(['allocations', 'account'])->where('amount_pence', '>', 0)
            ->whereBetween('posted_on', [$job->starts_on->copy()->subDays(30)->format('Y-m-d'), $job->ends_on->copy()->addDays(120)->format('Y-m-d')])
            ->where(function ($q) use ($job, $words) {
                if ($job->fee_pence) {
                    $q->orWhere('amount_pence', $job->fee_pence);
                }
                foreach ($words as $w) {
                    $q->orWhere('counterparty', 'like', '%'.addcslashes($w, '%_\\').'%')->orWhere('description', 'like', '%'.addcslashes($w, '%_\\').'%');
                }
            })->limit(30)->get();
        foreach ($rows as $tx) {
            if (isset($this->rejected['income:'.$tx->id]) || $tx->allocations->contains(fn (Allocation $a) => $a->work_job_id !== null)
                || $tx->allocations->contains(fn (Allocation $a) => $a->kind !== 'business_income')) {
                continue;
            }
            // Your own money paid in (your name, a top-up, a card bill) is never a job's fee, whatever its amount,
            // unless you've decided it as income yourself.
            if (OwnAccounts::reason($tx) && ! $tx->allocations->contains(fn (Allocation $a) => $a->decided_by === 'you')) {
                continue;
            }
            $why = array_filter([
                $job->fee_pence && $tx->amount_pence === $job->fee_pence ? 'it’s the agreed fee ('.Money::format($job->fee_pence).')' : null,
                $words->contains(fn ($w) => str_contains(mb_strtoupper($tx->description.' '.$tx->counterparty), $w)) ? 'it’s from '.$job->client?->name : null,
            ]);
            $this->add('income', (string) $tx->id, 'possible', ($tx->counterparty ?: $tx->description).' · '.$tx->effectiveDate()->format('j M Y'),
                'Looks like payment for this job: '.implode(' and ', $why), $tx->effectiveDate()->format('Y-m-d'), $tx->amount_pence);
        }
    }

    /** Stays and journeys, and invoices quoting the PO, that the follow-up linked. A void invoice no longer counts, so isn't listed. */
    private function travelAndInvoices(WorkJob $job): void
    {
        foreach (TravelItem::query()->where('work_job_id', $job->id)->where('job_linked_by', '!=', 'you')->get() as $t) {
            $this->add('travel', (string) $t->id, 'linked', (TravelItem::KINDS[$t->kind] ?? $t->kind).': '.($t->kind === 'hotel' ? ($t->place ?: $t->provider) : $t->provider),
                'On the job’s dates', $t->starts_on->format('Y-m-d'), $t->amount_minor ? -$t->amount_minor : null, $t->currency);
        }
        foreach (Invoice::query()->where('work_job_id', $job->id)->whereNotNull('po_number')->where('status', '!=', 'void')->get() as $i) {
            $this->add('invoice', (string) $i->id, 'linked', 'Invoice '.($i->number ?? 'draft'), 'Quotes the job’s PO number', $i->issue_date?->format('Y-m-d'), $i->total_minor, $i->currency);
        }
    }

    // ---------------------------------------------------------------- you decide

    /**
     * Link something the search thought may belong. What it found is checked again first: if you've since put it on
     * another job, or decided it as something else, it's left as you have it (and the out-of-date find goes).
     */
    public function accept(JobFind $find): string
    {
        $job = $find->job()->with('client')->firstOrFail();
        $message = match ($find->type) {
            'calendar' => $this->linkEvent($find, $job),
            'document' => $this->linkDoc($find, $job),
            'payment' => $this->linkPayment($find, $job),
            'income' => $this->linkIncome($find, $job),
            default => throw new InvalidArgumentException('That can’t be linked from here.'),
        };
        $find->update(['status' => 'linked', 'detail' => mb_substr('Linked by you. '.$find->detail, 0, 500)]);

        return $message;
    }

    /**
     * "Not this job": undo a link, or reject a suggestion, so no search links it to this job again. It acts on what is
     * true now, not when it was found: something you've since put on another job stays there (only "not this job" is
     * remembered), and an issued invoice is never changed.
     */
    public function reject(JobFind $find): string
    {
        $job = $find->job;

        return DB::transaction(function () use ($find, $job) {
            $message = match ($find->type) {
                'calendar' => $this->rejectEvent($find, $job),
                'document' => ($d = Document::query()->find((int) $find->ref)) ? $this->finder->rejectDocumentFor($d, $job) : null,
                'payment', 'income' => $this->unlinkPayment((int) $find->ref, $job),
                'travel' => ($t = TravelItem::query()->find((int) $find->ref)) && $t->work_job_id === $job->id ? $this->finder->linkTravel($t, null, 'you') : null,
                'invoice' => $this->unlinkInvoice((int) $find->ref, $job),
                default => null,
            };
            $find->update(['status' => 'rejected']);
            AuditLog::record('job.find_rejected', $job, null, ['type' => $find->type, 'ref' => $find->ref], 'Not this job');

            return is_string($message) ? $message : 'OK: not part of '.$job->title.'. It won’t be suggested for it again.';
        });
    }

    private function linkEvent(JobFind $find, WorkJob $job): string
    {
        $e = $this->event($find) ?? throw new InvalidArgumentException('That calendar entry has gone.');
        $link = CalendarJobLink::query()->where('event_key', $e->key())->first();
        if ($link?->linked_by === 'you' && $link->work_job_id && $link->work_job_id !== $job->id) {
            $this->outOfDate($find, 'You’ve put that entry on '.(WorkJob::query()->find($link->work_job_id)?->title ?? 'another job').' since, so it’s left there. To move it, use the calendar.');
        }
        if ($link?->linked_by === 'you' && $link->not_job) {
            $this->outOfDate($find, 'You’ve marked that entry “not a job” since, so it’s left as it is. Undo that on the calendar first.');
        }
        $this->finder->linkCalendar($e, $job, 'you', 'Linked by you');

        return 'Linked the calendar entry and added its day to the job.';
    }

    private function linkDoc(JobFind $find, WorkJob $job): string
    {
        $doc = Document::query()->findOrFail((int) $find->ref);
        if ($doc->job_linked_by === 'you' && $doc->work_job_id && $doc->work_job_id !== $job->id) {
            $this->outOfDate($find, 'You’ve put that document on '.(WorkJob::query()->find($doc->work_job_id)?->title ?? 'another job').' since, so it’s left there. Open it to move it.');
        }
        $this->finder->linkDocument($doc, $job, 'you');

        return 'Linked the document.';
    }

    private function linkPayment(JobFind $find, WorkJob $job): string
    {
        $tx = BankTransaction::query()->with('allocations')->findOrFail((int) $find->ref);
        if ($other = $tx->allocations->first(fn (Allocation $a) => $a->work_job_id !== null && $a->work_job_id !== $job->id)) {
            $this->outOfDate($find, 'That payment is on '.(WorkJob::query()->find($other->work_job_id)?->title ?? 'another job').' now, so it’s left there. Open it to move it.');
        }
        if (! $tx->allocations->contains('kind', 'business_expense')) {
            throw new InvalidArgumentException('Decide what this payment is first (open it), then it can go on the job.');
        }
        $this->writer->decide($tx, $tx->allocations->map(fn (Allocation $a) => self::line($a, $a->kind === 'business_expense' ? $job->id : $a->work_job_id))->all(), 'you', true, 'Linked to '.$job->title);

        return 'Added to the job’s costs.';
    }

    /**
     * Money in as this job's income. Undecided: the whole amount is the job's turnover. Already decided as business
     * income: those lines (and their amounts) are kept and only the job is added. Decided as anything else since it
     * was found (a split, a transfer, a loan), or on another job: left as it is.
     */
    private function linkIncome(JobFind $find, WorkJob $job): string
    {
        $tx = BankTransaction::query()->with('allocations')->findOrFail((int) $find->ref);
        $a = $tx->allocations;
        if ($other = $a->first(fn (Allocation $x) => $x->work_job_id !== null && $x->work_job_id !== $job->id)) {
            $this->outOfDate($find, 'That payment is on '.(WorkJob::query()->find($other->work_job_id)?->title ?? 'another job').' now, so it’s left there. Open it to move it.');
        }
        if ($a->isNotEmpty() && ! $a->every(fn (Allocation $x) => $x->kind === 'business_income')) {
            $this->outOfDate($find, 'That payment has been decided as something else since it was found, so it’s left as it is. Open it to change it.');
        }
        $turnover = Category::query()->where('code', 'turnover')->value('id');
        $lines = $a->isNotEmpty()
            ? $a->map(fn (Allocation $x) => self::line($x, $job->id))->all()
            : AllocationWriter::lines($tx->amount_pence, 'business_income', $turnover, $job->id);
        $this->writer->decide($tx, $lines, 'you', true, 'Payment for '.$job->title);

        return 'Recorded as income for this job.';
    }

    /** A find whose item has changed since: it goes from the job's list (the next search finds it again if it still fits). */
    private function outOfDate(JobFind $find, string $message): never
    {
        $find->delete();

        throw new InvalidArgumentException($message);
    }

    private function unlinkPayment(int $id, WorkJob $job): void
    {
        $tx = BankTransaction::query()->with('allocations')->find($id);
        if (! $tx || ! $tx->allocations->contains('work_job_id', $job->id)) {
            return;
        }
        $automatic = $tx->allocations->every(fn (Allocation $a) => $a->confirmed_at === null && ! $a->is_manual);
        $lines = $tx->allocations->map(fn (Allocation $a) => self::line($a, $a->work_job_id === $job->id ? null : $a->work_job_id))->all();
        $this->writer->decide($tx, $lines, $automatic ? 'matcher' : 'you', ! $automatic, 'Not for '.$job->title);
    }

    /** A calendar entry isn't this job's: unlinked if it's on it, otherwise remembered, wherever the entry is now. */
    private function rejectEvent(JobFind $find, WorkJob $job): ?string
    {
        if ($e = $this->event($find)) {
            $this->finder->rejectCalendarFor($e, $job->id);

            return null;
        }
        // Gone from your calendar since: its link is updated by its key.
        $this->finder->rejectCalendarKeyFor(explode('|', $find->ref, 2)[0], $job);

        return 'OK: not part of '.$job->title.'. (That entry is no longer in your calendar.)';
    }

    /**
     * An invoice the search linked by its PO: a draft comes off the job (and isn't linked to it again). An issued or
     * paid one can't be changed; a void one stays on the job's record and is only taken off its list.
     */
    private function unlinkInvoice(int $id, WorkJob $job): ?string
    {
        $invoice = Invoice::query()->whereKey($id)->where('work_job_id', $job->id)->first();
        if (! $invoice) {
            return null;
        }
        $name = 'Invoice '.($invoice->number ?? 'draft');
        if ($invoice->status === 'void') {
            return 'OK: '.$name.' is void, so it stays on '.$job->title.'’s record but no longer counts. It won’t be listed here again.';
        }
        if ($invoice->status === 'paid') {
            throw new InvalidArgumentException($name.' has been paid, so it stays with '.$job->title.': a paid invoice can’t be changed.');
        }
        if ($invoice->status !== 'draft') {
            throw new InvalidArgumentException($name.' has been issued, so it stays with '.$job->title.'. To put it on another job, void it and issue a new one.');
        }
        $invoice->update(['work_job_id' => null]);
        AuditLog::record('invoice.job_unlinked', $invoice, ['work_job_id' => $job->id], ['work_job_id' => null], 'Not part of '.$job->title);

        return null;
    }

    private function event(JobFind $find): ?CalendarEvent
    {
        [$key, $date] = explode('|', $find->ref, 2) + [null, null];

        // By its key wherever it has moved to; the date only narrows the search for entries loaded before keys were kept.
        return $key ? JobFinder::event($key, $date ?: null) : null;
    }

    /**
     * Days a job no longer has (taken off it, or between a rig and a de-rig): automatic, unconfirmed suggestions that
     * put a cost paid then on the job are made again, and stays the search linked to it are lined up again. Calendar
     * entries the search linked because they were on a day just taken off come off the job too. A cost you decided or
     * confirmed, one a rule of yours decided, a stay's own payment and an entry you linked are left alone.
     *
     * @param  list<string>  $removed  dates just taken off the job
     * @return int how many payments were looked at again
     */
    public function recheckOffDays(WorkJob $job, array $removed = []): int
    {
        $job = $job->relationLoaded('days') ? $job : $job->fresh('days');
        if (! $job) {
            return 0;
        }
        $this->unlinkOffDayEntries($job, $removed);
        $check = [];
        foreach ($removed as $d) {
            foreach ([-1, 0, 1] as $i) {
                $check[CarbonImmutable::parse($d)->addDays($i)->format('Y-m-d')] = true;
            }
        }
        for ($d = CarbonImmutable::parse($job->starts_on->format('Y-m-d')), $n = 0; $d->lte($job->ends_on) && $n < JobDays::MAX_DAYS; $d = $d->addDay(), $n++) {
            $check[$d->format('Y-m-d')] = true;
        }
        $check = array_filter($check, fn ($v, $d) => ! $job->isNear($d), ARRAY_FILTER_USE_BOTH);
        if ($check === []) {
            return 0;
        }

        $locked = TaxYear::query()->whereNotNull('locked_at')->pluck('label')->all();
        $stayPayments = TravelItem::query()->whereNotNull('bank_transaction_id')->select('bank_transaction_id');
        $txs = BankTransaction::query()->with('allocations')->where('amount_pence', '<', 0)->whereNotIn('tax_year', $locked)
            ->whereHas('allocations', fn ($q) => $q->where('work_job_id', $job->id))->whereNotIn('id', $stayPayments)->get()
            ->filter(fn (BankTransaction $t) => isset($check[$t->effectiveDate()->format('Y-m-d')]));
        $done = 0;
        foreach ($txs as $tx) {
            $automatic = $tx->allocations->every(fn (Allocation $a) => $a->confirmed_at === null && ! $a->is_manual && in_array($a->decided_by, ['matcher', 'ai'], true) && $a->invoice_id === null);
            if (! $automatic) {
                continue;
            }
            try {
                $this->writer->clear($tx, 'Looked at again: '.$tx->effectiveDate()->format('j M').' is no longer one of '.$job->title.'’s days', 'matcher');
                $this->suggester->suggestFor(collect([$tx->fresh()]));
                $done++;
            } catch (InvalidArgumentException) {
                continue; // locked since loaded
            }
            if (! Allocation::query()->where('bank_transaction_id', $tx->id)->where('work_job_id', $job->id)->exists()) {
                JobFind::query()->where('work_job_id', $job->id)->where('type', 'payment')->where('ref', (string) $tx->id)->where('status', 'linked')->delete();
            }
        }

        // Stays and journeys the search put on the job: on none of its days (or next to one), they come off it.
        foreach (TravelItem::query()->where('work_job_id', $job->id)->whereNull('decided_at')
            ->where(fn ($q) => $q->whereNull('job_linked_by')->orWhere('job_linked_by', '!=', 'you'))->get() as $item) {
            $dates = $item->kind === 'hotel' ? $item->nights() : [$item->starts_on->format('Y-m-d')];
            $near = collect($dates)->contains(fn ($d) => $job->worksOn($d) || ($item->kind === 'hotel' ? $job->worksOn(CarbonImmutable::parse($d)->addDay()->format('Y-m-d')) : $job->isNear($d)));
            try {
                if (! $near) {
                    $this->finder->linkTravel($item, null, 'rules');
                    JobFind::query()->where('work_job_id', $job->id)->where('type', 'travel')->where('ref', (string) $item->id)->where('status', 'linked')->delete();
                } elseif (collect($dates)->contains(fn ($d) => isset($check[$d]) || isset($check[CarbonImmutable::parse($d)->addDay()->format('Y-m-d')]))) {
                    app(TravelMatcher::class)->suggest($item->fresh());
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $done;
    }

    /**
     * Entries linked automatically (never by you) that were on a day just taken off the job, and are on none of its
     * days now: what they were linked for has gone, so they come off it (the next search may suggest them again).
     *
     * @param  list<string>  $removed
     */
    private function unlinkOffDayEntries(WorkJob $job, array $removed): void
    {
        if ($removed === []) {
            return;
        }
        $gone = array_flip($removed);
        foreach (CalendarJobLink::query()->where('work_job_id', $job->id)->where('linked_by', '!=', 'you')->get() as $link) {
            $e = JobFinder::event($link->event_key, $link->event_starts_at?->copy()->setTimezone(CalendarEvent::TZ)->format('Y-m-d'));
            if (! $e) {
                continue; // gone from your calendar: left as it is
            }
            $dates = [];
            for ($d = CarbonImmutable::parse($e->day()), $n = 0; $d->format('Y-m-d') <= JobDays::lastDay($e) && $n < 31; $d = $d->addDay(), $n++) {
                $dates[] = $d->format('Y-m-d');
            }
            $was = array_values(array_filter($dates, fn ($d) => isset($gone[$d])));
            if ($was === [] || collect($dates)->contains(fn ($d) => $job->worksOn($d))) {
                continue;
            }
            try {
                $this->finder->unlinkAutomatic($e, $job, CarbonImmutable::parse($was[0])->format('j M').' is no longer one of '.$job->title.'’s days');
                JobFind::query()->where('work_job_id', $job->id)->where('type', 'calendar')->where('status', 'linked')->get()
                    ->filter(fn (JobFind $f) => str_starts_with($f->ref, $link->event_key.'|'))->each->delete();
            } catch (Throwable $ex) {
                report($ex);
            }
        }
    }

    // ---------------------------------------------------------------- helpers

    private function add(string $type, string $ref, string $status, string $label, string $detail, ?string $date = null, ?int $amount = null, ?string $currency = 'GBP'): void
    {
        $this->found[$type.':'.$ref] = ['type' => $type, 'ref' => $ref, 'status' => $status, 'label' => mb_substr($label, 0, 255), 'detail' => mb_substr($detail, 0, 500),
            'on_date' => $date, 'amount_pence' => $amount, 'currency' => $currency];
    }

    /**
     * Keep what was found: new links and suggestions; suggestions no longer found are dropped; rejections stay. A link
     * that no longer holds (you've put the item on another job since, or unlinked it) is dropped too, so the job
     * doesn't offer to undo something that isn't on it.
     */
    private function save(WorkJob $job): void
    {
        $existing = JobFind::query()->where('work_job_id', $job->id)->get()->keyBy(fn (JobFind $f) => $f->type.':'.$f->ref);
        foreach ($this->found as $key => $f) {
            $row = $existing[$key] ?? null;
            if ($row && ($row->status === 'rejected' || ($row->status === 'linked' && $f['status'] === 'possible'))) {
                continue;
            }
            JobFind::query()->updateOrCreate(['work_job_id' => $job->id, 'type' => $f['type'], 'ref' => $f['ref']], $f);
        }
        foreach ($existing as $key => $row) {
            if (isset($this->found[$key])) {
                continue;
            }
            if ($row->status === 'possible' || ($row->status === 'linked' && ! self::stillOnJob($row, $job))) {
                $row->delete();
            }
        }
    }

    /** Whether what a find linked is still on the job. */
    private static function stillOnJob(JobFind $find, WorkJob $job): bool
    {
        $id = (int) $find->ref;
        $on = fn ($value) => $value !== null && (int) $value === $job->id;

        return match ($find->type) {
            'calendar' => $on(CalendarJobLink::query()->where('event_key', explode('|', $find->ref, 2)[0])->value('work_job_id')),
            'document' => $on(Document::query()->whereKey($id)->value('work_job_id')),
            'travel' => $on(TravelItem::query()->whereKey($id)->value('work_job_id')),
            'invoice' => $on(Invoice::query()->whereKey($id)->where('status', '!=', 'void')->value('work_job_id')),
            'payment', 'income' => Allocation::query()->where('bank_transaction_id', $id)->where('work_job_id', $job->id)->exists(),
            default => true,
        };
    }

    /**
     * The job's client, venue, postcode or whole title (of two words or more), as words in the text. A single word of
     * the title ("awards"), or a venue that could be anywhere ("London"), isn't enough to say a document is this job's.
     */
    private function namesJob(string $text, WorkJob $job): ?string
    {
        if ($named = $this->mentionsJob($text, $job, false, false)) {
            return $named;
        }
        $title = self::norm($job->title);
        if (mb_strlen($title) >= 8 && substr_count($title, ' ') >= 1 && str_contains(' '.self::norm($text).' ', ' '.$title.' ')) {
            return $job->title;
        }

        return null;
    }

    /**
     * A document's text without email headers: the From, To, Date and Subject lines an email starts with, and the
     * headers of the emails quoted in a reply or forward ("On … wrote:", even wrapped over two or three lines or in
     * the middle of a line, as in an HTML-only email; "From: … Sent: …"). Their dates are when an email was sent, not
     * what it's about. Only real headers go: a block of them with a From line and the date it was sent. A PO or call
     * sheet that starts "To: Alex Smith", or has a "From:" line, keeps every word.
     */
    public static function withoutHeaders(string $text): string
    {
        $lines = preg_split('/\r?\n/', $text) ?: [];
        $out = [];
        for ($i = 0, $count = count($lines); $i < $count; $i++) {
            if (preg_match('/^-{2,}\s*(Original Message|Forwarded message)\s*-{2,}/i', ltrim($lines[$i], "> \t"))) {
                continue;
            }
            $skip = self::attribution($lines, $i) ?: self::headerBlock($lines, $i);
            if ($skip > 0) {
                $i += $skip - 1;

                continue;
            }
            $out[] = $lines[$i];
        }
        $text = implode("\n", $out);

        // An HTML-only email's text has no line breaks where its tags were: a quoted header can be inside a line.
        return trim(preg_replace('/\bOn\s[^\n]{4,200}?\swrote:/u', ' ', $text) ?? $text);
    }

    /** How many lines from $i are a quoted email's "On Wed, 14 May 2025 at 10:00, Acme <crew@…> wrote:" (0 if none). */
    private static function attribution(array $lines, int $i): int
    {
        $joined = '';
        for ($k = 0; $k < 3 && isset($lines[$i + $k]); $k++) {
            $part = trim(ltrim($lines[$i + $k], "> \t"));
            if ($k > 0 && $part === '') {
                return 0;
            }
            $joined = trim($joined.' '.$part);
            if (! preg_match('/^On\s/i', $joined)) {
                return 0;
            }
            if (preg_match('/^On\s.{4,300}\swrote:$/iu', $joined)) {
                return $k + 1;
            }
        }

        return 0;
    }

    /** How many lines from $i are an email's headers: From, To, Date… lines with a From and the date it was sent (0 if none). */
    private static function headerBlock(array $lines, int $i): int
    {
        $n = 0;
        $from = $sent = false;
        while (isset($lines[$i + $n]) && preg_match('/^(From|To|Cc|Bcc|Date|Sent|Subject|Reply-To)\s*:/i', ltrim($lines[$i + $n], "> \t"), $m)) {
            $field = strtolower($m[1]);
            $from = $from || $field === 'from';
            $sent = $sent || $field === 'date' || $field === 'sent';
            $n++;
        }

        return $from && $sent ? $n : 0;
    }

    /** Whether a job's place could be anywhere ("London", "Home", "TBC", a link, none): see VAGUE_PLACES. */
    public static function vaguePlace(?string $location): bool
    {
        $place = mb_strtolower(trim(explode(',', (string) $location)[0]));

        return $place === '' || (bool) preg_match('#^https?://#', trim((string) $location)) || in_array($place, self::VAGUE_PLACES, true);
    }

    /** The job's client, venue (unless $vague is false and it could be anywhere), postcode or PO, as words in the text. */
    private function mentionsJob(string $text, WorkJob $job, bool $po = true, bool $vague = true): ?string
    {
        $norm = ' '.self::norm($text).' ';
        $place = $job->location && ($vague || ! self::vaguePlace($job->location)) ? explode(',', $job->location)[0] : null;
        foreach (array_filter([$job->client?->name, $place, $job->postcode, $po ? $job->po_number : null]) as $name) {
            $n = self::norm(preg_replace('/\b(ltd|limited|plc|llp|the)\b/i', '', $name));
            if (mb_strlen($n) >= 3 && str_contains($norm, ' '.$n.' ')) {
                return $name;
            }
        }

        return null;
    }

    private static function norm(?string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower((string) $text)) ?? '') ?? '');
    }

    /** @return array<string, mixed> an allocation as a line for AllocationWriter, with its job set */
    private static function line(Allocation $a, ?int $jobId): array
    {
        return ['kind' => $a->kind, 'category_id' => $a->category_id, 'work_job_id' => $jobId, 'invoice_id' => $a->invoice_id,
            'amount_pence' => $a->amount_pence, 'business_use_pct' => $a->business_use_pct, 'note' => $a->note];
    }
}
