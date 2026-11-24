<?php

namespace App\Domain\Intel;

use Carbon\CarbonInterface;

/**
 * A finding as a detector reports it, before it's recorded (FindingsRegistry::sync()) or shown on the to-do list
 * (TodoList): what it's about in the shared vocabulary, how it reads, its money and the one-tap actions it offers.
 *
 * Amounts are in hundredths of the currency (pence, cents), signed as the to-do list shows them (money out is
 * negative); a null currency is pounds. The direction says which way the money goes, so totals are never added
 * across directions or currencies.
 */
final class FindingDraft
{
    /** Which way the money goes (docs/intelligence-cycle.md section 2.2). */
    public const DIRECTIONS = ['owed_to_you', 'owed_by_you', 'unrecorded', 'at_risk', 'claimable', 'none'];

    /** How each direction reads beside an amount. */
    public const DIRECTION_WORDS = ['owed_to_you' => 'you may be owed', 'owed_by_you' => 'you may owe', 'unrecorded' => 'missing from your records', 'at_risk' => 'may be lost',
        'claimable' => 'could claim', 'none' => null];

    /** @var list<string> */
    public readonly array $evidence;

    public readonly ?string $dueOn;

    /**
     * @param  string  $key  the to-do key, which the finding is kept by
     * @param  list<?string>  $evidence  refs the claim rests on (the subject is added first)
     * @param  list<array{code:string, id:string, label:string, style:string}>  $actions  one-tap actions (TodoList::action())
     * @param  list<string>  $hiddenUnder  other keys a snooze or "not relevant" may be kept under (an older form of the key)
     * @param  ?string  $dismissKey  the key "not relevant" is kept under, when it isn't $key (a gap: its dates and amount)
     * @param  array<string, mixed>  $meta  what else the to-do item carries (a mileage item's jobs)
     */
    public function __construct(
        public readonly string $key,
        public readonly string $code,
        public readonly string $group,
        public readonly int $priority,
        public readonly string $title,
        public readonly ?string $detail = null,
        public readonly ?string $href = null,
        public readonly ?string $subjectRef = null,
        public readonly ?string $scopeRef = null,
        array $evidence = [],
        public readonly ?int $amountPence = null,
        public readonly ?string $currency = null,
        public readonly ?string $direction = null,
        public readonly ?string $grade = null,
        CarbonInterface|string|null $dueOn = null,
        public readonly array $actions = [],
        public readonly array $hiddenUnder = [],
        public readonly ?string $dismissKey = null,
        public readonly array $meta = [],
        public readonly ?int $detectorVersion = null,
    ) {
        $this->evidence = Ref::list([$subjectRef, ...$evidence]);
        $this->dueOn = $dueOn instanceof CarbonInterface ? $dueOn->format('Y-m-d') : $dueOn;
    }

    /**
     * What the to-do item carries beyond its group, priority, key, title, detail, href and actions: the money (only
     * when there is some, and the currency only when it's named, as the list always has), the extra the detector
     * gave, and the finding's vocabulary for the ranking and the page.
     *
     * @return array<string, mixed>
     */
    public function itemExtra(): array
    {
        return ($this->amountPence !== null ? ['amount' => $this->amountPence] : [])
            + ($this->amountPence !== null && $this->currency !== null ? ['currency' => $this->currency] : [])
            + $this->meta
            + array_filter([
                'code' => $this->code, 'subject_ref' => $this->subjectRef, 'scope_ref' => $this->scopeRef, 'direction' => $this->direction, 'grade' => $this->grade,
                'due_on' => $this->dueOn, 'why' => $this->evidence ?: null, 'dismiss_key' => $this->dismissKey, 'hidden_under' => $this->hiddenUnder ?: null,
            ], fn ($v) => $v !== null);
    }
}
