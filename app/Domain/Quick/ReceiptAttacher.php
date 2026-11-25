<?php

namespace App\Domain\Quick;

use App\Domain\Audit\AuditLog;
use App\Domain\Intel\AuditContext;
use App\Domain\Intel\Methods;
use App\Domain\Intel\Provenance;
use App\Domain\Intel\ReceiptMatch;
use App\Domain\Intel\Ref;
use App\Domain\Intel\Vetoes;
use App\Domain\Ledger\AllocationWriter;
use App\Domain\Ledger\ManualExpenses;
use App\Domain\Matching\OwnAccounts;
use App\Domain\Todo\TodoList;
use App\Models\Account;
use App\Models\Allocation;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\ExchangeRate;
use App\Models\QuickEntry;
use App\Models\TaxYear;
use App\Models\Veto;
use App\Models\WorkJob;
use App\Support\Money;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Business-card receipts from the phone and their bank lines (the method receipt.quick): a receipt kept with "Business
 * card or account" waits until its line comes in, then goes on it, so the cost is counted once, on the line.
 *
 * A line fits a receipt when it's money out, not a transfer, in an open year, for the same amount in the receipt's own
 * currency (pounds against the sterling, another currency against what the account or the shop charged in it), dated
 * within ReceiptMatch::DATE_DAYS days, not holding another live receipt and not one you said isn't this receipt's.
 * Lines on the account you picked come first.
 *
 * Only a sure match is made by itself: exactly one line fits, and no other waiting receipt fits that line. Then the
 * receipt is attached (attached_by matcher) and its choices (category, job, business share) are offered on the line as
 * the receipt.quick suggestion, for one tap in the Inbox: never confirmed by itself. A line you decided already keeps
 * your decision (the receipt goes on it as evidence, and To do asks when they differ). Anything less sure is left for
 * you (receipt.which). It runs when the receipt arrives (forEntry), after every import (forLines, from
 * Importer::afterImport) and nightly (sweep: relate.receipts, or ledger:receipts with the cycle off).
 */
final class ReceiptAttacher
{
    public const METHOD = 'receipt.quick';

    /** A receipt and its line are at most this many days apart. */
    public const DATE_DAYS = ReceiptMatch::DATE_DAYS;

    /** Near misses (a tip, a pre-authorisation): lines within this share of the amount... */
    public const NEAR_SHARE = 15;

    /** ...and this many days of the receipt's date (typed or read). */
    public const NEAR_DAYS = 7;

    /** Lines offered to attach by hand on the entry page: within this many days, any amount, closest first. */
    public const HAND_DAYS = 7;

    /** A pounds-only line within this share of the HMRC conversion of a foreign receipt is offered, never attached. */
    public const FX_SHARE = 3;

    public function __construct(private AllocationWriter $writer) {}

    // ---- what fits --------------------------------------------------------------------------------------------------

    /**
     * A receipt's figures for matching: what you typed (its currency certain), or, for a photo with no amount typed,
     * what was read from it.
     *
     * The sterling printed on a foreign receipt (you typed it) is what a line in pounds only shows.
     *
     * @return array{minor:int, hundredths:int, currency:string, date:CarbonImmutable, certain:bool, sterling?:?int}|null
     */
    public static function figuresOf(QuickEntry $e): ?array
    {
        $date = $e->spent_on ? CarbonImmutable::parse($e->spent_on->format('Y-m-d')) : null;
        if ($e->amount_minor !== null && $date !== null) {
            $currency = $e->currency ?: 'GBP';

            return ['minor' => abs($e->amount_minor), 'hundredths' => (int) $e->hundredths(), 'currency' => $currency, 'date' => $date, 'certain' => true,
                'sterling' => $currency !== 'GBP' && $e->sterling_pence ? abs((int) $e->sterling_pence) : null];
        }
        if ($e->document_id === null || ! $e->document) {
            return null;
        }
        $f = ReceiptMatch::figures($e->document);
        if ($f['hundredths'] === null || ($f['date'] ?? $date) === null) {
            return null;
        }

        return ['minor' => (int) Money::fromHundredths($f['hundredths'], $f['currency']), 'hundredths' => $f['hundredths'], 'currency' => $f['currency'],
            'date' => $f['date'] ?? $date, 'certain' => false];
    }

