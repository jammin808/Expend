<?php

namespace App\Domain\Intel\Detectors;

use App\Domain\Intel\Contracts\Detector;
use App\Domain\Intel\Coverage;
use App\Domain\Intel\Cycle\CycleContext;
use App\Domain\Intel\FindingDraft;
use App\Domain\Intel\Ref;
use App\Domain\Todo\TodoList;
use App\Models\BankTransaction;
use App\Models\Document;
use App\Models\TravelItem;
use App\Models\Verification;
use App\Support\Money;
use App\Support\UkTaxYear;

/**
 * Money that can't be checked word for word (check.unverifiable, M4): an AI reading whose double-check came back
 * "can't be checked word for word" (a scan or photo, a second read that couldn't be done), about something that carries
 * money: a receipt, PO or invoice read from a document, a booking with an amount, or a suggestion on a bank line. Its
 * figures may be right, but nothing has shown it. A document not read yet is left out (there's nothing read to check).
 * No grade: it's about what could be checked, not a claim about your money. Only reads.
 */
final class CheckUnverifiable implements Detector
{
    public const CODE = 'check.unverifiable';

    public const VERSION = 1;

    /** Documents that carry money. */
    private const MONEY_KINDS = ['receipt', 'po', 'invoice'];

    private ?int $checked = null;

    private Coverage $coverage = Coverage::Failed;

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

    public function checked(): ?int
    {
        return $this->checked;
    }

    /** @return list<FindingDraft> */
    public function detect(CycleContext $ctx): array
    {
        $this->coverage = Coverage::Failed;
        $this->checked = null;
        $checks = Verification::query()->where('status', 'unverifiable')->whereIn('subject_type', ['document', 'travel_item', 'transaction'])->orderByDesc('checked_at')
            ->get(['subject_type', 'subject_id', 'summary', 'checked_at']);
        $ids = fn (string $type) => $checks->where('subject_type', $type)->pluck('subject_id')->unique()->values()->all() ?: [0];
        $docs = Document::query()->whereIn('id', $ids('document'))->whereNotNull('extraction')->get(['id', 'parent_id', 'kind', 'original_name', 'email_subject', 'extraction', 'sha256'])
            ->filter(fn (Document $d) => in_array($d->kind, self::MONEY_KINDS, true) || ! empty($d->extraction['receipt']) || ! empty($d->extraction['po']))->keyBy('id');
        $travel = TravelItem::query()->whereIn('id', $ids('travel_item'))->whereNotNull('amount_minor')->get(['id', 'kind', 'provider', 'starts_on', 'amount_minor', 'currency'])->keyBy('id');
        $txs = BankTransaction::query()->whereIn('id', $ids('transaction'))->get(['id', 'posted_on', 'transaction_on', 'amount_pence', 'description', 'counterparty', 'tax_year'])->keyBy('id');
        $drafts = [];
        foreach ($checks as $v) {
            $subject = match ($v->subject_type) {
                'document' => isset($docs[$v->subject_id]) ? [
                    'label' => (Document::KINDS[$docs[$v->subject_id]->kind] ?? 'Document').': '.($docs[$v->subject_id]->email_subject ?: $docs[$v->subject_id]->original_name),
                    'href' => '/documents/'.($docs[$v->subject_id]->parent_id ?? $v->subject_id), 'ref' => Ref::make('doc', $v->subject_id, null, $docs[$v->subject_id]->sha256),
                ] + self::documentMoney($docs[$v->subject_id]) : null,
                'travel_item' => isset($travel[$v->subject_id]) ? [
                    'label' => (TravelItem::KINDS[$travel[$v->subject_id]->kind] ?? 'Booking').': '.$travel[$v->subject_id]->provider.' '.$travel[$v->subject_id]->starts_on->format('j M Y'),
                    'href' => '/travel?year='.UkTaxYear::fromDate($travel[$v->subject_id]->starts_on)->label(), 'ref' => Ref::make('travel', $v->subject_id),
                    'amount' => -abs((int) $travel[$v->subject_id]->amount_minor), 'currency' => $travel[$v->subject_id]->currency ?: 'GBP',
                ] : null,
                'transaction' => isset($txs[$v->subject_id]) ? [
                    'label' => 'The suggestion for '.($txs[$v->subject_id]->counterparty ?: $txs[$v->subject_id]->description).' on '.$txs[$v->subject_id]->effectiveDate()->format('j M Y'),
                    'href' => '/transactions?year='.$txs[$v->subject_id]->tax_year.'&q='.urlencode($txs[$v->subject_id]->counterparty ?: $txs[$v->subject_id]->description),
                    'ref' => Ref::make('tx', $v->subject_id), 'amount' => (int) $txs[$v->subject_id]->amount_pence, 'currency' => null,
                ] : null,
                default => null,
            };
            if ($subject === null) {
                continue;
            }
            $drafts[] = new FindingDraft(
                key: 'unverifiable:'.$v->subject_type.':'.$v->subject_id, code: self::CODE, group: 'records', priority: 3,
                title: 'Can’t be checked word for word: '.$subject['label'],
                detail: trim((string) $v->summary).' Check its amounts against the original yourself'.($v->subject_type === 'document' ? ', or press Re-check for an independent second read' : '').'.'
                    .($subject['amount'] !== null ? ' It involves '.Money::format(abs($subject['amount']), $subject['currency'] ?? 'GBP', false).'.' : ''),
                href: $subject['href'], subjectRef: $subject['ref'], evidence: [Ref::make('check', $v->subject_type.':'.$v->subject_id)],
                amountPence: $subject['amount'], currency: $subject['currency'], direction: 'none',
                actions: [TodoList::action('recheck', $v->subject_type.':'.$v->subject_id, 'Re-check')], detectorVersion: self::VERSION,
            );
        }
        $this->checked = $checks->count();
        $this->coverage = Coverage::Complete;

        return $drafts;
    }

    /**
     * The money a document carries as read: a receipt's amount (money out) or a PO's total (money in), in hundredths
     * of its currency.
     *
     * @return array{amount:?int, currency:?string}
     */
    private static function documentMoney(Document $d): array
    {
        $x = $d->extraction ?? [];
        if (is_numeric($x['receipt']['amount_minor'] ?? null)) {
            return ['amount' => -abs((int) $x['receipt']['amount_minor']), 'currency' => strtoupper((string) ($x['receipt']['currency'] ?? 'GBP')) ?: 'GBP'];
        }
        if (is_numeric($x['po']['stated_total_minor'] ?? null)) {
            return ['amount' => (int) $x['po']['stated_total_minor'], 'currency' => strtoupper((string) ($x['po']['currency'] ?? 'GBP')) ?: 'GBP'];
        }

        return ['amount' => null, 'currency' => null];
    }
}
