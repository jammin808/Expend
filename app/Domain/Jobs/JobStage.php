<?php

namespace App\Domain\Jobs;

use App\Domain\Clients\ClientIds;
use App\Domain\Income\IncomeText;
use App\Domain\Invoices\InvoicePayments;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceEmail;
use App\Models\JobDay;
use App\Models\TodoDismissal;
use App\Models\WorkJob;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Where each job is, from booked to paid, in one word and one chip: read only, worked out when asked, with a few
 * batched queries for any number of jobs (the jobs list, the calendar, a client's page, the to-do list).
 *
 * The date suggests Done (doneDue) but never sets it: only you mark a job done (JobStatus).
 *
 * Stages, in the order a job goes through them:
 *  - upcoming, under_way ('Day 2 of 3 · last day Fri 14 Mar')
 *  - done_due ('Done?'): its last day has finished, it's still booked, and it finished in the last 60 days
 *  - not_done: still booked, finished longer ago (or you said it isn't finished yet): it keeps 'Invoice <job>'
 *  - done_no_draft, draft ('Draft · 2 changes'), waiting_payment (the client pays without an invoice from you)
 *  - not_sent ('In Gmail drafts', 'Putting it in Gmail…', 'Gmail draft deleted, not sent', 'Not sent'): an issued
 *    invoice not sent is never overdue
 *  - sent ('Due 25 Dec'), overdue ('Overdue 3 days'), part_paid ('Part paid £450 of £900'), paid, closed; a job paid
 *    by income alone reads paid only when the income covers its work (JobBilling::short): with a deposit in, it still
 *    asks 'Done?', and done with no draft it reads 'Part paid £100 of £650'
 *  - cancelled_to_invoice (a cancellation fee or costs owed), cancelled
 */
final class JobStage
{
    public const TZ = 'Europe/London';

    /** Only jobs that finished this many days ago at most ask "Is it done?" (owner decision); older ones keep "Invoice <job>". */
    public const DONE_WINDOW_DAYS = 60;

    /** A day with no finish time finishes at the end of it. */
    private const END_OF_DAY = '23:59';

    /** Which of a job's invoices its chip shows when it has several: the one that needs you most. */
    private const URGENCY = ['not_sent' => 0, 'overdue' => 1, 'part_paid' => 2, 'sent' => 3, 'closed' => 4, 'paid' => 5];

    /**
     * When the job finishes: the end of its last day of any kind, in London time. That's the day's finish time (the
     * job's own finish when the day has none), or that time the next morning when it ends before 06:00 or before it
     * starts (a show from 19:00 to 01:30), or 23:59 when the day has no finish time. A job with no days stored goes by
     * its last date.
     */
    public static function finishAt(WorkJob $job): CarbonImmutable
    {
        $days = self::days($job);

        return self::dayEnd($job, $days[count($days) - 1], count($days) === 1, true);
    }

    /** When the job starts: its first day's start time (the job's own when the day has none), else that day's start. */
    public static function startAt(WorkJob $job): CarbonImmutable
    {
        $first = self::days($job)[0];
        $start = $first['start_time'] ?? ($job->start_time ? substr((string) $job->start_time, 0, 5) : null);
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $first['date'], self::TZ);

        return $start === null ? $day : $day->setTime((int) substr($start, 0, 2), (int) substr($start, 3, 2));
    }

    /**
     * The job's days that haven't finished yet (each by its own finish time), oldest first: what "Did it finish
     * early?" offers to remove before marking it done.
     *
     * @return list<string> Y-m-d
     */
    public static function daysToCome(WorkJob $job, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $days = self::days($job);
        $out = [];
        foreach ($days as $i => $day) {
            if (self::dayEnd($job, $day, $i === 0, $i === count($days) - 1)->gt($now)) {
                $out[] = $day['date'];
            }
        }

        return $out;
    }

    /**
     * Whether to ask "Is it done?" now: it's booked, its finish has passed within the last 60 days, it hasn't been
     * billed (an issued invoice, or income recorded for it), and neither its "job_done:{id}" nor its "invoice:{id}"
     * to-do item is snoozed or set aside.
     */
    public static function doneDue(WorkJob $job, ?CarbonImmutable $now = null): bool
    {
        return self::of([$job], $now)[$job->id]['done_due'] ?? false;
    }

    /**
     * One stage and chip per job.
     *
     * @param  iterable<WorkJob>  $jobs
     * @param  array<int, int>  $draftChanges  by draft invoice id: how many changes it has since you last looked (the
     *                                         draft sync works these out; none given reads as no changes)
     * @return array<int, array{stage:string, chip:string, done_due:bool, finish_at:string, invoice_id:?int,
     *     draft_id:?int, state:?string, email:?string, overdue_days:?int}> by job id
     */
    public static function of(iterable $jobs, ?CarbonImmutable $now = null, array $draftChanges = []): array
    {
        $now ??= CarbonImmutable::now();
        $jobs = new EloquentCollection(collect($jobs)->filter(fn ($j) => $j instanceof WorkJob)->keyBy('id')->values()->all());
        if ($jobs->isEmpty()) {
            return [];
        }
        $jobs->loadMissing('days');
        $ids = $jobs->pluck('id')->map(fn ($id) => (int) $id)->all();

        $invoices = Invoice::query()->whereIn('work_job_id', $ids)->where('status', '!=', 'void')->orderBy('id')->get()->groupBy('work_job_id');
        $payable = $invoices->flatten()->filter(fn (Invoice $i) => in_array($i->status, InvoicePayments::PAYABLE, true))->values();
        $received = InvoicePayments::received($payable);
        $unsent = $payable->filter(fn (Invoice $i) => $i->sent_at === null)->pluck('id')->all();
        $emails = $unsent === [] ? collect() : InvoiceEmail::query()->whereIn('invoice_id', $unsent)->where('kind', 'invoice')
            ->orderBy('id')->get()->keyBy('invoice_id'); // the latest row for each invoice
        $billed = JobBilling::billed($ids);
        // Paid by income short of the work (a deposit): not paid, and still asks whether it's done.
        $short = JobBilling::short($jobs, $billed);
        $hidden = TodoDismissal::activeKeys();
        $resolved = ClientIds::resolveMany($jobs->pluck('client_id'));
        $routes = $resolved === [] ? collect() : Client::query()->whereKey(array_values($resolved))->get(['id', 'invoice_route'])->keyBy('id');
        $today = $now->setTimezone(self::TZ)->startOfDay();

        $out = [];
        foreach ($jobs as $job) {
            $finish = self::finishAt($job);
            $mine = $invoices[$job->id] ?? collect();
            $draft = $mine->where('status', 'draft')->sortByDesc('id')->first();
            $route = $job->client_id !== null ? ($routes[$resolved[(int) $job->client_id] ?? 0] ?? null)?->invoiceRoute() : null;
            $paidUp = isset($billed[$job->id]) && ! isset($short[$job->id]);
            $due = $job->status === 'booked' && $finish->lte($now) && $finish->gt($now->subDays(self::DONE_WINDOW_DAYS))
                && ! $paidUp && ! isset($hidden['job_done:'.$job->id]) && ! isset($hidden['invoice:'.$job->id]);

            $row = ['done_due' => $due, 'finish_at' => $finish->toIso8601String(), 'invoice_id' => null, 'draft_id' => $draft?->id,
                'state' => null, 'email' => null, 'overdue_days' => null];
            $invoiced = self::invoiceStage($mine, $received, $emails, $today);

            $out[$job->id] = match (true) {
                $job->status === 'cancelled' && in_array($job->cancel_charge, ['fee', 'costs'], true) => $invoiced ?? ['stage' => 'cancelled_to_invoice', 'chip' => 'Cancelled · to invoice'],
                $job->status === 'cancelled' => ['stage' => 'cancelled', 'chip' => 'Cancelled'],
                $job->status === 'booked' && $finish->gt($now) => self::ahead($job, $now),
                $invoiced !== null => $invoiced,
                $paidUp => ['stage' => 'paid', 'chip' => 'Paid'],
                $job->status === 'booked' && $due => ['stage' => 'done_due', 'chip' => 'Done?'],
                $draft !== null => ['stage' => 'draft', 'chip' => self::draftChip((int) ($draftChanges[$draft->id] ?? 0))],
                $job->status === 'done' && isset($short[$job->id]) => ['stage' => 'part_paid',
                    'chip' => 'Part paid '.IncomeText::money($short[$job->id]['paid'], 'GBP').' of '.IncomeText::money($short[$job->id]['expected'], 'GBP')],
                $job->status === 'booked' => ['stage' => 'not_done', 'chip' => 'Not marked done'],
                $route === 'none' => ['stage' => 'waiting_payment', 'chip' => 'Waiting for payment'],
                default => ['stage' => 'done_no_draft', 'chip' => 'Done · no invoice yet'],
            } + $row;
        }

        return $out;
    }

    /**
     * Upcoming or under way, for a booked job whose finish is still to come.
     *
     * @return array{stage:string, chip:string}
     */
    private static function ahead(WorkJob $job, CarbonImmutable $now): array
    {
        $days = self::days($job);
        $last = CarbonImmutable::createFromFormat('!Y-m-d', $days[count($days) - 1]['date'], self::TZ);
        if (self::startAt($job)->gt($now)) {
            return ['stage' => 'upcoming', 'chip' => 'Starts '.CarbonImmutable::createFromFormat('!Y-m-d', $days[0]['date'], self::TZ)->format('D j M')];
        }
        if (count($days) === 1) {
            return ['stage' => 'under_way', 'chip' => 'Under way'];
        }
        $today = $now->setTimezone(self::TZ)->format('Y-m-d');
        $n = max(1, count(array_filter($days, fn (array $d) => $d['date'] <= $today)));

        return ['stage' => 'under_way', 'chip' => 'Day '.$n.' of '.count($days).' · last day '.$last->format('D j M')];
    }

    /**
     * The stage of a job's issued invoices, by the one that needs you most (not sent, then overdue, part paid, sent,
     * closed and paid), or null when it has none issued.
     *
     * @param  Collection<int, Invoice>  $invoices
     * @param  array<int, array>  $received
     * @param  Collection<int, InvoiceEmail>  $emails
     * @return array{stage:string, chip:string, invoice_id:int, state:string, email:?string, overdue_days:?int}|null
     */
    private static function invoiceStage($invoices, array $received, $emails, CarbonImmutable $today): ?array
    {
        $best = null;
        foreach ($invoices->filter(fn (Invoice $i) => in_array($i->status, InvoicePayments::PAYABLE, true)) as $invoice) {
            $r = $received[$invoice->id] ?? InvoicePayments::EMPTY;
            $currency = InvoicePayments::currencyOf($invoice);
            $overdue = $invoice->due_date !== null && $r['outstanding'] > 0
                ? max(0, (int) CarbonImmutable::parse($invoice->due_date->format('Y-m-d'), self::TZ)->diffInDays($today, false)) : 0;
            $email = $invoice->sent_at === null ? ($emails[$invoice->id] ?? null)?->status : null;
            $stage = match (true) {
                $r['state'] === 'paid' => ['stage' => 'paid', 'chip' => 'Paid'],
                $r['state'] === 'closed' => ['stage' => 'closed', 'chip' => 'Closed · '.IncomeText::money($r['closed'], $currency).' not paid'],
                $invoice->sent_at === null => ['stage' => 'not_sent', 'chip' => match ($email) {
                    'drafted' => 'In Gmail drafts',
                    'pending' => 'Putting it in Gmail…',
                    'deleted' => 'Gmail draft deleted, not sent',
                    default => 'Not sent',
                }],
                $r['state'] === 'part_paid' => ['stage' => 'part_paid', 'chip' => 'Part paid '.IncomeText::money($r['paid'], $currency).' of '.IncomeText::money((int) $invoice->total_minor, $currency)],
                $overdue > 0 => ['stage' => 'overdue', 'chip' => 'Overdue '.$overdue.' '.($overdue === 1 ? 'day' : 'days')],
                default => ['stage' => 'sent', 'chip' => $invoice->due_date ? 'Due '.$invoice->due_date->format('j M') : 'Sent'],
            };
            $stage += ['invoice_id' => $invoice->id, 'state' => $r['state'], 'email' => $email, 'overdue_days' => $overdue > 0 ? $overdue : null];
            if ($best === null || self::URGENCY[$stage['stage']] <= self::URGENCY[$best['stage']]) {
                $best = $stage; // the latest of equally urgent ones
            }
        }

        return $best;
    }

    private static function draftChip(int $changes): string
    {
        return $changes > 0 ? 'Draft · '.$changes.' '.($changes === 1 ? 'change' : 'changes') : 'Draft';
    }

    /**
     * The job's days with their own times, oldest first; a job with no days stored has its dates from first to last.
     *
     * @return non-empty-list<array{date:string, start_time:?string, end_time:?string}>
     */
    private static function days(WorkJob $job): array
    {
        $days = $job->relationLoaded('days') ? $job->days : $job->days()->get();
        $time = fn ($t) => $t === null || $t === '' ? null : substr((string) $t, 0, 5);
        if ($days->isNotEmpty()) {
            return $days->sortBy(fn (JobDay $d) => $d->on_date->format('Y-m-d'))
                ->map(fn (JobDay $d) => ['date' => $d->on_date->format('Y-m-d'), 'start_time' => $time($d->start_time), 'end_time' => $time($d->end_time)])->values()->all();
        }

        return array_map(fn (string $d) => ['date' => $d, 'start_time' => null, 'end_time' => null], $job->workDates() ?: [$job->ends_on->format('Y-m-d')]);
    }

    /**
     * When one day finishes. The job's own start time is its first day's; its own finish time is its last day's.
     *
     * @param  array{date:string, start_time:?string, end_time:?string}  $day
     */
    private static function dayEnd(WorkJob $job, array $day, bool $first, bool $last): CarbonImmutable
    {
        $end = $day['end_time'] ?? ($last && $job->end_time ? substr((string) $job->end_time, 0, 5) : null);
        $start = $day['start_time'] ?? ($first && $job->start_time ? substr((string) $job->start_time, 0, 5) : null);
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $day['date'], self::TZ);
        $end ??= self::END_OF_DAY;
        if ($end !== self::END_OF_DAY && ($end < JobDays::OVERNIGHT_UNTIL || ($start !== null && $end < $start))) {
            $date = $date->addDay(); // it ran past midnight: it finishes the next morning
        }

        return $date->setTime((int) substr($end, 0, 2), (int) substr($end, 3, 2));
    }
}