    /**
     * Whether a line fits a receipt's figures: money out, the same amount in its currency (or the sterling you typed
     * from a foreign receipt, on a line in pounds), within the days, and not your own money moving between your
     * accounts (a pair, a transfer suggested or decided on it, or a line that names an account of yours).
     */
    public static function fitsFigures(array $f, BankTransaction $line, int $days = self::DATE_DAYS): bool
    {
        if ((int) $line->amount_pence >= 0 || $line->transfer_pair_id !== null) {
            return false;
        }
        if (! self::dateFits($f, $line, $days) || ! self::amountFits($f, $line)) {
            return false;
        }

        return ! self::ownMoney($line);
    }

    /** Whether a line's date is within $days of a receipt's. */
    public static function dateFits(array $f, BankTransaction $line, int $days = self::DATE_DAYS): bool
    {
        return abs(CarbonImmutable::parse($line->effectiveDate()->format('Y-m-d'))->diffInDays($f['date'])) <= $days;
    }

    /**
     * Whether a line is for a receipt's amount: the same figure in the receipt's currency, or, for a foreign receipt
     * with its sterling typed, that sterling on a line that shows no figure of its own in the receipt's currency.
     */
    public static function amountFits(array $f, BankTransaction $line): bool
    {
        if (ReceiptMatch::sameAmountIn($line, $f['hundredths'], $f['currency'])) {
            return true;
        }
        $sterling = $f['sterling'] ?? null;
        if ($sterling === null || $f['currency'] === 'GBP' || abs((int) $line->amount_pence) !== (int) $sterling) {
            return false;
        }
        $currency = strtoupper($f['currency']);

        return ! ($line->original_amount_minor && strtoupper((string) $line->original_currency) === $currency)
            && ! ($line->merchant_amount_minor && strtoupper((string) $line->merchant_currency) === $currency);
    }

    /**
     * Money moving between your own accounts, never a purchase a receipt is for: a transfer pair, a transfer suggested
     * or decided on the line, or a line naming an account of yours (OwnAccounts).
     */
    public static function ownMoney(BankTransaction $line): bool
    {
        if ($line->transfer_pair_id !== null || $line->allocations->contains(fn (Allocation $a) => $a->kind === 'transfer')) {
            return true;
        }

        return OwnAccounts::reason($line) !== null;
    }

    /**
     * The lines that fit a receipt, best first (the account you picked first, then the closest date). Without
     * $withTaken, a line holding another live receipt is left out.
     *
     * @return Collection<int, BankTransaction>
     */
    public function candidates(QuickEntry $e, bool $withTaken = false): Collection
    {
        $f = self::figuresOf($e);
        if ($f === null) {
            return collect();
        }
        $lines = $this->near($f['date'], self::DATE_DAYS)->filter(fn (BankTransaction $t) => self::fitsFigures($f, $t));
        $lines = $this->usable($e, $lines, $withTaken, [self::METHOD, null]);
        if ($e->account_id !== null && $lines->contains('account_id', $e->account_id)) {
            $lines = $lines->where('account_id', $e->account_id)->values();
        }

        return $lines->sortBy(fn (BankTransaction $t) => abs(CarbonImmutable::parse($t->effectiveDate()->format('Y-m-d'))->diffInDays($f['date'])))->values();
    }

    /**
     * Card lines that may be the same payment as a cash or personal-card entry (quick.maybe_card, and the guard on
     * "Paid in cash"): money out on an account that isn't cash, not a transfer, not decided as personal or a transfer,
     * the same amount in the entry's currency, within $days days, not holding another live receipt, and not one you
     * said was a different payment.
     *
     * @return Collection<int, BankTransaction>
     */
    public function cardCandidates(QuickEntry $e, int $days = 2): Collection
    {
        if ($e->amount_minor === null || $e->spent_on === null) {
            return collect();
        }
        $f = self::cardFigures($e);
        $cash = Account::query()->where('kind', 'cash')->pluck('id')->all();
        $no = array_map('intval', $e->answered('not_card'));
        $lines = $this->near($f['date'], $days)->filter(fn (BankTransaction $t) => ! in_array($t->account_id, $cash, true) && ! in_array($t->id, $no, true)
            && self::fitsFigures($f, $t, $days)
            && ! $t->allocations->contains(fn (Allocation $a) => in_array($a->kind, ['personal', 'transfer'], true) && ($a->is_manual || $a->confirmed_at !== null)));

        return $this->usable($e, $lines, false, null)
            ->sortBy(fn (BankTransaction $t) => abs(CarbonImmutable::parse($t->effectiveDate()->format('Y-m-d'))->diffInDays($f['date'])))->values();
    }

