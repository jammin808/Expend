<?php

namespace App\Domain\Intel\Detectors;

use App\Domain\Intel\Contracts\CoversCodes;
use App\Domain\Intel\Contracts\Detector;
use App\Domain\Intel\Coverage;
use App\Domain\Intel\Cycle\CycleContext;
use App\Domain\Intel\FindingDraft;
use App\Domain\Intel\Grades;
use App\Domain\Intel\Ref;
use App\Domain\Matching\MerchantKey;
use App\Domain\Quick\CardLineFits;
use App\Domain\Quick\QuickReadCheck;
use App\Domain\Quick\ReceiptAttacher;
use App\Domain\Todo\TodoList;
use App\Models\Account;
use App\Models\Allocation;
use App\Models\BankTransaction;
use App\Models\ManualExpense;
use App\Models\QuickEntry;
use App\Models\TaxYear;
use App\Support\Money;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What needs you about the expenses you added on the phone (To do's group "From your phone"). Only reads.
 *
 *  - receipt.waiting: a business-card receipt still with no bank line, once the statement for the account you picked
 *    (for "Any", every account's) runs WAIT_DAYS days past its date, or WAIT_MAX_DAYS days have passed whatever
 *    happens. Lines that nearly fit (a tip, a pre-authorisation) are offered first ("It's this one"); then "None of
 *    these" shows Paid by personal card, Paid in cash and Not a business cost;
 *  - receipt.which: more than one line fits, or another receipt fits the same line: you choose;
 *  - receipt.second_copy: the only line that fits holds another receipt already;
 *  - quick.photo_differs: the photo's read differs from what you typed (QuickReadCheck);
 *  - quick.line_differs: the receipt's choices differ from what you decided on its line;
 *  - quick.held: kept, not added (a locked year, no exchange rate, details missing);
 *  - quick.maybe_card: added as cash or a personal card, and a card line of the same amount within two days: the same
 *    payment counted twice unless you say it was two (merchant words aren't needed: a chip says only "Parking");
 *  - quick.maybe_same: two records of the same amount within a day (the phone, Telegram, hand-added): the same
 *    receipt twice, unless their merchants plainly differ or you said they're two;
 *  - quick.photo_waiting: a photo still on the phone a day later.
 */
final class QuickEntryChecks implements CoversCodes, Detector
{
    public const CODES = ['receipt.waiting', 'receipt.which', 'receipt.second_copy', 'quick.photo_differs', 'quick.line_differs', 'quick.held', 'quick.maybe_card',
        'quick.maybe_same', 'quick.photo_waiting'];

    public const VERSION = 1;

    public const GROUP = 'phone';

    /** Asked about once the statement runs this many days past the receipt's date... */
    public const WAIT_DAYS = 7;

    /** ...or this many days have passed, whatever happens. */
    public const WAIT_MAX_DAYS = 21;

    /** A card line this many days from a cash entry may be the same payment. */
    public const CARD_DAYS = 2;

    /** Two records this many days apart may be the same receipt. */
    public const SAME_DAYS = 1;

    /** A photo still on the phone after this many hours. */
    public const PHOTO_HOURS = 24;

    private Coverage $coverage = Coverage::Failed;

    private ?int $checked = null;

    public function __construct(private ?ReceiptAttacher $attacher = null)
    {
        $this->attacher ??= app(ReceiptAttacher::class);
    }

    public function code(): string
    {
        return 'quick.checks';
    }

    public function codes(): array
    {
        return self::CODES;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function coverage(): Coverage
    {
        return $this->coverage;
    }

    public function coverageOf(string $code): Coverage
    {
        return $this->coverage;
    }

    public function checked(): ?int
    {
        return $this->checked;
    }

    /** @return list<FindingDraft> */
    public function detect(CycleContext $ctx): array
    {
        $this->coverage = Coverage::Failed;
        $locked = array_flip(TaxYear::query()->whereNotNull('locked_at')->pluck('label')->all());
        $drafts = [];
        $entries = QuickEntry::query()->with(['category', 'job', 'document', 'account'])->whereNotIn('status', ['withdrawn', 'dismissed', 'receiving'])->orderBy('id')->get();
        $this->checked = $entries->count();

        foreach ($entries->filter(fn (QuickEntry $e) => $e->isWaiting()) as $e) {
            array_push($drafts, ...$this->waiting($e));
        }
        foreach ($entries->where('status', 'held') as $e) {
            $drafts[] = $this->held($e);
        }
        foreach ($entries->filter(fn (QuickEntry $e) => (($e->read_check ?? [])['status'] ?? null) === 'differs' && $e->isLive()) as $e) {
            if ($q = QuickReadCheck::questions($e)) {
                $drafts[] = $this->photoDiffers($e, $q);
            }
        }
        foreach ($entries->filter(fn (QuickEntry $e) => $e->photo_status === 'expected' && $e->isLive() && $e->created_at !== null && $e->created_at->lt(now()->subHours(self::PHOTO_HOURS))) as $e) {
            $drafts[] = new FindingDraft(key: 'quick_photo_waiting:'.$e->id, code: 'quick.photo_waiting', group: self::GROUP, priority: 4,
                title: 'The photo for '.$e->words().' is still on your phone: open Expend with signal to send it.', detail: null, href: '/quick/entries/'.$e->id,
                subjectRef: self::ref($e), grade: Grades::CERTAIN, detectorVersion: self::VERSION);
        }
        $attached = $entries->where('status', 'attached')->filter(fn (QuickEntry $e) => $e->spent_on && ! isset($locked[UkTaxYear::fromDate($e->spent_on)->label()]));
        array_push($drafts, ...$this->lineDiffers($attached));
        $added = $entries->where('status', 'added')->filter(fn (QuickEntry $e) => $e->spent_on && ! isset($locked[UkTaxYear::fromDate($e->spent_on)->label()]));
        array_push($drafts, ...$this->maybeCard($added));
        array_push($drafts, ...$this->maybeSame($entries, $locked));

        $this->coverage = Coverage::Complete;

        return $drafts;
    }

    /**
     * The warnings for the phone's reply to one entry: a card payment of the same amount, the same receipt added.
     *
     * @return list<array{code:string, message:string}>
     */
    public function forEntry(QuickEntry $e): array
    {
        $out = [];
        if ($e->status === 'added' && ($line = $this->attacher->cardCandidates($e, self::CARD_DAYS)->first())) {
            $out[] = ['code' => 'quick.maybe_card', 'message' => 'Looks like a '.CardLineFits::describe($line).' card payment: check To do'];
        }
        foreach ($this->maybeSame(QuickEntry::query()->whereKey($e->id)->get()->concat(QuickEntry::query()->where('id', '!=', $e->id)
            ->where('amount_minor', $e->amount_minor)->where('currency', $e->currency)->whereNotIn('status', ['withdrawn', 'dismissed', 'receiving'])->get()), []) as $d) {
            if (preg_match('/(^quick_same:|\|)quick:'.$e->id.'(\||$)/', $d->key)) {
                $out[] = ['code' => 'quick.maybe_same', 'message' => $d->title];
                break;
            }
        }

        return $out;
    }

    // ---- business-card receipts ---------------------------------------------------------------------------------------

    /** @return list<FindingDraft> */
    private function waiting(QuickEntry $e): array
    {
        $fits = $this->attacher->candidates($e);
        if ($fits->count() > 1 || ($fits->count() === 1 && $this->sharedLine($e, $fits->first()))) {
            return [$this->which($e, $fits)];
        }
        if ($fits->isEmpty() && ($taken = $this->attacher->candidates($e, true))->count() === 1 && ! in_array($taken->first()->id, array_map('intval', $e->answered('other_payment')), true)) {
            return [$this->secondCopy($e, $taken->first())];
        }
        if ($fits->isEmpty() && ($fx = $this->attacher->fxCandidates($e))->isNotEmpty()) {
            return [$this->which($e, $fx, true)];
        }
        if ($fits->isNotEmpty() || ! $this->due($e, $runsTo)) {
            return [];
        }
        $near = in_array('all', $e->answered('none_of_these'), true) ? collect() : $this->attacher->nearMisses($e);
        $actions = [];
        foreach ($near as $t) {
            $actions[] = TodoList::action('qx', $e->id.':attach:'.$t->id, 'It’s this one: '.CardLineFits::describe($t).(abs((int) $t->amount_pence) !== $e->hundredths() ? ' (a tip or a pre-authorisation?)' : ''), 'primary');
        }
        if ($actions !== []) {
            $actions[] = TodoList::action('qx', $e->id.':none', 'None of these');
        } else {
            $actions = [TodoList::action('qx', $e->id.':paid_personal', 'Paid by personal card'), TodoList::action('qx', $e->id.':paid_cash', 'Paid in cash'),
                TodoList::action('qx', $e->id.':aside', 'Not a business cost')];
        }
        $account = $e->account?->name;

        return [new FindingDraft(
            key: 'quick_waiting:'.$e->id, code: 'receipt.waiting', group: self::GROUP, priority: 3,
            title: 'Receipt with no bank line yet: '.$e->words().', '.($e->spent_on?->format('j M') ?? '').' (business card)',
            detail: ($runsTo ? 'Your '.($account ? $account.' statement runs' : 'statements run').' to '.$runsTo->format('j M').'. ' : '')
                .($near->isNotEmpty() ? 'One of these may be it: a tip or a pre-authorisation changes the amount.' : 'If you paid another way, say so; if it wasn’t a business cost, set it aside.'),
            href: '/quick/entries/'.$e->id, subjectRef: self::ref($e), evidence: [Ref::maybe('doc', $e->document_id)],
            amountPence: $e->hundredths() !== null ? -$e->hundredths() : null, currency: $e->currency !== 'GBP' ? $e->currency : null, direction: 'unrecorded',
            grade: Grades::CERTAIN, actions: $actions, detectorVersion: self::VERSION,
        )];
    }

    /**
     * Whether a receipt is due to be asked about: the statement for its account (for Any, every account's) runs
     * WAIT_DAYS days past its date, or WAIT_MAX_DAYS days have passed.
     */
    private function due(QuickEntry $e, ?CarbonImmutable &$runsTo = null): bool
    {
        $runsTo = null;
        if ($e->spent_on === null) {
            return false;
        }
        $on = CarbonImmutable::parse($e->spent_on->format('Y-m-d'));
        $accounts = $e->account_id ? [$e->account_id] : Account::query()->where('kind', '!=', 'cash')->where('is_active', true)->pluck('id')->all();
        $ends = [];
        foreach ($accounts as $id) {
            $last = BankTransaction::query()->where('account_id', $id)->max('posted_on');
            $ends[] = $last ? CarbonImmutable::parse(substr((string) $last, 0, 10)) : null;
        }
        $known = array_filter($ends);
        if ($known !== []) {
            $runsTo = min($known);
        }
        if ($on->addDays(self::WAIT_MAX_DAYS)->lte(today())) {
            return true;
        }

        return $ends !== [] && ! in_array(null, $ends, true) && $runsTo !== null && $runsTo->gte($on->addDays(self::WAIT_DAYS));
    }

    /** Whether another waiting receipt fits the same line. */
    private function sharedLine(QuickEntry $e, BankTransaction $line): bool
    {
        return QuickEntry::query()->waiting()->where('id', '!=', $e->id)->get()
            ->contains(fn (QuickEntry $o) => ($f = ReceiptAttacher::figuresOf($o)) !== null && ReceiptAttacher::fitsFigures($f, $line)
                && ! isset(ReceiptAttacher::refusedLines($o, [ReceiptAttacher::METHOD, null])[$line->id]));
    }

    /**
     * @param  Collection<int, BankTransaction>  $lines
     * @param  bool  $fx  lines in pounds near HMRC's conversion of a foreign receipt
     */
    private function which(QuickEntry $e, Collection $lines, bool $fx = false): FindingDraft
    {
        $actions = $lines->take(3)->map(fn (BankTransaction $t) => TodoList::action('qx', $e->id.':attach:'.$t->id, 'It’s this one: '.CardLineFits::describe($t), 'primary'))->values()->all();

        return new FindingDraft(
            key: 'quick_which:'.$e->id, code: 'receipt.which', group: self::GROUP, priority: 3,
            title: 'Which payment is this receipt for? '.$e->words().', '.($e->spent_on?->format('j M') ?? ''),
            detail: $fx ? 'Your bank shows only pounds: '.($lines->count() > 1 ? 'these card payments are' : 'this card payment is').' within '.ReceiptAttacher::FX_SHARE
                    .'% of HMRC’s conversion of it. Choose one, or find another on its page.'
                : ($lines->count() > 1 ? $lines->count().' card payments of that amount fit it. Choose one, or find another on its page.'
                    : 'Another receipt of yours fits the same card payment. Choose, or find another on its page.'),
            href: '/quick/entries/'.$e->id, subjectRef: self::ref($e), evidence: $lines->take(3)->map(fn ($t) => 'tx:'.$t->id)->all(),
            amountPence: $e->hundredths() !== null ? -$e->hundredths() : null, currency: $e->currency !== 'GBP' ? $e->currency : null, direction: 'unrecorded',
            grade: $fx ? Grades::POSSIBLE : Grades::LIKELY, actions: $actions, detectorVersion: self::VERSION,
        );
    }

    private function secondCopy(QuickEntry $e, BankTransaction $line): FindingDraft
    {
        $other = QuickEntry::query()->where('status', 'attached')->where('bank_transaction_id', $line->id)->where('id', '!=', $e->id)->first();

        return new FindingDraft(
            key: 'quick_copy:'.$e->id.':'.$line->id, code: 'receipt.second_copy', group: self::GROUP, priority: 2,
            title: 'A second copy of a receipt? '.$e->words().', '.($e->spent_on?->format('j M') ?? ''),
            detail: 'The only card payment that fits ('.CardLineFits::describe($line).') already has your receipt'
                .($other?->captured_at ? ' from '.$other->captured_at->timezone('Europe/London')->format('j M H:i') : '').'.',
            href: '/quick/entries/'.$e->id, subjectRef: self::ref($e), evidence: ['tx:'.$line->id, $other ? self::ref($other) : null],
            amountPence: $e->hundredths() !== null ? -$e->hundredths() : null, currency: $e->currency !== 'GBP' ? $e->currency : null, direction: 'at_risk',
            grade: Grades::LIKELY, actions: [TodoList::action('qx', $e->id.':same_copy', 'Same receipt: remove this one', 'primary'),
                TodoList::action('qx', $e->id.':other_payment:'.$line->id, 'A different payment: keep waiting')], detectorVersion: self::VERSION,
        );
    }

    /** @param  Collection<int, QuickEntry>  $attached @return list<FindingDraft> */
    private function lineDiffers(Collection $attached): array
    {
        $out = [];
        if ($attached->isEmpty()) {
            return $out;
        }
        $lines = BankTransaction::query()->whereIn('id', $attached->pluck('bank_transaction_id')->all())->with('allocations.category', 'allocations.job')->get()->keyBy('id');
        foreach ($attached as $e) {
            $line = $lines->get($e->bank_transaction_id);
            if (! $line || in_array($line->id, array_map('intval', $e->answered('keep_line')), true)) {
                continue;
            }
            // Its figures changed on a line you chose or decided (Use what the photo says): still this payment? A
            // line you chose that never fitted (a tip, a pre-authorisation) isn't asked about.
            $f = ReceiptAttacher::figuresOf($e);
            $changed = $e->attached_by === 'matcher' || collect((array) (($e->read_check ?? [])['answered'] ?? []))
                ->contains(fn ($a) => ($a['answer'] ?? null) === 'use' && $e->attached_at !== null && isset($a['at']) && CarbonImmutable::parse($a['at'])->gt($e->attached_at));
            if ($changed && $f !== null && ! (ReceiptAttacher::amountFits($f, $line) && ReceiptAttacher::dateFits($f, $line))) {
                $out[] = new FindingDraft(
                    key: 'quick_line:'.$e->id.':'.$line->id, code: 'quick.line_differs', group: self::GROUP, priority: 2,
                    title: 'Still this payment? Your receipt now says '.$e->words().', '.($e->spent_on?->format('j M') ?? ''),
                    detail: 'It’s on '.CardLineFits::describe($line).', which no longer fits it. If it isn’t this payment’s receipt, take it off: it waits for its own line.',
                    href: '/quick/entries/'.$e->id, subjectRef: self::ref($e), evidence: ['tx:'.$line->id],
                    amountPence: (int) $line->amount_pence, direction: 'at_risk', grade: Grades::CERTAIN,
                    actions: [TodoList::action('qx', $e->id.':detach', 'Not this payment’s receipt', 'primary'), TodoList::action('qx', $e->id.':keep_line', 'It is this payment')],
                    detectorVersion: self::VERSION,
                );

                continue;
            }
            if ($e->category_id === null) {
                continue;
            }
            $mine = $line->allocations->filter(fn ($a) => $a->is_manual || $a->confirmed_at !== null);
            if ($mine->isEmpty() || $mine->count() !== $line->allocations->count()) {
                continue;
            }
            $business = $mine->first(fn ($a) => $a->kind === 'business_expense');
            $same = $business && (int) $business->category_id === (int) $e->category_id && (int) ($business->work_job_id ?? 0) === (int) ($e->work_job_id ?? 0)
                && (int) ($business->business_use_pct ?? 100) === (int) ($e->business_pct ?? 100);
            if ($same) {
                continue;
            }
            $says = $business ? ($business->category?->name ?? 'a business cost').($business->job ? ', '.$business->job->title : '') : (Allocation::KINDS[$mine->first()->kind] ?? $mine->first()->kind);
            $out[] = new FindingDraft(
                key: 'quick_line:'.$e->id.':'.$line->id, code: 'quick.line_differs', group: self::GROUP, priority: 3,
                title: 'Your receipt and its bank line differ: '.$e->words(),
                detail: 'The receipt says '.($e->category?->name ?? 'a business cost').($e->job ? ', '.$e->job->title : '').(($e->business_pct ?? 100) < 100 ? ' ('.$e->business_pct.'% business)' : '')
                    .'; the line ('.CardLineFits::describe($line).') says '.$says.'.',
                href: '/quick/entries/'.$e->id, subjectRef: self::ref($e), evidence: ['tx:'.$line->id],
                amountPence: (int) $line->amount_pence, grade: Grades::CERTAIN,
                actions: [TodoList::action('qx', $e->id.':use_receipt', 'Use the receipt’s', 'primary'), TodoList::action('qx', $e->id.':keep_line', 'Keep the line as it is')],
                detectorVersion: self::VERSION,
            );
        }

        return $out;
    }

    // ---- held, and the photo's read ------------------------------------------------------------------------------------

    private function held(QuickEntry $e): FindingDraft
    {
        $label = $e->spent_on ? UkTaxYear::fromDate($e->spent_on)->label() : null;
        [$title, $detail, $actions] = match ($e->hold_reason) {
            'locked_year' => ["Kept, not added: {$label} is locked", $e->words().'. Its photo is kept. Unlock '.$label.' with a reason to add it, or remove it.',
                [TodoList::action('qx', $e->id.':add_held', 'Add it now', 'primary'), TodoList::action('qx', $e->id.':cancel', 'Remove')]],
            'needs_rate' => ['Kept, not added: no exchange rate for '.$e->words(), 'Enter the rate or the sterling amount on its page, or try the rate again.',
                [TodoList::action('qx', $e->id.':add_held', 'Try the rate again', 'primary'), TodoList::action('qx', $e->id.':cancel', 'Remove')]],
            'same_photo' => ['Kept, not added: the same photo as another entry', (string) ((($e->answers ?? [])['held_message'] ?? null) ?: 'Check which is right on its page.'),
                [TodoList::action('qx', $e->id.':cancel', 'Same receipt: remove this one', 'primary'), TodoList::action('qx', $e->id.':add_held', 'A different payment: add it')]],
            default => ['Kept, not added: details missing for '.$e->words(), (string) ((($e->answers ?? [])['held_message'] ?? null) ?: 'Choose what it was and how you paid on its page.'),
                [TodoList::action('qx', $e->id.':cancel', 'Remove')]],
        };

        return new FindingDraft(key: 'quick_held:'.$e->id, code: 'quick.held', group: self::GROUP, priority: 3, title: $title, detail: $detail, href: '/quick/entries/'.$e->id,
            subjectRef: self::ref($e), evidence: [Ref::maybe('doc', $e->document_id)], amountPence: $e->hundredths() !== null ? -$e->hundredths() : null,
            currency: $e->currency !== 'GBP' ? $e->currency : null, direction: 'unrecorded', grade: Grades::CERTAIN, actions: $actions, detectorVersion: self::VERSION);
    }

    /** @param  list<array{field:string, text:string, use:string, keep:string}>  $questions */
    private function photoDiffers(QuickEntry $e, array $questions): FindingDraft
    {
        $actions = [];
        foreach ($questions as $q) {
            $actions[] = TodoList::action('qx', $e->id.':use_read:'.$q['field'], $q['use'], 'primary');
            $actions[] = TodoList::action('qx', $e->id.':keep_mine:'.$q['field'], $q['keep']);
        }

        return new FindingDraft(key: 'quick_photo:'.$e->id, code: 'quick.photo_differs', group: self::GROUP, priority: 3, title: $questions[0]['text'],
            detail: implode('. ', array_column(array_slice($questions, 1), 'text')) ?: 'Read from your photo of '.$e->words().'. Nothing changes until you say.',
            href: '/quick/entries/'.$e->id, subjectRef: self::ref($e), evidence: [Ref::maybe('doc', $e->document_id)], grade: Grades::POSSIBLE,
            actions: $actions, detectorVersion: self::VERSION);
    }

    // ---- counted twice -------------------------------------------------------------------------------------------------

    /** @param  Collection<int, QuickEntry>  $added @return list<FindingDraft> */
    private function maybeCard(Collection $added): array
    {
        $out = [];
        $added = $added->filter(fn (QuickEntry $e) => in_array($e->paid_with, ['cash', 'personal_card'], true) && $e->amount_minor !== null)->values();
        if ($added->isEmpty()) {
            return $out;
        }
        // One read of the lines that could be any of them (the amounts in pounds, or any line in another currency), so
        // only an entry with a line of its amount near it is looked at closely.
        $from = $added->min(fn (QuickEntry $e) => $e->spent_on->format('Y-m-d'));
        $to = $added->max(fn (QuickEntry $e) => $e->spent_on->format('Y-m-d'));
        $pounds = $added->map(fn (QuickEntry $e) => $e->currency === 'GBP' ? -abs((int) $e->amount_minor) : ($e->sterling_pence ? -abs((int) $e->sterling_pence) : null))
            ->filter()->unique()->values()->all();
        $near = BankTransaction::query()->where('amount_pence', '<', 0)
            ->whereDate('posted_on', '>=', CarbonImmutable::parse($from)->subDays(self::CARD_DAYS + 5)->format('Y-m-d'))
            ->whereDate('posted_on', '<=', CarbonImmutable::parse($to)->addDays(self::CARD_DAYS + 5)->format('Y-m-d'))
            ->where(fn ($q) => $q->whereIn('amount_pence', $pounds ?: [0])->orWhereNotNull('original_currency')->orWhereNotNull('merchant_currency'))
            ->get(['id', 'posted_on', 'transaction_on', 'amount_pence', 'original_amount_minor', 'original_currency', 'merchant_amount_minor', 'merchant_currency', 'transfer_pair_id']);
        foreach ($added as $e) {
            $f = ReceiptAttacher::cardFigures($e);
            if (! $near->contains(fn (BankTransaction $t) => ReceiptAttacher::dateFits($f, $t, self::CARD_DAYS) && ReceiptAttacher::amountFits($f, $t))) {
                continue;
            }
            $lines = $this->attacher->cardCandidates($e, self::CARD_DAYS)->take(3);
            if ($lines->isEmpty()) {
                continue;
            }
            $first = $lines->first();
            $how = $e->paid_with === 'cash' ? 'cash' : 'a personal card';
            $named = $e->what_source !== 'chip' && $e->what ? DupeCrossSource::merchantMatch(MerchantKey::from((string) $e->what), $first->merchant_key ?: MerchantKey::from($first->description, $first->counterparty)) : null;
            $actions = $lines->map(fn (BankTransaction $t) => TodoList::action('qx', $e->id.':to_card:'.$t->id, 'It was the card: '.($t->account?->name ?? 'card').', '.$t->effectiveDate()->format('j M'), 'primary'))->values()->all();
            $actions[] = TodoList::action('qx', $e->id.':not_card:'.$lines->pluck('id')->implode(','), 'Two payments');
            $out[] = new FindingDraft(
                key: 'quick_card:'.$e->id, code: 'quick.maybe_card', group: self::GROUP, priority: 2,
                title: 'Added as '.$how.', and a card payment of the same amount: '.Money::formatMinor((int) $e->amount_minor, $e->currency, false).', '
                    .($first->account?->name ?? 'card').', '.$first->effectiveDate()->format('j M').' ('.($first->counterparty ?: $first->description).')',
                detail: 'If it’s the same payment, it was the card: the expense you added goes and the receipt goes on the card line, so it isn’t counted twice.',
                href: '/quick/entries/'.$e->id, subjectRef: self::ref($e), evidence: [Ref::maybe('exp', $e->manual_expense_id), ...$lines->map(fn ($t) => 'tx:'.$t->id)->all()],
                amountPence: -(int) $e->hundredths(), currency: $e->currency !== 'GBP' ? $e->currency : null, direction: 'at_risk',
                grade: $named === Grades::LIKELY ? Grades::LIKELY : Grades::POSSIBLE, actions: $actions, detectorVersion: self::VERSION,
            );
        }

        return $out;
    }

    /**
     * Two records of the same amount and currency within SAME_DAYS days: your phone entries (but drafts, set aside,
     * taken back and duplicates) and expenses you added by hand. Not when both name merchants that plainly differ, or
     * you said they're two payments. One of each pair is a phone entry (its buttons act on it).
     *
     * @param  Collection<int, QuickEntry>  $entries
     * @param  array<string, int>  $locked
     * @return list<FindingDraft>
     */
    private function maybeSame(Collection $entries, array $locked): array
    {
        $records = [];
        foreach ($entries as $e) {
            if (in_array($e->status, ['draft', 'dismissed', 'withdrawn', 'duplicate', 'set_aside', 'receiving'], true) || $e->amount_minor === null || $e->spent_on === null
                || isset($locked[UkTaxYear::fromDate($e->spent_on)->label()])) {
                continue;
            }
            $records[] = ['ref' => 'quick:'.$e->id, 'entry' => $e, 'minor' => abs($e->amount_minor), 'currency' => $e->currency, 'on' => CarbonImmutable::parse($e->spent_on->format('Y-m-d')),
                'at' => $e->captured_at ?? $e->created_at, 'name' => self::nameOf($e), 'typed' => self::typedOf($e), 'how' => $e->channel === 'telegram' ? 'in Telegram' : 'on your phone'];
        }
        if ($records === []) {
            return [];
        }
        $own = QuickEntry::query()->whereNotNull('manual_expense_id')->pluck('manual_expense_id')->map(fn ($id) => (int) $id)->all();
        $days = collect($records)->pluck('on');
        $hand = ManualExpense::query()->whereNotIn('id', $own ?: [0])->where('amount_pence', '<', 0)
            ->whereDate('spent_on', '>=', $days->min()->subDays(self::SAME_DAYS)->format('Y-m-d'))->whereDate('spent_on', '<=', $days->max()->addDays(self::SAME_DAYS)->format('Y-m-d'))->get();
        foreach ($hand as $x) {
            if (isset($locked[(string) $x->tax_year])) {
                continue;
            }
            $currency = $x->original_currency ?: 'GBP';
            $records[] = ['ref' => 'exp:'.$x->id, 'entry' => null, 'minor' => abs((int) ($x->original_currency ? $x->original_amount_minor : $x->amount_pence)), 'currency' => $currency,
                'on' => CarbonImmutable::parse($x->spent_on->format('Y-m-d')), 'at' => $x->created_at, 'name' => $x->merchant_key ?: MerchantKey::from((string) $x->description),
                'typed' => null, 'how' => 'by hand'];
        }
        $out = [];
        $by = collect($records)->groupBy(fn ($r) => $r['currency'].':'.$r['minor']);
        foreach ($by as $group) {
            $group = $group->sortBy(fn ($r) => [$r['on']->format('Y-m-d'), $r['at']?->getTimestamp() ?? 0])->values();
            for ($i = 0; $i < $group->count(); $i++) {
                for ($j = $i + 1; $j < $group->count(); $j++) {
                    [$a, $b] = [$group[$i], $group[$j]];
                    if ($a['entry'] === null && $b['entry'] === null) {
                        continue;
                    }
                    if (abs($a['on']->diffInDays($b['on'])) > self::SAME_DAYS) {
                        continue;
                    }
                    // Merchant names that plainly differ (NCP and Q-Park, read from the photos or added by hand): two
                    // payments. What you typed or texted isn't a name ("parking Leeds" names a place, not NCP): it
                    // only makes the question less sure.
                    if ($a['name'] !== null && $b['name'] !== null && DupeCrossSource::merchantMatch($a['name'], $b['name']) === null) {
                        continue;
                    }
                    $answered = [...($a['entry']?->answered('not_same') ?? []), ...($b['entry']?->answered('not_same') ?? [])];
                    if (in_array($a['ref'], $answered, true) || in_array($b['ref'], $answered, true)) {
                        continue;
                    }
                    [$remove, $keep, $label] = self::removeOf($a, $b);
                    // The buttons act on a phone entry: the one kept when it is one, else the one removed.
                    $holder = $keep['entry'] ?? $remove['entry'];
                    $other = $holder === $keep['entry'] ? $remove : $keep;
                    $words = Money::formatMinor($a['minor'], $a['currency'], false);
                    $names = [$a['name'] ?? $a['typed'], $b['name'] ?? $b['typed']];
                    $match = $names[0] !== null && $names[1] !== null ? DupeCrossSource::merchantMatch($names[0], $names[1]) : null;
                    $out[] = new FindingDraft(
                        key: 'quick_same:'.$a['ref'].'|'.$b['ref'], code: 'quick.maybe_same', group: self::GROUP, priority: 2,
                        title: 'The same receipt twice? '.$words.' on '.$a['on']->format('j M').($a['on']->ne($b['on']) ? ' and '.$b['on']->format('j M') : ''),
                        detail: 'Added '.$a['how'].($a['at'] ? ' at '.$a['at']->timezone('Europe/London')->format('H:i') : '').' and '.$b['how']
                            .($b['at'] ? ' at '.$b['at']->timezone('Europe/London')->format('H:i') : '').'. If it’s one receipt, '.$label['detail'].' so it isn’t counted twice.',
                        href: '/quick/entries/'.$holder->id, subjectRef: self::ref($holder), evidence: [$other['entry'] ? self::ref($other['entry']) : $other['ref']],
                        amountPence: -(int) Money::toHundredths($a['minor'], $a['currency']), currency: $a['currency'] !== 'GBP' ? $a['currency'] : null, direction: 'at_risk',
                        grade: $match === Grades::LIKELY ? Grades::LIKELY : Grades::POSSIBLE,
                        actions: [TodoList::action('qx', $holder->id.':same:'.$remove['ref'], $label['button'], 'primary'),
                            TodoList::action('qx', $holder->id.':not_same:'.$other['ref'], 'Two payments')],
                        detectorVersion: self::VERSION,
                    );
                }
            }
        }

        return $out;
    }

    /**
     * Which of two records of one receipt to remove, so it's counted once: never a business-card receipt over a cash,
     * personal-card or hand-added expense (the card line counts it; the expense is the second count); of two card
     * receipts, not the one on its line; otherwise the later one.
     *
     * @return array{0:array, 1:array, 2:array{button:string, detail:string}} the record to remove, the one kept, the words
     */
    private static function removeOf(array $a, array $b): array
    {
        $card = fn (array $r) => $r['entry'] !== null && $r['entry']->paid_with === 'business' && in_array($r['entry']->status, ['waiting', 'attached', 'held'], true);
        $later = ['button' => 'Same receipt: remove the later one', 'detail' => 'remove the later one'];
        if ($card($a) !== $card($b)) {
            [$remove, $keep] = $card($a) ? [$b, $a] : [$a, $b];
            $what = $remove['entry'] === null ? 'the one added by hand' : ($remove['entry']->paid_with === 'personal_card' ? 'the personal card one' : 'the cash one');

            return [$remove, $keep, ['button' => 'Same receipt, paid by card: remove '.$what, 'detail' => 'it was the business card: remove '.$what]];
        }
        if ($card($a) && $card($b) && ($a['entry']->status === 'attached') !== ($b['entry']->status === 'attached')) {
            [$remove, $keep] = $a['entry']->status === 'attached' ? [$b, $a] : [$a, $b];

            return [$remove, $keep, ['button' => 'Same receipt: remove the one with no bank line', 'detail' => 'remove the one with no bank line']];
        }

        return [$b, $a, $later];
    }

    /**
     * The merchant an entry names for certain: the one read from its photo. What you typed or texted says the kind
     * of cost and often a place ("parking Leeds"), so it never sets two records apart (typedOf()).
     */
    private static function nameOf(QuickEntry $e): ?string
    {
        if ($e->what && $e->what_source === 'photo') {
            return MerchantKey::from((string) $e->what);
        }
        $read = $e->read_check['read']['merchant'] ?? null;

        return $read ? MerchantKey::from((string) $read) : null;
    }

    /** What you typed or texted, as merchant words: only to say how sure the same-receipt question is. */
    private static function typedOf(QuickEntry $e): ?string
    {
        return $e->what && in_array($e->what_source, ['typed', 'telegram'], true) ? MerchantKey::from((string) $e->what) : null;
    }

    /** What an entry is cited as: quick:ID, or its photo when quick refs aren't known yet. */
    public static function ref(QuickEntry $e): ?string
    {
        return Ref::valid('quick:'.$e->id) ? 'quick:'.$e->id : (Ref::maybe('doc', $e->document_id) ?? Ref::maybe('exp', $e->manual_expense_id));
    }
}
