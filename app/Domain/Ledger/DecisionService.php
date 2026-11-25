<?php

namespace App\Domain\Ledger;

use App\Domain\Audit\AuditLog;
use App\Domain\Income\IncomeClient;
use App\Domain\Income\IncomeText;
use App\Domain\Income\Lines;
use App\Domain\Intel\AuditContext;
use App\Domain\Intel\Vetoes;
use App\Domain\Invoices\InvoicePayments;
use App\Domain\Matching\Categoriser;
use App\Models\Allocation;
use App\Models\AuditEvent;
use App\Models\BankTransaction;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\ManualExpense;
use App\Models\MerchantRule;
use App\Models\TaxYear;
use App\Models\WorkJob;
use App\Support\Money;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Turns what you chose on screen into allocations for one or many items. Deciding differently from an automatic
 * suggestion remembers it (Intel\Vetoes::fromSuggestion()), so nothing automatic proposes it for that item again.
 *
 * Also your changes that keep the rest of a decision as it is: who money in was from (setClient), which client a cost
 * with no job was for (setExpenseClient), and a cost shared with another job (shareCost). Those are yours only, in open
 * years only: automation never sets a client on an expense or splits a cost.
 */
final class DecisionService
{
    /** What was left as it was, and why: an invoice match, a split, a locked year, a suggestion waiting, or another kind. */
    public const NONE_LEFT = ['invoice' => 0, 'split' => 0, 'locked' => 0, 'suggestion' => 0, 'other' => 0];

    public function __construct(private AllocationWriter $writer) {}

    /**
     * Your decision for one or many payments.
     *
     * Links (income design v3): each line takes invoice_id and client_id only when it names them (a line without the
     * key says nothing about them); a single decision's top-level invoice_id and client_id go on the business line it
     * builds. A named value is your choice, and null takes the link off. When a decision doesn't name them, and it's
     * not one for many payments at once, what the payment had is kept when your decision says the same (keepInvoice,
     * keepClient).
     *
     * For many at once ($bulk): nothing is carried over (no keepInvoice, no keepClient), and a payment whose suggestion
     * names an invoice is left for you to check on its own (left.invoice), so no invoice suggestion is ever accepted or
     * said no to in bulk. $keepSplits leaves payments split into several lines as they are (left.split).
     *
     * @param  Collection<int, BankTransaction|ManualExpense>  $sources
     * @return array{saved:int, skipped:int, errors:list<string>, learned:int, left:array{invoice:int, split:int, locked:int, suggestion:int, other:int}}
     */
    public function apply(array $decision, Collection $sources, bool $includeProtected = true, bool $bulk = false, bool $keepSplits = false): array
    {
        $saved = 0;
        $skipped = 0;
        $errors = [];
        $left = self::NONE_LEFT;

        foreach ($sources as $source) {
            if (! $includeProtected && $source->allocations()->where(fn ($q) => $q->where('is_manual', true)->orWhereNotNull('confirmed_at'))->exists()) {
                $skipped++;

                continue;
            }

            try {
                // What automation suggested before your decision: if you decided differently, it isn't proposed again.
                // Only what the page showed you (shown, when it says): a suggestion that replaced it since isn't what you
                // decided differently from.
                $was = $source->allocations()->get();
                if ($bulk && IncomeText::isInvoiceSuggestion($was)) {
                    $left['invoice']++; // an invoice match: checked one by one, never in bulk

                    continue;
                }
                if ($keepSplits && $was->count() > 1) {
                    $left['split']++;

                    continue;
                }
                [$lines, $namesInvoice, $namesClient] = self::linesFor($decision, (int) $source->amount_pence);
                if (! $bulk && ! $namesInvoice) {
                    $lines = self::keepInvoice($lines, $was, (int) $source->amount_pence);
                }
                if (! $bulk && ! $namesClient) {
                    $lines = self::keepClient($lines, $was);
                }
                $this->writer->decide($source, $lines, 'you', true, $decision['reason'] ?? null);
                $saved++;
                if (! array_key_exists('shown', $decision) || Vetoes::isShown($was, $decision['shown'])) {
                    Vetoes::fromSuggestion($source, $was, $lines, 'changed');
                }
            } catch (InvalidArgumentException $e) {
                $errors[] = $e->getMessage();
            }
        }

        if (($decision['make_rule'] ?? false) && ! isset($decision['lines']) && ($first = $sources->first())) {
            $rule = MerchantRule::query()->firstOrNew(['merchant_key' => $first->merchant_key]);
            $before = $rule->exists ? $rule->toArray() : null;
            $rule->fill([
                'kind' => $decision['kind'],
                'category_id' => $decision['category_id'] ?? null,
                'work_job_id' => $decision['work_job_id'] ?? null,
                'business_pct' => (int) ($decision['business_pct'] ?? 100),
            ]);
            if ($rule->isDirty() && $rule->exists && self::learned($rule)) {
                // A different decision over a rule Expend learned: it's your rule now. It applies both ways and
                // suggests ("Suggest this next time") until you switch on confirming in Settings › Rules; the learned
                // rule's direction and confirming on its own aren't carried over. (A rule you made yourself keeps
                // whatever you set there.)
                $rule->forceFill(['direction' => null, 'auto_confirm' => false]);
            } elseif ($rule->isDirty()) {
                $rule->forceFill(['direction' => null]);
            } elseif (! RuleLearner::appliesTo($rule, $first->amount_pence)) {
                $rule->forceFill(['direction' => null]); // the same decision the other way round: now both ways
            }
            $rule->save();
            AuditLog::record('rule.saved', $rule, $before, $rule->toArray());
        }

        // Learn from it: other payments waiting from the same merchants get the same suggestion.
        $learned = $saved ? app(Categoriser::class)->learnFrom($sources) : 0;

        return ['saved' => $saved, 'skipped' => $skipped, 'errors' => array_values(array_unique($errors)), 'learned' => $learned, 'left' => $left];
    }