    /**
     * A cash or personal-card entry's figures for the card lines it may be (cardCandidates, quick.maybe_card): what you
     * typed, with the sterling you typed from a foreign receipt.
     *
     * @return array{hundredths:int, currency:string, date:CarbonImmutable, sterling:?int}
     */
    public static function cardFigures(QuickEntry $e): array
    {
        $currency = $e->currency ?: 'GBP';

        return ['hundredths' => (int) $e->hundredths(), 'currency' => $currency, 'date' => CarbonImmutable::parse($e->spent_on->format('Y-m-d')),
            'sterling' => $currency !== 'GBP' && $e->sterling_pence ? abs((int) $e->sterling_pence) : null];
    }

    /**
     * Lines that nearly fit a receipt with no line (a tip, a pre-authorisation, a late photo): business-account money
     * out within NEAR_SHARE% of the amount and NEAR_DAYS days of the typed date or the photo's read date, closest
     * first, at most three.
     *
     * @return Collection<int, BankTransaction>
     */
    public function nearMisses(QuickEntry $e, int $max = 3): Collection
    {
        $f = self::figuresOf($e);
        if ($f === null) {
            return collect();
        }
        $dates = array_filter([$f['date'], $e->document ? ReceiptMatch::figures($e->document)['date'] : null]);
        $cash = Account::query()->where('kind', 'cash')->pluck('id')->all();
        $pence = $f['currency'] === 'GBP' ? $f['hundredths'] : null;
        $out = collect();
        foreach ($dates as $d) {
            $out = $out->concat($this->near($d, self::NEAR_DAYS)->filter(function (BankTransaction $t) use ($f, $pence, $cash) {
                if ((int) $t->amount_pence >= 0 || $t->transfer_pair_id !== null || in_array($t->account_id, $cash, true) || self::ownMoney($t)) {
                    return false;
                }
                $mine = $pence ?? (ReceiptMatch::sameAmountIn($t, $f['hundredths'], $f['currency']) ? abs((int) $t->amount_pence) : null);
                if ($mine === null) {
                    // A foreign receipt and a line in pounds only: within the HMRC conversion's few per cent, offered only.
                    return $t->original_currency === null && $t->merchant_currency === null && ($conv = self::sterlingOf($f)) !== null
                        && abs(abs((int) $t->amount_pence) - $conv) * 100 <= $conv * self::FX_SHARE;
                }

                return abs(abs((int) $t->amount_pence) - $mine) * 100 <= $mine * self::NEAR_SHARE;
            }));
        }
        $out = $this->usable($e, $out->unique('id'), false, [self::METHOD, null]);

        return $out->sortBy(fn (BankTransaction $t) => [abs(CarbonImmutable::parse($t->effectiveDate()->format('Y-m-d'))->diffInDays($f['date'])),
            abs(abs((int) $t->amount_pence) - (int) ($pence ?? 0))])->take($max)->values();
    }

