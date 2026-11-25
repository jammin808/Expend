<?php

namespace App\Domain\Intel;

/**
 * How far a finding can be relied on, graded from the evidence it rests on (docs/intelligence-cycle.md section 2.3).
 * Expend never invents a probability: a grade names the weakest evidence the claim cites, and its basis is shown
 * beside it.
 *
 *   certain   only confirmed records and arithmetic: bank lines, your decisions, issued invoices, filed figures
 *   strong    also an owner-confirmed link, or an AI-read fact whose check passed against its source
 *   likely    an unconfirmed automatic link, or an estimate (postcode distance × JobTrips::ROAD_FACTOR)
 *   possible  an unchecked AI value, or a match whose runner-up is close
 *   weak      failed, contradicted or missing evidence (only in the checks and health groups)
 *   unmeasured  a machine method without enough measured outcomes yet (section 2.3 b)
 *
 * No laundering: a claim never grades higher than any unconfirmed machine proposal it cites, which weakest() gives.
 */
final class Grades
{
    public const CERTAIN = 'certain';

    public const STRONG = 'strong';

    public const LIKELY = 'likely';

    public const POSSIBLE = 'possible';

    public const WEAK = 'weak';

    public const UNMEASURED = 'unmeasured';

    /** Strongest first. */
    public const ALL = [self::CERTAIN, self::STRONG, self::LIKELY, self::POSSIBLE, self::WEAK, self::UNMEASURED];

    /** The fixed, documented ranking weights (section 2.3). */
    public const WEIGHTS = [self::CERTAIN => 1.0, self::STRONG => 0.9, self::LIKELY => 0.7, self::POSSIBLE => 0.4, self::WEAK => 0.1, self::UNMEASURED => 0.5];

    /** The order evidence is weakest in: weak, then possible, likely, unmeasured, strong, certain. */
    private const STRENGTH = [self::WEAK => 0, self::POSSIBLE => 1, self::LIKELY => 2, self::UNMEASURED => 3, self::STRONG => 4, self::CERTAIN => 5];

