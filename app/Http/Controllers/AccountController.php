<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Ledger\Reconciler;
use App\Models\Account;
use App\Models\StatementBalance;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class AccountController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('accounts/index', [
            'accounts' => Account::query()->withCount('transactions')->orderBy('name')->get()->map(fn (Account $a) => [
                'id' => $a->id, 'name' => $a->name, 'kind' => $a->kind, 'provider' => $a->provider, 'last4' => $a->last4,
                'is_active' => $a->is_active, 'transactions' => $a->transactions_count, 'preset' => $a->import_profile['preset'] ?? null,
                'currency' => strtoupper($a->currency ?: 'GBP'), 'parent' => $a->parent?->name,
                'reconcile' => Reconciler::account($a),
                'balances' => StatementBalance::query()->where('account_id', $a->id)->orderByDesc('as_of')->limit(24)->get()
                    ->map(fn (StatementBalance $b) => ['id' => $b->id, 'as_of' => $b->as_of->format('Y-m-d'), 'balance_pence' => $b->balance_pence, 'note' => $b->note]),
            ]),
        ]);
    }

    /**
     * A balance copied from a statement, to check the account against. Entering one for a date that has one already
     * replaces it, and the audit log keeps the figure it replaced.
     */
    public function addBalance(Request $request, Account $account): RedirectResponse
    {
        $data = $request->validate(['as_of' => 'required|date|before_or_equal:today', 'balance' => 'required|string|max:20', 'note' => 'nullable|string|max:200']);
        // In the account's own currency and its own units, as its lines are kept and checked (Reconciler): a yen
        // pocket's "17,500" is 17500, a dinar pocket's "12.345" is 12345.
        $currency = strtoupper($account->currency ?: 'GBP');
        try {
            $pence = Money::parseIn($data['balance'], $currency);
        } catch (InvalidArgumentException) {
            $pence = null;
        }
        if ($pence === null) {
            return back()->withErrors(['balance' => Money::digits($currency) === 2
                ? 'Enter the balance as it appears on the statement, like 1,234.56 (with a full stop before the pence).'
                : 'Enter the balance as it appears on the statement, in '.$currency.' (with a full stop before any decimals, and at most '.Money::digits($currency).' of them).'])->withInput();
        }
        // A card's balance is what you owe, and its statement marks a balance in credit "CR" (and one owed "DR"): the
        // other way round from a bank account, which is how the amount is read.
        if ($account->isCard() && preg_match('/(CR|DR)\s*\)?\s*$/i', trim($data['balance']))) {
            $pence = -$pence;
        }
        $asOf = CarbonImmutable::parse($data['as_of'])->format('Y-m-d');
        DB::transaction(function () use ($account, $asOf, $pence, $data) {
            $row = StatementBalance::query()->where('account_id', $account->id)->whereDate('as_of', $asOf)->lockForUpdate()->first();
            $before = $row ? self::audited($row) : null;
            $row ??= new StatementBalance(['account_id' => $account->id]);
            $row->fill(['as_of' => $asOf, 'balance_pence' => $pence, 'note' => $data['note'] ?? null])->save();
            AuditLog::record($before ? 'account.balance_changed' : 'account.balance_added', $account, $before, self::audited($row));
        });
        $check = Reconciler::account($account->fresh());

        return back()->with($check['gaps'] ? 'error' : 'success', 'Saved. '.$check['text']);
    }

    public function removeBalance(Account $account, StatementBalance $balance): RedirectResponse
    {
        abort_unless($balance->account_id === $account->id, 404);
        AuditLog::record('account.balance_removed', $account, self::audited($balance), null);
        $balance->delete();

        return back()->with('success', 'Removed.');
    }

    /** @return array{as_of:string, balance_pence:int, note:?string} */
    private static function audited(StatementBalance $b): array
    {
        return ['as_of' => $b->as_of->format('Y-m-d'), 'balance_pence' => (int) $b->balance_pence, 'note' => $b->note];
    }

    public function store(Request $request): RedirectResponse
    {
        $account = Account::query()->create($this->validated($request));
        AuditLog::record('account.created', $account, null, $account->toArray());

        return back()->with('success', "Added {$account->name}.");
    }

    public function update(Request $request, Account $account): RedirectResponse
    {
        $before = $account->toArray();
        $account->update($this->validated($request));
        AuditLog::record('account.updated', $account, $before, $account->fresh()->toArray());

        return back()->with('success', 'Saved.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            // Long enough for the names a bank's own connection gives ("Starling " and the account's name there).
            'name' => 'required|string|max:255',
            'kind' => 'required|in:bank,credit_card',
            'provider' => 'nullable|in:starling,amex,capital_one,revolut,monzo,wise,paypal,other',
            'last4' => 'nullable|digits:4',
            'is_active' => 'boolean',
        ]);
    }
}