    /**
     * A foreign receipt with no sterling typed, and lines in pounds only (the bank gave no foreign figure) within
     * FX_SHARE% of HMRC's conversion for its month, within DATE_DAYS days: offered as receipt.which, never attached by
     * themselves.
     *
     * @return Collection<int, BankTransaction>
     */
    public function fxCandidates(QuickEntry $e, int $max = 3): Collection
    {
        $f = self::figuresOf($e);
        if ($f === null || $f['currency'] === 'GBP' || ($f['sterling'] ?? null) !== null || ($conv = self::sterlingOf($f)) === null) {
            return collect();
        }
        $lines = $this->near($f['date'], self::DATE_DAYS)->filter(fn (BankTransaction $t) => $t->transfer_pair_id === null && $t->original_currency === null
            && $t->merchant_currency === null && abs(abs((int) $t->amount_pence) - $conv) * 100 <= $conv * self::FX_SHARE && ! self::ownMoney($t));

        return $this->usable($e, $lines, false, [self::METHOD, null])
            ->sortBy(fn (BankTransaction $t) => [abs(abs((int) $t->amount_pence) - $conv), abs(CarbonImmutable::parse($t->effectiveDate()->format('Y-m-d'))->diffInDays($f['date']))])
            ->take($max)->values();
    }

    /**
     * Lines you can attach a receipt to by hand on its page: money out within HAND_DAYS days of it, any amount,
     * closest first.
     *
     * @return Collection<int, BankTransaction>
     */
    public function handLines(QuickEntry $e, int $max = 12): Collection
    {
        if ($e->spent_on === null) {
            return collect();
        }
        $on = CarbonImmutable::parse($e->spent_on->format('Y-m-d'));
        $lines = $this->near($on, self::HAND_DAYS)->filter(fn (BankTransaction $t) => (int) $t->amount_pence < 0 && $t->transfer_pair_id === null);
        $h = $e->hundredths();

        return $this->usable($e, $lines, false, false)
            ->sortBy(fn (BankTransaction $t) => [abs((int) $t->amount_pence) === $h ? 0 : 1, abs(CarbonImmutable::parse($t->effectiveDate()->format('Y-m-d'))->diffInDays($on))])
            ->take($max)->values();
    }

    /**
     * Active lines dated within $days of $on, with their decisions and accounts.
     *
     * @return Collection<int, BankTransaction>
     */
    private function near(CarbonImmutable $on, int $days): Collection
    {
        $from = $on->subDays($days)->format('Y-m-d');
        $to = $on->addDays($days)->format('Y-m-d');

        return BankTransaction::query()->where('amount_pence', '<', 0)
            ->where(fn ($q) => $q->where(fn ($q) => $q->whereNotNull('transaction_on')->whereDate('transaction_on', '>=', $from)->whereDate('transaction_on', '<=', $to))
                ->orWhere(fn ($q) => $q->whereNull('transaction_on')->whereDate('posted_on', '>=', $from)->whereDate('posted_on', '<=', $to)))
            ->with(['allocations', 'account'])->orderBy('id')->get()
            ->filter(fn (BankTransaction $t) => abs(CarbonImmutable::parse($t->effectiveDate()->format('Y-m-d'))->diffInDays($on)) <= $days)->values();
    }

    /**
     * Of these lines, the ones a receipt may go on: in an open year, not holding another live receipt (unless
     * $withTaken), and not vetoed for this receipt ($methods: the methods whose vetoes count; null for every one;
     * false for none, as for your own choice).
     *
     * @param  Collection<int, BankTransaction>  $lines
     * @param  list<?string>|false|null  $methods
     * @return Collection<int, BankTransaction>
     */
    private function usable(QuickEntry $e, Collection $lines, bool $withTaken, array|false|null $methods): Collection
    {
        if ($lines->isEmpty()) {
            return $lines;
        }
        $locked = array_flip(TaxYear::query()->whereNotNull('locked_at')->pluck('label')->all());
        $taken = $withTaken ? [] : array_flip(QuickEntry::query()->where('status', 'attached')->where('id', '!=', (int) $e->id)
            ->whereIn('bank_transaction_id', $lines->pluck('id')->all())->pluck('bank_transaction_id')->map(fn ($id) => (int) $id)->all());
        $refused = $methods === false ? [] : self::refusedLines($e, $methods);

        return $lines->filter(fn (BankTransaction $t) => ! isset($locked[UkTaxYear::fromDate($t->effectiveDate())->label()]) && ! isset($taken[$t->id]) && ! isset($refused[$t->id]))->values();
    }

