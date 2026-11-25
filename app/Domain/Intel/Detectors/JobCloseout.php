<?php

namespace App\Domain\Intel\Detectors;

use App\Domain\Charges\ChargeStatus;
use App\Domain\Charges\ChargeTerms;
use App\Domain\Charges\CostRef;
use App\Domain\Charges\CostUnits;
use App\Domain\Charges\CostWords;
use App\Domain\Intel\Contracts\Detector;
use App\Domain\Intel\Coverage;
use App\Domain\Intel\Cycle\CycleContext;
use App\Domain\Intel\FindingDraft;
use App\Domain\Intel\Grades;
use App\Domain\Intel\Ref;
use App\Domain\Invoices\InvoiceDraftSync;
use App\Domain\Invoices\InvoicePayments;
use App\Domain\Invoices\InvoiceService;
use App\Domain\Todo\TodoList;
use App\Models\Allocation;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\TodoDismissal;
use App\Models\TravelItem;
use App\Models\WorkJob;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Jobs to close out (job.closeout, M4; version 3): one verdict per finished job (and per cancelled job that still owes
 * a fee or costs), from everything that says what you're owed for it: the PO's total against what's been invoiced, and
 * the costs to charge that aren't on an invoice. What's short of the PO and the costs not found on an invoice are one
 * amount you may be owed, in the job's currency.
 *
 * Two ways, by whether the job is covered (Charges\ChargeStatus::covered):
 *  - Covered (invoiced before Expend tracked where lines come from, billed by income recorded with no Expend invoice,
 *    or "Already sorted"): exactly as version 2. The PO against what the invoices total (or what came in), and the
 *    bookings you decided to recharge against the invoice's lines (a line of the same amount, or one line for several
 *    of them together). Its costs are asked about rather than added to a draft; one you've decided since (it was on an
 *    invoice, not charged, or charge it now) goes by your decision.
 *  - Otherwise (no invoice, a draft, or an invoice Expend tracks): the costs come from ChargeStatus, by ref. Each cost
 *    still owed and expected to be charged (in the job's currency, with a known amount), plus a day added after the
 *    invoice went, is looked for among the invoices' own lines (typed or the PO's: a line of its amount, or one line for
 *    several), as version 2 does for bookings. The PO is compared with the ticked work lines only (the PO's, the fee,
 *    days, hours, extra and travel days, and your own lines): costs on the invoice no longer hide a fee billed short of
 *    the PO, unless the PO itself has a line for travel or expenses. Lines are ticked lines only.
 *
 * It subsumes the to-do list's "invoice less than its PO" and "recharge to the client" items for finished jobs
 * (underbilled:{invoice}, recharge:{booking}) that it accounts for: those aren't listed for a finished job any more,
 * so nothing is counted twice, and the ones it replaces are cited in its evidence and named in subsumed(). Left as they
 * were: one you snoozed or marked not relevant (what it said is left out of the close-out, so your decision stands), a
 * recharge with no amount, cancelled or in another currency, and an invoice in another currency. A close-out that
 * replaces items that were on the list keeps their place on it (priority 1, not below the fold); otherwise it's new,
 * and starts low (Intel\DetectorLevels). A job with no invoice yet offers the usual "Draft the invoice".
 *
 * A snooze or "not relevant" on a close-out is kept by its parts as you saw them (dismissKey: closeout:{job}:{parts},
 * TodoList::dismissKeyFor()): what's short of the PO (sGBP15000), each booking (r{booking}) and each other cost
 * (c{number from its ref}). A part you hid stays hidden (what's short, while it's no more than you saw); a new one (a
 * booking decided as a recharge later, a cost linked later) brings the close-out back with only what's new.
 *
 * Grade: strong when the PO's total was found word for word in the PO, likely when it wasn't (or the PO was linked to
 * the job automatically); for costs, strong when no line of the invoice could hold them, likely when a line that's left
 * could (it names the provider, or it's for travel or expenses) or the job was invoiced outside Expend. Only reads.
 */
final class JobCloseout implements Detector
{
    public const CODE = 'job.closeout';

    public const VERSION = 3;

    /** Words on an invoice line that say it may carry costs passed on to the client. */
    public const EXPENSE_WORDS = ['travel', 'hotel', 'accommodation', 'expense', 'recharge', 'train', 'rail', 'flight', 'taxi', 'parking', 'mileage', 'subsistence', 'car hire',
        'per diem', 'disbursement', 'at cost'];

    /** One line for several recharges is looked for among at most this many of a job's (every combination of them). */
    private const MAX_COMBINED = 10;

    private ?int $checked = null;

    private Coverage $coverage = Coverage::Failed;

    /** @var array<string, ?string> */
    private array $subsumed = [];

    /** @param  list<int>|null  $only  only these jobs (to work out one close-out's parts: TodoList::dismissKeyFor()) */
    public function __construct(private readonly ?array $only = null) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function coverage(): Coverage
    {
        return $this->coverage;
    }

    /** The finished jobs the last detect() looked at (null: it didn't finish). */
    public function checked(): ?int
    {
        return $this->checked;
    }

    /**
     * The to-do list's items for finished jobs that the last detect() accounted for instead (underbilled:{invoice},
     * recharge:{booking}), each with the close-out that now says it (null: nothing is owed for that job any more).
     *
     * @return array<string, ?string>
     */
    public function subsumed(): array
    {
        return $this->subsumed;
    }

    /**
     * Finished, as the to-do list counts it: its last day before today, and not cancelled unless it was cancelled with
     * something still owed (a cancellation fee, or its costs).
     */
    public static function finished(): Builder
    {
        return WorkJob::query()->where(fn ($q) => $q->where('status', '!=', 'cancelled')->orWhereIn('cancel_charge', ['fee', 'costs']))
            ->whereDate('ends_on', '<', today()->format('Y-m-d'));
    }

    /** @return list<FindingDraft> */
    public function detect(CycleContext $ctx): array
    {
        $this->coverage = Coverage::Failed;
        $this->checked = null;
        $this->subsumed = [];
        // Your snoozes and "not relevant"s running now: on the items a close-out would say, and on close-outs.
        $hidden = TodoDismissal::activeKeys();
        $jobs = self::finished()->when($this->only !== null, fn ($q) => $q->whereIn('id', $this->only ?: [0]))->get()->keyBy('id');
        $ids = $jobs->keys()->all();
        $invoices = collect();
        $recharges = collect();
        foreach (array_chunk($ids, 500) as $chunk) {
            $invoices = $invoices->concat(Invoice::query()->whereIn('work_job_id', $chunk)->where('status', '!=', 'void')->orderBy('id')
                ->get(['id', 'number', 'work_job_id', 'document_id', 'status', 'currency', 'total_minor', 'po_total_minor', 'issue_date', 'synced_at']));
            $recharges = $recharges->concat(TravelItem::query()->whereIn('work_job_id', $chunk)->where('decision', 'recharge')->orderBy('starts_on')->orderBy('id')
                ->get(['id', 'kind', 'provider', 'place', 'status', 'starts_on', 'amount_minor', 'currency', 'work_job_id']));
        }
        $invoices = $invoices->groupBy('work_job_id');
        $recharges = $recharges->groupBy('work_job_id');
        // Ticked lines only: what each invoice charges.
        $lines = collect();
        foreach ($invoices->flatten()->pluck('id')->chunk(500) as $chunk) {
            $lines = $lines->concat(InvoiceLine::query()->whereIn('invoice_id', $chunk->values()->all())->where('included', true)
                ->orderBy('invoice_id')->orderBy('sort')->orderBy('id')->get(['id', 'invoice_id', 'description', 'amount_minor', 'source', 'source_ref']));
        }
        $lines = $lines->groupBy('invoice_id');
        $needPo = array_values(array_filter($ids, fn ($id) => ! ($invoices[$id] ?? collect())->contains(fn (Invoice $i) => $i->po_total_minor !== null)));
        $poDocs = collect();
        foreach (array_chunk($needPo, 500) as $chunk) {
            $poDocs = $poDocs->concat(Document::query()->whereIn('work_job_id', $chunk)->where('kind', 'po')->whereNotNull('extraction')
                ->get(['id', 'work_job_id', 'sha256', 'extraction', 'job_linked_by']));
        }
        $poDocs = $poDocs->groupBy('work_job_id');
        $invoiceDocs = Document::query()->whereIn('id', $invoices->flatten()->pluck('document_id')->filter()->unique()->values()->all() ?: [0])
            ->get(['id', 'sha256', 'extraction', 'job_linked_by'])->keyBy('id');
        // Income recorded for the jobs with no invoice issued in Expend: invoiced some other way, perhaps.
        $income = self::income(array_values(array_filter($ids, fn ($id) => ! ($invoices[$id] ?? collect())->contains(fn (Invoice $i) => in_array($i->status, ['issued', 'paid'], true)))));
        $closed = self::closedParts($hidden);
        // Where each cost stands (by ref), for the jobs whose costs it looks at (every job that isn't covered, and covered
        // jobs with bookings to recharge), with a day added after the invoice went for jobs it tracks.
        $covered = ChargeStatus::covered($ids);
        $costed = $jobs->filter(fn (WorkJob $j) => ! isset($covered[(int) $j->id]) || $covered[(int) $j->id]['work_only'] || isset($recharges[$j->id]));
        $terms = $costed->isEmpty() ? [] : ChargeTerms::forJobs($costed);
        $units = $costed->isEmpty() ? [] : CostUnits::forJobs($costed, $terms);
        $tracked = $costed->filter(fn (WorkJob $j) => ($invoices[$j->id] ?? collect())->contains(fn (Invoice $i) => in_array($i->status, InvoicePayments::PAYABLE, true) && $i->synced_at !== null));
        if ($tracked->isNotEmpty()) {
            (new EloquentCollection($tracked->values()->all()))->load('days');
            foreach ($tracked as $id => $job) {
                array_push($units[(int) $id], ...CostUnits::work($job));
            }
        }
        $all = collect($units)->flatten(1)->values()->all();
        $statuses = $all === [] ? [] : ChargeStatus::for($all, null, $covered, $terms);

        $views = [];
        foreach ($jobs as $id => $job) {
            $cover = $covered[(int) $id] ?? null;
            $v = $cover !== null && ! $cover['work_only']
                ? $this->verdict($job, $invoices[$id] ?? collect(), $recharges[$id] ?? collect(), $lines, $poDocs[$id] ?? collect(), $invoiceDocs, $income[$id] ?? [], $hidden,
                    $units[(int) $id] ?? [], $statuses)
                : $this->tracked($job, $invoices[$id] ?? collect(), $recharges[$id] ?? collect(), $lines, $poDocs[$id] ?? collect(), $invoiceDocs, $hidden,
                    $units[(int) $id] ?? [], $statuses, $terms[(int) $id] ?? []);
            $parts = self::parts($v);
            foreach ($v['legacy'] as $key) {
                $this->subsumed[$key] = $parts !== [] ? 'closeout:'.$id : null;
            }
            if ($parts === []) {
                continue;
            }
            // A close-out you snoozed or marked not relevant: the parts you hid stay hidden; what's new is shown alone.
            [$left, $by] = self::uncovered((int) $id, $parts, $closed);
            if ($left === []) {
                $views[$id] = ['v' => $v, 'dismiss_key' => self::dismissKey((int) $id, $parts), 'hidden_under' => $by];
            } else {
                $views[$id] = ['v' => self::only($v, $left), 'dismiss_key' => self::dismissKey((int) $id, $left), 'hidden_under' => []];
            }
        }

        $drafts = $this->drafts($jobs, $views, $invoices);
        $this->checked = count($ids);
        $this->coverage = Coverage::Complete;

        return $drafts;
    }

    /**
     * What's owed for a covered job (version 2's verdict): what's short of the PO and the recharges not found on an
     * invoice line (each to be shown unless you hid it), with the to-do items it accounts for ('legacy'). A booking you
     * decided about since (it was on an invoice, not charged, or charge it now) goes by your decision; one you said to
     * charge now is listed as not on the invoice.
     *
     * @param  array<string, int>  $income  confirmed income recorded for the job, by currency (hundredths)
     * @param  array<string, true>  $hidden  keys you snoozed or marked not relevant
     * @param  list<array<string, mixed>>  $units  the job's costs (CostUnits)
     * @return array{currency:string, po:?array, billed:int, billed_by:string, short:int, unbilled:list<array{key:string, amount:int, words:string, ref:string, line:?string}>, legacy:list<string>, evidence:list<string>}
     */
    private function verdict(WorkJob $job, Collection $invoices, Collection $recharges, Collection $lines, Collection $poDocs, Collection $invoiceDocs, array $income, array $hidden,
        array $units, array $statuses): array
    {
        $issued = $invoices->whereIn('status', ['issued', 'paid']);
        $billedBy = $issued->isNotEmpty() ? 'issued' : ($invoices->isNotEmpty() ? 'draft' : 'none');
        $counted = $billedBy === 'issued' ? $issued : $invoices;
        $po = $this->po($invoices, $poDocs, $invoiceDocs);
        $currency = self::currencyOf($invoices, $recharges, $po);
        $billed = (int) $counted->filter(fn (Invoice $i) => strtoupper($i->currency ?: 'GBP') === $currency)->sum('total_minor');
        // Nothing issued in Expend, but money came in for the job: it was invoiced some other way (before Expend, by
        // hand), so what came in counts as invoiced.
        if ($billedBy !== 'issued' && ($income[$currency] ?? 0) > $billed) {
            $billed = (int) $income[$currency];
            $billedBy = 'income';
        }
        // What said "less than its PO" before: each invoice in the job's currency for less than its PO (one in another
        // currency stays as it was). Set aside by you (one of those, or with nothing issued the job's "Invoice it"
        // item, snoozed or marked not relevant): nothing short is said, and those items stay as you left them.
        $underbilled = $invoices->filter(fn (Invoice $i) => in_array($i->status, ['issued', 'draft'], true) && $i->po_total_minor !== null
            && (int) $i->total_minor < (int) $i->po_total_minor && strtoupper($i->currency ?: 'GBP') === $currency)->map(fn (Invoice $i) => 'underbilled:'.$i->id)->values()->all();
        $setAside = collect($underbilled)->contains(fn (string $k) => isset($hidden[$k])) || ($billedBy !== 'issued' && isset($hidden['invoice:'.$job->id]));
        $short = ! $setAside && $po !== null && $po['currency'] === $currency ? max(0, $po['total'] - $billed) : 0;
        $legacy = $setAside ? [] : $underbilled;

        // Your decisions since: a booking's cost (its ref, or the payment it's linked to) that isn't covered any more.
        $statusOf = [];
        foreach ($units as $u) {
            foreach ([$u['ref'], ...$u['aliases']] as $r) {
                $statusOf[$r] = $statuses[$u['ref']] ?? null;
            }
        }
        $late = [];
        // The recharges it counts: in the job's currency, with an amount, not cancelled, and not hidden by you. The
        // rest stay on the list as they were.
        $mine = [];
        foreach ($recharges as $t) {
            if (isset($hidden['recharge:'.$t->id]) || $t->status === 'cancelled' || $t->amount_minor === null || (int) $t->amount_minor <= 0
                || strtoupper($t->currency ?: 'GBP') !== $currency) {
                continue;
            }
            $legacy[] = 'recharge:'.$t->id;
            $s = $statusOf['travel:'.$t->id] ?? null;
            if ($s !== null && $s['status'] !== 'covered') {
                if (in_array($s['status'], ['owed', 'owed_late'], true)) {
                    $late[] = self::booking($t, (int) $t->amount_minor);
                }

                continue;
            }
            $mine[] = self::booking($t, (int) $t->amount_minor);
        }
        // Each against the invoices' lines: a line of the same amount, each line once; then one line for several
        // together ("Travel and hotel, at cost").
        $free = $invoices->flatMap(fn (Invoice $i) => $lines[$i->id] ?? collect())->values()->all();
        [$open, $free] = self::match($mine, $free);
        // Not found on a line. A line that's left may still hold it (it names the provider, or it's for travel or
        // expenses, at another amount), and an invoice sent outside Expend can't be seen: then it's to check.
        $unbilled = array_map(fn (array $c) => ['line' => $billedBy === 'income' ? '' : CostWords::couldHold($c['words_hold'], $free)] + $c, $open);
        $unbilled = [...$unbilled, ...array_map(fn (array $c) => ['line' => null] + $c, $late)];

        $openKeys = array_flip(array_column($unbilled, 'key'));
        $evidence = [
            ...($short > 0 && $po['ref'] ? [$po['ref']] : []),
            ...$invoices->map(fn (Invoice $i) => Ref::make('inv', $i->id))->all(),
            ...array_column($unbilled, 'ref'),
            ...array_column(array_filter($mine, fn (array $c) => ! isset($openKeys[$c['key']])), 'ref'),
        ];

        return ['currency' => $currency, 'po' => $po, 'billed' => $billed, 'billed_by' => $billedBy, 'short' => $short, 'unbilled' => $unbilled, 'legacy' => $legacy,
            'evidence' => $evidence];
    }

    /**
     * What's owed for a job that isn't covered (no invoice, a draft, or an invoice Expend tracks): what's short of the
     * PO (against the ticked work lines), and each cost still owed and expected to be charged that no line of the
     * job's invoices holds.
     *
     * @param  array<string, true>  $hidden
     * @param  list<array<string, mixed>>  $units
     * @param  array<string, array{charge:?string, rate_minor:?int, currency:string, from:string}>  $terms
     * @return array{currency:string, po:?array, billed:int, billed_by:string, short:int, unbilled:list<array{key:string, amount:int, words:string, ref:string, line:?string}>, legacy:list<string>, evidence:list<string>}
     */
    private function tracked(WorkJob $job, Collection $invoices, Collection $recharges, Collection $lines, Collection $poDocs, Collection $invoiceDocs, array $hidden,
        array $units, array $statuses, array $terms): array
    {
        $issued = $invoices->whereIn('status', ['issued', 'paid']);
        $billedBy = $issued->isNotEmpty() ? 'issued' : ($invoices->isNotEmpty() ? 'draft' : 'none');
        $counted = $billedBy === 'issued' ? $issued : $invoices;
        $po = $this->po($invoices, $poDocs, $invoiceDocs);
        $currency = self::currencyOf($invoices, $recharges, $po);
        $inCurrency = $counted->filter(fn (Invoice $i) => strtoupper($i->currency ?: 'GBP') === $currency);
        // The PO against the work billed: costs on the invoice don't make up for a fee billed short of it, unless the
        // PO itself has a line for travel or expenses.
        $billed = ($po['expenses'] ?? false) ? (int) $inCurrency->sum('total_minor')
            : (int) $inCurrency->flatMap(fn (Invoice $i) => $lines[$i->id] ?? collect())
                ->filter(fn ($l) => $l->source === null || in_array($l->source, InvoiceDraftSync::WORK_SOURCES, true))->sum('amount_minor');
        $underbilled = $invoices->filter(fn (Invoice $i) => in_array($i->status, ['issued', 'draft'], true) && $i->po_total_minor !== null
            && (int) $i->total_minor < (int) $i->po_total_minor && strtoupper($i->currency ?: 'GBP') === $currency)->map(fn (Invoice $i) => 'underbilled:'.$i->id)->values()->all();
        $setAside = collect($underbilled)->contains(fn (string $k) => isset($hidden[$k])) || ($billedBy !== 'issued' && isset($hidden['invoice:'.$job->id]));
        $short = ! $setAside && $po !== null && $po['currency'] === $currency ? max(0, $po['total'] - $billed) : 0;
        $legacy = $setAside ? [] : $underbilled;

        $bookings = $recharges->keyBy('id');
        foreach ($recharges as $t) {
            if (! isset($hidden['recharge:'.$t->id]) && $t->status !== 'cancelled' && $t->amount_minor !== null && (int) $t->amount_minor > 0
                && strtoupper($t->currency ?: 'GBP') === $currency) {
                $legacy[] = 'recharge:'.$t->id;
            }
        }
        // Each cost still owed and expected on the invoice, in the job's currency, with a known amount; and a day
        // added after the invoice went.
        $mine = [];
        foreach ($units as $u) {
            $s = $statuses[$u['ref']] ?? null;
            if ($s === null || ! in_array($s['status'], ['owed', 'owed_late'], true) || ! $s['expected'] || $u['kind'] === 'credit'
                || strtoupper((string) $u['currency']) !== $currency || $s['remaining'] === null) {
                continue;
            }
            $booking = collect([$u['ref'], ...$u['aliases']])->first(fn ($r) => str_starts_with($r, 'travel:'));
            $t = $booking !== null ? ($bookings[(int) substr($booking, 7)] ?? null) : null;
            if ($t !== null && isset($hidden['recharge:'.$t->id])) {
                continue;
            }
            $amount = match ($u['kind']) {
                'work' => $s['difference'] && $u['unit_price_minor'] !== null ? InvoiceService::lineAmount((string) $s['remaining'], (int) $u['unit_price_minor']) : 0,
                'trip' => ($terms['mileage']['charge'] ?? null) === 'per_mile' && strtoupper($terms['mileage']['currency'] ?? 'GBP') === $currency
                    ? InvoiceService::lineAmount((string) $s['remaining'], (int) $terms['mileage']['rate_minor']) : 0,
                default => abs((int) ($s['difference'] ? $s['remaining'] : $s['amount'])),
            };
            if ($amount <= 0) {
                continue;
            }
            $mine[] = $t !== null ? self::booking($t, $amount) : [
                'key' => 'c'.sprintf('%u', crc32($u['ref'])), 'amount' => $amount, 'ref' => self::evidenceRef($u, $job),
                'words' => ($u['kind'] === 'work' && $s['note'] ? $job->title.': '.$s['note'] : $u['description']).' '.Money::format($amount, $currency, false),
                'words_hold' => $u['words'] ?? [],
            ];
        }
        $free = $invoices->flatMap(fn (Invoice $i) => ($lines[$i->id] ?? collect())->filter(fn ($l) => $l->source === null || $l->source === 'po'))->values()->all();
        [$open, $free] = self::match($mine, $free);
        $unbilled = array_map(fn (array $c) => ['line' => CostWords::couldHold($c['words_hold'], $free)] + $c, $open);

        $openKeys = array_flip(array_column($unbilled, 'key'));
        $evidence = [
            ...($short > 0 && $po['ref'] ? [$po['ref']] : []),
            ...$invoices->map(fn (Invoice $i) => Ref::make('inv', $i->id))->all(),
            ...array_column($unbilled, 'ref'),
            ...array_column(array_filter($mine, fn (array $c) => ! isset($openKeys[$c['key']])), 'ref'),
        ];

        return ['currency' => $currency, 'po' => $po, 'billed' => $billed, 'billed_by' => $billedBy, 'short' => $short, 'unbilled' => $unbilled, 'legacy' => $legacy,
            'evidence' => $evidence];
    }

    /** A booking as a close-out says it: "Hotel Premier Inn Arena 2 Jun £180.00". */
    private static function booking(TravelItem $t, int $amount): array
    {
        $currency = strtoupper($t->currency ?: 'GBP');

        return ['key' => 'r'.$t->id, 'amount' => $amount, 'ref' => Ref::make('travel', $t->id), 'words_hold' => [$t->provider],
            'words' => (TravelItem::KINDS[$t->kind] ?? 'Booking').' '.($t->kind === 'hotel' ? ($t->place ?: $t->provider) : $t->provider).' '.$t->starts_on->format('j M')
                .' '.Money::format($amount, $currency, false)];
    }

    /** What a cost's evidence cites: its payment, expense, booking or trip, or the job for its work. */
    private static function evidenceRef(array $u, WorkJob $job): string
    {
        $p = CostRef::parse($u['ref']);

        return match ($p['type']) {
            'tx', 'exp', 'travel', 'trip' => Ref::make($p['type'], $p['id']),
            default => Ref::make('job', $job->id),
        };
    }

    /**
     * Costs against the invoices' lines: a line of the same amount, each line once; then one line for several together.
     *
     * @param  list<array{key:string, amount:int}>  $costs
     * @param  array<int, object>  $free
     * @return array{0:list<array>, 1:array<int, object>} the costs not found, and the lines not used
     */
    private static function match(array $costs, array $free): array
    {
        $open = [];
        foreach ($costs as $c) {
            $hit = null;
            foreach ($free as $n => $line) {
                if ((int) $line->amount_minor === (int) $c['amount']) {
                    $hit = $n;
                    break;
                }
            }
            if ($hit === null) {
                $open[] = $c;
            } else {
                unset($free[$hit]);
            }
        }

        return [self::combined($open, $free), $free];
    }

    /**
     * The costs left once a line that's the sum of several of them is found ("Travel and hotel, at cost" for a train
     * and a hotel): each such line once. Lines that say they're for expenses are tried first.
     *
     * @param  list<array{key:string, amount:int}>  $open
     * @param  array<int, InvoiceLine>  $free  the lines not yet used (those used here are taken out)
     * @return list<array{key:string, amount:int}>
     */
    private static function combined(array $open, array &$free): array
    {
        if (count($open) < 2 || $free === []) {
            return $open;
        }
        $order = collect($free)->sortBy(fn (InvoiceLine $l) => CostWords::forExpenses((string) $l->description) ? 0 : 1)->keys()->all();
        foreach ($order as $n) {
            $pool = array_slice($open, 0, self::MAX_COMBINED);
            if (count($pool) < 2) {
                break;
            }
            $target = (int) $free[$n]->amount_minor;
            for ($mask = 3; $target > 0 && $mask < (1 << count($pool)); $mask++) {
                $picked = array_keys(array_filter($pool, fn ($t, $b) => (($mask >> $b) & 1) === 1, ARRAY_FILTER_USE_BOTH));
                if (count($picked) < 2 || array_sum(array_map(fn ($b) => (int) $pool[$b]['amount'], $picked)) !== $target) {
                    continue;
                }
                unset($free[$n]);
                $taken = array_flip(array_map(fn ($b) => $pool[$b]['key'], $picked));
                $open = array_values(array_filter($open, fn (array $c) => ! isset($taken[$c['key']])));
                break;
            }
        }

        return $open;
    }

    /**
     * Confirmed business income recorded for each job, by the currency it came in (hundredths), as InvoicePayments
     * counts it. One query per 500 jobs.
     *
     * @param  list<int>  $jobIds
     * @return array<int, array<string, int>>
     */
    private static function income(array $jobIds): array
    {
        $out = [];
        foreach (array_chunk($jobIds, 500) as $chunk) {
            $rows = Allocation::query()->where('kind', 'business_income')->whereNotNull('confirmed_at')->whereIn('work_job_id', $chunk)
                ->with(['transaction:id,amount_pence,original_amount_minor,original_currency', 'manualExpense:id,amount_pence,original_amount_minor,original_currency'])
                ->get(['id', 'bank_transaction_id', 'manual_expense_id', 'work_job_id', 'amount_pence']);
            foreach ($rows as $a) {
                if ($a->bank_transaction_id && ! $a->transaction) {
                    continue; // a line from a held or undone statement counts nowhere
                }
                $source = $a->transaction ?? $a->manualExpense;
                $currency = strtoupper((string) $source?->original_currency);
                if ($currency === '' || $currency === 'GBP' || ! $source->original_amount_minor || ! $source->amount_pence) {
                    [$currency, $minor] = ['GBP', (int) $a->amount_pence];
                } else {
                    $minor = (int) Money::toHundredths((int) round($a->amount_pence * abs($source->original_amount_minor) / abs($source->amount_pence)), $currency);
                }
                $out[(int) $a->work_job_id][$currency] = ($out[(int) $a->work_job_id][$currency] ?? 0) + $minor;
            }
        }

        return $out;
    }

    /**
     * A close-out's parts, by name: what's short of the PO (s{currency}{amount}), each booking not found on an invoice
     * (r{booking}) and each other cost (c{number}), with their amounts.
     *
     * @return array<string, int>
     */
    private static function parts(array $v): array
    {
        $out = $v['short'] > 0 ? ['s'.$v['currency'].$v['short'] => $v['short']] : [];
        foreach ($v['unbilled'] as $u) {
            $out[$u['key']] = (int) $u['amount'];
        }

        return $out;
    }

    /** The key a snooze or "not relevant" on a close-out with these parts is kept under. */
    private static function dismissKey(int $job, array $parts): string
    {
        $key = 'closeout:'.$job.':'.implode('.', array_keys($parts));

        return strlen($key) <= 191 ? $key : 'closeout:'.$job.':h'.substr(md5(implode('.', array_keys($parts))), 0, 12);
    }

    /**
     * The parts of close-outs you snoozed or marked not relevant (closeout:{job}:{parts}), by job: what's short of the
     * PO by currency, and each booking or cost, with the key each is hidden under.
     *
     * @param  array<string, true>  $hidden
     * @return array<int, array{keys:array<string, true>, short?:array<string, array<string, int>>, parts?:array<string, string>}>
     */
    private static function closedParts(array $hidden): array
    {
        $out = [];
        foreach (array_keys($hidden) as $key) {
            if (! preg_match('/^closeout:(\d+):(.+)$/', (string) $key, $m)) {
                continue;
            }
            $job = (int) $m[1];
            $out[$job]['keys'][$key] = true;
            foreach (explode('.', $m[2]) as $part) {
                if (preg_match('/^s([A-Z]{3})(\d+)$/', $part, $s)) {
                    $out[$job]['short'][$s[1]][$key] = (int) $s[2];
                } elseif (preg_match('/^[rc]\d+$/', $part)) {
                    $out[$job]['parts'][$part] = $key;
                }
            }
        }

        return $out;
    }

    /**
     * The parts not hidden by a snooze or "not relevant" on the close-out, and the keys hiding the rest. What's short
     * of the PO stays hidden while it's no more than you saw; a booking or a cost, by itself.
     *
     * @param  array<string, int>  $parts
     * @return array{0: array<string, int>, 1: list<string>}
     */
    private static function uncovered(int $job, array $parts, array $closed): array
    {
        $c = $closed[$job] ?? null;
        if ($c === null) {
            return [$parts, []];
        }
        $whole = self::dismissKey($job, $parts);
        if (isset($c['keys'][$whole])) {
            return [[], [$whole]];
        }
        $left = [];
        $by = [];
        foreach ($parts as $name => $amount) {
            $key = null;
            if (preg_match('/^s([A-Z]{3})(\d+)$/', $name, $m)) {
                foreach ($c['short'][$m[1]] ?? [] as $k => $seen) {
                    if ($seen >= (int) $m[2]) {
                        $key = $k;
                        break;
                    }
                }
            } else {
                $key = $c['parts'][$name] ?? null;
            }
            if ($key === null) {
                $left[$name] = $amount;
            } else {
                $by[] = $key;
            }
        }

        return [$left, array_values(array_unique($by))];
    }

    /** A verdict with only these parts shown. @param  array<string, int>  $parts */
    private static function only(array $v, array $parts): array
    {
        $v['short'] = isset($parts['s'.$v['currency'].$v['short']]) ? $v['short'] : 0;
        $v['unbilled'] = array_values(array_filter($v['unbilled'], fn (array $u) => isset($parts[$u['key']])));

        return $v;
    }

    /**
     * The job's currency: its invoices', else its PO's, else its first recharge's, else pounds.
     *
     * @param  array{currency:string}|null  $po
     */
    private static function currencyOf(Collection $invoices, Collection $recharges, ?array $po): string
    {
        $recharge = $recharges->first(fn (TravelItem $t) => $t->status !== 'cancelled' && $t->amount_minor !== null);

        return strtoupper((string) ($invoices->first()?->currency ?: ($po['currency'] ?? ($recharge?->currency ?: 'GBP'))));
    }

    /**
     * The PO's total for a job: from its latest invoice that carries one (and the PO document it was drafted from), or
     * else from the one PO document linked to the job (two that disagree: none). 'checked' says whether the total was
     * found word for word in the PO (and, for a document linked to the job automatically, never: it's only as sure
     * as that link).
     *
     * @return array{total:int, currency:string, ref:?string, checked:bool}|null
     */
    private function po(Collection $invoices, Collection $poDocs, Collection $invoiceDocs): ?array
    {
        $carrying = $invoices->filter(fn (Invoice $i) => $i->po_total_minor !== null)->sortByDesc('id')->first();
        if ($carrying) {
            $doc = $carrying->document_id ? ($invoiceDocs[$carrying->document_id] ?? null) : null;

            return ['total' => (int) $carrying->po_total_minor, 'currency' => strtoupper($carrying->currency ?: 'GBP'),
                'ref' => $doc ? Ref::make('doc', $doc->id, 'f=po_total', $doc->sha256) : null, 'checked' => $doc !== null && ($doc->extraction['po']['total_verified'] ?? false) === true,
                'expenses' => $doc !== null && self::poExpenses($doc)];
        }
        $totals = $poDocs->filter(fn (Document $d) => is_int($d->extraction['po']['stated_total_minor'] ?? null))
            ->map(fn (Document $d) => ['doc' => $d, 'total' => (int) $d->extraction['po']['stated_total_minor'], 'currency' => strtoupper((string) ($d->extraction['po']['currency'] ?? 'GBP'))]);
        if ($totals->isEmpty() || $totals->unique(fn ($t) => $t['total'].$t['currency'])->count() > 1) {
            return null;
        }
        $t = $totals->first();
        $auto = in_array($t['doc']->job_linked_by, ['rules', 'matcher', 'ai'], true);

        return ['total' => $t['total'], 'currency' => $t['currency'], 'ref' => Ref::make('doc', $t['doc']->id, 'f=po_total', $t['doc']->sha256),
            'checked' => ! $auto && ($t['doc']->extraction['po']['total_verified'] ?? false) === true, 'expenses' => self::poExpenses($t['doc'])];
    }

    /** Whether a PO has a line for travel or expenses: then what it covers includes costs. */
    private static function poExpenses(Document $doc): bool
    {
        return collect($doc->extraction['po']['lines'] ?? [])->contains(fn ($l) => is_array($l) && CostWords::forExpenses((string) ($l['description'] ?? '')));
    }

    /**
     * @param  Collection<int, WorkJob>  $jobs
     * @param  array<int, array{v:array, dismiss_key:string, hidden_under:list<string>}>  $views  what each close-out shows
     * @return list<FindingDraft>
     */
    private function drafts(Collection $jobs, array $views, Collection $invoices): array
    {
        if ($views === []) {
            return [];
        }
        // What's come in, and the costs, for the jobs with something owed (for the words only).
        $issued = collect($views)->keys()->flatMap(fn ($id) => ($invoices[$id] ?? collect())->whereIn('status', ['issued', 'paid']))->values();
        $received = $issued->isEmpty() ? [] : InvoicePayments::received($issued);
        $costs = Allocation::query()->whereIn('work_job_id', array_keys($views))->where('kind', 'business_expense')->whereNotNull('confirmed_at')
            ->selectRaw('work_job_id, SUM(amount_pence) AS total')->groupBy('work_job_id')->pluck('total', 'work_job_id');
        $drafts = [];
        foreach ($views as $id => ['v' => $v, 'dismiss_key' => $dismissKey, 'hidden_under' => $hiddenUnder]) {
            $job = $jobs[$id];
            $money = fn (int $minor) => Money::format($minor, $v['currency'], false);
            $jobInvoices = $invoices[$id] ?? collect();
            $latest = $jobInvoices->whereIn('status', ['issued', 'paid'])->sortByDesc('id')->first() ?? $jobInvoices->where('status', 'draft')->sortByDesc('id')->first();
            $paid = $jobInvoices->whereIn('status', ['issued', 'paid'])->sum(fn (Invoice $i) => $received[$i->id]['paid'] ?? 0);
            $listed = fn (array $us) => implode('; ', array_map(fn (array $u) => $u['words'], array_slice($us, 0, 3))).(count($us) > 3 ? ' and '.(count($us) - 3).' more' : '');
            // Not on any line of the invoice, for sure; or not found by its amount, where a line could still hold it.
            $missing = array_values(array_filter($v['unbilled'], fn (array $u) => $u['line'] === null));
            $toCheck = array_values(array_filter($v['unbilled'], fn (array $u) => $u['line'] !== null));
            $owed = $v['short'] + array_sum(array_map(fn (array $u) => (int) $u['amount'], $v['unbilled']));
            $grade = Grades::weakest([
                $v['short'] > 0 ? ($v['po']['checked'] ? Grades::STRONG : Grades::LIKELY) : null,
                $missing !== [] ? Grades::STRONG : null,
                $toCheck !== [] ? Grades::LIKELY : null,
            ]);
            $parts = [];
            if ($v['short'] > 0) {
                $parts[] = 'PO '.$money($v['po']['total']).match ($v['billed_by']) {
                    'issued' => ', invoiced '.$money($v['billed']).': '.$money($v['short']).' short.',
                    'draft' => ', draft invoice '.$money($v['billed']).': '.$money($v['short']).' short.',
                    'income' => ', and '.$money($v['billed']).' received for the job with no invoice issued in Expend: '.$money($v['short']).' short.',
                    default => ' and no invoice yet.',
                }.($v['po']['checked'] ? '' : ' The PO’s total wasn’t found word for word in it: check it before you invoice.');
            }
            if ($missing !== []) {
                $parts[] = 'To recharge and not on '.($v['billed_by'] === 'none' ? 'an invoice yet' : 'the invoice').': '.$listed($missing).'.';
            }
            if ($toCheck !== []) {
                $parts[] = $v['billed_by'] === 'income'
                    ? 'To recharge: '.$listed($toCheck).'. The job was invoiced outside Expend: check it was on that invoice.'
                    : 'To recharge, and no line of the invoice is for that amount: '.$listed($toCheck).'. Check it’s on the invoice (“'.mb_strimwidth($toCheck[0]['line'], 0, 60, '…').'” may include it).';
            }
            if ($paid > 0) {
                $parts[] = $money((int) $paid).' received so far.';
            }
            if (isset($costs[$id]) && (int) $costs[$id] !== 0) {
                $parts[] = 'Costs recorded for the job: '.Money::format(abs((int) $costs[$id]), 'GBP', false).'.';
            }
            $parts[] = match ($v['billed_by']) {
                'none' => 'Draft the invoice and add '.($v['unbilled'] !== [] ? 'the recharges' : 'everything the PO covers').'.',
                'draft' => 'Add it to the draft invoice before you issue it.',
                'income' => 'If the rest hasn’t been invoiced, draft an invoice for it.',
                default => $v['short'] === 0 && $missing === [] ? 'If it isn’t on it, invoice it separately.' : 'Invoice the rest separately, or void the invoice and issue it again with everything on it.',
            };
            $sure = $v['short'] > 0 || $missing !== [];
            $drafts[] = new FindingDraft(
                key: 'closeout:'.$id, code: self::CODE, group: 'closeout', priority: $v['legacy'] !== [] ? 1 : 2,
                title: $job->title.': '.$money($owed).($sure ? ' not invoiced' : ' may not be invoiced'),
                detail: implode(' ', $parts),
                href: $latest ? '/invoices/'.$latest->id : '/jobs/'.$id,
                subjectRef: Ref::make('job', $id), scopeRef: Ref::make('job', $id), evidence: $v['evidence'],
                amountPence: $owed, currency: $v['currency'], direction: 'owed_to_you', grade: $grade,
                actions: $jobInvoices->isEmpty() ? [TodoList::action('job_invoice', $id, 'Draft the invoice')] : [],
                hiddenUnder: $hiddenUnder, dismissKey: $dismissKey, meta: ['subsumes' => $v['legacy']], detectorVersion: self::VERSION,
            );
        }

        return $drafts;
    }
}
