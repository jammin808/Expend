<?php

namespace App\Domain\Ai;

use App\Domain\Intel\Provenance;
use App\Domain\Ledger\AllocationWriter;
use App\Domain\Verification\Verifier;
use App\Http\Presenters\Present;
use App\Models\Allocation;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Setting;
use App\Models\TaxYear;
use App\Models\Veto;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * AI suggestions for undecided transactions. Sends only the minimum (date, amount, bank description,
 * bank category, candidate jobs). Each answer is validated against real categories and the candidate jobs
 * for that transaction, and saved as an unconfirmed AI suggestion for you to accept or change. One you said no to
 * for that payment (Intel\Vetoes) is never saved (AllocationWriter refuses it), and payments you said no to a
 * suggestion for are sent after the others.
 *
 * Money in is sent with no jobs (income design v3): which job or invoice a payment is for is the invoice matcher's and
 * yours, never put on the job worked that day by the AI. For money in it proposes the kind and category only.
 */
final class TransactionAdvisor
{
    public function __construct(private AiClient $ai, private AllocationWriter $writer) {}

    /** @return array{suggested:int, skipped:int} */
    public function suggest(?string $taxYear = null, int $limit = 40): array
    {
        if (! Setting::get('ai_suggestions')) {
            throw new RuntimeException('AI suggestions are switched off. Turn them on in Settings › Integrations.');
        }
        $locked = TaxYear::query()->whereNotNull('locked_at')->pluck('label');
        $waiting = fn () => BankTransaction::query()->with('account')->where('review_status', 'unreviewed')->whereDoesntHave('allocations')
            ->whereNotIn('tax_year', $locked)->when($taxYear, fn ($q, $y) => $q->where('tax_year', $y))->orderBy('posted_on')->orderBy('id');
        // Payments you said no to a suggestion for go last (Intel\Vetoes): each press reaches the newer ones first, rather
        // than paying the AI again for answers that may be refused.
        $saidNo = Veto::query()->active()->where('relation', 'allocation_shape')->where('subject_ref', 'like', 'tx:%')
            ->where(fn ($q) => $q->whereNull('method')->orWhere('method', 'ai.advisor'))->pluck('subject_ref')
            ->map(fn ($r) => (int) substr((string) $r, 3))->unique()->values()->all();
        $txs = $waiting()->whereNotIn('id', $saidNo ?: [0])->limit($limit)->get();
        if ($saidNo !== [] && $txs->count() < $limit) {
            $txs = $txs->concat($waiting()->whereIn('id', $saidNo)->limit($limit - $txs->count())->get())->values();
        }
        if ($txs->isEmpty()) {
            return ['suggested' => 0, 'skipped' => 0];
        }

        $categories = Category::query()->orderBy('sort')->get(['code', 'name', 'kind', 'is_allowable']);
        $items = $txs->map(fn (BankTransaction $t) => [
            'id' => $t->id, 'date' => $t->effectiveDate()->format('Y-m-d'), 'amount_pence' => $t->amount_pence,
            'description' => $t->description, 'bank_category' => $t->bank_category, 'account_type' => $t->account->kind,
            // No jobs for money in: the AI never puts income on a job (its invoice and job are matched, or yours).
            'jobs_around_date' => $t->amount_pence > 0 ? [] : Present::jobsNear($t->effectiveDate()->format('Y-m-d')),
        ])->values();

        $result = $this->ai->send([
            'maxTokens' => 16000,
            'system' => 'You suggest how a UK self-employed events and production freelancer should treat each bank or card transaction for their Self Assessment. '
                .'Base each suggestion only on the details given. When you are not reasonably sure, choose "unsure". Never invent jobs: only use a job id listed for that transaction. '
                .'Personal spending (groceries, personal shopping) is "personal". Payments between their own accounts are "transfer". Payments to HMRC are "tax_payment". '
                .'Money in from clients is business_income with category turnover.',
            'messages' => [['role' => 'user', 'content' => "Categories (code, name, kind):\n".json_encode($categories)."\n\nTransactions:\n".json_encode($items)]],
            'outputConfig' => ['effort' => 'low', 'format' => ['type' => 'json_schema', 'schema' => [
                'type' => 'object', 'additionalProperties' => false, 'required' => ['suggestions'],
                'properties' => ['suggestions' => ['type' => 'array', 'items' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'required' => ['id', 'kind', 'category_code', 'job_id', 'business_pct', 'reason'],
                    'properties' => [
                        'id' => ['type' => 'integer'],
                        'kind' => ['type' => 'string', 'enum' => [...array_keys(Allocation::KINDS), 'unsure']],
                        'category_code' => ['type' => ['string', 'null']],
                        'job_id' => ['type' => ['integer', 'null']],
                        'business_pct' => ['type' => 'integer'],
                        'reason' => ['type' => 'string'],
                    ],
                ]]],
            ]]],
        ]);

        return $this->apply(json_decode($result->text(), true)['suggestions'] ?? [], $txs, $items);
    }

    /** @return array{suggested:int, skipped:int} */
    public function apply(array $suggestions, Collection $txs, Collection $items): array
    {
        $byId = $txs->keyBy('id');
        $jobsFor = $items->mapWithKeys(fn ($i) => [$i['id'] => array_column($i['jobs_around_date'], 'id')]);
        $codes = Category::query()->get(['id', 'code', 'kind'])->keyBy('code');
        $made = $skipped = 0;

        foreach ($suggestions as $s) {
            $tx = $byId[$s['id'] ?? 0] ?? null;
            if (! $tx || ($s['kind'] ?? 'unsure') === 'unsure' || ! isset(Allocation::KINDS[$s['kind']])) {
                $skipped++;

                continue;
            }
            $category = $s['category_code'] ? $codes[$s['category_code']] ?? null : null;
            $needs = in_array($s['kind'], Allocation::NEEDS_CATEGORY, true);
            $expected = $s['kind'] === 'business_income' ? 'income' : 'expense';
            if ($needs && (! $category || $category->kind !== $expected)) {
                $skipped++;

                continue;
            }
            $jobId = $tx->amount_pence <= 0 && in_array($s['job_id'] ?? null, $jobsFor[$tx->id] ?? [], true) ? $s['job_id'] : null;
            $pct = max(1, min(100, (int) ($s['business_pct'] ?? 100)));

            try {
                // Refused (false) when it's what you said no to for this payment, or a suggestion that outranks the AI's
                // is there by now: skipped, like an answer that doesn't fit.
                $done = $this->writer->decide($tx, AllocationWriter::lines($tx->amount_pence, $s['kind'], $needs ? $category->id : null, $needs ? $jobId : null, $pct),
                    'ai', false, mb_substr('AI: '.($s['reason'] ?? ''), 0, 240), null, new Provenance('ai.advisor', [$needs && $jobId ? 'job:'.$jobId : null]));
                $done ? $made++ : $skipped++;
            } catch (\InvalidArgumentException) {
                $skipped++;
            }
        }

        // Every AI suggestion is checked again against the payment and the job's dates.
        $verifier = app(Verifier::class);
        foreach ($byId as $tx) {
            $verifier->transaction($tx);
        }

        return ['suggested' => $made, 'skipped' => $skipped];
    }
}