    /**
     * The lines you said aren't this receipt's: its vetoes (receipt_payment), and your answers kept on the entry.
     *
     * @param  list<?string>|null  $methods  the vetoes' methods that count (null: all)
     * @return array<int, true>
     */
    public static function refusedLines(QuickEntry $e, ?array $methods = null): array
    {
        $out = [];
        foreach ([...$e->answered('not_lines'), ...$e->answered('not_card')] as $id) {
            $out[(int) $id] = true;
        }
        if ($e->id && self::cites()) {
            $vetoes = Veto::query()->active()->where('relation', 'receipt_payment')->where('subject_ref', $e->ref())->get();
            foreach ($vetoes as $v) {
                if (($methods === null || in_array($v->method, $methods, true)) && preg_match('/^tx:(\d+)$/', (string) $v->object_ref, $m)) {
                    $out[(int) $m[1]] = true;
                }
            }
        }

        return $out;
    }

    /** Whether quick entries can be cited (ref kind quick, method receipt.quick): needed for vetoes and the line's why. */
    public static function cites(): bool
    {
        return Ref::valid('quick:1') && Methods::has(self::METHOD);
    }

    // ---- attaching ----------------------------------------------------------------------------------------------------

    /**
     * Match one receipt now (it has just arrived, or changed): attached when exactly one line fits it and no other
     * waiting receipt fits that line; otherwise left for you.
     *
     * @return string attached | which | second_copy | none | not_waiting
     */
    public function forEntry(QuickEntry $e): string
    {
        $e->refresh();
        if (! $e->isWaiting()) {
            return 'not_waiting';
        }
        $lines = $this->candidates($e);
        if ($lines->count() === 1) {
            $line = $lines->first();
            $others = QuickEntry::query()->waiting()->where('id', '!=', $e->id)->get()
                ->filter(fn (QuickEntry $o) => ($f = self::figuresOf($o)) !== null && self::fitsFigures($f, $line) && ! isset(self::refusedLines($o, [self::METHOD, null])[$line->id]));
            if ($others->isNotEmpty()) {
                return 'which';
            }

            return $this->attach($e, $line, 'matcher');
        }
        if ($lines->count() > 1) {
            return 'which';
        }

        return $this->candidates($e, true)->count() === 1 ? 'second_copy' : 'none';
    }

    /**
     * After lines come in (an import, a Starling or Revolut sync, an undone import restored): the receipts waiting
     * that one of these lines fits are matched again, including any whose line was reversed since.
     *
     * @param  iterable<BankTransaction>  $lines
     * @return int receipts attached
     */
    public function forLines(iterable $lines): int
    {
        $lines = collect($lines)->filter(fn ($t) => $t instanceof BankTransaction && (int) $t->amount_pence < 0);
        if ($lines->isEmpty()) {
            return 0;
        }
        $n = 0;
        foreach (QuickEntry::query()->waiting()->orderBy('id')->get() as $e) {
            $f = self::figuresOf($e);
            if ($f === null || ! $lines->contains(fn (BankTransaction $t) => self::fitsFigures($f, $t))) {
                continue;
            }
            try {
                $n += $this->forEntry($e) === 'attached' ? 1 : 0;
            } catch (Throwable $ex) {
                report($ex); // one receipt going wrong never stops an import
            }
        }

        return $n;
    }

    /**
     * The nightly pass: every receipt still waiting is matched again (statements imported by the old paths, a line
     * reversed and imported again), and suggestions from receipts that no longer point at their line are taken back.
     * Works from $after (an entry id) while $hasTime() says so.
     *
     * @return array{attached:int, taken_back:int, last_id:?int}
     */
    public function sweep(int $after = 0, ?callable $hasTime = null): array
    {
        $attached = 0;
        $last = null;
        $chunk = QuickEntry::query()->waiting()->where('id', '>', $after)->orderBy('id')->limit(500)->get();
        foreach ($chunk as $e) {
            try {
                $attached += $this->forEntry($e) === 'attached' ? 1 : 0;
            } catch (Throwable $ex) {
                report($ex);
            }
            $last = $e->id;
            if ($hasTime && ! $hasTime()) {
                return ['attached' => $attached, 'taken_back' => 0, 'last_id' => $last];
            }
        }
        if ($chunk->count() === 500) {
            return ['attached' => $attached, 'taken_back' => 0, 'last_id' => $last]; // more to come
        }

        return ['attached' => $attached, 'taken_back' => $this->takeBackGone(), 'last_id' => null];
    }

