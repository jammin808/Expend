<?php

namespace App\Http\Controllers;

use App\Domain\Charges\CostCharges;
use App\Domain\Charges\CostRef;
use App\Domain\Charges\CostUnits;
use App\Domain\Ledger\DecisionService;
use App\Models\BankTransaction;
use App\Models\Client;
use App\Models\CostCharge;
use App\Models\Document;
use App\Models\ManualExpense;
use App\Models\WorkJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Your decisions about one cost, by its id (CostRef::toId: 'tx:381:j45' for tx:381#j45): Charge to Acme, Not charged,
 * Charged another way, It was on INV-0031, Claimed (on their expense form), paid back or not, the same cost as another
 * or not, or forget it. And on a payment: "Recharge to Acme", and which client a confirmed cost with no job was for.
 * Every write goes through CostCharges or DecisionService as you; none is a ledger write except the client on a cost
 * (setExpenseClient), which a locked year refuses.
 */
class CostChargeController extends Controller
{
    /** What each decision is called, for the message. */
    private const SAID = [
        'recharge' => 'Charged to the client: it goes on their next draft.',
        'no_charge' => 'Not charged: it stays a cost of yours.',
        'elsewhere' => 'Charged another way: it won’t be offered on an invoice.',
        'invoiced' => 'Noted: it was on that invoice.',
    ];

    /** Charge to Acme (recharge), Not charged (no_charge), Charged another way (elsewhere) or It was on INV-x (invoiced). */
    public function decide(Request $request, string $id, CostCharges $charges): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'charge' => ['required', Rule::in(CostCharge::CHARGES)], 'client_id' => 'nullable|integer|exists:clients,id',
            'invoice_id' => 'nullable|integer|exists:invoices,id', 'note' => 'nullable|string|max:250',
        ], [
            'charge.required' => 'Say how it’s charged.', 'charge.in' => 'Say how it’s charged.',
            'client_id.exists' => 'That client no longer exists: pick another.', 'invoice_id.exists' => 'That invoice no longer exists: pick another.',
            'note.max' => 'The note is too long (250 characters at most).',
        ]);

        return $this->run($request, fn () => $charges->decide(self::known($id), $data['charge'], $data['client_id'] ?? null, $data['invoice_id'] ?? null, $data['note'] ?? null)
            ? self::SAID[$data['charge']] : '');
    }

    /** Claimed on the client's own expense form: charged another way, noted as claimed. */
    public function claimed(Request $request, string $id, CostCharges $charges): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['note' => 'nullable|string|max:250']);

        return $this->run($request, fn () => $charges->claimed(self::known($id), $data['note'] ?? null) ? 'Noted: claimed on their expense form.' : '');
    }

    /** Was a cost you charged another way (or claimed) paid back? */
    public function outcome(Request $request, string $id, CostCharges $charges): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['outcome' => ['required', Rule::in(CostCharge::OUTCOMES)]], ['outcome.required' => 'Say whether it was paid back.', 'outcome.in' => 'Say whether it was paid back.']);

        return $this->run($request, fn () => $charges->outcome(self::ref($id), $data['outcome'])
            ? ($data['outcome'] === 'paid_back' ? 'Noted: paid back.' : 'Noted: not paid back, so it stays a cost of yours.') : '');
    }

    /** Forget your decision: it's charged by the arrangement again. */
    public function clear(Request $request, string $id, CostCharges $charges): JsonResponse|RedirectResponse
    {
        return $this->run($request, function () use ($id, $charges) {
            $charges->clear(self::ref($id));

            return 'Back to the usual arrangement.';
        });
    }

    /** It's the same cost as another (same: it counts once) or not (distinct: not asked again). */
    public function pair(Request $request, string $id, CostCharges $charges): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['other' => 'required|string|max:191', 'pair' => ['required', Rule::in(CostCharge::PAIRS)]], [
            'other.required' => 'Pick the other cost.', 'pair.required' => 'Say whether they’re the same.', 'pair.in' => 'Say whether they’re the same.',
        ]);

        return $this->run($request, function () use ($id, $data, $charges) {
            $other = CostRef::fromId($data['other']) ?? (CostRef::valid($data['other']) ? $data['other'] : null);
            if ($other === null || ! self::exists($other)) {
                throw new InvalidArgumentException('That other cost isn’t one Expend knows: open it again.');
            }
            $charges->pair(self::known($id), $other, $data['pair']);

            return $data['pair'] === 'same' ? 'Noted: they’re the same cost, charged once.' : 'Noted: they’re different costs.';
        });
    }

    /**
     * "Recharge to Acme" on a payment or an expense: it goes on the client's next draft, and a confirmed cost with no
     * job in an open year counts in their costs too (the message says when it doesn't, and why).
     */
    public function recharge(Request $request, string $source, int $id, CostCharges $charges): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['client_id' => 'required|integer|exists:clients,id', 'note' => 'nullable|string|max:250'], [
            'client_id.required' => 'Pick the client to charge it to.', 'client_id.exists' => 'That client no longer exists: pick another.',
        ]);
        $item = self::item($source, $id);

        return $this->run($request, fn () => $charges->rechargeToClient($item, (int) $data['client_id'], $data['note'] ?? null)['message']);
    }

    /** Which client a confirmed cost with no job was for (null: none), so it counts in their costs. */
    public function expenseClient(Request $request, string $source, int $id, DecisionService $decisions): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['client_id' => 'nullable|integer|exists:clients,id'], ['client_id.exists' => 'That client no longer exists: pick another.']);
        $item = self::item($source, $id);

        return $this->run($request, function () use ($decisions, $item, $data) {
            $changed = $decisions->setExpenseClient($item, isset($data['client_id']) ? (int) $data['client_id'] : null);
            if (! $changed) {
                return 'Nothing changed: only its costs with no job take a client.';
            }

            return isset($data['client_id']) ? 'Counted in '.Client::query()->whereKey($data['client_id'])->value('name').'’s costs.' : 'Not counted for any client now.';
        });
    }

    /** The ref an id names, or a refusal in plain words. */
    private static function ref(string $id): string
    {
        return CostRef::fromId($id) ?? throw new InvalidArgumentException('That isn’t a cost Expend knows: open it again.');
    }

    /**
     * The ref an id names, when it's a cost Expend has now: a payment's or expense's cost on the job or for the client
     * it names (CostUnits::refsOf), a booking, a trip, or a job's or PO's own. A decision filed under a ref the cost
     * doesn't have would sit unseen, and apply by surprise if the cost moved there later.
     */
    private static function known(string $id): string
    {
        $ref = self::ref($id);
        if (! self::exists($ref)) {
            throw new InvalidArgumentException('That isn’t a cost Expend knows: open it again.');
        }

        return $ref;
    }

    private static function exists(string $ref): bool
    {
        $p = CostRef::parse($ref);

        return match ($p['type']) {
            'job' => WorkJob::query()->whereKey($p['id'])->exists(),
            'doc' => Document::query()->whereKey($p['id'])->exists(),
            default => in_array($ref, CostUnits::refsOf(CostRef::subject($ref)), true),
        };
    }

    private static function item(string $source, int $id): BankTransaction|ManualExpense
    {
        return $source === 'manual' ? ManualExpense::query()->findOrFail($id) : BankTransaction::query()->findOrFail($id);
    }

    /** Run a decision; its refusal goes back as the error (422 to a sheet that reads JSON). */
    private function run(Request $request, callable $do): JsonResponse|RedirectResponse
    {
        try {
            $message = (string) $do();
        } catch (InvalidArgumentException $e) {
            return $request->expectsJson() && ! $request->header('X-Inertia') ? response()->json(['message' => $e->getMessage()], 422) : back()->with('error', $e->getMessage());
        }

        return $request->expectsJson() && ! $request->header('X-Inertia') ? response()->json(['message' => $message]) : back()->with('success', $message);
    }
}