    /**
     * The grade of each kind of finding from the evidence it rests on, with its basis in plain words. A code not
     * listed (health, Gmail) has no grade: it's about Expend itself, not a claim about your money.
     */
    public const FOR_CODE = [
        'tax.hmrc_unallocated' => [self::CERTAIN, 'Your bank line and how it’s recorded'],
        'travel.unclaimed' => [self::LIKELY, 'Miles estimated from postcodes'],
        'year.filed_drift' => [self::CERTAIN, 'Your records against the figures you filed'],
        'import.held' => [self::CERTAIN, 'The statement’s own running balance'],
        'invoice.overdue' => [self::CERTAIN, 'Your issued invoice and the payments recorded'],
        'invoice.underbilled' => [self::LIKELY, 'The PO total read from its document'],
        'travel.recharge' => [self::CERTAIN, 'Your decision to recharge it'],
        'check.failed' => [self::WEAK, 'A double-check that didn’t match its source'],
        'tax.deadline' => [self::CERTAIN, 'The filing dates in law and your notice to file'],
        'tax.penalty' => [self::LIKELY, 'An estimate from your records'],
        'tax.submission' => [self::CERTAIN, 'The dates in law and the dates you recorded'],
        'job.uninvoiced' => [self::CERTAIN, 'Your finished job and your invoices'],
        'job.done_due' => [self::CERTAIN, 'Your job’s days and its invoices'],
        'invoice.draft' => [self::CERTAIN, 'Your draft invoice'],
        'invoice.overpaid' => [self::CERTAIN, 'Your issued invoice and the payments you linked to it'],
        'invoice.void_paid' => [self::CERTAIN, 'Your void invoice and the payments linked to it'],
        'cost.claimed' => [self::CERTAIN, 'Your decision to charge it another way'],
        'cost.late' => [self::CERTAIN, 'Your costs on the job and its issued invoice'],
        'invoice.unsent' => [self::CERTAIN, 'Your issued invoice'],
        'job.candidate' => [self::POSSIBLE, 'Found by the job finder, not yet confirmed'],
        'job.offer_reply' => [self::LIKELY, 'Read from the email that offered it'],
        'job.booking' => [self::LIKELY, 'Read from the email that booked it'],
        'tx.duplicate' => [self::LIKELY, 'Two bank lines alike: same day, merchant and amount'],
        'job.no_po' => [self::LIKELY, 'No PO found linked to the job'],
        'acct.gap' => [self::CERTAIN, 'The bank’s balances against your lines'],
        'acct.stale' => [self::CERTAIN, 'The date of the last statement imported'],
        'travel.payment_missing' => [self::LIKELY, 'A booking that says it was paid'],
        'doc.held' => [self::CERTAIN, 'A document waiting for your approval'],
        // M4's detectors. A close-out rests on the PO's total as read and checked (strong, or likely when the check
        // didn't pass), and each draft gives its own; acct.unchecked and check.unverifiable have none (about Expend's
        // checks, not your money).
        'job.closeout' => [self::STRONG, 'Your PO, invoices and recharge decisions'],
        'travel.double_claim' => [self::CERTAIN, 'Your mileage and the travel you decided'],
        'income.unlinked' => [self::CERTAIN, 'Your confirmed income'],
        'receipt.unmatched' => [self::LIKELY, 'A receipt read from a document, matched by amount and date'],
        'cost.no_receipt' => [self::LIKELY, 'Your confirmed cost, matched to receipts by amount and date'],
        'dupe.cross_source' => [self::LIKELY, 'A cash expense and a bank line alike: amount, merchant and date'],
        'rule.drift' => [self::CERTAIN, 'Your own changes to what the rule decided'],
        'tax.saving' => [self::LIKELY, 'An estimate from the tax savings review of your records'],
        'year.locked_drift' => [self::CERTAIN, 'The figures when you locked the year against your records now'],
        // Expenses from your phone (QuickEntryChecks): your own entries' states, and pairs matched by amount and date.
        'receipt.waiting' => [self::CERTAIN, 'Your receipt from the phone and your imported statements'],
        'receipt.which' => [self::LIKELY, 'Card payments of the receipt’s amount near its date'],
        'receipt.second_copy' => [self::LIKELY, 'The only card payment that fits already has a receipt'],
        'quick.photo_differs' => [self::POSSIBLE, 'Read from your photo with AI, not checked against what you typed'],
        'quick.line_differs' => [self::CERTAIN, 'Your receipt and your decision on its bank line'],
        'quick.held' => [self::CERTAIN, 'Your entry, kept and not added'],
        'quick.maybe_card' => [self::POSSIBLE, 'A cash or personal-card cost and a card payment of the same amount within two days'],
        'quick.maybe_same' => [self::POSSIBLE, 'Two records of the same amount within a day'],
        'quick.photo_waiting' => [self::CERTAIN, 'Your entry, sent without its photo'],
    ];

    public static function valid(?string $grade): bool
    {
        return in_array($grade, self::ALL, true);
    }

    /** The grade of a kind of finding (null: it has none), and its basis. @return array{0:?string, 1:?string} */
    public static function forCode(?string $code): array
    {
        return self::FOR_CODE[$code] ?? [null, null];
    }

    /**
     * The grade of a claim resting on all of these: its weakest (no laundering). Null when none is given.
     *
     * @param  iterable<?string>  $grades
     */
    public static function weakest(iterable $grades): ?string
    {
        $out = null;
        foreach ($grades as $g) {
            if ($g !== null && self::valid($g) && ($out === null || self::STRENGTH[$g] < self::STRENGTH[$out])) {
                $out = $g;
            }
        }

        return $out;
    }

    /** The ranking weight of a grade; a finding with no grade weighs as unmeasured. */
    public static function weight(?string $grade): float
    {
        return self::WEIGHTS[$grade] ?? self::WEIGHTS[self::UNMEASURED];
    }

    /** How a grade reads on the page: "Certain". */
    public static function label(?string $grade): ?string
    {
        return $grade === null ? null : ucfirst($grade);
    }
}
