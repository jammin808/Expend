<?php

namespace App\Domain\Intel;

use App\Domain\Ledger\AllocationWriter;
use App\Domain\Matching\Categoriser;
use App\Domain\Matching\TransferPairer;
use App\Models\Allocation;
use App\Models\AuditEvent;
use App\Models\MerchantRule;
use App\Models\TravelItem;
use InvalidArgumentException;

/**
 * Every way Expend proposes or makes a link on its own, by a dotted name (docs/intelligence-cycle.md 2.4). Each has
 * the actor it writes as, its basis, the decision kind it serves (section 5.3), a version bumped whenever its logic
 * changes, what its own score means, and a recogniser for lines written before the method was stored with them.
 */
final class Methods
{
    /** What a method rests on. */
    public const BASES = [
        'exact' => 'an exact match', 'rule' => 'a fixed rule', 'heuristic' => 'matching', 'ai_grounded' => 'AI, checked against the source',
        'ai_ungrounded' => 'AI, not checked against a source', 'owner' => 'you',
    ];

    /**
     * name => [actor, basis, decision kind, version, how it works in plain words, what its score means (null: none)]
     *
     * @var array<string, array{0:string, 1:string, 2:string, 3:int, 4:string, 5:?string}>
     */
    private const REGISTRY = [
        'transfer.pair' => ['matcher', 'heuristic', 'transfer.pair', 1, 'both sides of money moving between your accounts, matched by amount, date and wording', 'how sure, out of 10 for both sides together'],
        'transfer.one_sided' => ['matcher', 'heuristic', 'transfer.one_sided', 1, 'a line that shows on its own that it’s your money moving (your name, a pot, a top-up of yours)', null],
        'transfer.partner' => ['matcher', 'exact', 'transfer.partner', 1, 'the other side of a transfer you decided', null],
        'revolut.exchange' => ['matcher', 'exact', 'transfer.exchange', 1, 'the two sides of one currency exchange in a Revolut statement', null],
        // Income design v3 (Income\InvoiceMatcher): what's still to come on an invoice, not its total; two to four of
        // the payer's client's invoices adding up to the payment; and either with a fee or commission taken off.
        'suggest.invoice' => ['matcher', 'heuristic', 'income.invoice', 2, 'the only open invoice whose amount still to come is the payment’s', 'how many open invoices had that amount still to come'],
        'suggest.invoice_set' => ['matcher', 'heuristic', 'income.invoice', 1, '2 to 4 of the payer’s client’s open invoices that add up to the payment', 'how many of the client’s open invoices were searched'],
        'suggest.invoice_net' => ['matcher', 'heuristic', 'income.invoice', 1, 'one invoice, or 2 to 4, with a fee or commission taken off: the bank’s, or the payer’s usual', 'how many open invoices were tried'],
        'suggest.hmrc' => ['matcher', 'rule', 'tax.hmrc_payment', 1, 'a payment to HMRC, by its words', null],
        'suggest.penalty' => ['matcher', 'rule', 'category.pattern', 1, 'a fine or penalty charge, by its words', null],
        'suggest.car_hire' => ['matcher', 'rule', 'category.pattern', 1, 'a car hire company’s name', null],
        'suggest.travel' => ['matcher', 'heuristic', 'category.pattern', 1, 'TfL, taxis, trains or parking, against your job days', null],
        'categorise.merchant' => ['matcher', 'heuristic', 'category.learned', 1, 'how you decided this merchant before', 'your decisions that agree'],
        'categorise.similar' => ['matcher', 'heuristic', 'category.learned', 1, 'how you decided similar merchants', 'your decisions that agree'],
        'categorise.bank_learned' => ['matcher', 'heuristic', 'category.learned', 1, 'how you usually decide the bank’s category for it', 'your decisions that agree'],
        'categorise.hint' => ['matcher', 'heuristic', 'category.pattern', 1, 'a list of common merchants', null],
        'categorise.bank_hint' => ['matcher', 'heuristic', 'category.pattern', 1, 'the bank’s own category', null],
        'rule.learned' => ['rule', 'rule', 'category.rule', 1, 'a rule learned from your decisions (“Always do this”)', null],
        'rule.owner' => ['rule', 'rule', 'category.rule', 1, 'a rule you saved', null],
        'travel.match' => ['matcher', 'heuristic', 'travel.stay', 1, 'a stay or journey lined up with your job days, night by night', 'nights for work'],
        'jobs.calendar_rules' => ['matcher', 'heuristic', 'link.calendar_job', 1, 'the job finder: the entry’s date and words against your jobs', 'match score (5 or more links, 3 clear of the next job)'],
        'jobs.calendar_ai' => ['ai', 'ai_grounded', 'link.calendar_job', 1, 'the job finder, with an AI answer whose quoted words are in the entry', 'match score (5 or more links, 3 clear of the next job)'],
        'jobs.document' => ['matcher', 'heuristic', 'link.document_job', 1, 'the job finder: a document that quotes the job’s PO number, or gives its date and names it', 'match score (5 or more links, 3 clear of the next job)'],
        'jobs.travel' => ['matcher', 'heuristic', 'link.travel_job', 1, 'the job finder: a stay or journey on the job’s days', null],
        'jobs.invoice_po' => ['matcher', 'exact', 'link.invoice_job', 1, 'the same PO number as the job', null],
        'jobs.gather' => ['matcher', 'heuristic', 'job.cost', 1, 'the job’s own search: its days, client, venue and PO number', null],
        'doc.read' => ['ai', 'ai_grounded', 'evidence.read', 1, 'a document read with AI, each detail checked against its text', null],
        'travel.read' => ['ai', 'ai_grounded', 'evidence.read', 1, 'a booking email read with AI, each detail checked against its text', null],
        'ai.advisor' => ['ai', 'ai_ungrounded', 'category.ai', 1, 'the AI’s suggestion for waiting payments', null],
        'receipt.quick' => ['matcher', 'heuristic', 'category.receipt', 1, 'your receipt from your phone, matched by amount and date', 'days between the receipt and the payment'],
        'quick.maybe_card' => ['checker', 'heuristic', 'finding.close', 1, 'a cash or personal-card cost from your phone against a card payment of the same amount', null],
        'ai.assistant' => ['ai', 'ai_ungrounded', 'category.ai', 1, 'the assistant, when you asked it', null],
    ];