    /**
     * The lines a decision gives for a payment of this amount, and whether it names an invoice and a client.
     *
     * @return array{0: list<array<string, mixed>>, 1: bool, 2: bool}
     */
    private static function linesFor(array $decision, int $amount): array
    {
        if (isset($decision['lines'])) {
            $lines = array_map(fn ($l) => [
                'kind' => $l['kind'],
                'category_id' => $l['category_id'] ?? null,
                'work_job_id' => $l['work_job_id'] ?? null,
                'amount_pence' => (int) $l['amount_pence'],
                'business_use_pct' => $l['business_use_pct'] ?? null,
                'note' => $l['note'] ?? null,
            ] + self::links($l), array_values($decision['lines']));
            $names = fn (string $key) => collect($decision['lines'])->contains(fn ($l) => is_array($l) && array_key_exists($key, $l));

            return [$lines, $names('invoice_id'), $names('client_id')];
        }

        $lines = AllocationWriter::lines(
            $amount,
            $decision['kind'],
            $decision['category_id'] ?? null,
            $decision['work_job_id'] ?? null,
            (int) ($decision['business_pct'] ?? 100),
            $decision['note'] ?? null,
        );
        $links = self::links($decision);
        if ($links !== []) {
            // On the business line the decision builds (its first; a personal part never carries a link).
            $at = collect($lines)->search(fn ($l) => $l['kind'] === $decision['kind']);
            if ($at !== false) {
                $lines[$at] = $links + $lines[$at];
            }
        }

        return [$lines, array_key_exists('invoice_id', $decision), array_key_exists('client_id', $decision)];
    }

    /** The invoice and client a line or decision names (only the keys it has; null takes the link off). */
    private static function links(array $from): array
    {
        $out = [];
        foreach (['invoice_id', 'client_id'] as $key) {
            if (array_key_exists($key, $from)) {
                $out[$key] = $from[$key] === null || $from[$key] === '' ? null : (int) $from[$key];
            }
        }

        return $out;
    }