    /**
     * Put a receipt on a line: the line and the entry are locked, and it's checked again that no other live receipt
     * holds the line and nothing you said stops it. A line another receipt got first leaves this one waiting (To do
     * asks whether it's a second copy). The why is kept with the link (link_provenance receipt_payment); with the
     * matcher, the receipt's choices are offered on the line for one tap.
     *
     * @param  string  $by  matcher, or you (a line you picked: what you said before about it doesn't stop you)
     * @return string attached | second_copy | vetoed | locked | gone
     */
    public function attach(QuickEntry $e, BankTransaction $line, string $by = 'matcher'): string
    {
        $out = DB::transaction(function () use ($e, $line, $by) {
            $tx = BankTransaction::query()->whereKey($line->id)->lockForUpdate()->first();
            $row = QuickEntry::query()->whereKey($e->id)->lockForUpdate()->first();
            if (! $tx || ! $row || ! in_array($row->status, ['waiting', 'attached'], true)) {
                return 'gone';
            }
            if ($row->status === 'attached' && (int) $row->bank_transaction_id === $tx->id) {
                return 'attached'; // already
            }
            if ($row->status === 'attached' && $by === 'matcher' && BankTransaction::query()->whereKey($row->bank_transaction_id)->exists()) {
                return 'gone'; // on another active line: the matcher never moves it
            }
            if (ManualExpenses::locked(UkTaxYear::fromDate($tx->effectiveDate())->label())) {
                return 'locked';
            }
            if (QuickEntry::query()->where('status', 'attached')->where('bank_transaction_id', $tx->id)->where('id', '!=', $row->id)->exists()) {
                return 'second_copy';
            }
            if ($by !== 'you' && isset(self::refusedLines($row, [self::METHOD, null])[$tx->id])) {
                return 'vetoed';
            }
            $before = QuickEntries::snapshot($row);
            $moved = $row->status === 'attached' && $row->bank_transaction_id !== null;
            $was = $row->bank_transaction_id;
            $row->forceFill(['status' => 'attached', 'bank_transaction_id' => $tx->id, 'attached_by' => $by, 'attached_at' => now()])->save();
            $days = $row->spent_on ? (int) abs(CarbonImmutable::parse($tx->effectiveDate()->format('Y-m-d'))->diffInDays(CarbonImmutable::parse($row->spent_on->format('Y-m-d')))) : null;
            if (self::cites()) {
                (new Provenance(self::METHOD, [$row->ref(), Ref::maybe('doc', $row->document_id), Ref::maybe('job', $row->work_job_id)], $days))
                    ->recordLink('receipt_payment', $row->ref(), 'tx:'.$tx->id);
            }
            AuditLog::record($moved ? 'quick_entry.moved' : 'quick_entry.attached', $row, $before + ['from_line' => $was], QuickEntries::snapshot($row),
                $by === 'you' ? 'You said it’s this payment' : 'Matched by amount and date', $by === 'you' ? 'you' : 'matcher');
            if ($moved && $was !== null) {
                $this->takeBackFrom((int) $was, $row);
            }
            $this->suggest($row, $tx, $days);

            return 'attached';
        });
        TodoList::forget();

        return $out;
    }

    /**
     * Offer the receipt's choices on its line (receipt.quick, by the matcher, never confirmed): its category, job and
     * business share. A line you decided or a rule confirmed keeps its decision; the receipt is evidence there.
     */
    public function suggest(QuickEntry $e, BankTransaction $line, ?int $days = null): bool
    {
        if ($e->category_id === null || Category::query()->whereKey($e->category_id)->value('kind') !== 'expense') {
            return false;
        }
        $when = ($e->captured_at ?? $e->created_at)?->timezone('Europe/London');
        $job = $e->work_job_id ? WorkJob::query()->whereKey($e->work_job_id)->value('title') : null;
        $category = Category::query()->whereKey($e->category_id)->value('name');
        $reason = 'From your receipt photo'.($when ? ', '.$when->format('j M H:i') : '').': '.$category.($job ? ', '.$job : '');
        $provenance = self::cites() ? new Provenance(self::METHOD, [$e->ref(), Ref::maybe('doc', $e->document_id), Ref::maybe('job', $e->work_job_id)], $days) : null;
        try {
            return AuditContext::with('matcher', fn () => $this->writer->decide($line, AllocationWriter::lines((int) $line->amount_pence, 'business_expense', (int) $e->category_id,
                $e->work_job_id ? (int) $e->work_job_id : null, $e->business_pct ?? 100), 'matcher', false, $reason, provenance: $provenance));
        } catch (InvalidArgumentException) {
            return false; // a locked year: the receipt is evidence only
        }
    }