    /** Whether a method is registered (detectors are registered by their code: detector.<code>). */
    public static function has(string $method): bool
    {
        return isset(self::REGISTRY[$method]) || preg_match('/^detector\.[a-z0-9_.]{1,31}$/', $method) === 1;
    }

    /**
     * The one order of precedence over open suggestions (docs/intelligence-cycle.md 4.2, rule 4): whether a suggestion
     * by method $a (made by $aBy) outranks one by $b. You > rule > matcher (invoice > HMRC > transfer > pattern >
     * categoriser, with the assistant's proposal from your words) > the background AI. Kept with the writer that
     * enforces it (AllocationWriter::PRECEDENCE).
     */
    public static function outranks(?string $a, string $aBy, ?string $b, string $bBy): bool
    {
        return AllocationWriter::outranks($a, $aBy, $b, $bBy);
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::REGISTRY);
    }

    public static function actor(string $method): string
    {
        return self::entry($method)[0];
    }

    public static function basis(string $method): string
    {
        return self::entry($method)[1];
    }

    public static function kind(string $method): string
    {
        return self::entry($method)[2];
    }

    public static function version(string $method): int
    {
        return self::entry($method)[3];
    }

    /** How the method works, in plain words ("how you decided this merchant before"). */
    public static function describe(string $method): string
    {
        return self::entry($method)[4];
    }

    /** What its score means, or null when it keeps none. */
    public static function scoreMeans(string $method): ?string
    {
        return self::entry($method)[5];
    }

    /** @return array{0:string, 1:string, 2:string, 3:int, 4:string, 5:?string} */
    private static function entry(string $method): array
    {
        if (isset(self::REGISTRY[$method])) {
            return self::REGISTRY[$method];
        }
        if (self::has($method)) {
            return ['checker', 'exact', 'finding.close', 1, 'a check of your records', null];
        }
        throw new InvalidArgumentException("Unknown method “{$method}”.");
    }

    /** The method of a rule's decisions: learned from your decisions ("Always do this"), or one you saved. */
    public static function forRule(?MerchantRule $rule): string
    {
        if ($rule === null) {
            return 'rule.owner';
        }

        return $rule->getAttribute('direction') !== null
            || AuditEvent::query()->where('action', 'rule.learned')->where('subject_type', 'MerchantRule')->where('subject_id', $rule->id)->exists()
            ? 'rule.learned' : 'rule.owner';
    }

    /**
     * The method behind an allocation: as stored with it, or, for a line written before methods were stored, as its
     * maker and reason show (null when they don't, or when you made it).
     *
     * @return array{method:?string, recorded:bool}
     */
    public static function of(Allocation $a): array
    {
        if ($a->method !== null && self::has($a->method)) {
            return ['method' => $a->method, 'recorded' => true];
        }

        return ['method' => self::legacy((string) $a->decided_by, (string) $a->reason, $a->matcher, $a->merchant_rule_id), 'recorded' => false];
    }

    /** The legacy recogniser: a method from who made a line and how its reason is worded. */
    public static function legacy(string $decidedBy, string $reason, ?string $marker = null, ?int $ruleId = null): ?string
    {
        if ($decidedBy === 'rule') {
            return $ruleId ? self::forRule(MerchantRule::query()->find($ruleId)) : null;
        }
        if ($decidedBy === 'ai') {
            return str_starts_with($reason, 'AI: ') ? 'ai.advisor' : 'ai.assistant';
        }
        if ($decidedBy !== 'matcher') {
            return null;
        }
        $r = str_starts_with($reason, 'Check: ') ? substr($reason, strlen('Check: ')) : $reason;
        $starts = fn (string ...$p) => array_filter($p, fn ($x) => str_starts_with($r, $x)) !== [];

        return match (true) {
            $starts('Matches invoice ') => 'suggest.invoice',
            str_ends_with($reason, 'moving between your accounts') => 'transfer.pair',
            $starts(...TransferPairer::ONE_SIDED_REASONS) => 'transfer.one_sided',
            $starts('Currency exchange: ') => 'revolut.exchange',
            $starts('Looks like a payment to HMRC') => 'suggest.hmrc',
            $starts('Looks like a fine') => 'suggest.penalty',
            $starts('Looks like car hire') => 'suggest.car_hire',
            preg_match('/^(TfL|Taxi or ride|Train ticket|Parking or road charge) on /', $r) === 1 => 'suggest.travel',
            $starts('A business cost on ') => 'jobs.gather',
            $starts('Like the other ', 'Unlike the other ') => 'categorise.similar',
            $starts('You usually decide the bank’s', 'Not how you usually decide the bank’s') => 'categorise.bank_learned',
            $starts('The bank calls it') => 'categorise.bank_hint',
            $starts('Like ', 'Unlike ') => 'categorise.merchant',
            $starts('Looks like ') && ($marker === Categoriser::MARKER || $marker === null) => 'categorise.hint',
            preg_match('/^.+ \(('.implode('|', array_map(fn ($k) => preg_quote($k, '/'), TravelItem::KINDS)).'), [^)]*\): /u', $reason) === 1 => 'travel.match',
            $marker === Categoriser::MARKER => 'categorise.hint',
            default => null,
        };
    }
}