    /**
     * Your decision keeps the client the payment had on its own (client-only income) when it says the same: one income
     * line with no job and no invoice, as before.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  Collection<int, Allocation>  $was
     * @return list<array<string, mixed>>
     */
    private static function keepClient(array $lines, Collection $was): array
    {
        $had = $was->first();
        if (count($lines) !== 1 || $was->count() !== 1 || $had->client_id === null || $had->kind !== 'business_income'
            || $had->work_job_id !== null || $had->invoice_id !== null || $lines[0]['kind'] !== 'business_income'
            || ($lines[0]['work_job_id'] ?? null) !== null || ($lines[0]['invoice_id'] ?? null) !== null || ($lines[0]['client_id'] ?? null) !== null) {
            return $lines;
        }
        $lines[0]['client_id'] = (int) $had->client_id;

        return $lines;
    }

    /**
     * "These are Acme Events'": money in from a payer, set as one client's (Transactions' "Set client", the year page's
     * "Decide all"). Each payment is decided as you:
     *  - undecided, or an open suggestion naming no invoice and no job (a guess): one turnover line for the client;
     *  - lines you made or confirmed, all income (with any fee taken off): rebuilt field by field (never flattened),
     *    with the client on the lines whose invoice or job doesn't say who they're for; one with nothing to change
     *    counts as done already.
     * Left as they are, and counted: a locked year (locked); money out, a transfer, or lines you decided as something
     * else (other); an open suggestion naming an invoice or a job, for you to accept or change first (suggestion).
     * Other payments waiting from the same payers then learn from it, once.
     *
     * @param  Collection<int, BankTransaction|ManualExpense>  $sources
     * @return array{saved:int, already:int, errors:list<string>, learned:int, left:array{invoice:int, split:int, locked:int, suggestion:int, other:int}}
     */
    public function setClient(Collection $sources, int $clientId): array
    {
        self::yours('set who a payment is from');
        $client = self::client($clientId);
        $saved = collect();
        $already = 0;
        $errors = [];
        $left = self::NONE_LEFT;

        foreach ($sources as $source) {
            if ($this->locked($source)) {
                $left['locked']++;

                continue;
            }
            if ($source->amount_pence <= 0 || ($source instanceof BankTransaction && $source->transfer_pair_id)) {
                $left['other']++;

                continue;
            }
            $was = $source->allocations()->get();
            $open = Vetoes::isOpenSuggestion($was);
            if ($was->isEmpty() || ($open && $was->every(fn (Allocation $a) => $a->invoice_id === null && $a->work_job_id === null))) {
                $lines = [Lines::income((int) $source->amount_pence, null, null, $client)];
            } elseif ($open) {
                $left['suggestion']++;

                continue;
            } elseif ($was->every(fn (Allocation $a) => $a->kind === 'business_income' || Lines::isDeduction($a))) {
                $lines = self::withClient($was, $client);
                if ($lines === Lines::from($was)) {
                    $already++;

                    continue;
                }
            } else {
                $left['other']++;

                continue;
            }

            try {
                $this->writer->decide($source, $lines, 'you', true);
                Vetoes::fromSuggestion($source, $was, $lines, 'changed');
                $saved->push($source);
            } catch (InvalidArgumentException $e) {
                $errors[] = $e->getMessage();
            }
        }

        $learned = $saved->isNotEmpty() ? app(Categoriser::class)->learnFrom($saved) : 0;

        return ['saved' => $saved->count(), 'already' => $already, 'errors' => array_values(array_unique($errors)), 'learned' => $learned, 'left' => $left];
    }