    /**
     * After a receipt's figures change while it's on a line (Use what the photo says): a line it no longer fits is let
     * go when the matcher put it there and its offer there is still open (no veto: it just waits again and is matched
     * afresh); a line you chose or decided keeps it, and To do asks (quick.line_differs). A receipt waiting is matched.
     *
     * @return string kept | released | matched | none
     */
    public function refit(QuickEntry $e): string
    {
        $e->refresh();
        if ($e->isWaiting()) {
            $this->forEntry($e);

            return 'matched';
        }
        if ($e->status !== 'attached' || ! ($line = BankTransaction::query()->with('allocations')->find($e->bank_transaction_id))) {
            return 'none';
        }
        $f = self::figuresOf($e);
        if ($f === null || (self::amountFits($f, $line) && self::dateFits($f, $line))) {
            return 'kept';
        }
        $released = DB::transaction(function () use ($e, $line) {
            $row = QuickEntry::query()->whereKey($e->id)->lockForUpdate()->firstOrFail();
            $allocations = $line->allocations()->get();
            if ($row->status !== 'attached' || (int) $row->bank_transaction_id !== $line->id || $row->attached_by !== 'matcher'
                || ! $allocations->every(fn (Allocation $a) => self::isReceiptSuggestion($a, $row))) {
                return false;
            }
            $before = QuickEntries::snapshot($row);
            if (! $this->takeBackSuggestion($row) && $allocations->isNotEmpty()) {
                return false; // a locked year: its offer stays, and so does the receipt (To do asks)
            }
            $row->forceFill(['status' => 'waiting', 'bank_transaction_id' => null, 'attached_by' => null, 'attached_at' => null])->save();
            if (self::cites()) {
                (new Provenance(self::METHOD, [$row->ref()]))->recordLink('receipt_payment', $row->ref(), null);
            }
            AuditLog::record('quick_entry.detached', $row, $before, QuickEntries::snapshot($row), 'Its figures changed: it no longer fits that payment', 'matcher');

            return true;
        });
        if (! $released) {
            return 'kept';
        }
        $this->forEntry($e);
        TodoList::forget();

        return 'released';
    }

    /**
     * "Not this payment's receipt": the link goes, the receipt's suggestion on the line goes while it's still the
     * matcher's and unconfirmed, and the pair is never attached again by itself. The receipt waits again.
     */
    public function detach(QuickEntry $e, string $reason = 'Not this payment’s receipt'): QuickEntry
    {
        $row = DB::transaction(function () use ($e, $reason) {
            $row = QuickEntry::query()->whereKey($e->id)->lockForUpdate()->firstOrFail();
            if ($row->status !== 'attached' || $row->bank_transaction_id === null) {
                return $row;
            }
            $before = QuickEntries::snapshot($row);
            $line = (int) $row->bank_transaction_id;
            $this->takeBackSuggestion($row);
            $answers = $row->answers ?? [];
            $answers['not_lines'] = array_values(array_unique([...array_map('intval', (array) ($answers['not_lines'] ?? [])), $line]));
            $row->forceFill(['status' => 'waiting', 'bank_transaction_id' => null, 'attached_by' => null, 'attached_at' => null, 'answers' => $answers])->save();
            if (self::cites()) {
                Vetoes::record('receipt_payment', $row->ref(), 'tx:'.$line, null, self::METHOD, 'block', 'not_mine', $reason);
                (new Provenance(self::METHOD, [$row->ref()]))->recordLink('receipt_payment', $row->ref(), null);
            }
            AuditLog::record('quick_entry.detached', $row, $before, QuickEntries::snapshot($row), $reason);

            return $row;
        });
        TodoList::forget();

        return $row;
    }

