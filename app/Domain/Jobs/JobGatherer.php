<?php

namespace App\Domain\Jobs;

use App\Domain\Audit\AuditLog;
use App\Domain\Gmail\GmailClient;
use App\Domain\Ledger\AllocationWriter;
use App\Domain\Matching\OwnAccounts;
use App\Domain\Matching\Suggester;
use App\Domain\Verification\Verifier;
use App\Jobs\GatherFromGmail;
use App\Models\Allocation;
use App\Models\BankTransaction;
use App\Models\CalendarEvent;
use App\Models\CalendarJobLink;
use App\Models\Category;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\JobFind;
use App\Models\TaxYear;
use App\Models\TravelItem;
use App\Models\WorkJob;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
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

    public function __construct(private JobFinder $finder, private AllocationWriter $writer, private Suggester $suggester) {}

    /**
     * @return array{linked:int, possible:int, gmail:string, summary:string}
     */
    public function gather(WorkJob $job, bool $searchGmail = true): array
    {
        $job = $job->fresh(['client']);
        OwnAccounts::flush();
        $this->found = [];
        $this->rejected = JobFind::query()->where('work_job_id', $job->id)->where('status', 'rejected')->get()
            ->mapWithKeys(fn (JobFind $f) => [$f->type.':'.$f->ref => true])->all();

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
        if ($job->location && mb_strlen($job->location) >= 4 && ! preg_match('#^https?://#', $job->location)) {
            $terms[] = $quote(mb_substr(trim(explode(',', $job->location)[0]), 0, 60));
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

    /** Its other days in the calendar: the same entry on the job's dates is linked; nearby repeats are suggested. */
    private function calendar(WorkJob $job): void
    {
        $from = CarbonImmutable::parse($job->starts_on->format('Y-m-d'));
        $to = CarbonImmutable::parse($job->ends_on->format('Y-m-d'));
        $events = CalendarEvent::query()->whereBetween('starts_at', [$from->subDays(4)->format('Y-m-d 00:00:00'), $to->addDays(4)->format('Y-m-d 23:59:59')])->get();
        $links = CalendarJobLink::query()->whereIn('event_key', $events->map->key())->get()->keyBy('event_key');
        $titles = $events->filter(fn (CalendarEvent $e) => ($links[$e->key()] ?? null)?->work_job_id === $job->id)->map(fn ($e) => self::norm($e->summary))->filter()->unique()->all();

        foreach ($events as $e) {
            $link = $links[$e->key()] ?? null;
            $ref = $e->key().'|'.$e->day();
            if ($link?->work_job_id || $link?->not_job || in_array($job->id, $link?->rejected ?? [], true) || isset($this->rejected['calendar:'.$ref])) {
                continue;
            }
            $inRange = $job->worksOn($e->day());
            $same = $titles && in_array(self::norm($e->summary), $titles, true);
            $label = ($e->summary ?: 'Calendar entry').' · '.CarbonImmutable::parse($e->day())->format('D j M');
            if ($same && $inRange) {
                $this->finder->linkCalendar($e, $job, 'rules', 'The same entry as this job’s other days');
                $this->add('calendar', $ref, 'linked', $label, 'The same entry as this job’s other days', $e->day());
            } elseif ($same) {
                $this->add('calendar', $ref, 'possible', $label, 'The same entry on a day that isn’t one of the job’s days yet: another day of it (a load-in or de-rig)?', $e->day());
            } elseif ($inRange && $this->mentionsJob($e->text(), $job)) {
                $this->add('calendar', $ref, 'possible', $label, 'On the job’s dates and mentions '.$this->mentionsJob($e->text(), $job), $e->day());
            }
        }
    }

    /** POs, production packs, call sheets and emails already in Expend. */
    private function documents(WorkJob $job): void
    {
        $from = $job->starts_on->copy()->subMonths(9)->format('Y-m-d 00:00:00');
        $to = $job->ends_on->copy()->addMonths(4)->format('Y-m-d 23:59:59');
        Document::query()->whereNull('parent_id')->whereNull('work_job_id')->where('status', '!=', 'held')
            ->where(fn ($q) => $q->whereNull('job_linked_by')->orWhere('job_linked_by', '!=', 'you'))
            ->where(fn ($q) => $q->whereNull('received_at')->orWhereBetween('received_at', [$from, $to]))
            ->with('children:id,parent_id,text')
            ->select(['id', 'kind', 'original_name', 'email_subject', 'email_from', 'text', 'received_at', 'job_rejected', 'job_linked_by', 'work_job_id', 'parent_id'])
            ->chunkById(150, function (Collection $docs) use ($job) {
                foreach ($docs as $doc) {
                    if (in_array($job->id, $doc->job_rejected ?? [], true) || isset($this->rejected['document:'.$doc->id])) {
                        continue;
                    }
                    $text = trim($doc->email_subject."\n".$doc->text."\n".$doc->children->pluck('text')->filter()->implode("\n"));
                    if ($text === '') {
                        continue;
                    }
                    $ev = Verifier::jobEvidence($text, $job);
                    $label = $doc->email_subject ?: $doc->original_name;
                    $date = $doc->received_at?->format('Y-m-d');
                    if ($ev['ok']) {
                        $this->finder->linkDocument($doc, $job, 'rules');
                        $this->add('document', (string) $doc->id, 'linked', $label, 'Linked because '.implode(' and ', $ev['why']), $date);
                    } elseif ($ev['why'] && collect($ev['why'])->contains(fn ($w) => str_starts_with($w, 'it mentions'))) {
                        $this->add('document', (string) $doc->id, 'possible', $label, ucfirst(implode(' and ', $ev['why'])).', but not the job’s dates or PO', $date);
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
        $txs = OwnAccounts::scopeNotTransfers(BankTransaction::query())->with(['allocations', 'account'])
            ->where('amount_pence', '<', 0)->whereNotIn('tax_year', $locked)
            ->whereBetween('posted_on', [$from, $job->ends_on->copy()->addDays(3)->format('Y-m-d')])
            ->orderBy('posted_on')->limit(300)->get()
            ->filter(fn (BankTransaction $t) => isset($dates[$t->effectiveDate()->format('Y-m-d')]));

        foreach ($txs as $tx) {
            $ref = (string) $tx->id;
            if (isset($this->rejected['payment:'.$ref]) || OwnAccounts::reason($tx)) {
                continue;
            }
            $a = $tx->allocations;
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
                        $this->writer->clear($tx, 'Looked at again: there’s now a job that day');
                    }
                    $this->suggester->suggestFor(collect([$tx->fresh()]));
                    if ($mine()) {
                        $this->add('payment', $ref, 'linked', $label, 'Suggested as this job’s cost (confirm it in the Inbox)', $tx->effectiveDate()->format('Y-m-d'), $tx->amount_pence);
                    } elseif ($tx->fresh('allocations')->allocations->isEmpty()) {
                        $this->add('payment', $ref, 'possible', $label, ($onDay ? 'Paid on one of the job’s days' : 'Paid the day before or after one of the job’s days').' and not decided yet: was it for it?', $tx->effectiveDate()->format('Y-m-d'), $tx->amount_pence);
                    }
                } elseif ($a->contains('kind', 'business_expense')) {
                    if ($automatic) {
                        $lines = $a->map(fn (Allocation $x) => self::line($x, $x->kind === 'business_expense' ? $job->id : null))->all();
                        $this->writer->decide($tx, $lines, 'matcher', false, 'A business cost on '.$job->title.'’s dates');
                        $this->add('payment', $ref, 'linked', $label, 'A business cost on the job’s dates: suggested as its cost (confirm it in the Inbox)', $tx->effectiveDate()->format('Y-m-d'), $tx->amount_pence);
                    } else {
                        $this->add('payment', $ref, 'possible', $label, 'A business cost on the job’s dates with no job: link it?', $tx->effectiveDate()->format('Y-m-d'), $tx->amount_pence);
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
        $rows = OwnAccounts::scopeNotTransfers(BankTransaction::query())->with('allocations')->where('amount_pence', '>', 0)
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
            $why = array_filter([
                $job->fee_pence && $tx->amount_pence === $job->fee_pence ? 'it’s the agreed fee ('.Money::format($job->fee_pence).')' : null,
                $words->contains(fn ($w) => str_contains(mb_strtoupper($tx->description.' '.$tx->counterparty), $w)) ? 'it’s from '.$job->client?->name : null,
            ]);
            $this->add('income', (string) $tx->id, 'possible', ($tx->counterparty ?: $tx->description).' · '.$tx->effectiveDate()->format('j M Y'),
                'Looks like payment for this job: '.implode(' and ', $why), $tx->effectiveDate()->format('Y-m-d'), $tx->amount_pence);
        }
    }

    /** Stays and journeys, and invoices quoting the PO, that the follow-up linked. */
    private function travelAndInvoices(WorkJob $job): void
    {
        foreach (TravelItem::query()->where('work_job_id', $job->id)->where('job_linked_by', '!=', 'you')->get() as $t) {
            $this->add('travel', (string) $t->id, 'linked', (TravelItem::KINDS[$t->kind] ?? $t->kind).': '.($t->kind === 'hotel' ? ($t->place ?: $t->provider) : $t->provider),
                'On the job’s dates', $t->starts_on->format('Y-m-d'), $t->amount_minor ? -$t->amount_minor : null, $t->currency);
        }
        foreach (Invoice::query()->where('work_job_id', $job->id)->whereNotNull('po_number')->get() as $i) {
            $this->add('invoice', (string) $i->id, 'linked', 'Invoice '.($i->number ?? 'draft'), 'Quotes the job’s PO number', $i->issue_date?->format('Y-m-d'), $i->total_minor, $i->currency);
        }
    }

    // ---------------------------------------------------------------- you decide

    /** Link something the search thought may belong. */
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

    /** "Not this job": undo a link the search made, or reject a suggestion, so it isn't suggested again. */
    public function reject(JobFind $find): string
    {
        $job = $find->job;
        if ($find->status === 'linked') {
            match ($find->type) {
                'calendar' => ($e = $this->event($find)) ? $this->finder->unlinkCalendar($e, $job->id) : null,
                'document' => ($d = Document::query()->find((int) $find->ref)) ? $this->finder->linkDocument($d, null, 'you') : null,
                'payment', 'income' => $this->unlinkPayment((int) $find->ref, $job),
                'travel' => ($t = TravelItem::query()->find((int) $find->ref)) ? $this->finder->linkTravel($t, null, 'you') : null,
                'invoice' => Invoice::query()->whereKey((int) $find->ref)->where('work_job_id', $job->id)->update(['work_job_id' => null]),
                default => null,
            };
        }
        $find->update(['status' => 'rejected']);
        AuditLog::record('job.find_rejected', $job, null, ['type' => $find->type, 'ref' => $find->ref], 'Not this job');

        return 'OK: not part of '.$job->title.'. It won’t be suggested for it again.';
    }

    private function linkEvent(JobFind $find, WorkJob $job): string
    {
        $e = $this->event($find) ?? throw new InvalidArgumentException('That calendar entry has gone.');
        $this->finder->linkCalendar($e, $job, 'you', 'Linked by you');

        return 'Linked the calendar entry.';
    }

    private function linkDoc(JobFind $find, WorkJob $job): string
    {
        $this->finder->linkDocument(Document::query()->findOrFail((int) $find->ref), $job, 'you');

        return 'Linked the document.';
    }

    private function linkPayment(JobFind $find, WorkJob $job): string
    {
        $tx = BankTransaction::query()->with('allocations')->findOrFail((int) $find->ref);
        if (! $tx->allocations->contains('kind', 'business_expense')) {
            throw new InvalidArgumentException('Decide what this payment is first (open it), then it can go on the job.');
        }
        $this->writer->decide($tx, $tx->allocations->map(fn (Allocation $a) => self::line($a, $a->kind === 'business_expense' ? $job->id : $a->work_job_id))->all(), 'you', true, 'Linked to '.$job->title);

        return 'Added to the job’s costs.';
    }

    private function linkIncome(JobFind $find, WorkJob $job): string
    {
        $tx = BankTransaction::query()->with('allocations')->findOrFail((int) $find->ref);
        $turnover = Category::query()->where('code', 'turnover')->value('id');
        $lines = $tx->allocations->isNotEmpty() && $tx->allocations->every(fn (Allocation $a) => $a->kind === 'business_income')
            ? $tx->allocations->map(fn (Allocation $a) => self::line($a, $job->id))->all()
            : AllocationWriter::lines($tx->amount_pence, 'business_income', $turnover, $job->id);
        $this->writer->decide($tx, $lines, 'you', true, 'Payment for '.$job->title);

        return 'Recorded as income for this job.';
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

    private function event(JobFind $find): ?CalendarEvent
    {
        [$key, $date] = explode('|', $find->ref, 2) + [null, null];

        return $key && $date ? JobFinder::event($key, $date) : null;
    }

    // ---------------------------------------------------------------- helpers

    private function add(string $type, string $ref, string $status, string $label, string $detail, ?string $date = null, ?int $amount = null, ?string $currency = 'GBP'): void
    {
        $this->found[$type.':'.$ref] = ['type' => $type, 'ref' => $ref, 'status' => $status, 'label' => mb_substr($label, 0, 255), 'detail' => mb_substr($detail, 0, 500),
            'on_date' => $date, 'amount_pence' => $amount, 'currency' => $currency];
    }

    /** Keep what was found: new links and suggestions; suggestions no longer found are dropped; rejections stay. */
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
            if ($row->status === 'possible' && ! isset($this->found[$key])) {
                $row->delete();
            }
        }
    }

    private function mentionsJob(string $text, WorkJob $job): ?string
    {
        $norm = ' '.self::norm($text).' ';
        foreach (array_filter([$job->client?->name, $job->location ? explode(',', $job->location)[0] : null, $job->po_number]) as $name) {
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