    /**
     * Which client a cost with no job was for (a cost for a client with no job, or "Recharge to Acme" on a confirmed
     * payment), so it counts in that client's figures; null takes it off. Only on a payment whose lines you made or
     * confirmed, in an open year, and only on its business costs with no job; everything else on it stays as it is.
     * Decided as you. Automation never sets a client on an expense.
     *
     * @return bool whether anything changed
     *
     * @throws InvalidArgumentException a locked year, a payment not decided yet, or a client that's gone
     */
    public function setExpenseClient(BankTransaction|ManualExpense $source, ?int $clientId): bool
    {
        self::yours('set which client a cost was for');
        $client = $clientId === null ? null : self::client($clientId);
        $this->assertOpen($source);
        $was = $source->allocations()->get();
        if ($was->isEmpty() || $was->contains(fn (Allocation $a) => ! $a->is_manual && $a->confirmed_at === null)) {
            throw new InvalidArgumentException('Confirm the payment first, then say which client it was for.');
        }
        $lines = Lines::from($was);
        foreach ($lines as $i => $line) {
            if ($line['kind'] === 'business_expense' && $line['work_job_id'] === null) {
                $lines[$i]['client_id'] = $client;
            }
        }
        if ($lines === Lines::from($was)) {
            return false;
        }

        return $this->writer->decide($source, $lines, 'you', true);
    }

    /**
     * Share a cost with another job: the payment's cost line on one job is split, half each by default (the odd penny
     * stays with the first job), or $pence (a positive amount) goes to the other job. Every other line stays as it is.
     * Decided as you, in an open year; when $fp is given (the payment's SourceState fingerprint as it was shown), a
     * payment that changed since is refused.
     *
     * @return list<array<string, mixed>> the payment's lines now
     *
     * @throws InvalidArgumentException
     */
    public function shareCost(BankTransaction|ManualExpense $source, int $fromJob, int $toJob, ?int $pence = null, ?string $fp = null): array
    {
        self::yours('share a cost with another job');
        if ($fromJob === $toJob) {
            throw new InvalidArgumentException('Pick another job to share it with.');
        }
        if (! WorkJob::query()->whereKey($toJob)->exists()) {
            throw new InvalidArgumentException('That job no longer exists: pick another.');
        }
        $this->assertOpen($source);
        if ($fp !== null && ! SourceState::matches($source, $fp)) {
            throw new InvalidArgumentException('This payment changed since you looked: check it again.');
        }
        $was = $source->allocations()->get();
        $lines = Lines::from($was);
        $on = array_keys(array_filter($lines, fn (array $l) => $l['kind'] === 'business_expense' && $l['work_job_id'] === $fromJob));
        if (count($on) !== 1) {
            throw new InvalidArgumentException(count($on) === 0
                ? 'This payment has no cost on that job to share.'
                : 'This payment has more than one cost on that job: split it on the payment instead.');
        }
        $at = $on[0];
        $amount = (int) $lines[$at]['amount_pence'];
        $whole = abs($amount);
        $move = $pence ?? intdiv($whole, 2);
        if ($move <= 0 || $move >= $whole) {
            throw new InvalidArgumentException('Share less than the whole '.Money::format($whole).', and more than nothing.');
        }
        $signed = $amount < 0 ? -$move : $move;
        $lines[$at]['amount_pence'] = $amount - $signed;

        $share = ['work_job_id' => $toJob, 'amount_pence' => $signed, 'invoice_id' => null] + $lines[$at];
        $same = array_keys(array_filter($lines, fn (array $l) => $l['work_job_id'] === $toJob && $l['kind'] === $share['kind'] && $l['category_id'] === $share['category_id']
            && $l['business_use_pct'] === $share['business_use_pct'] && $l['note'] === $share['note'] && $l['invoice_id'] === null && $l['client_id'] === $share['client_id']));
        if ($same !== []) {
            $lines[$same[0]]['amount_pence'] += $signed; // already a line like it on that job: added to it
        } else {
            array_splice($lines, $at + 1, 0, [$share]);
        }

        $this->writer->decide($source, $lines, 'you', true);
        Vetoes::fromSuggestion($source, $was, $lines, 'changed');

        return $lines;
    }

