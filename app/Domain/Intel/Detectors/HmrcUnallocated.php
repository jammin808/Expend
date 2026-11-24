<?php

namespace App\Domain\Intel\Detectors;

use App\Domain\Intel\Contracts\Detector;
use App\Domain\Intel\Coverage;
use App\Domain\Intel\Cycle\CycleContext;
use App\Domain\Intel\FindingDraft;
use App\Domain\Intel\Grades;
use App\Domain\Intel\Ref;
use App\Domain\Matching\Suggester;
use App\Domain\Todo\TodoList;
use App\Models\Allocation;
use App\Models\BankTransaction;
use App\Models\TaxYear;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;

/**
 * Payments to HMRC not recorded as tax (tax.hmrc_unallocated, M1): money-out lines whose words are a payment to HMRC
 * (Suggester::HMRC_PATTERN, with the database picking out the lines worth testing by Suggester::HMRC_LIKE) that
 * were never decided, or were decided or suggested as something else. Fines and interest count only the payments
 * recorded as tax, so until it is they may be overstated. One tap records it as tax, as your decision; in a locked
 * year there's only the link. Only reads.
 */
final class HmrcUnallocated implements Detector
{
    public const CODE = 'tax.hmrc_unallocated';

    public const VERSION = 1;

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

    /** The payments to HMRC the last detect() looked at (null: it didn't finish). */
    public function checked(): ?int
    {
        return $this->checked;
    }

    /** @return list<FindingDraft> */
    public function detect(CycleContext $ctx): array
    {
        $this->coverage = Coverage::Failed;
        $this->checked = null;
        $lines = BankTransaction::query()->where('amount_pence', '<', 0)->whereNull('transfer_pair_id')
            ->where(function (Builder $q) {
                foreach (Suggester::HMRC_LIKE as $like) {
                    $q->orWhere('description', 'like', $like)->orWhere('counterparty', 'like', $like);
                }
            })
            ->with('allocations.category')->orderByDesc('posted_on')->orderByDesc('id')->get()
            ->filter(fn (BankTransaction $t) => preg_match(Suggester::HMRC_PATTERN, $t->description.' '.$t->counterparty) === 1)->values();
        $locked = TaxYear::query()->whereNotNull('locked_at')->pluck('label')->flip();
        $pounds = fn (int $pence) => Money::format(abs($pence), 'GBP', false);
        $drafts = [];
        $draft = fn (BankTransaction $t, string $title, string $detail, string $href, int $amount, array $actions) => new FindingDraft(
            key: 'hmrc:'.$t->id, code: self::CODE, group: 'tax', priority: 2, title: $title, detail: $detail, href: $href,
            subjectRef: Ref::make('tx', $t->id), scopeRef: Ref::maybe('year', $t->tax_year), amountPence: $amount, direction: 'none',
            grade: Grades::forCode(self::CODE)[0], actions: $actions, detectorVersion: self::VERSION,
        );
        foreach ($lines as $t) {
            $tax = $t->allocations->where('kind', 'tax_payment');
            $other = $t->allocations->reject(fn (Allocation $a) => $a->kind === 'tax_payment');
            if ($t->allocations->isNotEmpty() && $other->isEmpty()) {
                continue; // recorded as tax (or suggested as tax, waiting with the payments to decide)
            }
            $how = fn (Allocation $a) => mb_strtolower(Allocation::KINDS[$a->kind] ?? $a->kind).($a->category ? ' ('.$a->category->name.')' : '');
            $isLocked = isset($locked[$t->tax_year]);
            $name = $t->counterparty ?: $t->description;
            $href = '/transactions?year='.$t->tax_year.'&q='.urlencode($name);
            $on = $t->effectiveDate()->format('j M Y');
            if ($tax->isNotEmpty()) {
                // Split, with part of it already tax (someone else's bill paid with yours, say): only the rest is in
                // question, and one tap would undo the split, so there's only the link.
                $part = (int) $other->sum('amount_pence');
                $drafts[] = $draft($t, 'Part of a payment to HMRC not recorded as tax: '.$pounds($part).' of '.$pounds($t->amount_pence).' on '.$on,
                    $name.' · Split: '.$pounds((int) $tax->sum('amount_pence')).' as tax, '
                    .$other->groupBy($how)->map(fn ($as, $kind) => $pounds((int) $as->sum('amount_pence')).' '.($as->every(fn (Allocation $a) => $a->confirmed_at === null) ? 'suggested as ' : 'as ').$kind)->implode(', ').'. '
                    .'Fines and interest count only the part recorded as tax. If the rest was your own tax too, change the split in Transactions'
                    .($isLocked ? ' (unlock '.$t->tax_year.' on its year page first)' : '').'; if it wasn’t, mark this not relevant.',
                    $href, $part, []);

                continue;
            }
            $now = $other->isEmpty() ? 'Not decided yet'
                : ($other->every(fn (Allocation $a) => $a->confirmed_at === null) ? 'Suggested as ' : 'Recorded as ').$other->map($how)->unique()->implode(' and ');
            $drafts[] = $draft($t, 'Payment to HMRC not recorded as tax: '.$pounds($t->amount_pence).' on '.$on,
                $name.' · '.$now.'. Fines and interest count only payments recorded as tax, so they may be overstated until it is.'
                    .($isLocked ? ' '.$t->tax_year.' is locked: unlock it on its year page to change it.' : ' If it wasn’t your own tax, mark this not relevant.'),
                $href, $t->amount_pence, $isLocked ? [] : [TodoList::action('tx_tax', $t->id, 'Record as tax')]);
        }
        $this->checked = $lines->count();
        $this->coverage = Coverage::Complete;

        return $drafts;
    }
}