    /**
     * Take back the receipt's suggestion on its line, while it's still the matcher's receipt.quick and nobody has
     * confirmed it. Call inside the entry's transaction. A confirmed one, or anyone else's, stays.
     */
    public function takeBackSuggestion(QuickEntry $row): bool
    {
        return $row->bank_transaction_id !== null && $this->takeBackFrom((int) $row->bank_transaction_id, $row);
    }

    private function takeBackFrom(int $lineId, QuickEntry $row): bool
    {
        $line = BankTransaction::query()->find($lineId);
        if (! $line) {
            return false;
        }
        $lines = $line->allocations()->get();
        if ($lines->isEmpty() || ! $lines->every(fn (Allocation $a) => self::isReceiptSuggestion($a, $row))) {
            return false;
        }
        try {
            $this->writer->clear($line, 'The receipt isn’t this payment’s any more', 'matcher');
        } catch (InvalidArgumentException) {
            return false; // a locked year: left as it is
        }

        return true;
    }

    /** Whether a line is this receipt's open suggestion: the matcher's receipt.quick, unconfirmed (citing the entry when it can). */
    private static function isReceiptSuggestion(Allocation $a, ?QuickEntry $row): bool
    {
        if ($a->is_manual || $a->confirmed_at !== null || $a->decided_by !== 'matcher') {
            return false;
        }
        if ($a->method === self::METHOD) {
            return $row === null || ! is_array($a->evidence) || in_array($row->ref(), $a->evidence, true);
        }

        return $a->method === null && str_starts_with((string) $a->reason, 'From your receipt photo');
    }

    /**
     * The nightly part receipt_gone: a receipt.quick suggestion whose receipt no longer points at that line (taken
     * back, moved, detached) is taken back. Open years only.
     */
    public function takeBackGone(): int
    {
        $n = 0;
        $allocations = Allocation::query()->whereNotNull('bank_transaction_id')->whereNull('confirmed_at')->where('is_manual', false)->where('decided_by', 'matcher')
            ->where(fn ($q) => $q->where('method', self::METHOD)->orWhere(fn ($q) => $q->whereNull('method')->where('reason', 'like', 'From your receipt photo%')))
            ->get(['id', 'bank_transaction_id', 'method', 'evidence', 'reason', 'decided_by', 'is_manual', 'confirmed_at'])->groupBy('bank_transaction_id');
        foreach ($allocations as $lineId => $rows) {
            $holds = QuickEntry::query()->where('status', 'attached')->where('bank_transaction_id', (int) $lineId)->get();
            $cited = $rows->flatMap(fn (Allocation $a) => (array) ($a->evidence ?? []))->filter(fn ($r) => str_starts_with((string) $r, 'quick:'))->values();
            $still = $holds->contains(fn (QuickEntry $e) => $cited->isEmpty() || $cited->contains($e->ref()));
            if ($still) {
                continue;
            }
            $line = BankTransaction::query()->find((int) $lineId);
            if (! $line) {
                continue;
            }
            try {
                $this->writer->clear($line, 'The receipt it came from isn’t on this payment any more', 'matcher');
                $n++;
            } catch (InvalidArgumentException) {
                // a locked year: left as it is
            }
        }

        return $n;
    }

    /** The amount of a foreign receipt in pounds at HMRC's rate kept for that month, or null when none is kept. */
    private static function sterlingOf(array $f): ?int
    {
        if ($f['currency'] === 'GBP') {
            return $f['hundredths'];
        }
        $rate = ExchangeRate::query()->where('currency', $f['currency'])->whereDate('period_start', $f['date']->startOfMonth()->format('Y-m-d'))
            ->orderByRaw("case source when 'manual' then 0 else 1 end")->value('units_per_gbp');
        if (! $rate || (float) $rate <= 0) {
            return null;
        }

        return (int) round($f['hundredths'] / (float) $rate);
    }
}