    /**
     * The lines of a payment you decided, with the client on each income line (and each fee taken off with no job)
     * whose invoice and own job don't say who it's for.
     *
     * @param  Collection<int, Allocation>  $was
     * @return list<array<string, mixed>>
     */
    private static function withClient(Collection $was, int $client): array
    {
        $lines = Lines::from($was);
        $invoiceClients = Invoice::query()->whereKey(array_filter(array_column($lines, 'invoice_id')))->pluck('client_id', 'id');
        $jobClients = WorkJob::query()->whereKey(array_filter(array_column($lines, 'work_job_id')))->pluck('client_id', 'id');
        foreach ($lines as $i => $line) {
            $given = ($line['invoice_id'] !== null ? $invoiceClients->get($line['invoice_id']) : null)
                ?? ($line['work_job_id'] !== null ? $jobClients->get($line['work_job_id']) : null);
            $deduction = Lines::isDeduction($line);
            if ($given === null && ($line['kind'] === 'business_income' || ($deduction && $line['work_job_id'] === null))) {
                $lines[$i]['client_id'] = $client;
            }
        }

        return $lines;
    }

    /** The client as it counts now (a merged duplicate is the one it went into); refused when it's gone. */
    private static function client(int $clientId): int
    {
        if (! Client::query()->whereKey($clientId)->exists()) {
            throw new InvalidArgumentException('That client no longer exists: pick another.');
        }

        return (int) IncomeClient::resolve($clientId);
    }

    /** These changes are yours: never automation's (a policy, a matcher or the AI). */
    private static function yours(string $what): void
    {
        if (AuditContext::actor() !== 'you') {
            throw new InvalidArgumentException("Only you can {$what}.");
        }
    }

    private function locked(BankTransaction|ManualExpense $source): bool
    {
        return (bool) TaxYear::query()->where('label', $this->writer->taxYearOf($source))->first()?->isLocked();
    }

    private function assertOpen(BankTransaction|ManualExpense $source): void
    {
        if ($this->locked($source)) {
            $label = $this->writer->taxYearOf($source);

            throw new InvalidArgumentException("Tax year {$label} is locked. Unlock it (with a reason) before changing anything in it.");
        }
    }

    /**
     * Your decision keeps the invoice the payment was matched to when it says the same: all of it income, on the same
     * job (or none, as the match had). Saving it with a note, or another income category, keeps it the invoice's
     * payment; deciding it as something else, splitting it, or putting it on another job doesn't (and the invoice is then
     * remembered as not this payment's: Vetoes::fromSuggestion()). A suggestion's invoice that can't be paid any more
     * (voided, or back to a draft, since it was suggested) isn't carried over either: Accept refuses it, so Save never
     * confirms it without saying. A line you made or confirmed keeps its invoice whatever it is now (re-saving it).
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  Collection<int, Allocation>  $was
     * @return list<array<string, mixed>>
     */
    private static function keepInvoice(array $lines, Collection $was, int $amount): array
    {
        $matched = $was->first();
        if (count($lines) !== 1 || $was->count() !== 1 || $matched->invoice_id === null || $matched->kind !== 'business_income'
            || $lines[0]['kind'] !== 'business_income' || (int) $lines[0]['amount_pence'] !== $amount
            || (int) ($lines[0]['work_job_id'] ?? 0) !== (int) ($matched->work_job_id ?? 0)) {
            return $lines;
        }
        $yours = $matched->is_manual || $matched->confirmed_at !== null;
        if (! $yours && ! in_array(Invoice::query()->whereKey($matched->invoice_id)->value('status'), InvoicePayments::PAYABLE, true)) {
            return $lines;
        }
        $lines[0]['invoice_id'] = (int) $matched->invoice_id;

        return $lines;
    }

    /** Whether Expend learned this rule from your decisions ("Always do this"), rather than you saving it yourself. */
    private static function learned(MerchantRule $rule): bool
    {
        return $rule->getOriginal('direction') !== null
            || AuditEvent::query()->where('action', 'rule.learned')->where('subject_type', 'MerchantRule')->where('subject_id', $rule->id)->exists();
    }
}
