<?php

namespace App\Domain\Ledger;

use App\Domain\Audit\AuditLog;
use App\Domain\Intel\AuditContext;
use App\Domain\Intel\Methods;
use App\Domain\Intel\Provenance;
use App\Domain\Intel\Vetoes;
use App\Domain\Matching\TransferPairer;
use App\Models\Allocation;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\ManualExpense;
use App\Models\TaxYear;
use App\Models\Veto;
use App\Support\Money;
use App\Support\UkTaxYear;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only way allocations are written. Enforces the ledger's invariants:
 *  - lines sum exactly to the source amount;
 *  - business lines have a category of the right kind;
 *  - automation never overwrites a decision you made or confirmed;
 *  - locked tax years can't change;
 *  - every change is audited, with who really made it: a decision names its maker, and confirming or clearing
 *    takes the actor of the surrounding AuditContext ('you' outside one);
 *  - two writers never decide the same line at once: the line and its allocations are locked while they are
 *    checked and replaced, so the guard above always sees the latest decision;
 *  - an automatic writer says why: its Provenance (the method, its score, the evidence and runner-ups) is stored with
 *    the lines, and confirming a line records who confirmed it (confirmed_by), so accepting a suggestion is told
 *    apart from making one;
 *  - automation never proposes what you said no to (Intel\Vetoes): a suggestion of the shape you cleared or changed
 *    (its kind, category, business share and job), or an invoice you said a payment isn't for, is refused for the
 *    method the veto names (or every automatic method). An 'ask' veto lets it be proposed, never confirmed on its own;
 *  - one order of precedence over open suggestions (outranks()): you > rule > matcher (invoice > HMRC > transfer >
 *    pattern > categoriser, with the assistant's proposal from your own words) > the background AI. An automatic writer
 *    never replaces a suggestion that outranks its own; one that ranks the same or lower is replaced as before. Why a
 *    write was refused is kept for the message ($refused).
 *
 * Deciding what a line already says changes nothing and records nothing (a nightly pass that comes to the same
 * suggestion again leaves no trace).
 */
final class AllocationWriter
{
    /**
     * Precedence of the methods that write suggestions (docs/intelligence-cycle.md 4.2, rule 4): higher outranks lower.
     * You are above all of them (OWNER), a rule above every matcher, the AI below. Matchers: an invoice of the same
     * amount, then HMRC, then money moving between your accounts, then patterns (fines, car hire, travel, a stay's own
     * payment, a job's costs), then the categoriser's guesses. A method not listed ranks as its maker does (MAKERS).
     */
    public const PRECEDENCE = [
        'rule.learned' => 6, 'rule.owner' => 6,
        'suggest.invoice' => 5,
        'suggest.hmrc' => 4,
        'transfer.pair' => 3, 'transfer.one_sided' => 3, 'transfer.partner' => 3, 'revolut.exchange' => 3,
        'suggest.penalty' => 2, 'suggest.car_hire' => 2, 'suggest.travel' => 2, 'travel.match' => 2, 'jobs.gather' => 2,
        'categorise.merchant' => 1, 'categorise.similar' => 1, 'categorise.bank_learned' => 1, 'categorise.hint' => 1, 'categorise.bank_hint' => 1,
        // The assistant proposes what you told it in the chat ("that Pret was lunch on the Leeds job"): it replaces a
        // guess (the categoriser's or the AI's), never a matcher's finding, a rule's, or yours. The background AI is last.
        'ai.assistant' => 1,
        'ai.advisor' => 0,
    ];

    /** Why the last automatic write was refused (decide() returned false), for a message; null when it wasn't. */
    public const REFUSED_WORDS = [
        'yours' => 'you have already decided or confirmed this transaction',
        'vetoed' => 'you said no to that for this transaction before',
        'outranked' => 'a stronger suggestion is waiting for it (a matcher’s or a rule’s), for you to accept or change',
    ];

    /** Why the last call to decide() was refused: 'yours', 'vetoed' or 'outranked' (REFUSED_WORDS), or null. */
    public ?string $refused = null;

    /** Your own decisions: above every method. */
    public const OWNER = 7;

    /** The rank of a line whose method isn't known, by who made it. */
    private const MAKERS = ['you' => self::OWNER, 'rule' => 6, 'policy' => 2, 'matcher' => 1, 'ai' => 0];

    /**
     * The precedence of a method (or, when it isn't known, of whoever made the line).
     */
    public static function rank(?string $method, string $decidedBy): int
    {
        if ($decidedBy === 'you') {
            return self::OWNER;
        }
        if ($decidedBy === 'rule') {
            return self::PRECEDENCE['rule.owner'];
        }

        return $method !== null && isset(self::PRECEDENCE[$method]) ? self::PRECEDENCE[$method] : (self::MAKERS[$decidedBy] ?? 0);
    }

    /** Whether a suggestion by $a outranks one by $b (Methods' names; the makers' when not known). */
    public static function outranks(?string $a, string $aBy, ?string $b, string $bBy): bool
    {
        return self::rank($a, $aBy) > self::rank($b, $bBy);
    }

    /**
     * @param  list<array{kind:string, category_id?:?int, work_job_id?:?int, amount_pence:int, business_use_pct?:?int, note?:?string}>  $lines
     * @param  Provenance|null  $provenance  why an automatic writer proposes this (every automatic writer gives one);
     *                                       null for your own decisions
     * @param  bool  $supersede  an automatic writer replacing a suggestion that outranks it on purpose, for a reason of
     *                           its own (TransferPairer: an invoice matched on the amount alone to money the line shows is
     *                           your own). Never over your decisions, anything confirmed, or a veto
     * @return bool true when the line now says this (written now, or it already did); false when skipped because
     *              automation tried to touch a protected decision, propose what you said no to, or replace a
     *              suggestion that outranks it
     */
    public function decide(
        BankTransaction|ManualExpense $source,
        array $lines,
        string $decidedBy = 'you',
        bool $confirm = true,
        ?string $reason = null,
        ?int $ruleId = null,
        ?Provenance $provenance = null,
        bool $supersede = false,
    ): bool {
        $this->refused = null;
        $amount = $source->amount_pence;
        $taxYear = $this->taxYearOf($source);
        $this->assertUnlocked($taxYear);
        $this->assertLines($lines, $amount);
        $method = $decidedBy === 'you' ? null : ($provenance?->method ?? Methods::legacy($decidedBy, (string) $reason, null, $ruleId));
        if ($decidedBy !== 'you') {
            // What you said no to is never proposed again by automation; an 'ask' veto lets it be proposed, never confirmed.
            $vetoed = $this->vetoed($source, $lines, $method, $decidedBy !== 'you' && $confirm);
            if ($vetoed === 'block') {
                $this->refused = 'vetoed';

                return false;
            }
            if ($vetoed === 'ask') {
                $confirm = false;
            }
        }

        $done = DB::transaction(function () use ($source, $lines, $decidedBy, $confirm, $reason, $ruleId, $taxYear, $provenance, $method, $supersede) {
            $existing = $this->lockedAllocations($source, $status);

            if ($decidedBy !== 'you' && $existing->contains(fn (Allocation $a) => $a->is_manual || $a->confirmed_at !== null)) {
                $this->refused = 'yours';

                return false;
            }
            if ($decidedBy !== 'you' && ! $supersede && $existing->isNotEmpty()) {
                // An open suggestion that outranks this one stays (a rule's over a matcher's, an invoice match over a guess).
                $have = $existing->first();
                $haveMethod = $have->decided_by === 'rule' ? null : Methods::of($have)['method']; // a rule ranks as a rule, whichever
                if (self::outranks($haveMethod, (string) $have->decided_by, $method, $decidedBy)) {
                    $this->refused = 'outranked';

                    return false;
                }
            }
            if ($this->unchanged($status, $existing, $lines, $decidedBy, $confirm, $reason, $ruleId)) {
                return true; // already says exactly this: nothing to write, nothing to record
            }

            $before = $existing->map(fn (Allocation $a) => $this->snapshot($a))->all();
            $source->allocations()->delete();

            $now = now();
            $created = [];
            $why = $provenance?->columns() ?? [];
            foreach ($lines as $line) {
                $created[] = $source->allocations()->create($why + [
                    'tax_year' => $taxYear,
                    'kind' => $line['kind'],
                    'category_id' => in_array($line['kind'], Allocation::NEEDS_CATEGORY, true) ? ($line['category_id'] ?? null) : null,
                    'work_job_id' => $line['work_job_id'] ?? null,
                    'invoice_id' => $line['invoice_id'] ?? null,
                    'amount_pence' => $line['amount_pence'],
                    'business_use_pct' => $line['business_use_pct'] ?? null,
                    'decided_by' => $decidedBy,
                    'is_manual' => $decidedBy === 'you',
                    'confirmed_at' => $confirm ? $now : null,
                    // Made and confirmed in one go: by you, or by a rule that confirms on its own.
                    'confirmed_by' => $confirm ? AuditContext::normalise($decidedBy) : null,
                    'merchant_rule_id' => $ruleId,
                    'reason' => self::fit($reason),
                    'note' => self::fit($line['note'] ?? null),
                ]);
            }

            $source->forceFill(['review_status' => $confirm ? 'confirmed' : 'suggested'])->saveQuietly();

            AuditLog::record(
                $decidedBy === 'you' ? 'allocation.decided' : 'allocation.suggested',
                $source,
                $before ?: null,
                array_map(fn (Allocation $a) => $this->snapshot($a), $created),
                $reason,
                $decidedBy === 'you' ? 'you' : $decidedBy,
            );

            return true;
        });
        if ($done && $decidedBy === 'you') {
            $this->afterYou($source);
        }

        return $done;
    }

    /**
     * Whether a veto holds back this automatic write: 'block' (never propose it), 'ask' (propose it, never confirm it on
     * its own), or null. Its shape (what each line is) against what you cleared or changed, and the invoice it would be
     * matched to against an invoice you said it isn't for.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    private function vetoed(BankTransaction|ManualExpense $source, array $lines, ?string $method, bool $acting): ?string
    {
        // One query for everything said about this line (usually nothing).
        $vetoes = Veto::query()->active()->where('subject_ref', ($source instanceof BankTransaction ? 'tx:' : 'exp:').$source->getKey())
            ->whereIn('relation', ['allocation_shape', 'invoice_payment'])->get();
        if ($vetoes->isEmpty()) {
            return null;
        }
        $shapes = $vetoes->where('relation', 'allocation_shape');
        $shape = Vetoes::shape($lines);
        if (Vetoes::matching($shapes, null, $shape, $method)->isNotEmpty()) {
            return 'block';
        }
        foreach (array_unique(array_filter(array_map(fn ($l) => $l['invoice_id'] ?? null, $lines))) as $invoiceId) {
            if (Vetoes::matching($vetoes->where('relation', 'invoice_payment'), 'inv:'.$invoiceId, null, $method)->isNotEmpty()) {
                return 'block';
            }
        }

        return $acting && Vetoes::matching($shapes, null, $shape, $method, true)->isNotEmpty() ? 'ask' : null;
    }

    /** One side of a transfer pair decided by you settles the other side (see TransferPairer::afterYourDecision). */
    private function afterYou(BankTransaction|ManualExpense $source): void
    {
        if ($source instanceof BankTransaction && $source->transfer_pair_id) {
            app(TransferPairer::class)->afterYourDecision($source);
        }
    }

    /** Reasons and notes are shown in full in the history; the stored copy fits its column. */
    private static function fit(?string $text, int $max = 255): ?string
    {
        return $text !== null && mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1).'…' : $text;
    }

    /**
     * Accept the current suggestion as it stands. Recorded as the surrounding AuditContext's actor: 'you' for your
     * tap, 'matcher' when a matcher settles a line for you (the other side of a transfer you decided). The lines it
     * confirms keep who confirmed them (confirmed_by), so a suggestion you accepted is told apart from one confirmed
     * automatically.
     */
    public function confirm(BankTransaction|ManualExpense $source, ?string $reason = null): void
    {
        $this->assertUnlocked($this->taxYearOf($source));
        $actor = AuditContext::actor();

        DB::transaction(function () use ($source, $reason, $actor) {
            $allocations = $this->lockedAllocations($source);
            if ($allocations->isEmpty()) {
                throw new InvalidArgumentException('There is nothing to confirm yet. Choose what this is first.');
            }
            $source->allocations()->whereNull('confirmed_at')->update(['confirmed_at' => now(), 'confirmed_by' => $actor]);
            $source->forceFill(['review_status' => 'confirmed'])->saveQuietly();
            AuditLog::record('allocation.confirmed', $source, null, $allocations->map(fn ($a) => $this->snapshot($a))->all(), $reason, $actor);
        });
        // Only your own confirmation settles the other side of a pair; a matcher's never sets off another.
        if ($actor === 'you') {
            $this->afterYou($source);
        }
    }

    /**
     * Remove the decision so the item goes back to the Inbox.
     *
     * @param  string|null  $actor  who cleared it, for the history: 'matcher' when an outdated suggestion is taken back;
     *                              when not given, the surrounding AuditContext's actor ('you' outside one)
     */
    public function clear(BankTransaction|ManualExpense $source, ?string $reason = null, ?string $actor = null): void
    {
        $this->assertUnlocked($this->taxYearOf($source));
        $actor ??= AuditContext::actor();

        DB::transaction(function () use ($source, $reason, $actor) {
            $before = $this->lockedAllocations($source)->map(fn ($a) => $this->snapshot($a))->all();
            $source->allocations()->delete();
            $source->forceFill(['review_status' => 'unreviewed'])->saveQuietly();
            AuditLog::record('allocation.cleared', $source, $before ?: null, null, $reason, $actor);
        });
    }

    /**
     * The line's allocations, read afresh with the line and its allocations locked until the surrounding transaction
     * ends, so another writer waits rather than deciding from what it read before.
     *
     * @param  string|null  $status  set to the line's review status as stored
     * @return EloquentCollection<int, Allocation>
     */
    private function lockedAllocations(BankTransaction|ManualExpense $source, ?string &$status = null): EloquentCollection
    {
        $status = $source->newQuery()->whereKey($source->getKey())->lockForUpdate()->value('review_status');

        return $source->allocations()->lockForUpdate()->get();
    }

    /**
     * Whether the line already says exactly this: the same lines, by the same maker, with the same reason and rule,
     * confirmed or not alike, and the line's status to match. The Categoriser's marker isn't compared: it's set after
     * the write, from the reason.
     *
     * @param  EloquentCollection<int, Allocation>  $existing
     */
    private function unchanged(?string $status, EloquentCollection $existing, array $lines, string $decidedBy, bool $confirm, ?string $reason, ?int $ruleId): bool
    {
        if ($existing->isEmpty() || $existing->count() !== count($lines) || $status !== ($confirm ? 'confirmed' : 'suggested')) {
            return false;
        }
        $int = fn ($v) => $v === null ? null : (int) $v;
        $key = fn (array $l) => json_encode([
            $l['kind'], $int($l['category_id']), $int($l['work_job_id']), $int($l['invoice_id']), (int) $l['amount_pence'],
            $int($l['business_use_pct']), $l['note'], $l['decided_by'], (bool) $l['is_manual'], (bool) $l['confirmed'],
            $int($l['merchant_rule_id']), $l['reason'],
        ]);
        $have = $existing->map(fn (Allocation $a) => $key([
            'kind' => $a->kind, 'category_id' => $a->category_id, 'work_job_id' => $a->work_job_id, 'invoice_id' => $a->invoice_id,
            'amount_pence' => $a->amount_pence, 'business_use_pct' => $a->business_use_pct, 'note' => $a->note, 'decided_by' => $a->decided_by,
            'is_manual' => $a->is_manual, 'confirmed' => $a->confirmed_at !== null, 'merchant_rule_id' => $a->merchant_rule_id, 'reason' => $a->reason,
        ]))->sort()->values()->all();
        $want = collect($lines)->map(fn (array $l) => $key([
            'kind' => $l['kind'],
            'category_id' => in_array($l['kind'], Allocation::NEEDS_CATEGORY, true) ? ($l['category_id'] ?? null) : null,
            'work_job_id' => $l['work_job_id'] ?? null, 'invoice_id' => $l['invoice_id'] ?? null, 'amount_pence' => $l['amount_pence'],
            'business_use_pct' => $l['business_use_pct'] ?? null, 'note' => self::fit($l['note'] ?? null), 'decided_by' => $decidedBy,
            'is_manual' => $decidedBy === 'you', 'confirmed' => $confirm, 'merchant_rule_id' => $ruleId, 'reason' => self::fit($reason),
        ]))->sort()->values()->all();

        return $have === $want;
    }

    /**
     * Build lines for a single decision, optionally split into business and personal parts.
     *
     * @return list<array<string, mixed>>
     */
    public static function lines(int $amount, string $kind, ?int $categoryId = null, ?int $jobId = null, int $businessPct = 100, ?string $note = null): array
    {
        if (! in_array($kind, Allocation::NEEDS_CATEGORY, true) || $businessPct >= 100) {
            return [[
                'kind' => $kind, 'category_id' => $categoryId, 'work_job_id' => $jobId, 'amount_pence' => $amount,
                'business_use_pct' => in_array($kind, Allocation::NEEDS_CATEGORY, true) ? 100 : null, 'note' => $note,
            ]];
        }

        [$business, $personal] = Money::splitByPercent($amount, max(0, $businessPct));
        $lines = [];
        if ($business !== 0) {
            $lines[] = ['kind' => $kind, 'category_id' => $categoryId, 'work_job_id' => $jobId, 'amount_pence' => $business, 'business_use_pct' => $businessPct, 'note' => $note];
        }
        if ($personal !== 0) {
            $lines[] = ['kind' => 'personal', 'category_id' => null, 'work_job_id' => null, 'amount_pence' => $personal, 'business_use_pct' => null, 'note' => $note];
        }

        return $lines;
    }

    public function taxYearOf(BankTransaction|ManualExpense $source): string
    {
        return $source instanceof BankTransaction
            ? UkTaxYear::fromDate($source->effectiveDate())->label()
            : UkTaxYear::fromDate($source->spent_on)->label();
    }

    private function assertUnlocked(string $label): void
    {
        $year = TaxYear::query()->where('label', $label)->first();
        if ($year?->isLocked()) {
            throw new InvalidArgumentException("Tax year {$label} is locked. Unlock it (with a reason) before changing anything in it.");
        }
    }

    private function assertLines(array $lines, int $amount): void
    {
        if ($lines === []) {
            throw new InvalidArgumentException('A decision needs at least one line.');
        }

        $sum = 0;
        foreach ($lines as $i => $line) {
            if (! isset(Allocation::KINDS[$line['kind'] ?? ''])) {
                throw new InvalidArgumentException('Line '.($i + 1).' has an unknown type.');
            }
            if (! is_int($line['amount_pence'] ?? null) || $line['amount_pence'] === 0) {
                throw new InvalidArgumentException('Line '.($i + 1).' needs a non-zero amount.');
            }
            if (($line['amount_pence'] < 0) !== ($amount < 0)) {
                throw new InvalidArgumentException('Line '.($i + 1).' must be '.($amount < 0 ? 'money out' : 'money in').', like the transaction itself.');
            }
            if (in_array($line['kind'], Allocation::NEEDS_CATEGORY, true)) {
                $category = isset($line['category_id']) ? Category::query()->find($line['category_id']) : null;
                if (! $category) {
                    throw new InvalidArgumentException('Line '.($i + 1).' needs an HMRC category.');
                }
                $expected = $line['kind'] === 'business_income' ? 'income' : 'expense';
                if ($category->kind !== $expected) {
                    throw new InvalidArgumentException('Line '.($i + 1).": \"{$category->name}\" isn't an {$expected} category.");
                }
            }
            $sum += $line['amount_pence'];
        }

        if ($sum !== $amount) {
            throw new InvalidArgumentException(sprintf(
                'The lines add up to %s but the amount is %s. They must match exactly.',
                Money::format($sum), Money::format($amount),
            ));
        }
    }

    /**
     * A line as the history keeps it: enough to rebuild what was decided, by whom and why (its invoice, rule, method and
     * reason), so outcomes can be worked out from the audit trail alone.
     */
    private function snapshot(Allocation $a): array
    {
        return $a->only(['kind', 'category_id', 'work_job_id', 'invoice_id', 'amount_pence', 'business_use_pct', 'decided_by', 'is_manual', 'note', 'merchant_rule_id', 'method', 'reason'])
            + ['confirmed' => $a->confirmed_at !== null];
    }
}
