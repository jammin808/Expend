<?php

namespace App\Domain\Invoices;

use App\Domain\Audit\AuditLog;
use App\Domain\Charges\ChargeStatus;
use App\Domain\Charges\ChargeTerms;
use App\Domain\Charges\CostRef;
use App\Domain\Charges\CostUnits;
use App\Domain\Charges\CostWords;
use App\Domain\Clients\ClientIds;
use App\Domain\Intel\AuditContext;
use App\Domain\Jobs\JobCreator;
use App\Models\AuditEvent;
use App\Models\Client;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\JobDay;
use App\Models\WorkJob;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A draft invoice's lines follow what's linked to its job (and, for its client, what's still owed from earlier jobs),
 * keeping whatever you changed yourself. Runs as 'policy', on drafts only; an issued invoice never changes.
 *
 * What a draft gets:
 *  - its own job's work (fee, days or hours, extra days, travel days, a travel fee, a meals allowance, or a
 *    cancellation fee), its costs (every one: ticked where the client usually pays it, the rest unticked) and its
 *    mileage trips;
 *  - "From earlier jobs": costs of the same paying client's other jobs that came after their invoice was sent
 *    (owed_late), from jobs with no open draft of their own, plus costs for the client with no job that you marked to
 *    recharge. Each goes on one draft only: the one that has it already, else the client's oldest open draft (one with
 *    no job first, as draftForClient makes).
 * Never another job's work, a cost of a job with its own open draft (that draft keeps it), or a covered cost (billed
 * before Expend tracked line sources, or another way: JobCloseout asks about those).
 *
 * A line that matches a cost (by its ref or an alias, re-keyed to the ref) follows it: its description unless you
 * changed the wording (own_text), its quantity and price unless you changed them (own_amount), its tick unless you
 * ticked or unticked it (ticked_by 'you'). A line whose cost has gone (or isn't to be charged here any more) is
 * deleted when it's unticked or untouched by you; one you changed or ticked stays, flagged 'gone', and blocks issuing
 * until you remove it or keep it as your own line. Typed lines and PO lines are never touched.
 *
 * A cost that may already be on one of your own ticked lines of the same amount (CostWords::couldHold) is added
 * unticked, flagged maybe_same, and stays so on every later sync while your line could hold it (until you tick it or
 * say it's the same). A cost ChargeStatus leaves unticked as maybe the same as one charged already carries that flag
 * too. A cost in another currency than the draft's is never converted: it's listed with no
 * price (0, flagged no_amount) and unticked. On a draft made before Expend tracked line sources, the job's work takes
 * over a typed line only when exactly one has exactly its amount; otherwise work lines aren't added at all (recorded
 * once as invoice.legacy_draft, so later syncs don't add them either).
 *
 * The changes are worked out first with no lock: no change means no lock, no write and no audit event. When something
 * changes it takes the locks in the one order every audited writer uses (the audit head, then the paying client's row,
 * or the job's when there's no client, then the invoice and its lines), works the changes out again, and writes them:
 * invoice.line_added, invoice.line_changed, invoice.line_removed and invoice.line_flagged, each on the invoice. It also
 * fills an empty PO number, client and bill-to details (invoice.filled). synced_at is set on a draft's first sync and
 * whenever its lines change, never on a sync that changes nothing.
 */
final class InvoiceDraftSync
{
    /**
     * How many times a transaction is tried when the database ends it to break a deadlock (another writer took the same
     * rows the other way round): it reads everything again under its locks, so trying again is safe.
     */
    private const ATTEMPTS = 3;

    /** Line sources that are the job's own work (a PO's lines are the work it sets). */
    public const WORK_SOURCES = ['po', 'fee', 'days', 'hours', 'extra', 'tdays', 'tfee', 'meals', 'cancel'];

    /** What "since you last looked" lists. */
    public const CHANGE_ACTIONS = ['invoice.line_added', 'invoice.line_changed', 'invoice.line_removed', 'invoice.line_flagged', 'invoice.converted'];

    /** Flags that stop a ticked line being issued (check: until you set its amount yourself). */
    public const BLOCKING = ['gone', 'no_amount', 'check'];

    /** Recorded once on a draft made before Expend tracked line sources whose work lines weren't added. */
    public const LEGACY_EVENT = 'invoice.legacy_draft';

    public const LEGACY_NOTE = 'Made before Expend tracked line sources: check the fee isn’t on it twice.';

    /** Which of a cost's flags a line shows, most important first (one per line). */
    private const FLAG_ORDER = ['no_amount', 'check', 'maybe_same', 'unconfirmed', 'no_payment'];

    /** The fields of a line you see: a change to one of these is a change since you last looked. */
    private const VISIBLE = ['description', 'quantity', 'unit_price_minor', 'amount_minor', 'included', 'flag'];

    /**
     * Bring a draft up to date with what's linked to it.
     *
     * @return array{changed:int, wrote:bool, added:int, updated:int, removed:int, flagged:int, filled:list<string>}
     *                                                                                                               changed: how many changes you'd see (lines added, removed, flagged, or changed in what’s shown)
     */
    public function sync(Invoice $draft): array
    {
        $none = ['changed' => 0, 'wrote' => false, 'added' => 0, 'updated' => 0, 'removed' => 0, 'flagged' => 0, 'filled' => []];
        $stored = Invoice::query()->find($draft->id);
        if (! $stored || $stored->status !== 'draft') {
            return $none;
        }
        if (! $this->plan($stored)['writes']) {
            return $none;
        }

        return DB::transaction(function () use ($stored, $none) {
            AuditLog::lockHead();
            $this->lockOwner($stored);
            $invoice = Invoice::query()->lockForUpdate()->find($stored->id);
            if (! $invoice || $invoice->status !== 'draft') {
                return $none;
            }
            InvoiceLine::query()->where('invoice_id', $invoice->id)->lockForUpdate()->get(['id']);
            $plan = $this->plan($invoice);
            if (! $plan['writes']) {
                return $none;
            }

            return AuditContext::with('policy', fn () => $this->apply($invoice, $plan));
        }, self::ATTEMPTS);
    }

    /**
     * The lines a draft should have now, by ref, with what each cost is (for the issue checks): worked out with no
     * lock and nothing written.
     *
     * @return array<string, array<string, mixed>>
     */
    public function wanted(Invoice $invoice): array
    {
        $job = $invoice->work_job_id ? WorkJob::query()->with(['days', 'client'])->find($invoice->work_job_id) : null;

        return $this->wantedFor($invoice, $job, self::payer($invoice, $job));
    }

    /**
     * Changes the sync made since you last looked ('Got it', a save or an issue), or since the draft was made: its
     * first fill isn't a change. Converting it to a PO's lines is listed too.
     *
     * @return Collection<int, AuditEvent>
     */
    public static function changes(Invoice $invoice): Collection
    {
        $floor = self::floors([(int) $invoice->id])[(int) $invoice->id] ?? 0;
        $floor = max($floor, (int) $invoice->lines_seen_event_id);

        return self::changeQuery([(int) $invoice->id])->where('subject_id', $invoice->id)->where('id', '>', $floor)->orderBy('id')->get();
    }

    /**
     * How many changes each draft has since you last looked (JobStage's "2 changes since you looked").
     *
     * @param  list<int>  $invoiceIds
     * @return array<int, int> by invoice id (drafts with none left out)
     */
    public static function changeCounts(array $invoiceIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $invoiceIds)));
        if ($ids === []) {
            return [];
        }
        $seen = Invoice::query()->whereIn('id', $ids)->where('status', 'draft')->pluck('lines_seen_event_id', 'id');
        $floors = self::floors($seen->keys()->map(fn ($id) => (int) $id)->all());
        $out = [];
        foreach (self::changeQuery($seen->keys()->all())->get(['id', 'subject_id']) as $e) {
            $id = (int) $e->subject_id;
            if ((int) $e->id > max((int) ($seen[$id] ?? 0), $floors[$id] ?? 0)) {
                $out[$id] = ($out[$id] ?? 0) + 1;
            }
        }

        return $out;
    }

    /** The last change listed for a draft now (what a save or an issue marks as seen), or null. */
    public static function lastChange(Invoice $invoice): ?int
    {
        $id = self::changeQuery([(int) $invoice->id])->max('id');

        return $id === null ? null : (int) $id;
    }

    /** A line as you last saw it, for a save tied to it: changes whenever anything shown or followed on it changes. */
    public static function version(InvoiceLine $line): string
    {
        return substr(hash('sha256', json_encode([(int) $line->id, $line->description, (string) $line->quantity, (int) $line->unit_price_minor, (int) $line->amount_minor,
            (bool) $line->included, $line->flag, $line->source_ref, (bool) $line->own_text, (bool) $line->own_amount, $line->ticked_by, (int) $line->sort])), 0, 12);
    }

    /** Every line of a draft as you last saw it (ids, refs and versions): a whole-form save and an issue are tied to it. */
    public static function linesVersion(Invoice $invoice): string
    {
        $lines = InvoiceLine::query()->where('invoice_id', $invoice->id)->orderBy('id')->get()
            ->map(fn (InvoiceLine $l) => [(int) $l->id, $l->source_ref, self::version($l)])->all();

        return substr(hash('sha256', json_encode($lines)), 0, 12);
    }

    /**
     * A job's travel days, travel fee and meals allowance, as its arrangement charges them (lines of the job's own
     * work, counted by quantity): travel days at the day rate or a rate a day (listed unticked, at the day rate, while
     * there's no arrangement), a travel fee once or for each working day, and meals at a rate for each working day. A
     * cancelled job has none.
     *
     * @param  array<string, array{charge:?string, rate_minor:?int, currency:string, from:string}>|null  $terms
     * @return list<array<string, mixed>>
     */
    public static function allowances(WorkJob $job, ?array $terms = null): array
    {
        if ($job->status === 'cancelled') {
            return [];
        }
        $terms ??= ChargeTerms::forJob($job);
        $client = ClientIds::resolve($job->client_id === null ? null : (int) $job->client_id);
        $days = $job->relationLoaded('days') ? $job->days : $job->days()->get();
        $travel = $days->filter(fn (JobDay $d) => $d->kind === 'travel')->values();
        $worked = JobCreator::workingDays($job);
        $dayRate = $job->rate_unit === 'day' && (int) $job->rate_pence > 0 ? (int) $job->rate_pence : null;
        $make = fn (string $part, int $qty, ?int $price, string $currency, string $description, array $flags = []) => [
            'ref' => CostRef::work((int) $job->id, $part), 'aliases' => [], 'subject' => CostRef::work((int) $job->id, $part), 'kind' => 'work', 'class' => null, 'parts' => [],
            'date' => $job->starts_on?->format('Y-m-d'), 'description' => $description, 'words' => [], 'merchant' => null, 'amount_pence' => null,
            'currency' => strtoupper($currency ?: 'GBP'), 'amount_minor' => null, 'quantity' => $qty.'.00', 'unit' => 'days', 'unit_price_minor' => $price, 'job_id' => (int) $job->id,
            'client_id' => $client, 'tax_year' => null, 'confirmed' => null, 'locked' => false, 'flags' => $flags, 'pair_ref' => null, 'part' => $part,
        ];
        $out = [];
        $td = $terms['travel_days'] ?? ['charge' => null, 'rate_minor' => null, 'currency' => 'GBP'];
        if ($travel->isNotEmpty() && $td['charge'] !== 'none') {
            $span = CostWords::span(CarbonImmutable::parse($travel->first()->on_date->format('Y-m-d')), CarbonImmutable::parse($travel->last()->on_date->format('Y-m-d')));
            [$price, $currency] = $td['charge'] === 'per_day' ? [$td['rate_minor'], $td['currency']] : [$dayRate, 'GBP'];
            $out[] = $make('tdays', $travel->count(), $price, $currency, 'Travel '.($travel->count() === 1 ? 'day' : 'days').': '.$job->title.' ('.$span.')',
                $td['charge'] === null ? ['no_arrangement' => 'No arrangement for travel days yet: listed at the day rate.'] : []);
        }
        $tf = $terms['travel_fee'] ?? ['charge' => null];
        if (in_array($tf['charge'], ['per_job', 'per_day'], true) && (int) $tf['rate_minor'] > 0) {
            $out[] = $make('tfee', $tf['charge'] === 'per_job' ? 1 : max(1, $worked), (int) $tf['rate_minor'], $tf['currency'], 'Travel fee: '.$job->title);
        }
        $meals = $terms['meals'] ?? ['charge' => null];
        if ($meals['charge'] === 'per_day' && (int) $meals['rate_minor'] > 0) {
            $out[] = $make('meals', max(1, $worked), (int) $meals['rate_minor'], $meals['currency'], 'Meals allowance: '.$job->title);
        }

        return $out;
    }

    /** Who pays for this draft: its own client, else its job's (a merged client is the one it went into). */
    public static function payer(Invoice $invoice, ?WorkJob $job = null): ?int
    {
        $id = $invoice->client_id ?? $job?->client_id ?? ($invoice->work_job_id ? WorkJob::query()->whereKey($invoice->work_job_id)->value('client_id') : null);

        return ClientIds::resolve($id === null ? null : (int) $id);
    }

    /**
     * What a sync would do: each line to add, update (or take over), remove or flag, and the details to fill.
     *
     * @return array{ops:list<array<string, mixed>>, fill:array<string, mixed>, legacy:?array{skipped:list<string>}, first:bool, writes:bool, changed:int}
     */
    private function plan(Invoice $invoice): array
    {
        $job = $invoice->work_job_id ? WorkJob::query()->with(['days', 'client'])->find($invoice->work_job_id) : null;
        $payer = self::payer($invoice, $job);
        $lines = InvoiceLine::query()->where('invoice_id', $invoice->id)->orderBy('sort')->orderBy('id')->get();
        $wanted = $this->wantedFor($invoice, $job, $payer, $lines);
        $first = $invoice->synced_at === null;
        $legacy = ($first && $lines->contains(fn (InvoiceLine $l) => $l->source === null))
            || AuditEvent::query()->where('subject_type', 'Invoice')->where('subject_id', $invoice->id)->where('action', self::LEGACY_EVENT)->exists();

        $byRef = $lines->filter(fn (InvoiceLine $l) => $l->source_ref !== null)->keyBy('source_ref');
        $typed = $lines->filter(fn (InvoiceLine $l) => $l->source === null)->values();
        $used = [];
        $ops = [];
        $skipped = [];
        $sort = (int) ($lines->max('sort') ?? -1);
        foreach ($wanted as $w) {
            $line = null;
            foreach ([$w['ref'], ...$w['aliases']] as $r) {
                if (isset($byRef[$r]) && ! isset($used[$byRef[$r]->id])) {
                    $line = $byRef[$r];
                    break;
                }
            }
            if ($line !== null) {
                $used[$line->id] = true;
                // A line left unticked as maybe on one of your own lines stays so while your line could still hold it.
                $held = $line->flag === 'maybe_same' && $line->ticked_by !== 'you' && self::onYourLine($w, $typed);
                $set = self::follow($line, $w, $held);
                if ($set !== []) {
                    $ops[] = ['op' => 'update', 'line' => $line, 'set' => $set];
                }

                continue;
            }
            if ($w['role'] === 'work' && $legacy) {
                // Made before Expend tracked line sources: the work takes over your line only when exactly one has its
                // amount; otherwise it isn't added (it may be on the draft already, in your own words).
                $same = $typed->filter(fn (InvoiceLine $t) => ! isset($used[$t->id]) && $w['priced'] && (int) $t->amount_minor === (int) $w['amount_minor']);
                if ($same->count() === 1) {
                    $t = $same->first();
                    $used[$t->id] = true;
                    $ops[] = ['op' => 'takeover', 'line' => $t, 'set' => self::takeover($t, $w)];
                } else {
                    $skipped[] = $w['ref'];
                }

                continue;
            }
            $row = $w;
            if (self::onYourLine($w, $typed)) {
                [$row['included'], $row['flag']] = [false, 'maybe_same'];
            }
            $row['sort'] = ++$sort;
            $ops[] = ['op' => 'add', 'set' => $row];
        }
        foreach ($lines as $l) {
            if ($l->source === null || $l->source === 'po' || $l->source_ref === null || isset($used[$l->id])) {
                continue;
            }
            if (! $l->included || ($l->ticked_by === null && ! $l->own_text && ! $l->own_amount)) {
                $ops[] = ['op' => 'remove', 'line' => $l];
            } elseif ($l->flag !== 'gone') {
                $ops[] = ['op' => 'flag', 'line' => $l];
            }
        }
        $fill = self::fill($invoice, $job, $payer);
        // Filling an empty PO number or bill-to detail from the job and client isn't a change you need to see first.
        $changed = count(array_filter($ops, fn (array $op) => ! in_array($op['op'], ['update', 'takeover'], true)
            || array_intersect(array_keys($op['set']), self::VISIBLE) !== []));

        // A draft made before line sources stays one: recorded at its first sync, so later syncs don't add work lines.
        return ['ops' => $ops, 'fill' => $fill, 'legacy' => $first && $legacy ? ['skipped' => $skipped] : null, 'first' => $first,
            'writes' => $first || $ops !== [] || $fill !== [], 'changed' => $changed];
    }

    /** @return array{changed:int, wrote:bool, added:int, updated:int, removed:int, flagged:int, filled:list<string>} */
    private function apply(Invoice $invoice, array $plan): array
    {
        $out = ['changed' => $plan['changed'], 'wrote' => true, 'added' => 0, 'updated' => 0, 'removed' => 0, 'flagged' => 0, 'filled' => array_keys($plan['fill'])];
        foreach ($plan['ops'] as $op) {
            switch ($op['op']) {
                case 'add':
                    $line = $invoice->allLines()->create(self::columns($op['set']));
                    AuditLog::record('invoice.line_added', $invoice, null, self::snapshot($line));
                    $out['added']++;
                    break;
                case 'update':
                case 'takeover':
                    /** @var InvoiceLine $line */
                    $line = $op['line'];
                    $before = ['line' => (int) $line->id] + self::only($line, array_keys($op['set']));
                    $line->forceFill($op['set'])->save();
                    AuditLog::record('invoice.line_changed', $invoice, $before, ['line' => (int) $line->id, 'ref' => $line->source_ref] + self::only($line, array_keys($op['set'])),
                        $op['op'] === 'takeover' ? 'Made before Expend tracked line sources: your line is now the job’s, in your words' : null);
                    $out['updated']++;
                    break;
                case 'remove':
                    $before = self::snapshot($op['line']);
                    $op['line']->delete();
                    AuditLog::record('invoice.line_removed', $invoice, $before, null, 'No longer linked to the job, or charged another way');
                    $out['removed']++;
                    break;
                case 'flag':
                    $line = $op['line'];
                    $before = ['line' => (int) $line->id, 'flag' => $line->flag];
                    $line->forceFill(['flag' => 'gone'])->save();
                    AuditLog::record('invoice.line_flagged', $invoice, $before, ['line' => (int) $line->id, 'ref' => $line->source_ref, 'flag' => 'gone'],
                        'No longer linked to the job: you changed it, so it stays until you remove it or keep it as your own line');
                    $out['flagged']++;
                    break;
            }
        }
        if ($plan['legacy'] !== null) {
            AuditLog::record(self::LEGACY_EVENT, $invoice, null, ['work_not_added' => $plan['legacy']['skipped']], self::LEGACY_NOTE);
        }
        if ($plan['fill'] !== []) {
            $before = $invoice->only(array_keys($plan['fill']));
            $invoice->forceFill($plan['fill']);
            AuditLog::record('invoice.filled', $invoice, $before, $plan['fill'], 'Filled in from the job and the client');
        }
        $invoice->forceFill(['total_minor' => (int) InvoiceLine::query()->where('invoice_id', $invoice->id)->where('included', true)->sum('amount_minor')]);
        if ($plan['first'] || $plan['ops'] !== [] || $plan['fill'] !== []) {
            $invoice->forceFill(['synced_at' => now()]);
        }
        $invoice->save();

        return $out;
    }

    /**
     * The lines the draft should have, by ref.
     *
     * @param  Collection<int, InvoiceLine>|null  $lines
     * @return array<string, array<string, mixed>>
     */
    private function wantedFor(Invoice $invoice, ?WorkJob $job, ?int $payer, ?Collection $lines = null): array
    {
        $lines ??= InvoiceLine::query()->where('invoice_id', $invoice->id)->get();
        $currency = strtoupper($invoice->currency ?: 'GBP');
        $po = $invoice->document_id !== null;
        // A PO with a line for travel or expenses may already cover the costs.
        $poExpenses = $po && collect(Document::query()->find($invoice->document_id)?->extraction['po']['lines'] ?? [])
            ->contains(fn ($l) => CostWords::forExpenses((string) ($l['description'] ?? '')));
        $out = [];
        $here = $lines->pluck('source_ref')->filter()->flip();
        if ($job !== null) {
            $terms = ChargeTerms::forJob($job);
            $units = [...CostUnits::work($job), ...self::allowances($job, $terms), ...CostUnits::forJob($job)];
            $statuses = ChargeStatus::for($units, (int) $invoice->id);
            foreach ($units as $u) {
                $has = collect([$u['ref'], ...$u['aliases']])->contains(fn ($r) => isset($here[$r]));
                $w = $this->want($u, $statuses[$u['ref']], $u['kind'] === 'work' ? 'work' : ($u['kind'] === 'trip' ? 'trip' : 'cost'), $job, $terms, $currency, $po, $poExpenses, $has);
                if ($w !== null) {
                    $out[$u['ref']] = $w;
                }
            }
        }
        if ($payer === null) {
            return $out;
        }

        // From earlier jobs (invoiced, with no open draft of their own), and the client's own costs marked to recharge.
        $clients = ClientIds::withMerged($payer);
        $earlier = WorkJob::query()->whereIn('client_id', $clients)->when($job, fn ($q) => $q->where('id', '!=', $job->id))
            ->whereHas('invoices', fn ($q) => $q->whereIn('status', InvoicePayments::PAYABLE))
            ->whereDoesntHave('invoices', fn ($q) => $q->where('status', 'draft'))
            ->with('days')->orderBy('starts_on')->orderBy('id')->get()->keyBy('id');
        $late = collect(CostUnits::forJobs($earlier))->flatten(1)->values()->all();
        $mine = CostUnits::forClient($payer, false);
        if ($late === [] && $mine === []) {
            return $out;
        }
        $statuses = ChargeStatus::for([...$late, ...$mine], (int) $invoice->id);
        $refs = collect([...$late, ...$mine])->flatMap(fn (array $u) => [$u['ref'], ...$u['aliases']])->unique()->values()->all();
        $elsewhere = collect();
        foreach (array_chunk($refs, 500) as $chunk) {
            $elsewhere = $elsewhere->concat(InvoiceLine::query()->join('invoices', 'invoices.id', '=', 'invoice_lines.invoice_id')->where('invoices.status', 'draft')
                ->where('invoices.id', '!=', $invoice->id)->whereIn('invoice_lines.source_ref', $chunk)->pluck('invoice_lines.source_ref'));
        }
        $elsewhere = $elsewhere->flip();
        $chosen = self::chosenDraft($payer) === (int) $invoice->id;
        $termsOf = ChargeTerms::forJobs($earlier);
        foreach ([['late', $late], ['client', $mine]] as [$role, $units]) {
            foreach ($units as $u) {
                $all = [$u['ref'], ...$u['aliases']];
                $has = collect($all)->contains(fn ($r) => isset($here[$r]));
                if (! $has && (! $chosen || collect($all)->contains(fn ($r) => isset($elsewhere[$r])))) {
                    continue;
                }
                $of = $u['job_id'] !== null ? ($earlier[(int) $u['job_id']] ?? null) : null;
                $w = $this->want($u, $statuses[$u['ref']], $role, $of, $of ? ($termsOf[(int) $of->id] ?? []) : [], $currency, false, false, $has);
                if ($w !== null) {
                    $out[$u['ref']] ??= $w;
                }
            }
        }

        return $out;
    }

    /**
     * One cost's line, as the draft should have it, or null when it isn't on this draft.
     *
     * @param  string  $role  work, trip or cost (the draft's own job), late (an earlier job's) or client (the client's, with no job)
     */
    private function want(array $u, array $s, string $role, ?WorkJob $job, array $terms, string $currency, bool $po, bool $poExpenses, bool $here = false): ?array
    {
        $status = $s['status'];
        $owed = in_array($status, ['owed', 'owed_late'], true);
        // Ticked on another draft too, and on this one already: it stays here, following the cost but not ticked by
        // Expend, so issuing either says it's ticked on both.
        $twice = $status === 'on_draft' && $here;
        if ($role === 'work') {
            // A PO sets the money: only extra days are asked about, unticked.
            if ((! $owed && ! $twice) || ($po && ! in_array($u['part'], ['extra', 'meals'], true))) {
                return null;
            }
        } elseif ($role === 'late') {
            if (($status !== 'owed_late' && ! $twice) || $u['kind'] === 'work') {
                return null;
            }
        } elseif ($role === 'client') {
            if (! $owed && ! $twice) {
                return null;
            }
        } elseif (! $owed && ! $twice && ! ($status === 'not_charged' && in_array($s['reason'], ['arrangement', 'unsure'], true))) {
            return null;
        }
        $tick = $owed && (bool) $s['tick'];
        if (isset($u['flags']['no_arrangement']) || ($po && $role === 'work' && $u['part'] === 'extra') || ($poExpenses && $role !== 'work')) {
            $tick = false;
        }
        $difference = (bool) $s['difference'];

        // Its price, in its own currency: never converted.
        $lineCurrency = strtoupper((string) ($u['currency'] ?: 'GBP'));
        $price = null;
        if ($u['kind'] === 'trip') {
            $mileage = $terms['mileage'] ?? ['charge' => null, 'rate_minor' => null, 'currency' => 'GBP'];
            $qty = (string) BigDecimal::of((string) ($difference && $s['remaining'] !== null ? $s['remaining'] : $u['quantity']))->toScale(2);
            if ($mileage['charge'] === 'per_mile' && (int) $mileage['rate_minor'] > 0) {
                [$price, $lineCurrency] = [(int) $mileage['rate_minor'], strtoupper($mileage['currency'] ?: 'GBP')];
            }
        } elseif ($u['kind'] === 'work') {
            $qty = (string) BigDecimal::of((string) ($difference && $s['remaining'] !== null ? $s['remaining'] : $u['quantity']))->toScale(2);
            $price = $u['unit_price_minor'] !== null && (int) $u['unit_price_minor'] > 0 ? (int) $u['unit_price_minor'] : null;
        } else {
            $qty = '1.00';
            $amount = $difference ? $s['remaining'] : $s['amount'];
            if ($amount !== null && ! isset($u['flags']['no_amount'])) {
                $price = ($u['kind'] === 'credit' ? -1 : 1) * abs((int) $amount);
            }
        }
        $priced = $price !== null && $lineCurrency === $currency && BigDecimal::of($qty)->isPositive();
        // The cost's own flags, and its status's (maybe the same as one charged already).
        $flags = $u['flags'] + ($s['flags'] ?? []);
        if (isset($flags['paid_by_other'])) {
            $flags['no_payment'] ??= $flags['paid_by_other'];
        }
        $flag = collect(self::FLAG_ORDER)->first(fn (string $f) => isset($flags[$f]));
        if (! $priced) {
            [$price, $flag, $tick] = [0, 'no_amount', false];
        }
        if ($poExpenses && $role !== 'work') {
            $flag ??= 'maybe_same';
        }

        $title = $job?->title;
        $description = match (true) {
            $u['kind'] === 'trip' => CostWords::mileage((string) $u['date'], $job ? CostWords::venue($job) : null, $qty, $priced ? $price : null, $lineCurrency),
            $u['kind'] === 'work' => $u['description'].($difference && $s['note'] ? ': '.$s['note'] : ''),
            default => $u['description'].' (at cost)'.($difference && $s['note'] ? ': '.$s['note'] : '').($role === 'late' && $title ? ', for '.$title : ''),
        };

        return [
            'ref' => $u['ref'], 'aliases' => array_values(array_diff($u['aliases'], [$u['ref']])), 'role' => $role,
            'source' => $u['kind'] === 'work' ? $u['part'] : ($u['kind'] === 'trip' ? 'mileage' : 'cost'), 'source_job_id' => $u['job_id'] !== null ? (int) $u['job_id'] : null,
            'description' => mb_substr($description, 0, 500), 'quantity' => $qty, 'unit_price_minor' => (int) $price,
            'amount_minor' => InvoiceService::lineAmount($qty, (int) $price), 'included' => $tick, 'flag' => $flag, 'priced' => $priced,
            'unit' => $u, 'status' => $s,
        ];
    }

    /**
     * Whether a cost the draft would tick may already be on one of your own ticked lines (it names the provider, or
     * it's for travel or expenses, at the same amount): then it's listed unticked, flagged maybe_same, until you say.
     *
     * @param  Collection<int, InvoiceLine>  $typed
     */
    private static function onYourLine(array $w, Collection $typed): bool
    {
        if (! in_array($w['role'], ['cost', 'late', 'client'], true) || ! $w['included']) {
            return false;
        }
        $same = $typed->filter(fn (InvoiceLine $t) => (bool) $t->included && (int) $t->amount_minor === (int) $w['amount_minor']);

        return $same->isNotEmpty() && CostWords::couldHold($w['unit']['words'] ?? [], $same) !== null;
    }

    /**
     * What changes on a line that follows its cost: never what you set yourself. A line held unticked as maybe on one
     * of your own lines ($held) stays unticked and flagged until you tick it or say it's the same.
     *
     * @return array<string, mixed>
     */
    private static function follow(InvoiceLine $line, array $w, bool $held = false): array
    {
        $want = [
            'source' => $w['source'], 'source_ref' => $w['ref'], 'source_job_id' => $w['source_job_id'],
            'source_amount_minor' => $w['priced'] ? (int) $w['amount_minor'] : null, 'source_quantity' => $w['quantity'],
        ];
        if (! $line->own_text) {
            $want['description'] = $w['description'];
        }
        if (! $line->own_amount) {
            $want += ['quantity' => $w['quantity'], 'unit_price_minor' => (int) $w['unit_price_minor'], 'amount_minor' => (int) $w['amount_minor']];
        }
        if ($line->ticked_by !== 'you') {
            $want['included'] = (bool) $w['included'] && ! $held;
        }
        // Its flag follows the cost's, except that your own price answers "no price" (and a check on the work's
        // figures), and a "maybe the same as your line" you haven't answered stays while your line could hold it.
        $flag = $w['flag'];
        if ($line->own_amount && ($flag === 'no_amount' || ($flag === 'check' && $w['role'] === 'work'))) {
            $flag = null;
        }
        if ($flag === null && $held) {
            $flag = 'maybe_same';
        }
        $want['flag'] = $flag;

        return self::diff($line, $want);
    }

    /** A typed line on a draft made before line sources that becomes the job's work line, in your words. */
    private static function takeover(InvoiceLine $line, array $w): array
    {
        $same = BigDecimal::of((string) $line->quantity)->isEqualTo($w['quantity']) && (int) $line->unit_price_minor === (int) $w['unit_price_minor'];

        return self::diff($line, [
            'source' => $w['source'], 'source_ref' => $w['ref'], 'source_job_id' => $w['source_job_id'], 'own_text' => true, 'own_amount' => ! $same,
            'source_amount_minor' => (int) $w['amount_minor'], 'source_quantity' => $w['quantity'],
        ]);
    }

    /** @return array<string, mixed> the fields that differ */
    private static function diff(InvoiceLine $line, array $want): array
    {
        $out = [];
        foreach ($want as $field => $value) {
            $now = $line->{$field};
            $same = match ($field) {
                'quantity', 'source_quantity' => $now !== null && $value !== null ? BigDecimal::of((string) $now)->isEqualTo((string) $value) : $now === $value,
                'included', 'own_text', 'own_amount' => (bool) $now === (bool) $value,
                'unit_price_minor', 'amount_minor', 'source_amount_minor', 'source_job_id' => ($now === null ? null : (int) $now) === ($value === null ? null : (int) $value),
                default => $now === $value,
            };
            if (! $same) {
                $out[$field] = $value;
            }
        }

        return $out;
    }

    /** @return array<string, mixed> what a new line is made with */
    private static function columns(array $w): array
    {
        return [
            'description' => $w['description'], 'quantity' => $w['quantity'], 'unit_price_minor' => (int) $w['unit_price_minor'], 'amount_minor' => (int) $w['amount_minor'],
            'sort' => (int) $w['sort'], 'source' => $w['source'], 'source_ref' => $w['ref'], 'source_job_id' => $w['source_job_id'], 'included' => (bool) $w['included'],
            'flag' => $w['flag'], 'source_amount_minor' => $w['priced'] ? (int) $w['amount_minor'] : null, 'source_quantity' => $w['quantity'],
        ];
    }

    /** Empty details the job and its client can fill: the PO number, the client and the bill-to name, email and address. */
    private static function fill(Invoice $invoice, ?WorkJob $job, ?int $payer): array
    {
        $fill = [];
        if ($job !== null && trim((string) $invoice->po_number) === '' && trim((string) $job->po_number) !== '') {
            $fill['po_number'] = mb_substr((string) $job->po_number, 0, 100);
        }
        if ($invoice->client_id === null && $job?->client_id !== null) {
            $fill['client_id'] = (int) $job->client_id;
        }
        $client = $payer === null ? null : Client::query()->find($payer);
        if ($client !== null) {
            $name = trim((string) ($client->invoice_name ?: $client->name));
            if (trim((string) $invoice->bill_to_name) === '' && $name !== '') {
                $fill['bill_to_name'] = mb_substr($name, 0, 200);
            }
            $email = InvoiceService::emailAddress($client->billing_email);
            if (trim((string) $invoice->bill_to_email) === '' && $email !== null) {
                $fill['bill_to_email'] = $email;
            }
            if (trim((string) $invoice->bill_to_address) === '' && trim((string) $client->billing_address) !== '') {
                $fill['bill_to_address'] = mb_substr((string) $client->billing_address, 0, 1000);
            }
        }

        return $fill;
    }

    /**
     * The draft a client's late costs and their own recharges go on: their oldest open draft with no job (one
     * draftForClient made), else their oldest open draft.
     */
    private static function chosenDraft(int $clientId): ?int
    {
        $ids = ClientIds::withMerged($clientId);
        $jobs = WorkJob::query()->whereIn('client_id', $ids)->pluck('id')->all();
        $drafts = Invoice::query()->where('status', 'draft')
            ->where(fn ($q) => $q->whereIn('client_id', $ids)->orWhere(fn ($q) => $q->whereNull('client_id')->whereIn('work_job_id', $jobs ?: [0])))
            ->orderBy('id')->get(['id', 'work_job_id']);
        $draft = $drafts->first(fn (Invoice $i) => $i->work_job_id === null) ?? $drafts->first();

        return $draft ? (int) $draft->id : null;
    }

    /** The paying client's row, or the job's when there's no client: locked after the audit head, before the invoice. */
    private function lockOwner(Invoice $invoice): void
    {
        $payer = self::payer($invoice);
        if ($payer !== null) {
            Client::query()->whereKey($payer)->lockForUpdate()->first(['id']);
        } elseif ($invoice->work_job_id !== null) {
            WorkJob::query()->whereKey($invoice->work_job_id)->lockForUpdate()->first(['id']);
        }
    }

    /** @return array<string, mixed> what an event says about a line */
    private static function snapshot(InvoiceLine $line): array
    {
        return ['line' => (int) $line->id, 'ref' => $line->source_ref, 'description' => $line->description, 'quantity' => (string) $line->quantity,
            'amount' => (int) $line->amount_minor, 'included' => (bool) $line->included, 'flag' => $line->flag];
    }

    /** @return array<string, mixed> */
    private static function only(InvoiceLine $line, array $fields): array
    {
        $out = [];
        foreach ($fields as $f) {
            $v = $line->{$f};
            $out[$f] = match ($f) {
                'quantity', 'source_quantity' => $v === null ? null : (string) $v,
                'included', 'own_text', 'own_amount' => (bool) $v,
                default => $v,
            };
        }

        return $out;
    }

    /** @param  list<int>  $ids */
    private static function changeQuery(array $ids)
    {
        return AuditEvent::query()->where('subject_type', 'Invoice')->whereIn('subject_id', $ids ?: [0])->whereIn('action', self::CHANGE_ACTIONS)
            ->where(fn ($q) => $q->where('actor', 'policy')->orWhere('action', 'invoice.converted'));
    }

    /**
     * Each draft's first fill isn't a change: changes count from the event that made it.
     *
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    private static function floors(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return AuditEvent::query()->where('subject_type', 'Invoice')->whereIn('subject_id', $ids)->where('action', 'invoice.drafted')
            ->groupBy('subject_id')->selectRaw('subject_id, MAX(id) AS floor')->pluck('floor', 'subject_id')->map(fn ($v) => (int) $v)->all();
    }
}
