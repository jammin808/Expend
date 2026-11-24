<?php

namespace App\Http\Controllers;

use App\Domain\Ledger\AllocationWriter;
use App\Domain\Ledger\DecisionService;
use App\Domain\Ledger\RuleLearner;
use App\Domain\Matching\Categoriser;
use App\Models\Allocation;
use App\Models\BankTransaction;
use App\Models\ManualExpense;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class DecisionController extends Controller
{
    public function __construct(private DecisionService $decisions, private AllocationWriter $writer) {}

    private function rules(): array
    {
        return [
            'kind' => ['required_without:lines', Rule::in(array_keys(Allocation::KINDS))],
            'category_id' => 'nullable|integer|exists:categories,id',
            'work_job_id' => 'nullable|integer|exists:work_jobs,id',
            'business_pct' => 'nullable|integer|min:1|max:100',
            'note' => 'nullable|string|max:500',
            'reason' => 'nullable|string|max:250',
            'make_rule' => 'boolean',
            'lines' => 'nullable|array|min:1',
            'lines.*.kind' => ['required_with:lines', Rule::in(array_keys(Allocation::KINDS))],
            'lines.*.category_id' => 'nullable|integer|exists:categories,id',
            'lines.*.work_job_id' => 'nullable|integer|exists:work_jobs,id',
            'lines.*.amount_pence' => 'required_with:lines|integer',
            'lines.*.business_use_pct' => 'nullable|integer|min:0|max:100',
            'lines.*.note' => 'nullable|string|max:500',
        ];
    }

    /** Decide one item (bank or manual). */
    public function decide(Request $request, string $source, int $id): RedirectResponse
    {
        $data = $request->validate($this->rules());
        $item = $this->find($source, $id);
        $result = $this->decisions->apply($data, collect([$item]));

        return $this->respond($result, $item instanceof BankTransaction ? $item->merchant_key : null);
    }

    /** Decide many: explicit ids, or every undecided item from a merchant (in one year or all years). */
    public function bulk(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules() + [
            'ids' => 'nullable|array',
            'ids.*' => 'integer',
            'merchant_key' => 'nullable|string',
            'tax_year' => 'nullable|string|max:7',
            'include_decided' => 'boolean',
        ]);

        if (! empty($data['ids'])) {
            $sources = BankTransaction::query()->whereIn('id', $data['ids'])->get();
            $includeProtected = true;
        } elseif (! empty($data['merchant_key'])) {
            $sources = BankTransaction::query()->where('merchant_key', $data['merchant_key'])
                ->when($data['tax_year'] ?? null, fn ($q, $y) => $q->where('tax_year', $y))->get();
            $includeProtected = (bool) ($data['include_decided'] ?? false);
        } else {
            return back()->with('error', 'Choose some items first.');
        }

        unset($data['lines']);

        return $this->respond($this->decisions->apply($data, $sources, $includeProtected), $sources->first() instanceof BankTransaction && ! ($data['make_rule'] ?? false) ? $sources->first()->merchant_key : null);
    }

    /** Accept a suggestion as it stands. Accepting is a choice of yours too: other payments waiting from there learn from it. */
    public function confirm(Request $request, string $source, int $id): RedirectResponse
    {
        $item = $this->find($source, $id);
        try {
            $this->writer->confirm($item);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
        $learned = app(Categoriser::class)->learnFrom(collect([$item]));
        $this->offerRule($item instanceof BankTransaction ? $item->merchant_key : null);

        if (request()->boolean('quiet')) {
            return $learned ? back()->with('success', self::learnedText($learned)) : back();
        }

        return back()->with('success', 'Confirmed.'.($learned ? ' '.self::learnedText($learned) : ''));
    }

    public function confirmBulk(Request $request): RedirectResponse
    {
        $data = $request->validate(['ids' => 'required_without:merchant_key|array', 'ids.*' => 'integer', 'merchant_key' => 'nullable|string', 'tax_year' => 'nullable|string|max:7']);
        $query = BankTransaction::query()->where('review_status', 'suggested');
        ! empty($data['ids'])
            ? $query->whereIn('id', $data['ids'])
            : $query->where('merchant_key', $data['merchant_key'])->when($data['tax_year'] ?? null, fn ($q, $y) => $q->where('tax_year', $y));
        $done = collect();
        foreach ($query->get() as $tx) {
            try {
                $this->writer->confirm($tx);
                $done->push($tx);
            } catch (InvalidArgumentException) {
            }
        }
        $learned = $done->isNotEmpty() ? app(Categoriser::class)->learnFrom($done) : 0;

        return back()->with('success', "Confirmed {$done->count()}.".($learned ? ' '.self::learnedText($learned) : ''));
    }

    public function clear(Request $request, string $source, int $id): RedirectResponse
    {
        try {
            $this->writer->clear($this->find($source, $id), $request->string('reason')->toString() ?: null);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return request()->boolean('quiet') ? back() : back()->with('success', 'Sent back to the Inbox.');
    }

    private function find(string $source, int $id): BankTransaction|ManualExpense
    {
        return $source === 'manual' ? ManualExpense::query()->findOrFail($id) : BankTransaction::query()->findOrFail($id);
    }

    /** Offer "always do this" for a merchant, when your decisions for it are ready for a rule. */
    private function offerRule(?string $merchantKey): void
    {
        $offer = $merchantKey ? app(RuleLearner::class)->offer($merchantKey) : null;
        if ($offer) {
            session()->flash('rule_offer', ['merchant_key' => $offer['merchant_key'], 'text' => $offer['text']]);
        }
    }

    /** What learning did to the other payments waiting: their suggestions were worked out again, not copied. */
    private static function learnedText(int $learned): string
    {
        return "Updated the suggestion for {$learned} other waiting ".($learned === 1 ? 'payment' : 'payments').' from there.';
    }

    private function respond(array $result, ?string $merchantKey = null): RedirectResponse
    {
        if ($result['saved']) {
            $this->offerRule($merchantKey);
        }
        $message = $result['saved'] === 1 ? 'Saved.' : "Saved {$result['saved']}.";
        if ($result['skipped']) {
            $message .= " Left {$result['skipped']} you'd already decided.";
        }
        if (($result['learned'] ?? 0) > 0) {
            $learned = self::learnedText($result['learned']);
            $message .= ' '.$learned;
            if (request()->boolean('quiet') && ! $result['errors']) {
                return back()->with('success', $learned);
            }
        }

        if ($result['errors']) {
            return back()->with('error', implode(' ', $result['errors']))->with('success', $result['saved'] ? $message : null);
        }

        return request()->boolean('quiet') ? back() : back()->with('success', $message);
    }
}
