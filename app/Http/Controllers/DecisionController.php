<?php

namespace App\Http\Controllers;

use App\Domain\Clients\ClientIds;
use App\Domain\Intel\Vetoes;
use App\Domain\Invoices\InvoicePayments;
use App\Domain\Ledger\AllocationWriter;
use App\Domain\Ledger\DecisionService;
use App\Domain\Ledger\RuleLearner;
use App\Domain\Matching\Categoriser;
use App\Domain\Matching\TransferPairer;
use App\Domain\Travel\TravelMatcher;
use App\Models\Allocation;
use App\Models\BankTransaction;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\ManualExpense;
use App\Models\TravelItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
            // Income design v3: the invoice a line pays and the client it's for (null takes the link off; a line without
            // the key says nothing about them). Top level for a one-line decision, or per line.
            'invoice_id' => 'nullable|integer|exists:invoices,id',
            'client_id' => 'nullable|integer|exists:clients,id',
            'lines.*.invoice_id' => 'nullable|integer|exists:invoices,id',
            'lines.*.client_id' => 'nullable|integer|exists:clients,id',
            // The lines the page showed (their ids): what you decided differently from is remembered only if it's still that.
            'shown' => 'nullable|array',
            'shown.*' => 'integer',
        ];
    }

    /** Plain words for a link chosen on a page that has gone since (deleted, or merged away). */
    private const MESSAGES = [
        'invoice_id.exists' => 'That invoice no longer exists: pick another.',
        'client_id.exists' => 'That client no longer exists: pick another.',
        'lines.*.invoice_id.exists' => 'The invoice on line :position no longer exists: pick another.',
        'lines.*.client_id.exists' => 'The client on line :position no longer exists: pick another.',
        'work_job_id.exists' => 'That job no longer exists: pick another.',
        'lines.*.work_job_id.exists' => 'The job on line :position no longer exists: pick another.',
    ];

    /** Decide one item (bank or manual). */
    public function decide(Request $request, string $source, int $id): RedirectResponse
    {
        $data = $request->validate($this->rules(), self::MESSAGES);
        $item = $this->find($source, $id);
        $result = $this->decisions->apply($data, collect([$item]));

        return $this->respond($result, $item instanceof BankTransaction ? $item->merchant_key : null);
    }

    /**
     * Decide many: explicit ids, or every undecided item from a merchant (in one year or all years). An invoice is picked
     * for each payment on its own, never for many at once; a payment whose suggestion names an invoice is left for you
     * to check, and with explicit ids a split payment is left as it is (both counted in the message). A client (no job,
     * no invoice) can be given to them all.
     */
    public function bulk(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules() + [
            'ids' => 'nullable|array',
            'ids.*' => 'integer',
            'merchant_key' => 'nullable|string',
            'tax_year' => 'nullable|string|max:7',
            'include_decided' => 'boolean',
        ], self::MESSAGES);

        if (($data['invoice_id'] ?? null) !== null) {
            return back()->with('error', 'Pick the invoice for each payment on its own (open it), not for all of them.');
        }
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

        unset($data['lines'], $data['shown'], $data['invoice_id']);

        return $this->respond($this->decisions->apply($data, $sources, $includeProtected, bulk: true, keepSplits: ! empty($data['ids'])),
            $sources->first() instanceof BankTransaction && ! ($data['make_rule'] ?? false) ? $sources->first()->merchant_key : null);
    }

    /**
     * "These are Acme Events'": money in from selected payments, or every payment from a payer (in one year, or all),
     * set as one client's (Transactions' "Set client", the year page's "Decide all" for money in). Decided as you
     * (DecisionService::setClient): a locked year, money out, and lines decided as something else are left as they
     * are, and so is a suggestion naming an invoice or a job; the message says what was left and why.
     */
    public function bulkClient(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'client_id' => 'required|integer|exists:clients,id',
            'ids' => 'nullable|array', 'ids.*' => 'integer',
            'merchant_key' => 'nullable|string|max:191',
            'tax_year' => 'nullable|string|max:7',
        ], [
            'client_id.required' => 'Pick the client first.',
            'client_id.exists' => 'That client no longer exists: pick another.',
        ]);
        if (! empty($data['ids'])) {
            $sources = BankTransaction::query()->whereIn('id', $data['ids'])->orderBy('posted_on')->orderBy('id')->get();
        } elseif (! empty($data['merchant_key'])) {
            $sources = BankTransaction::query()->where('merchant_key', $data['merchant_key'])
                ->when($data['tax_year'] ?? null, fn ($q, $y) => $q->where('tax_year', $y))->orderBy('posted_on')->orderBy('id')->get();
        } else {
            return back()->with('error', 'Choose some payments first.');
        }
        try {
            $result = $this->decisions->setClient($sources, (int) $data['client_id']);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
        $name = (string) Client::query()->whereKey(ClientIds::resolve((int) $data['client_id']))->value('name');
        $message = ($result['saved'] === 0 ? 'Nothing changed.' : 'Set '.$result['saved'].' as '.$name.'’s.')
            .($result['already'] > 0 ? ' '.$result['already'].' '.($result['already'] === 1 ? 'was' : 'were').' '.$name.'’s already.' : '')
            .self::leftWords($result['left'])
            .($result['learned'] > 0 ? ' '.self::learnedText($result['learned']) : '');

        if ($result['errors']) {
            return back()->with('error', implode(' ', $result['errors']))->with('success', $result['saved'] ? $message : null);
        }

        return back()->with('success', $message);
    }

    /**
     * What a bulk decision left as it was, and why, in words: " Left 4 invoice matches for you to check. Left 2 split
     * payments as they are."
     *
     * @param  array{invoice?:int, split?:int, locked?:int, suggestion?:int, other?:int}  $left
     */
    private static function leftWords(array $left): string
    {
        $n = fn (string $k) => (int) ($left[$k] ?? 0);
        $words = array_filter([
            $n('invoice') ? 'Left '.$n('invoice').' invoice '.($n('invoice') === 1 ? 'match' : 'matches').' for you to check.' : null,
            $n('split') ? 'Left '.$n('split').' split '.($n('split') === 1 ? 'payment' : 'payments').' as '.($n('split') === 1 ? 'it is' : 'they are').'.' : null,
            $n('locked') ? 'Left '.$n('locked').' in a locked year: unlock it (with a reason) first.' : null,
            $n('suggestion') ? 'Left '.$n('suggestion').' with a suggestion for a job or invoice: accept or change '.($n('suggestion') === 1 ? 'it' : 'them').' first.' : null,
            $n('other') ? 'Left '.$n('other').' that '.($n('other') === 1 ? 'isn’t' : 'aren’t').' money in from them, or '.($n('other') === 1 ? 'was' : 'were').' decided as something else.' : null,
        ]);

        return $words === [] ? '' : ' '.implode(' ', $words);
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

    /**
     * Accept many suggestions as they stand. One whose invoice was voided since, or isn't issued yet, can't be accepted
     * (Accept refuses it): it's counted and left for you.
     */
    public function confirmBulk(Request $request): RedirectResponse
    {
        $data = $request->validate(['ids' => 'required_without:merchant_key|array', 'ids.*' => 'integer', 'merchant_key' => 'nullable|string', 'tax_year' => 'nullable|string|max:7']);
        $query = BankTransaction::query()->where('review_status', 'suggested');
        ! empty($data['ids'])
            ? $query->whereIn('id', $data['ids'])
            : $query->where('merchant_key', $data['merchant_key'])->when($data['tax_year'] ?? null, fn ($q, $y) => $q->where('tax_year', $y));
        $done = collect();
        $unpayable = 0;
        foreach ($query->get() as $tx) {
            try {
                $this->writer->confirm($tx);
                $done->push($tx);
            } catch (InvalidArgumentException) {
                $invoices = Allocation::query()->where('bank_transaction_id', $tx->id)->whereNull('confirmed_at')->whereNotNull('invoice_id')->pluck('invoice_id');
                if ($invoices->isNotEmpty() && Invoice::query()->whereKey($invoices->all())->whereIn('status', InvoicePayments::PAYABLE)->count() < $invoices->unique()->count()) {
                    $unpayable++;
                }
            }
        }
        $learned = $done->isNotEmpty() ? app(Categoriser::class)->learnFrom($done) : 0;

        return back()->with('success', "Confirmed {$done->count()}."
            .($unpayable ? ' Left '.$unpayable.' whose invoice was voided or isn’t issued yet.' : '')
            .($learned ? ' '.self::learnedText($learned) : ''));
    }

    /**
     * Send an item back to the Inbox. Clearing an automatic suggestion ("Not this one", with a reason) remembers it, so
     * nothing automatic proposes it for this item again (Intel\Vetoes). The Inbox's own Undo (undo) only puts back what
     * you just did: it remembers nothing, and lifts what your decision remembered.
     *
     * Bound to what was shown: the page sends the lines it showed (shown, their ids), and when the item says something
     * else by now (another suggestion replaced it) nothing is cleared and nothing is remembered. Saying no to one side of
     * a suggested transfer pair unpairs the two, as deciding it would (TransferPairer::afterYourDecision); saying no to a
     * stay's payment found by the travel matcher takes it off the stay too (TravelMatcher::notItsPayment).
     */
    public function clear(Request $request, string $source, int $id): RedirectResponse
    {
        $data = $request->validate(['reason' => 'nullable|string|max:250', 'reason_code' => ['nullable', Rule::in(array_keys(Vetoes::REASONS))], 'undo' => 'boolean',
            'shown' => 'nullable|array', 'shown.*' => 'integer']);
        $item = $this->find($source, $id);
        $reason = ($data['reason'] ?? null) ?: null;
        $code = $data['reason_code'] ?? null;
        $undo = (bool) ($data['undo'] ?? false) || $reason === 'Undo from Inbox';
        if ($code !== null && $reason === null) {
            $reason = 'Not this: '.mb_strtolower(Vetoes::REASONS[$code]);
        }
        try {
            $done = DB::transaction(function () use ($item, $reason, $code, $undo, $data) {
                $was = $item->allocations()->lockForUpdate()->get();
                if (! $undo && array_key_exists('shown', $data) && ! Vetoes::isShown($was, $data['shown'])) {
                    return false;
                }
                $this->writer->clear($item, $reason);
                if ($undo) {
                    Vetoes::liftSinceLastDecision($item, 'Undone from the Inbox');

                    return true;
                }
                $words = $code !== null ? Vetoes::REASONS[$code] : null;
                Vetoes::fromSuggestion($item, $was, null, $code ?? 'cleared', $words);
                if ($item instanceof BankTransaction && Vetoes::isOpenSuggestion($was)) {
                    $this->unlinkWhatItClaimed($item, $was, $code ?? 'cleared', $words);
                }

                return true;
            });
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
        if (! $done) {
            return back()->with('error', self::CHANGED);
        }

        return request()->boolean('quiet') ? back() : back()->with('success', $code !== null ? 'Sent back to the Inbox. That suggestion won’t be made for it again.' : 'Sent back to the Inbox.');
    }

    /** What a press on an item that says something else by now is told: nothing was done. */
    public const CHANGED = 'This changed since the page was shown, so nothing was done. Look at it again.';

    /**
     * After you said no to an automatic suggestion on a payment: what it linked the payment to goes too. One side of a
     * suggested transfer pair is unpaired from the other (and that pair isn't made again); a stay or journey the travel
     * matcher found it for no longer claims it.
     *
     * @param  Collection<int, Allocation>  $was  the suggestion you said no to
     */
    private function unlinkWhatItClaimed(BankTransaction $tx, Collection $was, string $code, ?string $words): void
    {
        $tx->refresh();
        if ($tx->transfer_pair_id && TransferPairer::movesMoney($was)) {
            app(TransferPairer::class)->afterYourDecision($tx, $code, $words);
        }
        if ($was->contains(fn (Allocation $a) => $a->method === 'travel.match')) {
            $matcher = app(TravelMatcher::class);
            foreach (TravelItem::query()->whereNull('decided_at')->where('bank_transaction_id', $tx->id)->get() as $stay) {
                $matcher->notItsPayment($stay, $tx->id, $code, $words);
            }
        }
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
        $message .= self::leftWords($result['left'] ?? []);
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
